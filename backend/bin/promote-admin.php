<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Schema.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is available only from the CLI.\n");
    exit(1);
}

$email = strtolower(trim((string) ($argv[1] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    fwrite(STDERR, "Usage: php bin/promote-admin.php admin@example.com\n");
    exit(2);
}

$pdo = Database::connect();
Schema::migrate($pdo);
$pdo->beginTransaction();

try {
    $find = $pdo->prepare(
        'SELECT id, role, status, email_verified_at
         FROM users WHERE email = :email LIMIT 1 FOR UPDATE'
    );
    $find->execute([':email' => $email]);
    $user = $find->fetch();

    if (!$user) {
        throw new RuntimeException('Account not found. Register and verify it before promoting it.');
    }
    if ($user['status'] !== 'active' || $user['email_verified_at'] === null) {
        throw new RuntimeException('Only an active account with a verified email can become an administrator.');
    }
    if ($user['role'] !== 'customer' && $user['role'] !== 'admin') {
        throw new RuntimeException('Promote a customer account; seller accounts must retain their marketplace role.');
    }
    if ($user['role'] === 'admin') {
        $pdo->rollBack();
        fwrite(STDOUT, "The account is already an administrator.\n");
        exit(0);
    }

    $promote = $pdo->prepare(
        "UPDATE users
         SET role = 'admin', auth_version = auth_version + 1, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id"
    );
    $promote->execute([':id' => $user['id']]);

    $audit = $pdo->prepare(
        "INSERT INTO admin_audit_log
            (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
         VALUES (NULL, 'admin.promoted_cli', 'user', :targetId, 'customer', 'admin')"
    );
    $audit->execute([':targetId' => $user['id']]);

    $pdo->commit();
    fwrite(STDOUT, "Administrator access granted. Existing sessions were invalidated.\n");
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
