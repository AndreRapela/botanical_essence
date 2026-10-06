<?php

declare(strict_types=1);

final class WithdrawalService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RateLimiter $limiter,
        private readonly Mailer $mailer
    ) {
    }

    public function submit(array $input, array $user): array
    {
        Validation::allowedFields($input, ['orderNumber', 'type', 'message', 'deliveryReceivedDate', 'language']);
        $orderNumber = Validation::requiredString($input, 'orderNumber', 8, 32);
        $type = Validation::oneOf($input, 'type', ['withdrawal', 'problem']);
        $language = isset($input['language']) ? Validation::oneOf($input, 'language', ['en', 'fr']) : 'en';
        $message = Validation::optionalString($input, 'message', 2000);
        if ($type === 'problem' && mb_strlen((string) $message) < 10) {
            throw new HttpException('Descreva o problema com pelo menos 10 caracteres.', 422, ['message' => 'Informe os detalhes do problema.']);
        }
        $deliveryDate = $this->validatedDeliveryDate($input['deliveryReceivedDate'] ?? null);
        $userId = (int) $user['id'];
        $this->limiter->assertAllowed('withdrawal-request', (string) $userId, 12, 3600);

        $this->pdo->beginTransaction();
        try {
            $orderQuery = $this->pdo->prepare(
                'SELECT id, order_number, status, payment_status, payment_provider, payment_refund_outcome,
                        payment_intent_id, payment_refunded_cents AS refundedCents, email, customer_name, total, currency
                 FROM orders WHERE order_number = :number AND user_id = :userId LIMIT 1 FOR UPDATE'
            );
            $orderQuery->execute([':number' => $orderNumber, ':userId' => $userId]);
            $order = $orderQuery->fetch();
            if (!$order) {
                throw new HttpException('Pedido não encontrado.', 404);
            }
            if (!WithdrawalPolicy::canRequest($order)) {
                throw new HttpException('Este pedido não está disponível para solicitar retirada ou devolução pelo portal.', 409, [], 'withdrawal_not_available');
            }

            $active = $this->pdo->prepare(
                "SELECT id FROM withdrawal_requests
                 WHERE order_id = :orderId AND status IN ('submitted','reviewing','instructions_sent','return_received','refund_pending','refund_failed')
                 ORDER BY id DESC LIMIT 1"
            );
            $active->execute([':orderId' => $order['id']]);
            $activeId = $active->fetchColumn();
            if ($activeId) {
                $this->pdo->commit();
                return ['request' => $this->requestForUser((int) $activeId, $userId), 'alreadySubmitted' => true];
            }

            $requestNumber = 'RET-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $insert = $this->pdo->prepare(
                'INSERT INTO withdrawal_requests
                    (request_number, order_id, user_id, language, request_type, customer_message, declared_delivery_date)
                 VALUES (:number, :orderId, :userId, :language, :type, :message, :deliveryDate)'
            );
            $insert->execute([
                ':number' => $requestNumber,
                ':orderId' => $order['id'],
                ':userId' => $userId,
                ':language' => $language,
                ':type' => $type,
                ':message' => $message,
                ':deliveryDate' => $deliveryDate,
            ]);
            $requestId = (int) $this->pdo->lastInsertId();
            $this->addEvent($requestId, $userId, 'customer', 'request.submitted', null, 'submitted', $message);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $emailAcknowledged = $this->sendAcknowledgment($order, $requestNumber, $type, $message, $language, $requestId);
        return [
            'request' => $this->requestForUser($requestId, $userId),
            'alreadySubmitted' => false,
            'emailAcknowledged' => $emailAcknowledged,
        ];
    }

    public function adminList(array $query = []): array
    {
        Validation::allowedFields($query, ['page', 'limit']);
        $page = Validation::queryInteger($query, 'page', 1, 1, 1_000_000);
        $limit = Validation::queryInteger($query, 'limit', 50, 1, 100);
        $offset = ($page - 1) * $limit;
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM withdrawal_requests')->fetchColumn();
        $statement = $this->pdo->prepare(
            "SELECT r.id, r.request_number AS requestNumber, r.request_type AS requestType,
                    r.status, r.customer_message AS customerMessage, r.declared_delivery_date AS deliveryReceivedDate,
                    r.admin_note AS adminNote,
                    DATE_FORMAT(r.created_at, '%Y-%m-%dT%H:%i:%sZ') AS createdAt,
                    o.order_number AS orderNumber, o.status AS orderStatus, o.payment_status AS paymentStatus,
                    o.payment_provider AS paymentProvider, o.payment_refund_attempt AS refundAttempt,
                    o.payment_refund_outcome AS refundOutcome, o.payment_refund_error_code AS refundErrorCode,
                    o.payment_refund_error_message AS refundErrorMessage,
                    o.total, o.currency, o.customer_name AS customerName, o.email,
                    (SELECT GROUP_CONCAT(CONCAT(i.product_name, ' × ', i.quantity) ORDER BY i.id SEPARATOR ', ')
                     FROM order_items i WHERE i.order_id = o.id) AS orderItems
             FROM withdrawal_requests r
             INNER JOIN orders o ON o.id = r.order_id
             ORDER BY FIELD(r.status,'submitted','reviewing','instructions_sent','return_received','refund_pending','refund_failed','not_eligible','refunded'), r.created_at DESC, r.id DESC
             LIMIT :limit OFFSET :offset"
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        return [
            'data' => array_map(static fn (array $row): array => [
                ...$row,
                'id' => (int) $row['id'],
                'refundAttempt' => (int) $row['refundAttempt'],
                'total' => (float) $row['total'],
                'deliveryReceivedDate' => $row['deliveryReceivedDate'] ?: null,
                'orderItems' => $row['orderItems'] ?: '',
            ], $statement->fetchAll()),
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'hasMore' => $offset + $limit < $total,
        ];
    }

    public function forAdmin(int $requestId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.request_number AS requestNumber, r.order_id AS orderId, r.user_id AS userId,
                    r.request_type AS requestType, r.status, r.customer_message AS customerMessage,
                    r.admin_note AS adminNote, o.order_number AS orderNumber, o.status AS orderStatus,
                    o.payment_status AS paymentStatus, o.payment_provider AS paymentProvider,
                    o.payment_intent_id AS paymentIntentId, o.payment_refund_attempt AS refundAttempt,
                    o.payment_refund_outcome AS refundOutcome
             FROM withdrawal_requests r INNER JOIN orders o ON o.id = r.order_id
             WHERE r.id = :id LIMIT 1'
        );
        $statement->execute([':id' => $requestId]);
        $request = $statement->fetch();
        if (!$request) {
            throw new HttpException('Solicitação não encontrada.', 404);
        }
        return $request;
    }

    public function adminTransition(array $input, array $admin): array
    {
        Validation::allowedFields($input, ['requestId', 'action', 'note']);
        $requestId = Validation::integer($input, 'requestId', 1, PHP_INT_MAX);
        $action = Validation::oneOf($input, 'action', ['review', 'send_instructions', 'mark_received', 'not_eligible']);
        $note = Validation::optionalString($input, 'note', 2000);
        $transitions = [
            'review' => ['reviewing', 0],
            'send_instructions' => ['instructions_sent', 20],
            'mark_received' => ['return_received', 10],
            'not_eligible' => ['not_eligible', 10],
        ];
        [$nextStatus, $minimumNote] = $transitions[$action];
        if (mb_strlen((string) $note) < $minimumNote) {
            throw new HttpException('Inclua detalhes suficientes para registrar esta etapa.', 422, ['note' => 'A anotação é obrigatória para esta ação.']);
        }
        $adminId = (int) $admin['id'];
        $this->limiter->assertAllowed('admin-write', (string) $adminId, 300, 3600);

        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare('SELECT status, user_id, request_number FROM withdrawal_requests WHERE id = :id LIMIT 1 FOR UPDATE');
            $select->execute([':id' => $requestId]);
            $current = $select->fetch();
            if (!$current || !WithdrawalPolicy::canTransition((string) $current['status'], $nextStatus)) {
                throw new HttpException('A etapa da solicitação mudou ou não permite esta ação. Atualize a lista.', 409, [], 'withdrawal_state_conflict');
            }
            $update = $this->pdo->prepare(
                'UPDATE withdrawal_requests SET status = :status, admin_note = :note, handled_by_admin_id = :adminId WHERE id = :id'
            );
            $update->execute([':status' => $nextStatus, ':note' => $note, ':adminId' => $adminId, ':id' => $requestId]);
            $this->addEvent($requestId, $adminId, 'admin', 'request.' . $action, (string) $current['status'], $nextStatus, $note);
            $audit = $this->pdo->prepare(
                "INSERT INTO admin_audit_log
                    (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                 VALUES (:adminId, :action, 'withdrawal', :requestId, :previous, :new)"
            );
            $audit->execute([
                ':adminId' => $adminId,
                ':action' => 'withdrawal.' . $action,
                ':requestId' => $requestId,
                ':previous' => (string) $current['status'],
                ':new' => $nextStatus,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $this->sendStatusNotification($requestId, $nextStatus, $note);
        return ['message' => 'Solicitação atualizada: ' . $nextStatus . '.'];
    }

    public function setRefundState(int $requestId, int $adminId, string $status, ?string $note = null): void
    {
        if (!in_array($status, ['refund_pending', 'refund_failed', 'refunded'], true)) {
            throw new LogicException('Estado de reembolso de retirada inválido.');
        }
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare('SELECT status FROM withdrawal_requests WHERE id = :id LIMIT 1 FOR UPDATE');
            $select->execute([':id' => $requestId]);
            $current = $select->fetchColumn();
            if (!is_string($current) || !WithdrawalPolicy::canTransition($current, $status)) {
                if ($current === $status) {
                    $this->pdo->commit();
                    return;
                }
                throw new HttpException('O estado da solicitação mudou durante o reembolso.', 409, [], 'withdrawal_state_conflict');
            }
            $update = $this->pdo->prepare('UPDATE withdrawal_requests SET status = :status, handled_by_admin_id = :adminId WHERE id = :id');
            $update->execute([':status' => $status, ':adminId' => $adminId, ':id' => $requestId]);
            $this->addEvent($requestId, $adminId, 'admin', 'refund.' . $status, $current, $status, $note);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        $this->sendStatusNotification($requestId, $status, $note);
    }

    public function recordLocalTestRefund(int $requestId, int $adminId): void
    {
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare(
                'SELECT r.status AS requestStatus, r.order_id, o.status AS orderStatus,
                        o.payment_status AS paymentStatus, o.payment_provider AS paymentProvider,
                        o.test_payment AS testPayment
                 FROM withdrawal_requests r INNER JOIN orders o ON o.id = r.order_id
                 WHERE r.id = :id LIMIT 1 FOR UPDATE'
            );
            $select->execute([':id' => $requestId]);
            $current = $select->fetch();
            if (!$current || $current['requestStatus'] !== 'return_received'
                || $current['paymentStatus'] !== 'paid' || $current['paymentProvider'] !== 'local_test'
                || !(bool) $current['testPayment']) {
                throw new HttpException('O pedido de teste ou a solicitação mudou antes da resolução.', 409);
            }

            $updateOrder = $this->pdo->prepare(
                "UPDATE orders SET payment_status = 'refunded', payment_refunded_cents = ROUND(total * 100), payment_refund_outcome = 'manual_refunded'
                 WHERE id = :id AND payment_status = 'paid' AND payment_provider = 'local_test' AND test_payment = TRUE"
            );
            $updateOrder->execute([':id' => $current['order_id']]);
            if ($updateOrder->rowCount() !== 1) {
                throw new HttpException('O pedido de teste mudou durante a resolução.', 409);
            }

            $note = 'Pagamento local simulado; nenhum dinheiro foi movimentado.';
            $updateRequest = $this->pdo->prepare(
                "UPDATE withdrawal_requests SET status = 'refunded', admin_note = :note, handled_by_admin_id = :adminId
                 WHERE id = :id AND status = 'return_received'"
            );
            $updateRequest->execute([':note' => $note, ':adminId' => $adminId, ':id' => $requestId]);
            if ($updateRequest->rowCount() !== 1) {
                throw new HttpException('A solicitação mudou durante a resolução.', 409);
            }
            $this->addEvent($requestId, $adminId, 'admin', 'refund.simulated', 'return_received', 'refunded', $note);
            $audit = $this->pdo->prepare(
                "INSERT INTO admin_audit_log
                    (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                 VALUES (:adminId, 'order.withdrawal_refund_simulated', 'order', :orderId, :previous, :new)"
            );
            $audit->execute([
                ':adminId' => $adminId,
                ':orderId' => $current['order_id'],
                ':previous' => $current['orderStatus'] . ':paid:return_received',
                ':new' => $current['orderStatus'] . ':refunded:manual_test_only',
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $this->sendStatusNotification($requestId, 'refunded', 'Pagamento local simulado; nenhum dinheiro foi movimentado.');
    }

    public function recordProviderRefundFailure(
        int $requestId,
        int $orderId,
        int $adminId,
        string $paymentIntentId,
        int $attempt,
        ?string $refundId,
        string $providerCode,
        string $providerMessage,
        ?int $httpStatus
    ): void {
        $providerMessage = $this->boundedText($providerMessage, 500);
        $providerCode = substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $providerCode) ?: 'provider_error', 0, 100);
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare(
                'SELECT r.status AS requestStatus, r.order_id, o.status AS orderStatus,
                        o.payment_status AS paymentStatus, o.payment_intent_id AS paymentIntentId,
                        o.payment_refund_attempt AS refundAttempt
                 FROM withdrawal_requests r INNER JOIN orders o ON o.id = r.order_id
                 WHERE r.id = :requestId AND o.id = :orderId LIMIT 1 FOR UPDATE'
            );
            $select->execute([':requestId' => $requestId, ':orderId' => $orderId]);
            $current = $select->fetch();
            if (!$current) {
                throw new HttpException('Pedido ou solicitação de retirada não encontrados.', 404);
            }
            if ($current['requestStatus'] === 'refund_failed' && $current['paymentStatus'] === 'paid') {
                $this->pdo->commit();
                return;
            }
            if ($current['requestStatus'] !== 'refund_pending' || $current['paymentStatus'] !== 'refund_pending'
                || !hash_equals((string) $current['paymentIntentId'], $paymentIntentId)
                || (int) $current['refundAttempt'] !== $attempt) {
                throw new HttpException('O pedido mudou durante a confirmação da recusa do provedor.', 409, [], 'withdrawal_state_conflict');
            }

            $updateOrder = $this->pdo->prepare(
                "UPDATE orders SET payment_status = 'paid', payment_refund_outcome = 'definitive_failure',
                        payment_refund_id = COALESCE(:refundId, payment_refund_id),
                        payment_refund_error_code = :errorCode, payment_refund_error_message = :errorMessage,
                        payment_refund_error_http_status = :httpStatus
                 WHERE id = :id AND payment_status = 'refund_pending' AND payment_intent_id = :paymentIntent
                       AND payment_refund_attempt = :attempt"
            );
            $updateOrder->execute([
                ':refundId' => $refundId,
                ':errorCode' => $providerCode,
                ':errorMessage' => $providerMessage,
                ':httpStatus' => $httpStatus,
                ':id' => $orderId,
                ':paymentIntent' => $paymentIntentId,
                ':attempt' => $attempt,
            ]);
            if ($updateOrder->rowCount() !== 1) {
                throw new HttpException('O estado do pagamento mudou durante a atualização.', 409, [], 'withdrawal_state_conflict');
            }
            $updateRequest = $this->pdo->prepare(
                "UPDATE withdrawal_requests SET status = 'refund_failed', admin_note = :note, handled_by_admin_id = :adminId
                 WHERE id = :id AND status = 'refund_pending'"
            );
            $updateRequest->execute([':note' => $providerMessage, ':adminId' => $adminId, ':id' => $requestId]);
            if ($updateRequest->rowCount() !== 1) {
                throw new HttpException('A solicitação mudou durante a atualização.', 409, [], 'withdrawal_state_conflict');
            }
            $this->addEvent($requestId, $adminId, 'admin', 'refund.definitive_failure', 'refund_pending', 'refund_failed', $providerMessage);
            $audit = $this->pdo->prepare(
                "INSERT INTO admin_audit_log
                    (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                 VALUES (:adminId, 'order.refund_failed', 'order', :orderId, :previous, :new)"
            );
            $audit->execute([
                ':adminId' => $adminId,
                ':orderId' => $orderId,
                ':previous' => $current['orderStatus'] . ':refund_pending:attempt=' . $attempt,
                ':new' => $current['orderStatus'] . ':paid:definitive_failure:' . $providerCode,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $this->sendStatusNotification($requestId, 'refund_failed', null);
    }

    public function markRefundFailedForOrder(int $orderId, int $adminId, ?string $note = null): void
    {
        $query = $this->pdo->prepare("SELECT id FROM withdrawal_requests WHERE order_id = :orderId AND status = 'refund_pending' ORDER BY id DESC LIMIT 1");
        $query->execute([':orderId' => $orderId]);
        $requestId = $query->fetchColumn();
        if ($requestId) {
            $this->setRefundState((int) $requestId, $adminId, 'refund_failed', $note);
        }
    }

    private function requestForUser(int $requestId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, request_number AS requestNumber, request_type AS requestType, status,
                    DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%sZ') AS createdAt
             FROM withdrawal_requests WHERE id = :id AND user_id = :userId LIMIT 1"
        );
        $statement->execute([':id' => $requestId, ':userId' => $userId]);
        $request = $statement->fetch();
        if ($request) {
            $request['id'] = (int) $request['id'];
        }
        return $request ?: null;
    }

    private function validatedDeliveryDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new HttpException('Informe uma data de recebimento válida.', 422, ['deliveryReceivedDate' => 'Use o formato AAAA-MM-DD.']);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $date->format('Y-m-d') !== $value || $date > new DateTimeImmutable('today', new DateTimeZone('UTC'))) {
            throw new HttpException('Informe uma data de recebimento válida, que não esteja no futuro.', 422, ['deliveryReceivedDate' => 'Data inválida.']);
        }
        return $value;
    }

    private function addEvent(int $requestId, ?int $actorId, string $actorRole, string $name, ?string $previous, ?string $next, ?string $note): void
    {
        $event = $this->pdo->prepare(
            'INSERT INTO withdrawal_request_events
                (request_id, actor_user_id, actor_role, event_name, previous_status, new_status, note)
             VALUES (:requestId, :actorId, :actorRole, :name, :previous, :next, :note)'
        );
        $event->execute([
            ':requestId' => $requestId,
            ':actorId' => $actorId,
            ':actorRole' => $actorRole,
            ':name' => $name,
            ':previous' => $previous,
            ':next' => $next,
            ':note' => $note,
        ]);
    }

    private function sendAcknowledgment(array $order, string $requestNumber, string $type, ?string $message, string $language, int $requestId): bool
    {
        $french = $language === 'fr';
        $kind = $type === 'withdrawal'
            ? ($french ? 'demande de rétractation/retour' : 'withdrawal/return notice')
            : ($french ? 'demande d’assistance' : 'support request');
        $text = ($french
                ? "Nous avons reçu votre {$kind} concernant la commande {$order['order_number']}.\n"
                : "We received your {$kind} for order {$order['order_number']}.\n")
            . ($french ? "Référence : {$requestNumber}\nEnregistrée le : " : "Reference: {$requestNumber}\nRecorded at: ")
            . gmdate('Y-m-d H:i:s') . " UTC\n\n"
            . ($type === 'withdrawal'
                ? ($french
                    ? 'Votre notification est enregistrée avec sa date et son heure. Aucune justification n’est nécessaire pour exercer un droit légal de rétractation. L’équipe examinera les éventuelles exceptions applicables au produit concerné et vous communiquera la procédure de retour.'
                    : 'Your notice is recorded with its date and time. No reason is required to exercise a statutory withdrawal right. Our team will review any applicable exception for the specific product and send the return process.')
                : ($french
                    ? 'Votre demande est enregistrée. Notre équipe examinera les détails et vous répondra avec les prochaines étapes.'
                    : 'Your request is recorded. Our team will review the details and reply with next steps.'))
            . ($message ? ($french ? "\n\nInformations communiquées : {$message}" : "\n\nDetails you provided: {$message}") : '')
            . ($french
                ? "\n\nConservez ce message comme preuve de votre notification. Consultez l’état dans votre compte."
                : "\n\nKeep this message as proof of your notice. You can follow its status in your account.");
        $acknowledged = false;
        try {
            $subject = $french ? "Accusé de réception {$requestNumber}" : "Request received {$requestNumber}";
            $this->mailer->send((string) $order['email'], $subject, $text);
            $update = $this->pdo->prepare('UPDATE withdrawal_requests SET acknowledgment_sent_at = CURRENT_TIMESTAMP WHERE id = :id');
            $update->execute([':id' => $requestId]);
            $acknowledged = true;
        } catch (Throwable $exception) {
            error_log('Consumer withdrawal acknowledgment email failed for ' . $requestNumber . ': ' . $exception->getMessage());
        }
        $this->notifyAdmins($requestNumber, (string) $order['order_number'], (string) $order['customer_name'], (string) $order['email'], $type);
        return $acknowledged;
    }

    private function notifyAdmins(string $requestNumber, string $orderNumber, string $name, string $email, string $type): void
    {
        try {
            $recipients = $this->pdo->query("SELECT email FROM users WHERE role = 'admin' AND status = 'active' AND email_verified_at IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($recipients as $recipient) {
                $this->mailer->send((string) $recipient, 'New customer withdrawal or return request',
                    "A customer request was recorded.\n\nReference: {$requestNumber}\nOrder: {$orderNumber}\nCustomer: {$name} <{$email}>\nType: {$type}\n\nReview it in the administrative withdrawals and returns queue.");
            }
        } catch (Throwable $exception) {
            error_log('Consumer withdrawal admin notification failed for ' . $requestNumber . ': ' . $exception->getMessage());
        }
    }

    private function sendStatusNotification(int $requestId, string $status, ?string $note): void
    {
        try {
            $query = $this->pdo->prepare('SELECT r.request_number, r.request_type, r.language, o.order_number, o.email FROM withdrawal_requests r INNER JOIN orders o ON o.id = r.order_id WHERE r.id = :id');
            $query->execute([':id' => $requestId]);
            $request = $query->fetch();
            if (!$request) {
                return;
            }
            $labels = [
                'en' => [
                    'reviewing' => 'Your request is under review.',
                    'instructions_sent' => 'Return instructions are included below.',
                    'return_received' => 'Receipt of the return or proof of dispatch has been recorded.',
                    'not_eligible' => 'The team recorded an eligibility decision. See the details below and contact us if you need clarification.',
                    'refund_pending' => 'A refund was requested from the payment provider and is awaiting confirmation.',
                    'refund_failed' => 'The refund attempt was not accepted. Our team is reviewing the next step.',
                    'refunded' => 'The payment provider confirmed the refund to the original payment method.',
                ],
                'fr' => [
                    'reviewing' => 'Votre demande est en cours d’examen.',
                    'instructions_sent' => 'Les instructions de retour figurent ci-dessous.',
                    'return_received' => 'La réception du retour ou la preuve d’expédition a été enregistrée.',
                    'not_eligible' => 'L’équipe a enregistré une décision sur l’éligibilité. Consultez les détails et contactez-nous si vous souhaitez une précision.',
                    'refund_pending' => 'Un remboursement a été demandé au prestataire de paiement et attend sa confirmation.',
                    'refund_failed' => 'La tentative de remboursement n’a pas été acceptée. Notre équipe examine la suite à donner.',
                    'refunded' => 'Le prestataire de paiement a confirmé le remboursement sur le moyen de paiement initial.',
                ],
            ];
            $french = $request['language'] === 'fr';
            $lang = $french ? 'fr' : 'en';
            $subject = $french ? 'Mise à jour de la demande ' : 'Update to request ';
            $heading = $french ? 'Mise à jour de la commande' : 'Order update';
            $reference = $french ? 'Référence' : 'Reference';
            $teamMessage = $french ? 'Message de notre équipe' : 'Message from our team';
            $footer = $french
                ? 'Consultez votre compte ou répondez à cet e-mail si vous avez besoin d’aide.'
                : 'Check your account or reply to this email if you need help.';
            $this->mailer->send((string) $request['email'], $subject . $request['request_number'],
                "{$heading} {$request['order_number']}\n{$reference} : {$request['request_number']}\n\n"
                    . ($labels[$lang][$status] ?? ($french ? 'L’état de la demande a été mis à jour.' : 'The request status has been updated.'))
                    . ($note ? "\n\n{$teamMessage} :\n{$note}" : '')
                    . "\n\n{$footer}");
        } catch (Throwable $exception) {
            error_log('Consumer withdrawal status email failed for request ' . $requestId . ': ' . $exception->getMessage());
        }
    }

    private function boundedText(string $value, int $limit): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', trim($value)) ?? '';
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }
}
