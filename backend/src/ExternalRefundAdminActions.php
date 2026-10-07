<?php

declare(strict_types=1);

/** Audited local accounting decisions; every review re-reads Stripe and never sends a refund. */
trait ExternalRefundAdminActions
{
    public function issues(array $query = []): array
    {
        Validation::allowedFields($query, ['page', 'limit']);
        $page = Validation::queryInteger($query, 'page', 1, 1, 1_000_000);
        $limit = Validation::queryInteger($query, 'limit', 50, 1, 100);
        $total = (int) $this->pdo->query("SELECT COUNT(*) FROM payment_external_refunds WHERE resolution = 'unmatched'")->fetchColumn();
        $select = $this->pdo->prepare("SELECT r.stripe_refund_id AS refundId, r.order_id AS orderId,
            o.order_number AS orderNumber, r.payment_intent_id AS paymentIntentId, r.amount_cents AS amountCents,
            UPPER(r.currency) AS currency, r.provider_status AS providerStatus, r.resolution,
            DATE_FORMAT(r.created_at, '%Y-%m-%dT%H:%i:%sZ') AS createdAt,
            DATE_FORMAT(r.last_synced_at, '%Y-%m-%dT%H:%i:%sZ') AS lastSyncedAt
            FROM payment_external_refunds r LEFT JOIN orders o ON o.id = r.order_id
            WHERE r.resolution = 'unmatched' ORDER BY r.created_at, r.stripe_refund_id LIMIT :limit OFFSET :offset");
        $select->bindValue(':limit', $limit, PDO::PARAM_INT);
        $select->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $select->execute();
        $data = $select->fetchAll();
        foreach ($data as &$row) {
            $row['orderId'] = $row['orderId'] === null ? null : (int) $row['orderId'];
            $row['amountCents'] = (int) $row['amountCents'];
            $row['attempts'] = [];
            if ($row['orderId'] !== null) {
                $attempts = $this->pdo->prepare('SELECT attempt, attempt_kind AS kind, amount_cents AS amountCents,
                    UPPER(currency) AS currency, status, stripe_refund_id AS refundId,
                    (status = \'succeeded\' OR (status = \'manual\' AND provider_error_code = \'manual_refunded\')) AS alreadyCounted
                    FROM payment_refund_attempts WHERE order_id = :id ORDER BY attempt DESC');
                $attempts->execute([':id' => $row['orderId']]);
                $row['attempts'] = array_map(static function (array $attempt): array {
                    $attempt['attempt'] = (int) $attempt['attempt'];
                    $attempt['amountCents'] = (int) $attempt['amountCents'];
                    $attempt['alreadyCounted'] = (bool) $attempt['alreadyCounted'];
                    return $attempt;
                }, $attempts->fetchAll());
            }
        }
        unset($row);
        return ['data' => $data, 'page' => $page, 'limit' => $limit, 'total' => $total, 'hasMore' => $page * $limit < $total];
    }

    public function resolve(array $input, array $admin): array
    {
        Validation::allowedFields($input, ['refundId', 'action', 'attempt', 'reason']);
        $refundId = Validation::requiredString($input, 'refundId', 4, 128);
        if (!preg_match('/^re_[A-Za-z0-9]+$/D', $refundId)) throw new HttpException('Referência Stripe inválida.', 422);
        $action = Validation::oneOf($input, 'action', ['link', 'record', 'dismiss', 'reverse']);
        $reason = Validation::requiredString($input, 'reason', 10, 450);
        $attempt = in_array($action, ['link', 'reverse'], true) ? Validation::integer($input, 'attempt', 1, 4_294_967_295) : null;
        $adminId = Validation::integer($admin, 'id', 1, PHP_INT_MAX);
        $this->limiter->assertAllowed('admin-payment', (string) $adminId, 100, 3600);
        $registered = $this->pdo->prepare('SELECT order_id FROM payment_external_refunds WHERE stripe_refund_id = :id');
        $registered->execute([':id' => $refundId]);
        $registeredRow = $registered->fetch();
        if (!$registeredRow) throw new HttpException('Reembolso não registrado na fila.', 404);
        $orderId = $registeredRow['order_id'] === null ? null : (int) $registeredRow['order_id'];
        // Hold the same advisory lock as worker scans and payment mutations, so
        // an older provider response cannot overwrite a newer reviewed decision.
        if ($orderId !== null) $this->orderLock->acquire($orderId);
        try {
            return $this->resolveProviderReview($refundId, $action, $attempt, $reason, $adminId);
        } finally {
            if ($orderId !== null) $this->orderLock->release($orderId);
        }
    }

    private function resolveProviderReview(string $refundId, string $action, ?int $attempt, string $reason, int $adminId): array
    {
        // A failed GET is not a manual override: leave the durable queue untouched.
        $refund = $this->stripe->retrieveRefund($refundId);
        if (($refund['id'] ?? null) !== $refundId) throw new HttpException('A Stripe retornou outra referência.', 409);
        $identity = $this->identity($refund);
        // Keep fresh provider evidence even when the requested accounting decision is invalid.
        $this->capture($refund, true);
        $this->pdo->beginTransaction();
        try {
            $order = $this->findOrder($identity['paymentIntent']);
            $check = $this->pdo->prepare('SELECT * FROM payment_external_refunds WHERE stripe_refund_id = :id FOR UPDATE');
            $check->execute([':id' => $refundId]);
            $observation = $check->fetch();
            if (!$observation) throw new HttpException('O reembolso não está registrado na fila de conciliação.', 404);
            $this->capture($refund, true);
            $check->execute([':id' => $refundId]);
            $observation = $check->fetch();
            if ($observation['resolution'] !== 'unmatched') {
                if ($action === 'reverse' && $identity['status'] === 'succeeded' && $observation['resolution'] === 'linked') {
                    $this->pdo->commit();
                    return ['message' => 'A Stripe continua confirmando este reembolso; nenhum valor foi retirado do saldo.'];
                }
                if ($observation['resolution'] !== ['link' => 'linked', 'record' => 'external', 'dismiss' => 'irrelevant', 'reverse' => 'reversed'][$action]
                    || (in_array($action, ['link', 'reverse'], true) && (int) $observation['local_attempt'] !== $attempt)) {
                    throw new HttpException('Este reembolso já foi conciliado de outra forma.', 409);
                }
                $this->pdo->commit();
                return ['message' => 'Este reembolso já estava conciliado. Nenhum valor foi contado novamente.'];
            }
            if ($action === 'reverse') {
                if ($order === null) throw new HttpException('Pedido não encontrado para correção do saldo.', 409);
                $noticeReference = 'review-' . hash('sha256', $refundId) . '-' . ((int) $observation['review_revision'] + 1);
                $this->reverseSettledAttempt($order, $refund, (int) $attempt, $noticeReference);
                $resolution = 'reversed';
            } elseif ($action === 'dismiss') {
                if ($order !== null && !in_array($identity['status'], ['failed', 'canceled'], true)) {
                    throw new HttpException('Um reembolso ativo deste pedido não pode ser descartado.', 409, [], 'active_external_refund');
                }
                if ($order !== null) {
                    $counted = $this->pdo->prepare("SELECT 1 FROM payment_refund_attempts WHERE stripe_refund_id = :id
                        AND (status = 'succeeded' OR (status = 'manual' AND provider_error_code = 'manual_refunded')) LIMIT 1");
                    $counted->execute([':id' => $refundId]);
                    if ($counted->fetchColumn()) throw new HttpException('Este valor está contabilizado; corrija o saldo antes de encerrar.', 409);
                }
                $resolution = 'irrelevant';
            } else {
                if ($order === null) throw new HttpException('Não há pagamento do aplicativo correspondente a este reembolso.', 409);
                if ($action === 'record') $attempt = $this->createExternalAttempt($order, $refund, $adminId, $reason);
                $attemptQuery = $this->pdo->prepare('SELECT * FROM payment_refund_attempts WHERE order_id = :id AND attempt = :attempt FOR UPDATE');
                $attemptQuery->execute([':id' => $order['id'], ':attempt' => $attempt]);
                $row = $attemptQuery->fetch();
                if (!$row || (int) $row['amount_cents'] !== $identity['amount']
                    || strtolower((string) $row['currency']) !== $identity['currency']
                    || ($row['stripe_refund_id'] !== null && $row['stripe_refund_id'] !== $refundId)
                    || (($row['status'] === 'succeeded' || ($row['status'] === 'manual' && $row['provider_error_code'] === 'manual_refunded'))
                        && in_array($identity['status'], ['failed', 'canceled', 'requires_action'], true))) {
                    throw new HttpException('O reembolso não corresponde ao valor/identidade da tentativa escolhida.', 409, [], 'external_refund_link_mismatch');
                }
                $this->pdo->prepare('UPDATE payment_refund_attempts SET stripe_refund_id = :refundId WHERE order_id = :id AND attempt = :attempt')
                    ->execute([':refundId' => $refundId, ':id' => $order['id'], ':attempt' => $attempt]);
                // The immutable, explicitly audited binding authorizes the normal ledger
                // reconciler even when Stripe metadata was removed or never existed.
                ($this->applyRefund)($refund);
                $resolution = $action === 'record' ? 'external' : 'linked';
            }
            $this->pdo->prepare('UPDATE payment_external_refunds SET resolution = :resolution, local_attempt = :attempt,
                reviewed_by_admin_id = :admin, review_reason = :reason, review_revision = review_revision + 1 WHERE stripe_refund_id = :id')
                ->execute([':resolution' => $resolution, ':attempt' => $attempt, ':admin' => $adminId, ':reason' => $reason, ':id' => $refundId]);
            $this->audit($adminId, $order['id'] ?? null, 'refund.external_' . $resolution, $refundId . '; attempt=' . ($attempt ?? 'none') . '; ' . $reason);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
        return ['message' => 'Conciliação registrada. Nenhuma nova cobrança ou solicitação de reembolso foi enviada à Stripe.'];
    }

    private function reverseSettledAttempt(array $order, array $refund, int $attempt, string $noticeReference): void
    {
        if (!in_array($refund['status'], ['failed', 'canceled', 'requires_action'], true)) {
            throw new HttpException('A Stripe não confirmou retorno/falha desta devolução.', 409);
        }
        $find = $this->pdo->prepare('SELECT * FROM payment_refund_attempts WHERE order_id = :id AND attempt = :attempt FOR UPDATE');
        $find->execute([':id' => $order['id'], ':attempt' => $attempt]);
        $row = $find->fetch();
        if (!$row || $row['stripe_refund_id'] !== $refund['id'] || (int) $row['amount_cents'] !== $refund['amount']
            || strtolower($row['currency']) !== strtolower($refund['currency'])
            || !($row['status'] === 'succeeded' || ($row['status'] === 'manual' && $row['provider_error_code'] === 'manual_refunded'))
            || (int) $order['payment_refunded_cents'] < (int) $row['amount_cents']) {
            throw new HttpException('O valor devolvido pelo provedor não corresponde a uma tentativa contabilizada.', 409, [], 'external_refund_link_mismatch');
        }
        $remainingRefunded = (int) $order['payment_refunded_cents'] - (int) $row['amount_cents'];
        $pending = $refund['status'] === 'requires_action';
        $current = (int) $order['payment_refund_attempt'] === $attempt;
        $paymentStatus = $remainingRefunded > 0 ? 'partially_refunded' : 'paid';
        if (($current && $pending) || (!$current && $order['payment_status'] === 'refund_pending')) $paymentStatus = 'refund_pending';
        $this->pdo->prepare("UPDATE payment_refund_attempts SET status = :status,
            provider_error_code = 'provider_refund_returned', provider_error_message = :message WHERE order_id = :id AND attempt = :attempt")
            ->execute([':status' => $pending ? 'pending' : 'failed', ':message' => 'Stripe returned refund funds or requires corrected recipient details.', ':id' => $order['id'], ':attempt' => $attempt]);
        $this->pdo->prepare('UPDATE orders SET payment_refunded_cents = :amount, payment_status = :status WHERE id = :id')
            ->execute([':amount' => $remainingRefunded, ':status' => $paymentStatus, ':id' => $order['id']]);
        if ($current) {
            $this->pdo->prepare("UPDATE orders SET payment_refund_outcome = :outcome,
                payment_refund_error_code = 'provider_refund_returned', payment_refund_error_message = :message,
                payment_refund_error_http_status = NULL WHERE id = :id")
                ->execute([':outcome' => $pending ? 'pending' : 'definitive_failure', ':message' => 'Stripe refund was returned; support must verify the next refund route.', ':id' => $order['id']]);
        }
        if ($row['withdrawal_request_id'] !== null) {
            $select = $this->pdo->prepare('SELECT status FROM withdrawal_requests WHERE id = :id FOR UPDATE');
            $select->execute([':id' => $row['withdrawal_request_id']]);
            $previous = $select->fetchColumn();
            if ($previous !== false) {
                $next = $pending ? 'refund_pending' : 'refund_failed';
                $this->pdo->prepare('UPDATE withdrawal_requests SET status = :status WHERE id = :id')
                    ->execute([':status' => $next, ':id' => $row['withdrawal_request_id']]);
                $this->pdo->prepare("INSERT INTO withdrawal_request_events (request_id, actor_role, event_name, previous_status, new_status, note)
                    VALUES (:id, 'admin', 'refund.provider_returned', :previous, :next, :note)")
                    ->execute([':id' => $row['withdrawal_request_id'], ':previous' => $previous, ':next' => $next, ':note' => 'Provider refund return verified: ' . $refund['id']]);
            }
        }
        $this->pdo->prepare("INSERT IGNORE INTO payment_notification_outbox (order_id, notification_type, reference_id)
            VALUES (:id, 'refund_reversal_notice', :reference)")->execute([':id' => $order['id'], ':reference' => $noticeReference]);
    }

    private function createExternalAttempt(array $order, array $refund, int $adminId, string $reason): int
    {
        if (($refund['status'] ?? null) !== 'succeeded' || !in_array($order['payment_status'], ['paid', 'partially_refunded'], true)) {
            throw new HttpException('Somente uma devolução externa confirmada pode ser contabilizada como nova.', 409);
        }
        $ambiguous = $this->pdo->prepare("SELECT 1 FROM payment_refund_attempts WHERE order_id = :id
            AND (status IN ('pending','unknown') OR (status = 'manual' AND provider_error_code = 'manual_refunded' AND stripe_refund_id IS NULL)) LIMIT 1");
        $ambiguous->execute([':id' => $order['id']]);
        if ($ambiguous->fetchColumn()) {
            throw new HttpException('Vincule primeiro as tentativas pendentes ou resolvidas manualmente; o valor poderia ser contado duas vezes.', 409, [], 'external_refund_ambiguous');
        }
        $remaining = (int) round((float) $order['total'] * 100) - (int) $order['payment_refunded_cents'];
        if ($refund['amount'] > $remaining) throw new HttpException('A devolução externa excede o saldo local; revise o ledger.', 409, [], 'refund_amount_exceeds_remaining');
        $attempt = (int) $order['payment_refund_attempt'] + 1;
        $this->pdo->prepare("INSERT INTO payment_refund_attempts (order_id, attempt, attempt_kind, stripe_refund_id,
            amount_cents, currency, previous_payment_status, previous_order_status, status, reason, requested_by_admin_id)
            VALUES (:id, :attempt, 'partial', :refundId, :amount, :currency, :paymentStatus, :orderStatus, 'pending', :reason, :admin)")
            ->execute([':id' => $order['id'], ':attempt' => $attempt, ':refundId' => $refund['id'], ':amount' => $refund['amount'],
                ':currency' => $order['currency'], ':paymentStatus' => $order['payment_status'], ':orderStatus' => $order['status'],
                ':reason' => 'External Stripe refund: ' . $reason, ':admin' => $adminId]);
        $this->pdo->prepare("UPDATE orders SET payment_refund_attempt = :attempt, payment_refund_id = :refundId,
            payment_status = 'refund_pending', payment_refund_outcome = 'pending' WHERE id = :id")
            ->execute([':attempt' => $attempt, ':refundId' => $refund['id'], ':id' => $order['id']]);
        return $attempt;
    }
}
