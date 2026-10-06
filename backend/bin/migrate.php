<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Http.php';
require_once dirname(__DIR__) . '/src/Security.php';
require_once dirname(__DIR__) . '/src/PaymentPolicy.php';
require_once dirname(__DIR__) . '/src/RuntimeConfiguration.php';
require_once dirname(__DIR__) . '/src/Schema.php';

RuntimeConfiguration::assertSafeStartup();

$attempts = 0;
$lastError = 'unknown database error';
while (true) {
    try {
        Schema::migrate(Database::connect());
        fwrite(STDOUT, "Database schema is current.\n");
        exit(0);
    } catch (PDOException $exception) {
        $lastError = $exception->getMessage();
        $attempts++;
        if ($attempts >= 30) {
            fwrite(STDERR, "Database migration failed after {$attempts} attempts: {$lastError}\n");
            exit(1);
        }
        sleep(2);
    }
}
