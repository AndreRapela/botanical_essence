<?php

declare(strict_types=1);

/** Daily ECB reference rate cache used only to display and price USD/EUR amounts. */
final class ExchangeRateService
{
    private const PAIR = 'USD/EUR';
    private const SOURCE = 'ecb-daily-reference';
    private const URL = 'https://data-api.ecb.europa.eu/service/data/EXR/D.USD.EUR.SP00.A?lastNObservations=1&format=csvdata';
    private const MAX_CACHE_SECONDS = 1_800;
    private const MAX_RATE_AGE_DAYS = 5;
    private const MAX_RESPONSE_BYTES = 65_536;
    private const REFRESH_LOCK_PREFIX = 'botanical.exchange-rate-refresh.';

    private readonly Closure $fetcher;
    private readonly Closure $clock;

    public function __construct(PDO $pdo, ?callable $fetcher = null, ?callable $clock = null)
    {
        $this->pdo = $pdo;
        $this->fetcher = Closure::fromCallable($fetcher ?? self::fetchFromEcb(...));
        $this->clock = Closure::fromCallable($clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    private readonly PDO $pdo;

    /**
     * @return array{usdPerEur:float,eurPerUsd:float,rateDate:string,source:string,referenceType:string,rateToken:string,status:string,checkedAt:?string}
     */
    public function currentReferenceRate(): array
    {
        $now = ($this->clock)();
        $cached = $this->cachedRate();
        if ($cached !== null && $this->wasCheckedRecently($cached, $now)) {
            if ($this->isUsable($cached, $now)) {
                return $this->serializeRate($cached, $now);
            }
            if ((int) ($cached['failureCount'] ?? 0) > 0) {
                throw self::unavailable();
            }
        }

        $lock = $this->pdo->prepare('SELECT GET_LOCK(:lockName, 0)');
        $lock->execute([':lockName' => $this->refreshLockName()]);
        if ((int) $lock->fetchColumn() !== 1) {
            // Another request is refreshing the daily rate. Do not create a thundering
            // herd against the ECB; a recent cached reference is still safe to display.
            $cached = $this->cachedRate();
            $now = ($this->clock)();
            if ($cached !== null && $this->isUsable($cached, $now)) {
                return $this->serializeRate($cached, $now, 'refresh_in_progress');
            }
            throw self::unavailable();
        }

        try {
            // Recheck after acquiring the distributed lock; a request that completed
            // immediately before us may already have populated the fresh cache.
            $now = ($this->clock)();
            $cached = $this->cachedRate();
            if ($cached !== null && $this->wasCheckedRecently($cached, $now)) {
                if ($this->isUsable($cached, $now)) {
                    return $this->serializeRate($cached, $now);
                }
                if ((int) ($cached['failureCount'] ?? 0) > 0) {
                    throw self::unavailable();
                }
            }

            $observation = self::parseObservation(($this->fetcher)(), $now);
            $this->storeObservation($observation);
            return $this->serializeRate([
                ...$observation,
                'checkedAt' => $now->format(DATE_ATOM),
                'failureCount' => 0,
            ], $now);
        } catch (Throwable $exception) {
            if ($exception instanceof HttpException && $exception->errorCode === 'exchange_rate_unavailable') {
                throw $exception;
            }
            $this->recordRefreshFailure();
            if ($cached !== null && $this->isUsable($cached, $now)) {
                $cached['failureCount'] = (int) ($cached['failureCount'] ?? 0) + 1;
                return $this->serializeRate($cached, $now);
            }

            error_log('ECB reference-rate refresh failed; checkout is unavailable until a recent rate is available.');
            throw self::unavailable();
        } finally {
            try {
                $releaseLock = $this->pdo->prepare('SELECT RELEASE_LOCK(:lockName)');
                $releaseLock->execute([':lockName' => $this->refreshLockName()]);
            } catch (PDOException $exception) {
                // Closing the MySQL connection also releases a named lock; don't mask a valid rate.
                error_log('ECB reference-rate lock release failed (SQLSTATE ' . $exception->getCode() . ').');
            }
        }
    }

    /** @return array{usdPerEur:float,rateDate:string,source:string} */
    public static function parseObservation(string $csv, DateTimeImmutable $now): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        if (count($lines) < 2) {
            throw new RuntimeException('ECB CSV has no observations.');
        }
        $headers = str_getcsv(array_shift($lines));
        $dateIndex = array_search('TIME_PERIOD', $headers, true);
        $valueIndex = array_search('OBS_VALUE', $headers, true);
        if (!is_int($dateIndex) || !is_int($valueIndex)) {
            throw new RuntimeException('ECB CSV is missing required fields.');
        }

        $latest = null;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = str_getcsv($line);
            $dateValue = $row[$dateIndex] ?? '';
            $rateValue = $row[$valueIndex] ?? '';
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $dateValue, new DateTimeZone('UTC'));
            $rate = is_numeric($rateValue) ? (float) $rateValue : 0.0;
            if (!$date || $date->format('Y-m-d') !== $dateValue || $rate < 0.1 || $rate > 3.0 || $date > $now) {
                continue;
            }
            if ($latest === null || $date > $latest['date']) {
                $latest = ['date' => $date, 'rate' => $rate];
            }
        }

        if ($latest === null || self::ageInDays($latest['date'], $now) > self::MAX_RATE_AGE_DAYS) {
            throw new RuntimeException('ECB CSV does not contain a recent valid observation.');
        }

