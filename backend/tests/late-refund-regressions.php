<?php

declare(strict_types=1);

function lateRefundDatabase(): ?PDO
{
    gc_collect_cycles();
    $pdo = externalRefundDatabase();
    if ($pdo === null) return null;
    $pdo->exec("ALTER TABLE orders ADD payment_session_id VARCHAR(128) NULL, ADD payment_refund_reconciled_at TIMESTAMP NULL");
    $pdo->exec("UPDATE orders SET status = 'cancelled', payment_status = 'pending',
        payment_intent_id = NULL, payment_session_id = 'cs_test_late1'");
    $pdo->exec("CREATE TEMPORARY TABLE payment_refund_dispatches (
        order_id BIGINT, attempt INT, request_hash CHAR(64), request_started_at TIMESTAMP NULL,
        state VARCHAR(32) DEFAULT 'queued', available_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        attempts INT DEFAULT 0, last_error_code VARCHAR(100) NULL, last_error VARCHAR(500) NULL,
        PRIMARY KEY (order_id, attempt)
    ) ENGINE=InnoDB");
    return $pdo;
}

function lateRefundSession(): array
{
    return ['id' => 'cs_test_late1', 'payment_intent' => 'pi_external1', 'payment_status' => 'paid',
        'currency' => 'eur', 'amount_total' => 10000, 'livemode' => false];
}

function lateRefundPrepare(PDO $pdo): void
{
    (new LatePaymentRecoveryService($pdo))->refundCancelledOrderPayment(
        $pdo->query('SELECT * FROM orders WHERE id = 1')->fetch(), lateRefundSession(), 'cs_test_late1');
}

function lateRefundRow(string $status = 'succeeded'): array
{
    return [...externalRefundRow('re_late1', $status, 10000),
        'metadata' => ['order_number' => 'EXTERNAL-REFUND-1', 'refund_attempt' => '1']];
}

/** Controlled transport: no real provider/network, but real ledger, signatures, SQL and locks. */
function lateRefundGateway(PDO $pdo, array &$refunds, array &$calls, ?Closure $onPost = null, ?Closure $onList = null): StripeGateway
{
    return new StripeGateway(static function (string $url, $context) use ($pdo, &$refunds, &$calls, $onPost, $onList): array {
        $http = stream_context_get_options($context)['http'];
        $path = (string) parse_url($url, PHP_URL_PATH);
        assertSameValue(false, $pdo->inTransaction(), 'Provider I/O must never run inside a financial transaction.');
        parse_str($http['content'] ?? '', $body);
        preg_match('/Idempotency-Key: ([^\\r\\n]+)/', $http['header'], $key);
        $calls[] = ['method' => $http['method'], 'path' => $path, 'body' => $body, 'key' => $key[1] ?? null];
        if ($http['method'] === 'POST') {
            assertSameValue('/v1/refunds', $path, 'The fixture forbids charges and other provider mutations.');
            $ledger = $pdo->query('SELECT * FROM payment_refund_attempts WHERE order_id = 1 AND attempt = 1')->fetch();
            $job = $pdo->query('SELECT * FROM payment_refund_dispatches WHERE order_id = 1 AND attempt = 1')->fetch();
            assertSameValue('unknown', $ledger['status']);
            assertSameValue('retry', $job['state']);
            assertSameValue(true, $job['request_started_at'] !== null, 'The replay clock must be durable before POST.');
            if ($onPost !== null) return $onPost($body, $key[1] ?? null);
            $refunds['re_late1'] = lateRefundRow();
            return ['body' => json_encode($refunds['re_late1'], JSON_THROW_ON_ERROR), 'statusLine' => 'HTTP/1.1 200 OK'];
        }
        assertSameValue('GET', $http['method']);
        if ($path === '/v1/refunds') {
            $page = $onList ? $onList() : ['object' => 'list', 'data' => array_values($refunds), 'has_more' => false];
            return ['body' => json_encode($page, JSON_THROW_ON_ERROR), 'statusLine' => 'HTTP/1.1 200 OK'];
        }
        assertSameValue('/v1/refunds/re_late1', $path);
        return ['body' => json_encode($refunds['re_late1'], JSON_THROW_ON_ERROR), 'statusLine' => 'HTTP/1.1 200 OK'];
    });
}

function lateRefundWorker(PDO $pdo, StripeGateway $gateway, int &$now, ?Closure $apply = null): LateRefundDispatchService
{
    $harness = new ExternalRefundIntegrationHarness($pdo, $gateway);
    return new LateRefundDispatchService($pdo, $gateway, new PaymentOrderLock($pdo),
        $apply ?? $harness->apply(...), $harness->externalRefunds->scanOrder(...), static function () use (&$now): int { return $now; });
}

function lateCheckoutWebhook(ExternalRefundIntegrationHarness $harness, string $eventId): array
{
    $payload = json_encode(['id' => $eventId, 'type' => 'checkout.session.async_payment_succeeded',
        'livemode' => false, 'data' => ['object' => lateRefundSession()]], JSON_THROW_ON_ERROR);
    $now = time();
    return $harness->handleWebhook($payload, 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $payload, 'whsec_external_fixture'));
}

