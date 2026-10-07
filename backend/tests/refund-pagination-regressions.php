<?php

declare(strict_types=1);

function refundPaginationRow(int $index): array
{
    return ['id' => 're_page' . $index, 'object' => 'refund', 'created' => 100_000 - $index,
        'payment_intent' => 'pi_fixture', 'livemode' => false, 'currency' => 'eur', 'amount' => 1000,
        'status' => 'succeeded', 'metadata' => ['order_number' => 'OTHER-ORDER', 'refund_attempt' => '1']];
}

function refundPaginationPage(int $first, int $last, bool $more): array
{
    return ['object' => 'list', 'data' => array_map(refundPaginationRow(...), range($first, $last)), 'has_more' => $more];
}

function refundPaginationGateway(array $pages, array &$requests): StripeGateway
{
    return new StripeGateway(static function (string $url, $context) use ($pages, &$requests): array {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        assertSameValue('/v1/refunds', parse_url($url, PHP_URL_PATH));
        assertSameValue('GET', stream_context_get_options($context)['http']['method']);
        $requests[] = $query;
        $key = $query['starting_after'] ?? 'head';
        assertTrueValue(array_key_exists($key, $pages), 'An unexpected refund page was requested.');
        return $pages[$key] === null ? ['body' => false, 'statusLine' => '']
            : ['body' => json_encode($pages[$key], JSON_THROW_ON_ERROR), 'statusLine' => 'HTTP/1.1 200 OK'];
    });
}

function expectRefundPaginationFailure(callable $operation): void
{
    try {
        $operation();
    } catch (RuntimeException | InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('An incomplete refund lookup must not establish absence.');
}

function refundPaginationRecoveryFixture(StripeGateway $gateway): array
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->sqliteCreateFunction('GET_LOCK', static fn (): int => 1, 2);
    $pdo->sqliteCreateFunction('RELEASE_LOCK', static fn (): int => 1, 1);
    $pdo->sqliteCreateFunction('IF', static fn ($condition, $yes, $no) => $condition ? $yes : $no, 3);
    $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, order_number TEXT, payment_provider TEXT,
        payment_status TEXT, status TEXT, payment_refund_outcome TEXT, payment_refund_attempt INTEGER,
        payment_refund_reconciled_at TEXT NULL, payment_intent_id TEXT, test_payment INTEGER,
        payment_refund_id TEXT NULL, payment_refund_error_code TEXT NULL, payment_refund_error_message TEXT NULL,
        payment_refund_error_http_status INTEGER NULL, payment_refunded_cents INTEGER, created_at TEXT)');
    $pdo->exec("INSERT INTO orders VALUES (1, 'REFUND-PAGE-TEST', 'stripe', 'refund_pending', 'confirmed',
        'unknown', 1, NULL, 'pi_fixture', 1, NULL, NULL, NULL, NULL, 0, CURRENT_TIMESTAMP)");
    $pdo->exec('CREATE TABLE payment_refund_attempts (order_id INTEGER, attempt INTEGER, attempt_kind TEXT,
        stripe_refund_id TEXT NULL, status TEXT, amount_cents INTEGER)');
    $pdo->exec("INSERT INTO payment_refund_attempts VALUES (1, 1, 'partial', NULL, 'unknown', 1000)");
    $pdo->exec('CREATE TABLE admin_audit_log (admin_user_id INTEGER, action_name TEXT, target_type TEXT,
        target_id INTEGER, previous_value TEXT, new_value TEXT)');
    $operations = new PaymentOperationsService($pdo, $gateway, new PaymentOrderLock($pdo), new Mailer(),
        static fn (array $session, bool $paid) => null, static fn (int $id, string $status) => null,
        static function (array $refund) use ($pdo): void {
            $pdo->prepare("UPDATE orders SET payment_status = 'refunded', payment_refund_outcome = 'succeeded',
                payment_refund_id = :refundId, payment_refunded_cents = :amount WHERE id = 1")
                ->execute([':refundId' => $refund['id'], ':amount' => $refund['amount']]);
        });
    return [$pdo, $operations];
}

/** Exercise the real lookup/error handling; financial application is covered by the ledger suites. */
final class RefundPaginationAdminHarness
{
    use RefundAdminActions;
    use PartialRefundActions;