        return [
            'usdPerEur' => $latest['rate'],
            'rateDate' => $latest['date']->format('Y-m-d'),
            'source' => self::SOURCE,
        ];
    }

    public static function rateToken(float $usdPerEur, string $rateDate): string
    {
        return hash('sha256', self::PAIR . '|' . number_format($usdPerEur, 10, '.', '') . '|' . $rateDate . '|' . self::SOURCE);
    }

    private function cachedRate(): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT usd_per_eur AS usdPerEur, DATE_FORMAT(rate_date, \'%Y-%m-%d\') AS rateDate,
                    source, DATE_FORMAT(checked_at, \'%Y-%m-%dT%H:%i:%sZ\') AS checkedAt,
                    failure_count AS failureCount
             FROM commerce_exchange_rates WHERE pair = :pair LIMIT 1'
        );
        $statement->execute([':pair' => self::PAIR]);
        $rate = $statement->fetch();
        if (!$rate) {
            return null;
        }
        $rate['usdPerEur'] = $rate['usdPerEur'] === null ? null : (float) $rate['usdPerEur'];
        $rate['failureCount'] = (int) $rate['failureCount'];
        return $rate;
    }

    private function refreshLockName(): string
    {
        // MySQL named locks are server-wide, so include a stable database fingerprint
        // to keep staging/tests from serializing or interfering with production refreshes.
        $database = (string) (getenv('DB_DATABASE') ?: 'botanical_essence');
        return self::REFRESH_LOCK_PREFIX . substr(hash('sha256', $database), 0, 24);
    }

    private function storeObservation(array $observation): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO commerce_exchange_rates (pair, usd_per_eur, rate_date, source, checked_at, failure_count, last_error_code)
             VALUES (:pair, :rate, :rateDate, :source, UTC_TIMESTAMP(), 0, NULL)
             ON DUPLICATE KEY UPDATE usd_per_eur = VALUES(usd_per_eur), rate_date = VALUES(rate_date),
                 source = VALUES(source), checked_at = VALUES(checked_at), failure_count = 0, last_error_code = NULL'
        );
        $statement->execute([
            ':pair' => self::PAIR,
            ':rate' => number_format($observation['usdPerEur'], 10, '.', ''),
            ':rateDate' => $observation['rateDate'],
            ':source' => $observation['source'],
        ]);
    }

    private function recordRefreshFailure(): void
    {
        $this->pdo->prepare(
            'INSERT INTO commerce_exchange_rates (pair, usd_per_eur, rate_date, source, checked_at, failure_count, last_error_code)
             VALUES (:pair, NULL, NULL, NULL, UTC_TIMESTAMP(), 1, \'provider_unavailable\')
             ON DUPLICATE KEY UPDATE checked_at = VALUES(checked_at),
                 failure_count = LEAST(failure_count + 1, 255), last_error_code = \'provider_unavailable\''
        )->execute([':pair' => self::PAIR]);
    }

    private function wasCheckedRecently(array $rate, DateTimeImmutable $now): bool
    {
        if (!is_string($rate['checkedAt'] ?? null) || $rate['checkedAt'] === '') {
            return false;
        }
        $checkedAt = new DateTimeImmutable($rate['checkedAt'], new DateTimeZone('UTC'));
        $age = $now->getTimestamp() - $checkedAt->getTimestamp();
        return $age >= 0 && $age <= self::MAX_CACHE_SECONDS;
    }

    private function isUsable(array $rate, DateTimeImmutable $now): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($rate['rateDate'] ?? ''), new DateTimeZone('UTC'));
        $age = $date === false ? -1 : self::ageInDays($date, $now);
        return $date !== false && $age >= 0 && $age <= self::MAX_RATE_AGE_DAYS;
    }

    private function serializeRate(array $rate, DateTimeImmutable $now, ?string $statusOverride = null): array
    {
        $usdPerEur = (float) $rate['usdPerEur'];
        $date = (string) $rate['rateDate'];
        return [
            'usdPerEur' => $usdPerEur,
            'eurPerUsd' => 1 / $usdPerEur,
            'rateDate' => $date,
            'source' => self::SOURCE,
            'referenceType' => 'daily_reference',
            'rateToken' => self::rateToken($usdPerEur, $date),
            'status' => $statusOverride ?? ((int) ($rate['failureCount'] ?? 0) > 0 ? 'recent_cache_provider_unavailable' : 'current'),
            'checkedAt' => $rate['checkedAt'] ?? null,
        ];
    }

    private static function unavailable(): HttpException
    {
        return new HttpException(
            'A taxa de referência cambial está indisponível ou desatualizada. Tente novamente mais tarde.',
            503,
            [],
            'exchange_rate_unavailable'
        );
    }

    private static function ageInDays(DateTimeImmutable $date, DateTimeImmutable $now): int
    {
        $start = $date->setTime(0, 0)->getTimestamp();
        $end = $now->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0)->getTimestamp();
        return (int) floor(($end - $start) / 86_400);
    }

    private static function fetchFromEcb(): string
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => 5,
            'ignore_errors' => true,
            'header' => "Accept: text/csv\r\nUser-Agent: Botanical-Essence/1.0\r\n",
            // Only the configured ECB endpoint is trusted as an exchange-rate source.
            'follow_location' => 0,
            'max_redirects' => 0,
        ], 'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ]]);
        $csv = @file_get_contents(self::URL, false, $context, 0, self::MAX_RESPONSE_BYTES + 1);
        $status = $http_response_header[0] ?? '';
        if (!is_string($csv) || $csv === '' || strlen($csv) > self::MAX_RESPONSE_BYTES
            || !preg_match('/\s200\s/', $status)) {
            throw new RuntimeException('ECB reference-rate endpoint did not return HTTP 200.');
        }
        return $csv;
    }
}