function lateRefundPosts(array $calls): array
{
    return array_values(array_filter($calls, static fn (array $call): bool => $call['method'] === 'POST'));
}

return [
    'late-payment compensation queues ledger job and webhook marker atomically without provider I/O' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = lateRefundDatabase(); if ($pdo === null) return;
            $refunds = []; $calls = [];
            $harness = new ExternalRefundIntegrationHarness($pdo, lateRefundGateway($pdo, $refunds, $calls));
            $pdo->exec('ALTER TABLE payment_refund_dispatches ADD force_failure INT NOT NULL');
            try {
                lateCheckoutWebhook($harness, 'evt_late1');
                throw new RuntimeException('An incomplete durable job must roll back the webhook.');
            } catch (PDOException) {
                assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_webhook_events')->fetchColumn());
                assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_refund_attempts')->fetchColumn());
                assertSameValue('pending', $harness->order()['payment_status']);
            }
            $pdo->exec('ALTER TABLE payment_refund_dispatches DROP COLUMN force_failure');
            lateCheckoutWebhook($harness, 'evt_late1');
            assertSameValue(true, lateCheckoutWebhook($harness, 'evt_late1')['duplicate']);
            lateCheckoutWebhook($harness, 'evt_late2');
            assertSameValue([], $calls);
            assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM payment_refund_attempts WHERE attempt_kind = 'full' AND status = 'pending'")->fetchColumn());
            assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_refund_dispatches')->fetchColumn());
            assertSameValue('refund_pending', $harness->order()['payment_status']);
            assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
            assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
        });
    },
    'status-recovery late compensation owns its transaction and sends an exact committed amount only once' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = lateRefundDatabase(); if ($pdo === null) return;
            lateRefundPrepare($pdo);
            assertSameValue(false, $pdo->inTransaction());
            $refunds = []; $calls = []; $now = time();
            $worker = lateRefundWorker($pdo, lateRefundGateway($pdo, $refunds, $calls), $now);
            assertSameValue(1, $worker->runBatch());
            lateRefundPrepare($pdo);
            $now += 400;
            $worker->runBatch();
            $posts = lateRefundPosts($calls);
            assertSameValue(1, count($posts));
            assertSameValue('10000', $posts[0]['body']['amount']);
            assertSameValue('refund-EXTERNAL-REFUND-1-1', $posts[0]['key']);
            assertSameValue('refunded', $pdo->query('SELECT payment_status FROM orders')->fetchColumn());
            assertSameValue(10000, (int) $pdo->query('SELECT payment_refunded_cents FROM orders')->fetchColumn());
            assertSameValue('submitted', $pdo->query('SELECT state FROM payment_refund_dispatches')->fetchColumn());
            assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
            assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
        });
    },
    'late refund dispatcher refuses an open transaction before even reading the provider' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = lateRefundDatabase(); if ($pdo === null) return;
            lateRefundPrepare($pdo);
            $refunds = []; $calls = []; $now = time();
            $worker = lateRefundWorker($pdo, lateRefundGateway($pdo, $refunds, $calls), $now);
            $pdo->beginTransaction();
            try {
                $worker->runBatch();
                throw new RuntimeException('Uncommitted financial state cannot authorize a provider request.');
            } catch (LogicException) { assertSameValue([], $calls); }
            finally { $pdo->rollBack(); }
        });
    },
    'ambiguous late-refund responses recover by GET or replay exactly the same bounded request' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach ([true, false] as $created) {
                $pdo = lateRefundDatabase(); if ($pdo === null) return;
                lateRefundPrepare($pdo);
                $refunds = []; $calls = []; $now = time(); $posts = 0;
                $gateway = lateRefundGateway($pdo, $refunds, $calls,
                    static function () use (&$refunds, &$posts, $created): array {
                        $posts++;
                        if ($created || $posts > 1) $refunds['re_late1'] = lateRefundRow();
                        if ($posts === 1) throw StripeApiException::fromTransportFailure();
                        return ['body' => json_encode(lateRefundRow(), JSON_THROW_ON_ERROR), 'statusLine' => 'HTTP/1.1 200 OK'];
                    });
                $worker = lateRefundWorker($pdo, $gateway, $now);
                $worker->runBatch();
                $start = $pdo->query('SELECT request_started_at FROM payment_refund_dispatches')->fetchColumn();
                assertSameValue('unknown', $pdo->query('SELECT payment_refund_outcome FROM orders')->fetchColumn());
                $now += 301; $worker->runBatch();
                assertSameValue($created ? 1 : 2, count(lateRefundPosts($calls)));
                if (!$created) assertSameValue(lateRefundPosts($calls)[0], lateRefundPosts($calls)[1]);
                assertSameValue($start, $pdo->query('SELECT request_started_at FROM payment_refund_dispatches')->fetchColumn());
                assertSameValue('refunded', $pdo->query('SELECT payment_status FROM orders')->fetchColumn());
                assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
            }
        });
    },
    'late refund replay window expires permanently but an already-created refund remains recoverable by GET' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach ([true, false] as $created) {
                $pdo = lateRefundDatabase(); if ($pdo === null) return;
                lateRefundPrepare($pdo);
                $refunds = []; $calls = []; $now = time();
                $gateway = lateRefundGateway($pdo, $refunds, $calls, static function (): array { throw StripeApiException::fromTransportFailure(); });
                $worker = lateRefundWorker($pdo, $gateway, $now);
                $worker->runBatch(); $now += 23 * 3600;
                if ($created) $refunds['re_late1'] = lateRefundRow();
                $worker->runBatch(); $now += 48 * 3600; $worker->runBatch(); lateRefundPrepare($pdo);
                assertSameValue(1, count(lateRefundPosts($calls)));
                assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_refund_attempts')->fetchColumn());
                assertSameValue($created ? 'submitted' : 'manual_required', $pdo->query('SELECT state FROM payment_refund_dispatches')->fetchColumn());
                assertSameValue($created ? 'refunded' : 'refund_pending', $pdo->query('SELECT payment_status FROM orders')->fetchColumn());
                if (!$created) assertSameValue('late_refund_replay_window_expired', $pdo->query('SELECT payment_refund_error_code FROM orders')->fetchColumn());
            }
        });
    },
    'definitive late-refund rejection is audited and repeated checkout events cannot authorize a new attempt' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = lateRefundDatabase(); if ($pdo === null) return;
            lateRefundPrepare($pdo);
            $refunds = []; $calls = []; $now = time();
            $gateway = lateRefundGateway($pdo, $refunds, $calls, static fn (): array =>
                ['body' => '{"error":{"code":"balance_insufficient","message":"secret=fixture-token insufficient balance"}}', 'statusLine' => 'HTTP/1.1 400 Bad Request']);
            $worker = lateRefundWorker($pdo, $gateway, $now);
            $worker->runBatch();
            $harness = new ExternalRefundIntegrationHarness($pdo, $gateway);
            lateCheckoutWebhook($harness, 'evt_after_failure');
            $now += 100000; $worker->runBatch();
            assertSameValue(1, count(lateRefundPosts($calls)));
            assertSameValue('failed', $pdo->query('SELECT status FROM payment_refund_attempts')->fetchColumn());
            assertSameValue('failed', $pdo->query('SELECT state FROM payment_refund_dispatches')->fetchColumn());
            assertSameValue('definitive_failure', $harness->order()['payment_refund_outcome']);
            assertSameValue('paid', $harness->order()['payment_status']);
            assertSameValue('balance_insufficient', $harness->order()['payment_refund_error_code']);
            assertSameValue('secret=[redacted] insufficient balance', $harness->order()['payment_refund_error_message']);
            assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_refund_attempts')->fetchColumn());
        });
    },
    'late-refund preflight failure and externally-created refunds never authorize a new POST' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['invalid_page', 'external', 'identity', 'mode'] as $failure) {
                $pdo = lateRefundDatabase(); if ($pdo === null) return;
                lateRefundPrepare($pdo);
                $refunds = $failure === 'external' ? ['re_external1' => externalRefundRow()] : [];
                $calls = []; $now = time();
                if ($failure === 'identity') $pdo->exec('UPDATE payment_refund_attempts SET amount_cents = 9000');
                if ($failure === 'mode') $pdo->exec('UPDATE orders SET test_payment = 0');
                $gateway = lateRefundGateway($pdo, $refunds, $calls, null,
                    $failure === 'invalid_page' ? static fn (): array => ['object' => 'list', 'data' => [], 'has_more' => true] : null);
                lateRefundWorker($pdo, $gateway, $now)->runBatch();
                assertSameValue([], lateRefundPosts($calls));
                assertSameValue('refund_pending', $pdo->query('SELECT payment_status FROM orders')->fetchColumn());
                assertSameValue(0, (int) $pdo->query('SELECT payment_refunded_cents FROM orders')->fetchColumn());
                assertSameValue($failure === 'invalid_page' ? 'retry' : 'manual_required', $pdo->query('SELECT state FROM payment_refund_dispatches')->fetchColumn());
                assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
            }
        });
    },
    'provider identity survives a local refund confirmation failure and the regular worker repairs the outbox atomically' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = lateRefundDatabase(); if ($pdo === null) return;
            lateRefundPrepare($pdo);
            $refunds = []; $calls = []; $now = time();
            $gateway = lateRefundGateway($pdo, $refunds, $calls);
            $harness = new ExternalRefundIntegrationHarness($pdo, $gateway);
            $interruptBeforeCommit = static function (array $refund) use ($pdo, $harness): void {
                $pdo->beginTransaction();
                try {
                    $harness->apply($refund);
                    // Inject interruption AFTER real financial and outbox writes, before commit.
                    throw new RuntimeException('Injected confirmation interruption before commit.');
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $exception;
                }
            };
            $worker = lateRefundWorker($pdo, $gateway, $now, $interruptBeforeCommit);
            $worker->runBatch();
            assertSameValue('refund_pending', $harness->order()['payment_status']);
            assertSameValue(0, (int) $harness->order()['payment_refunded_cents']);
            assertSameValue('re_late1', $pdo->query('SELECT stripe_refund_id FROM payment_refund_attempts')->fetchColumn());
            assertSameValue('submitted', $pdo->query('SELECT state FROM payment_refund_dispatches')->fetchColumn());
            assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
            $operations = new PaymentOperationsService($pdo, $gateway, new PaymentOrderLock($pdo),
                new class implements MailTransport { public function send(string $recipient, string $subject, string $text): void {} },
                static function (): void {}, static function (): void {}, $harness->apply(...));
            assertSameValue(1, (new ReflectionMethod($operations, 'reconcilePendingRefunds'))->invoke($operations, 25));
            $now += 301; $worker->runBatch();
            assertSameValue(1, count(lateRefundPosts($calls)));
            assertSameValue('refunded', $harness->order()['payment_status']);
            assertSameValue(10000, (int) $harness->order()['payment_refunded_cents']);
            assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
            assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
        });
    },
    'a signed refund webhook arriving before the late-refund HTTP response remains idempotent' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = lateRefundDatabase(); if ($pdo === null) return;
            lateRefundPrepare($pdo);
            $refunds = []; $calls = []; $now = time(); $harness = null;
            $gateway = lateRefundGateway($pdo, $refunds, $calls, static function () use (&$harness): array {
                externalRefundWebhook($harness, lateRefundRow(), 'evt_late_refund');
                return ['body' => json_encode(lateRefundRow(), JSON_THROW_ON_ERROR), 'statusLine' => 'HTTP/1.1 200 OK'];
            });
            $harness = new ExternalRefundIntegrationHarness($pdo, $gateway);
            lateRefundWorker($pdo, $gateway, $now)->runBatch();
            assertSameValue(10000, (int) $harness->order()['payment_refunded_cents']);
            assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
            assertSameValue('submitted', $pdo->query('SELECT state FROM payment_refund_dispatches')->fetchColumn());
            assertSameValue(1, count(lateRefundPosts($calls)));
        });
    },
    'late refund workers honor the cross-connection order lock and release it after provider failures' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = lateRefundDatabase(); if ($pdo === null) return;
            lateRefundPrepare($pdo);
            $peer = disputeRecoveryMySQL(); $peerLock = new PaymentOrderLock($peer);
            $refunds = []; $calls = []; $now = time();
            $gateway = lateRefundGateway($pdo, $refunds, $calls, static function (): array { throw StripeApiException::fromTransportFailure(); });
            $worker = lateRefundWorker($pdo, $gateway, $now);
            $peerLock->acquire(1);
            try { assertSameValue(0, $worker->runBatch()); assertSameValue([], $calls); }
            finally { $peerLock->release(1); }
            $worker->runBatch();
            assertSameValue(1, (int) $pdo->query("SELECT IS_FREE_LOCK('botanical-payment-order-1')")->fetchColumn());
        });
    },
    'a later transport rejection cannot undo a signed refund success already committed by the webhook' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = lateRefundDatabase(); if ($pdo === null) return;
            lateRefundPrepare($pdo);
            $refunds = []; $calls = []; $now = time(); $harness = null;
            $gateway = lateRefundGateway($pdo, $refunds, $calls, static function () use (&$harness): array {
                externalRefundWebhook($harness, lateRefundRow(), 'evt_success_before_rejection');
                return ['body' => '{"error":{"code":"balance_insufficient","message":"Insufficient funds"}}', 'statusLine' => 'HTTP/1.1 400 Bad Request'];
            });
            $harness = new ExternalRefundIntegrationHarness($pdo, $gateway);
            lateRefundWorker($pdo, $gateway, $now)->runBatch();
            assertSameValue('refunded', $harness->order()['payment_status']);
            assertSameValue('succeeded', $harness->order()['payment_refund_outcome']);
            assertSameValue('succeeded', $pdo->query('SELECT status FROM payment_refund_attempts')->fetchColumn());
            assertSameValue('submitted', $pdo->query('SELECT state FROM payment_refund_dispatches')->fetchColumn());
            assertSameValue(10000, (int) $harness->order()['payment_refunded_cents']);
            assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
        });
    },
    'mismatched refund responses require review and cannot bind a foreign ID or repeat the request' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = lateRefundDatabase(); if ($pdo === null) return;
            lateRefundPrepare($pdo);
            $refunds = []; $calls = []; $now = time();
            $gateway = lateRefundGateway($pdo, $refunds, $calls, static fn (): array =>
                ['body' => json_encode(externalRefundRow('re_foreign', 'succeeded', 500), JSON_THROW_ON_ERROR), 'statusLine' => 'HTTP/1.1 200 OK']);
            $worker = lateRefundWorker($pdo, $gateway, $now);
            $worker->runBatch(); $now += 100000; $worker->runBatch();
            assertSameValue(1, count(lateRefundPosts($calls)));
            assertSameValue(null, $pdo->query('SELECT stripe_refund_id FROM payment_refund_attempts')->fetchColumn());
            assertSameValue('manual_required', $pdo->query('SELECT state FROM payment_refund_dispatches')->fetchColumn());
            assertSameValue('refund_pending', $pdo->query('SELECT payment_status FROM orders')->fetchColumn());
            assertSameValue(0, (int) $pdo->query('SELECT payment_refunded_cents FROM orders')->fetchColumn());
        });
    },
    'untrusted or mismatched late payment identities never reserve a refund attempt' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['payment_intent', 'currency', 'amount_total', 'livemode'] as $field) {
                $pdo = lateRefundDatabase(); if ($pdo === null) return;
                $session = lateRefundSession();
                $session[$field] = match ($field) { 'payment_intent' => '../../invalid', 'currency' => 'usd', 'amount_total' => 1, default => true };
                expectExternalRefundConflict(static fn () => (new LatePaymentRecoveryService($pdo))->refundCancelledOrderPayment(
                    $pdo->query('SELECT * FROM orders WHERE id = 1')->fetch(), $session, 'cs_test_late1'));
                assertSameValue('pending', $pdo->query('SELECT payment_status FROM orders')->fetchColumn());
                assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_refund_attempts')->fetchColumn());
                assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_refund_dispatches')->fetchColumn());
                assertSameValue(false, $pdo->inTransaction());
            }
        });
    },
    'manual or superseded late-refund attempts are never dispatched and the migration preserves existing data' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['manual', 'superseded'] as $resolution) {
                $pdo = lateRefundDatabase(); if ($pdo === null) return;
                lateRefundPrepare($pdo);
                if ($resolution === 'manual') $pdo->exec("UPDATE payment_refund_attempts SET status = 'manual', provider_error_code = 'manual_refunded'");
                else $pdo->exec('UPDATE orders SET payment_refund_attempt = 2');
                $refunds = []; $calls = []; $now = time();
                lateRefundWorker($pdo, lateRefundGateway($pdo, $refunds, $calls), $now)->runBatch();
                assertSameValue([], $calls);
                assertSameValue('closed', $pdo->query('SELECT state FROM payment_refund_dispatches')->fetchColumn());
            }
            $pdo = disputeRecoveryMySQL(); if ($pdo === null) return;
            $before = $pdo->query('SELECT COUNT(*) FROM payment_refund_dispatches')->fetchColumn();
            LateRefundDispatchSchema::migrate($pdo);
            LateRefundDispatchSchema::migrate($pdo);
            assertSameValue($before, $pdo->query('SELECT COUNT(*) FROM payment_refund_dispatches')->fetchColumn());
            assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = '2026-10-07-durable-late-refund-dispatch-v23'")->fetchColumn());
        });
    },
    'production replay deadlines use the shared database clock and fail closed on future or expired markers' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['expired', 'future'] as $clockState) {
                $pdo = lateRefundDatabase(); if ($pdo === null) return;
                lateRefundPrepare($pdo);
                $pdo->exec($clockState === 'expired'
                    ? 'UPDATE payment_refund_dispatches SET request_started_at = CURRENT_TIMESTAMP - INTERVAL 23 HOUR'
                    : 'UPDATE payment_refund_dispatches SET request_started_at = CURRENT_TIMESTAMP + INTERVAL 1 HOUR');
                $refunds = []; $calls = [];
                $gateway = lateRefundGateway($pdo, $refunds, $calls);
                $harness = new ExternalRefundIntegrationHarness($pdo, $gateway);
                $worker = new LateRefundDispatchService($pdo, $gateway, new PaymentOrderLock($pdo),
                    $harness->apply(...), $harness->externalRefunds->scanOrder(...));
                $worker->runBatch();
                assertSameValue([], lateRefundPosts($calls));
                assertSameValue('manual_required', $pdo->query('SELECT state FROM payment_refund_dispatches')->fetchColumn());
                assertSameValue($clockState === 'expired' ? 'late_refund_replay_window_expired' : 'late_refund_clock_invalid',
                    $pdo->query('SELECT payment_refund_error_code FROM orders')->fetchColumn());
                assertSameValue(0, (int) $harness->order()['payment_refunded_cents']);
            }
        });
    },
];