    public function __construct(private readonly PDO $pdo, private readonly StripeGateway $stripe) {}

    public function lookup(bool $partial): array
    {
        $order = $this->pdo->query('SELECT * FROM orders WHERE id = 1')->fetch();
        return $partial ? $this->reconcilePartialRefundAttempt($order, 42) : $this->reconcileAdminRefund($order, 42);
    }

    private function refundAttemptRow(int $orderId, int $attempt): ?array
    {
        $find = $this->pdo->prepare('SELECT * FROM payment_refund_attempts WHERE order_id = :orderId AND attempt = :attempt');
        $find->execute([':orderId' => $orderId, ':attempt' => $attempt]);
        return $find->fetch() ?: null;
    }

    private function applyAdminRefundResult(array $order, array $refund, int $adminId, ?int $withdrawalRequestId = null): array
    {
        return ['selectedRefundId' => $refund['id']];
    }

    private function applyPartialRefundResult(array $order, array $refund, int $adminId): array
    {
        return ['selectedRefundId' => $refund['id']];
    }
}

return [
    'Stripe refund recovery streams all pages with the same intent and creation boundary' => static function (): void {
        $requests = [];
        $gateway = refundPaginationGateway(['head' => refundPaginationPage(1, 100, true),
            're_page100' => refundPaginationPage(101, 200, true), 're_page200' => refundPaginationPage(201, 250, false)], $requests);
        withEnvironment(['STRIPE_SECRET_KEY' => 'sk_test_pagination_fixture'], static function () use ($gateway, &$requests): void {
            $rows = iterator_to_array($gateway->refundsForPaymentIntent('pi_fixture'));
            assertSameValue(250, count($rows));
            assertSameValue('re_page1', $rows[0]['id']);
            assertSameValue('re_page250', $rows[249]['id']);
            assertSameValue(3, count($requests));
            assertSameValue(false, isset($requests[0]['starting_after']));
            assertSameValue('re_page100', $requests[1]['starting_after']);
            assertSameValue('re_page200', $requests[2]['starting_after']);
            foreach ($requests as $query) {
                assertSameValue('pi_fixture', $query['payment_intent']);
                assertSameValue('100', $query['limit']);
                assertSameValue($requests[0]['created'], $query['created']);
                assertTrueValue((int) $query['created']['lte'] <= time());
            }
        });
    },
    'a found refund stops pagination without eagerly requesting unrelated later pages' => static function (): void {
        $requests = [];
        $gateway = refundPaginationGateway(['head' => refundPaginationPage(1, 100, true), 're_page100' => null], $requests);
        withEnvironment(['STRIPE_SECRET_KEY' => 'sk_test_pagination_fixture'], static function () use ($gateway, &$requests): void {
            $iterator = $gateway->refundsForPaymentIntent('pi_fixture');
            assertSameValue(0, count($requests), 'Pagination must be lazy.');
            foreach ($iterator as $row) { if ($row['id'] === 're_page2') break; }
            assertSameValue('re_page2', $row['id']);
            assertSameValue(1, count($requests));
        });
    },
    'an empty refund lookup is accepted only after a valid terminal provider page' => static function (): void {
        $requests = [];
        $gateway = refundPaginationGateway(['head' => ['object' => 'list', 'data' => [], 'has_more' => false]], $requests);
        withEnvironment(['STRIPE_SECRET_KEY' => 'sk_test_pagination_fixture'], static function () use ($gateway): void {
            assertSameValue([], iterator_to_array($gateway->refundsForPaymentIntent('pi_fixture')));
        });
    },
    'malformed refund pages fail before even their first candidate is yielded' => static function (): void {
        $good = refundPaginationRow(1);
        foreach ([[], ['object' => 'list', 'data' => [], 'has_more' => true],
            ['object' => 'list', 'data' => [], 'has_more' => 'false'],
            ['object' => 'list', 'data' => ['not' => $good], 'has_more' => false],
            ['object' => 'list', 'data' => array_fill(0, 101, $good), 'has_more' => false],
            ['object' => 'list', 'data' => [$good, [...$good, 'id' => 'not-a-refund']], 'has_more' => false],
            ['object' => 'list', 'data' => [$good, $good], 'has_more' => false],
            ['object' => 'list', 'data' => [[...$good, 'payment_intent' => 'pi_other']], 'has_more' => false],
            ['object' => 'list', 'data' => [[...$good, 'created' => time() + 600]], 'has_more' => false],
            ['object' => 'list', 'data' => [[...$good, 'created' => '1000']], 'has_more' => false],
            ['object' => 'list', 'data' => [refundPaginationRow(2), $good], 'has_more' => false],
        ] as $page) {
            $requests = [];
            $gateway = refundPaginationGateway(['head' => $page], $requests);
            $yielded = [];
            withEnvironment(['STRIPE_SECRET_KEY' => 'sk_test_pagination_fixture'], static function () use ($gateway, &$yielded): void {
                expectRefundPaginationFailure(static function () use ($gateway, &$yielded): void {
                    foreach ($gateway->refundsForPaymentIntent('pi_fixture') as $row) $yielded[] = $row;
                });
            });
            assertSameValue([], $yielded);
        }
    },
    'refund pagination rejects overlapping pages and multi-page cursor cycles' => static function (): void {
        $a = [...refundPaginationRow(1), 'created' => 1000];
        $b = [...refundPaginationRow(2), 'created' => 1000];
        $c = [...refundPaginationRow(3), 'created' => 1000];
        foreach ([
            ['head' => ['object' => 'list', 'data' => [$a, $b], 'has_more' => true],
                're_page2' => ['object' => 'list', 'data' => [$a, $c], 'has_more' => false]],
            ['head' => ['object' => 'list', 'data' => [$a], 'has_more' => true],
                're_page1' => ['object' => 'list', 'data' => [$b], 'has_more' => true],
                're_page2' => ['object' => 'list', 'data' => [$c], 'has_more' => true],
                're_page3' => ['object' => 'list', 'data' => [$a], 'has_more' => true]],
        ] as $pages) {
            $requests = [];
            $gateway = refundPaginationGateway($pages, $requests);
            withEnvironment(['STRIPE_SECRET_KEY' => 'sk_test_pagination_fixture'], static function () use ($gateway): void {
                expectRefundPaginationFailure(static fn () => iterator_to_array($gateway->refundsForPaymentIntent('pi_fixture')));
            });
            assertTrueValue(count($requests) <= 4);
        }
    },
    'expanded refund intent references are accepted only for the requested payment' => static function (): void {
        $requests = [];
        $row = [...refundPaginationRow(1), 'payment_intent' => ['id' => 'pi_fixture', 'object' => 'payment_intent']];
        $gateway = refundPaginationGateway(['head' => ['object' => 'list', 'data' => [$row], 'has_more' => false]], $requests);
        withEnvironment(['STRIPE_SECRET_KEY' => 'sk_test_pagination_fixture'], static function () use ($gateway): void {
            assertSameValue(1, count(iterator_to_array($gateway->refundsForPaymentIntent('pi_fixture'))));
        });
    },
    'invalid intent identifiers are rejected without calling Stripe' => static function (): void {
        $requests = [];
        $gateway = refundPaginationGateway([], $requests);
        foreach (['', 'ch_wrong', 'pi_bad/intent', "pi_bad\n", 'pi_' . str_repeat('x', 126)] as $intent) {
            expectRefundPaginationFailure(static fn () => iterator_to_array($gateway->refundsForPaymentIntent($intent)));
        }
        assertSameValue([], $requests);
    },
    'the worker finds an uncertain refund after the first hundred results through the real gateway' => static function (): void {
        $requests = [];
        $target = [...refundPaginationRow(101), 'metadata' => ['order_number' => 'REFUND-PAGE-TEST', 'refund_attempt' => '1']];
        $gateway = refundPaginationGateway(['head' => refundPaginationPage(1, 100, true),
            're_page100' => ['object' => 'list', 'data' => [$target], 'has_more' => false]], $requests);
        [$pdo, $service] = refundPaginationRecoveryFixture($gateway);
        withEnvironment(['STRIPE_SECRET_KEY' => 'sk_test_pagination_fixture', 'APP_ENV' => 'development'], static function () use ($pdo, $service): void {
            $run = new ReflectionMethod(PaymentOperationsService::class, 'reconcilePendingRefunds');
            assertSameValue(1, $run->invoke($service, 20));
            assertSameValue('refunded', $pdo->query('SELECT payment_status FROM orders')->fetchColumn());
            assertSameValue('re_page101', $pdo->query('SELECT payment_refund_id FROM orders')->fetchColumn());
            assertSameValue(1000, (int) $pdo->query('SELECT payment_refunded_cents FROM orders')->fetchColumn());
        });
        assertSameValue(2, count($requests));
    },
    'a worker lookup failure on a later page preserves the unresolved refund and ledger' => static function (): void {
        foreach ([null, ['unexpected' => 'response']] as $failure) {
            $requests = [];
            $gateway = refundPaginationGateway(['head' => refundPaginationPage(1, 100, true), 're_page100' => $failure], $requests);
            [$pdo, $service] = refundPaginationRecoveryFixture($gateway);
            withEnvironment(['STRIPE_SECRET_KEY' => 'sk_test_pagination_fixture', 'APP_ENV' => 'development'], static function () use ($pdo, $service): void {
                $run = new ReflectionMethod(PaymentOperationsService::class, 'reconcilePendingRefunds');
                assertSameValue(0, $run->invoke($service, 20));
                assertSameValue('refund_pending', $pdo->query('SELECT payment_status FROM orders')->fetchColumn());
                assertSameValue('unknown', $pdo->query('SELECT payment_refund_outcome FROM orders')->fetchColumn());
                assertSameValue(0, (int) $pdo->query('SELECT payment_refunded_cents FROM orders')->fetchColumn());
                assertSameValue(null, $pdo->query('SELECT payment_refund_id FROM orders')->fetchColumn());
                assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_refund_attempts')->fetchColumn());
                assertSameValue('unknown', $pdo->query('SELECT status FROM payment_refund_attempts')->fetchColumn());
                assertTrueValue($pdo->query('SELECT payment_refund_error_code FROM orders')->fetchColumn() !== null);
            });
        }
    },
    'full and partial admin refund lookups both find attempts beyond the first page' => static function (): void {
        foreach ([false, true] as $partial) {
            $requests = [];
            $target = [...refundPaginationRow(101), 'metadata' => ['order_number' => 'REFUND-PAGE-TEST', 'refund_attempt' => '1']];
            $gateway = refundPaginationGateway(['head' => refundPaginationPage(1, 100, true),
                're_page100' => ['object' => 'list', 'data' => [$target], 'has_more' => false]], $requests);
            [$pdo] = refundPaginationRecoveryFixture($gateway);
            $harness = new RefundPaginationAdminHarness($pdo, $gateway);
            withEnvironment(['STRIPE_SECRET_KEY' => 'sk_test_pagination_fixture'], static function () use ($harness, $partial): void {
                assertSameValue(['selectedRefundId' => 're_page101'], $harness->lookup($partial));
            });
            assertSameValue(2, count($requests));
        }
    },
    'full and partial admin lookups never label an incomplete page sequence as not found' => static function (): void {
        foreach ([false, true] as $partial) {
            $requests = [];
            $gateway = refundPaginationGateway(['head' => refundPaginationPage(1, 100, true), 're_page100' => ['unexpected' => 'response']], $requests);
            [$pdo] = refundPaginationRecoveryFixture($gateway);
            $harness = new RefundPaginationAdminHarness($pdo, $gateway);
            withEnvironment(['STRIPE_SECRET_KEY' => 'sk_test_pagination_fixture'], static function () use ($harness, $partial, $pdo): void {
                $result = $harness->lookup($partial);
                assertSameValue(false, isset($result['selectedRefundId']));
                assertSameValue(false, str_contains($result['message'], 'Nenhum reembolso'));
                assertSameValue($partial ? 'partial_refund_lookup_failed' : 'refund_lookup_failed', $pdo->query('SELECT payment_refund_error_code FROM orders')->fetchColumn());
                assertSameValue('refund_pending', $pdo->query('SELECT payment_status FROM orders')->fetchColumn());
                assertSameValue(0, (int) $pdo->query('SELECT payment_refunded_cents FROM orders')->fetchColumn());
                assertSameValue('unknown', $pdo->query('SELECT status FROM payment_refund_attempts')->fetchColumn());
                assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn());
            });
        }
    },
];
