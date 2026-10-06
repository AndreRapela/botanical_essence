<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaMigrationHelpers.php';

require_once __DIR__ . '/RefundRecoverySchema.php';
require_once __DIR__ . '/CheckoutPreparationSchema.php';
require_once __DIR__ . '/CheckoutIdempotencySchema.php';
require_once __DIR__ . '/WithdrawalSchema.php';
require_once __DIR__ . '/PaymentOperationsSchema.php';
require_once __DIR__ . '/CommerceRatesSchema.php';

final class Schema
{
    use SchemaMigrationHelpers;
    private const VERSION = '2026-09-24-production-foundation-v1';
    private const LEGACY_ACCOUNTS_VERSION = '2026-09-24-disable-legacy-demo-accounts-v2';
    private const LEGACY_ENCODING_VERSION = '2026-09-24-normalize-legacy-catalog-encoding-v3';
    private const SECURITY_VERSION = '2026-09-29-security-and-inventory-v4';
    private const MARKETPLACE_VERSION = '2026-09-30-marketplace-management-v5';
    private const ADMIN_AUDIT_VERSION = '2026-09-30-admin-audit-v6';
    private const API_HARDENING_VERSION = '2026-10-01-api-hardening-v7';
    private const ACCOUNT_ADMIN_VERSION = '2026-10-01-account-admin-v8';
    private const LOCATION_VERSION = '2026-10-01-region-country-v9';
    private const CURRENCY_VERSION = '2026-10-01-usd-eur-v10';
    private const PRODUCT_REVIEWS_VERSION = '2026-10-01-product-details-reviews-v11';
    private const BPOST_SHIPPING_VERSION = '2026-10-01-bpost-shipping-v12';
    private const BELGIAN_CATALOG_VERSION = '2026-10-02-belgian-catalog-v13';
    private const PAYMENTS_VERSION = '2026-10-03-stripe-checkout-payments-v15';
    private const ADMIN_AUDIT_SNAPSHOTS_VERSION = '2026-10-04-admin-audit-snapshots-v16';

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(120) PRIMARY KEY,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB'
        );
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::VERSION]);
        if ($check->fetchColumn()) {
            self::disableLegacyDemoAccounts($pdo);
            self::normalizeLegacyCatalog($pdo);
            self::applySecurityAndInventoryUpgrade($pdo);
            self::applyMarketplaceUpgrade($pdo);
            self::applyAdminAuditUpgrade($pdo);
            self::applyApiHardeningUpgrade($pdo);
            self::applyAccountAdminUpgrade($pdo);
            self::applyLocationUpgrade($pdo);
            self::applyCurrencyUpgrade($pdo);
            self::applyProductReviewsUpgrade($pdo);
            self::applyBpostShippingUpgrade($pdo);
            self::applyBelgianCatalogUpgrade($pdo);
            self::applyPaymentsUpgrade($pdo);
            RefundRecoverySchema::migrate($pdo);
            CheckoutPreparationSchema::migrate($pdo);
            CheckoutIdempotencySchema::migrate($pdo);
            WithdrawalSchema::migrate($pdo);
            PaymentOperationsSchema::migrate($pdo);
            CommerceRatesSchema::migrate($pdo);
            self::applyAdminAuditSnapshotsUpgrade($pdo);
            self::syncDemoAccounts($pdo);
            return;
        }

        self::ensureColumn($pdo, 'users', 'status', "ENUM('pending','active','disabled') NOT NULL DEFAULT 'pending' AFTER role");
        self::ensureColumn($pdo, 'users', 'email_verified_at', 'TIMESTAMP NULL AFTER avatar_url');
        $pdo->exec("UPDATE users SET status = 'active', email_verified_at = COALESCE(email_verified_at, created_at)");
        self::disableLegacyDemoAccounts($pdo);
        self::normalizeLegacyCatalog($pdo);

        $statements = [
            'CREATE TABLE IF NOT EXISTS email_verification_tokens (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL UNIQUE,
                expires_at TIMESTAMP NOT NULL,
                used_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_verification_lookup (token_hash, expires_at, used_at)
            ) ENGINE=InnoDB',
            'CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL UNIQUE,
                expires_at TIMESTAMP NOT NULL,
                used_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_reset_lookup (token_hash, expires_at, used_at)
            ) ENGINE=InnoDB',
            'CREATE TABLE IF NOT EXISTS rate_limits (
                action_name VARCHAR(60) NOT NULL,
                identifier_hash CHAR(64) NOT NULL,
                attempts INT UNSIGNED NOT NULL DEFAULT 1,
                window_started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (action_name, identifier_hash),
                INDEX idx_rate_window (window_started_at)
            ) ENGINE=InnoDB',
            'CREATE TABLE IF NOT EXISTS orders (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_number VARCHAR(32) NOT NULL UNIQUE,
                user_id BIGINT UNSIGNED NULL,
                customer_name VARCHAR(120) NOT NULL,
                email VARCHAR(180) NOT NULL,
                address_line VARCHAR(200) NOT NULL,
                city VARCHAR(100) NOT NULL,
                postal_code VARCHAR(20) NOT NULL,
                delivery_method ENUM(\'standard\',\'express\') NOT NULL,
                payment_method VARCHAR(40) NOT NULL,
                payment_status ENUM(\'pending\',\'paid\',\'failed\',\'refunded\') NOT NULL DEFAULT \'pending\',
                status ENUM(\'awaiting_payment\',\'confirmed\',\'preparing\',\'shipped\',\'delivered\',\'cancelled\') NOT NULL DEFAULT \'awaiting_payment\',
                subtotal DECIMAL(10,2) NOT NULL,
                shipping_amount DECIMAL(10,2) NOT NULL,
                total DECIMAL(10,2) NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT \'USD\',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_order_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_orders_user_created (user_id, created_at)
            ) ENGINE=InnoDB',
            'CREATE TABLE IF NOT EXISTS order_items (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NULL,
                seller_id BIGINT UNSIGNED NULL,
                product_name VARCHAR(140) NOT NULL,
                unit_price DECIMAL(10,2) NOT NULL,
                quantity SMALLINT UNSIGNED NOT NULL,
                line_total DECIMAL(10,2) NOT NULL,
                CONSTRAINT fk_item_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                CONSTRAINT fk_item_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
                CONSTRAINT fk_item_seller FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE SET NULL,
                INDEX idx_order_items_order (order_id)
            ) ENGINE=InnoDB',
            'CREATE TABLE IF NOT EXISTS contact_messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(180) NOT NULL,
                subject ENUM(\'order\',\'care\',\'seller\',\'other\') NOT NULL,
                message TEXT NOT NULL,
                status ENUM(\'new\',\'in_progress\',\'closed\') NOT NULL DEFAULT \'new\',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_contact_status_created (status, created_at)
            ) ENGINE=InnoDB',
            'CREATE TABLE IF NOT EXISTS newsletter_subscriptions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(180) NOT NULL UNIQUE,
                language ENUM(\'en\',\'fr\') NOT NULL DEFAULT \'en\',
                status ENUM(\'subscribed\',\'unsubscribed\') NOT NULL DEFAULT \'subscribed\',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB',
        ];
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }

        self::ensureIndex($pdo, 'products', 'idx_products_feed', '(is_marketplace, featured, created_at)');
        $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $insert->execute([':version' => self::VERSION]);
        self::applySecurityAndInventoryUpgrade($pdo);
        self::applyMarketplaceUpgrade($pdo);
        self::applyAdminAuditUpgrade($pdo);
        self::applyApiHardeningUpgrade($pdo);
        self::applyAccountAdminUpgrade($pdo);
        self::applyLocationUpgrade($pdo);
        self::applyCurrencyUpgrade($pdo);
        self::applyProductReviewsUpgrade($pdo);
        self::applyBpostShippingUpgrade($pdo);
        self::applyBelgianCatalogUpgrade($pdo);
        self::applyPaymentsUpgrade($pdo);
        RefundRecoverySchema::migrate($pdo);
        CheckoutPreparationSchema::migrate($pdo);
        CheckoutIdempotencySchema::migrate($pdo);
        WithdrawalSchema::migrate($pdo);
        PaymentOperationsSchema::migrate($pdo);
        CommerceRatesSchema::migrate($pdo);
        self::applyAdminAuditSnapshotsUpgrade($pdo);
        self::syncDemoAccounts($pdo);
    }

    private static function applyAdminAuditSnapshotsUpgrade(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::ADMIN_AUDIT_SNAPSHOTS_VERSION]);
        if ($check->fetchColumn()) {
            return;
        }

        $pdo->exec('ALTER TABLE admin_audit_log
            MODIFY previous_value TEXT NULL,
            MODIFY new_value TEXT NULL');
        $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $insert->execute([':version' => self::ADMIN_AUDIT_SNAPSHOTS_VERSION]);
    }

    private static function disableLegacyDemoAccounts(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::LEGACY_ACCOUNTS_VERSION]);
        if ($check->fetchColumn()) {
            return;
        }

        $legacyEmails = [
            'loja@botanicalessence.com.br',
            'marina@raizesurbanas.com.br',
            'clara@floradavila.com.br',
            'joao@cultivogentil.com.br',
            'cliente@botanicalessence.com.br',
        ];
        $placeholders = implode(',', array_fill(0, count($legacyEmails), '?'));
        $update = $pdo->prepare(
            "UPDATE users SET status = 'disabled', email_verified_at = NULL WHERE email IN ({$placeholders})"
        );
        $update->execute($legacyEmails);

        $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $insert->execute([':version' => self::LEGACY_ACCOUNTS_VERSION]);
    }

    private static function normalizeLegacyCatalog(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::LEGACY_ENCODING_VERSION]);
        if ($check->fetchColumn()) {
            return;
        }

        $userNames = [
            'loja@botanicalessence.com.br' => 'Equipe Botanical Essence',
            'marina@raizesurbanas.com.br' => 'Marina Raízes',
            'clara@floradavila.com.br' => 'Clara Flora',
            'joao@cultivogentil.com.br' => 'João Cultivo',
            'cliente@botanicalessence.com.br' => 'Cliente Demonstração',
        ];
        $userUpdate = $pdo->prepare('UPDATE users SET name = :name WHERE email = :email');
        foreach ($userNames as $email => $name) {
            $userUpdate->execute([':name' => $name, ':email' => $email]);
        }

        $sellers = [
            'botanical-essence' => ['Botanical Essence', 'Curadoria botânica para casas vivas.', 'São Paulo'],
            'raizes-urbanas' => ['Raízes Urbanas', 'Mudas tropicais cultivadas em pequena escala.', 'São Paulo'],
            'flora-da-vila' => ['Flora da Vila', 'Folhagens especiais e vasos feitos à mão.', 'Campinas'],
            'cultivo-gentil' => ['Cultivo Gentil', 'Insumos naturais para plantas saudáveis.', 'Curitiba'],
        ];
        $sellerUpdate = $pdo->prepare(
            'UPDATE sellers SET display_name = :displayName, bio = :bio, city = :city WHERE slug = :slug'
        );
        foreach ($sellers as $slug => [$displayName, $bio, $city]) {
            $sellerUpdate->execute(compact('displayName', 'bio', 'city', 'slug'));
        }

        $products = [
            'monstera-essencial' => ['Monstera Essencial', 'Monstera deliciosa com vaso artesanal de cerâmica.', 'Mais querida'],
            'ficus-lyrata' => ['Ficus Lyrata', 'Ficus lyrata de porte médio, aproximadamente 80 cm.', 'Novidade'],
            'calathea-orbifolia' => ['Calathea Orbifolia', 'Folhagem natural cultivada em pequena escala.', null],
            'bruma-botanica' => ['Bruma Botânica', 'Bruma de ambiente com eucalipto e alecrim, 120 ml.', 'Exclusivo'],
            'costela-de-adao-jovem' => ['Costela-de-adão Jovem', 'Muda selecionada com cerca de 40 cm.', null],
            'oleo-de-neem' => ['Óleo de Neem', 'Cuidado natural concentrado para plantas, 100 ml.', null],
        ];
        $productUpdate = $pdo->prepare(
            'UPDATE products SET name = :name, description = :description, badge = :badge WHERE slug = :slug'
        );
        foreach ($products as $slug => [$name, $description, $badge]) {
            $productUpdate->execute(compact('name', 'description', 'badge', 'slug'));
        }

        $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $insert->execute([':version' => self::LEGACY_ENCODING_VERSION]);
    }

    private static function applySecurityAndInventoryUpgrade(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::SECURITY_VERSION]);
        if ($check->fetchColumn()) {
            return;
        }

        self::ensureColumn($pdo, 'users', 'auth_version', 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER status');
        self::ensureColumn($pdo, 'orders', 'idempotency_key', 'VARCHAR(64) NULL AFTER order_number');
        self::ensureColumn(
            $pdo,
            'orders',
            'reservation_expires_at',
            'TIMESTAMP NULL AFTER status'
        );
        self::ensureUniqueIndex(
            $pdo,
            'orders',
            'uq_orders_user_idempotency',
            '(user_id, idempotency_key)'
        );
        self::ensureIndex(
            $pdo,
            'orders',
            'idx_orders_reservation_expiry',
            '(status, payment_status, reservation_expires_at)'
        );

        $catalogEmails = [
            'loja@botanicalessence.com.br',
            'marina@raizesurbanas.com.br',
            'clara@floradavila.com.br',
            'joao@cultivogentil.com.br',
            'cliente@botanicalessence.com.br',
        ];
        $placeholders = implode(',', array_fill(0, count($catalogEmails), '?'));
        $neutralize = $pdo->prepare(
            "UPDATE users SET password_hash = '!disabled-catalog-account!' WHERE email IN ({$placeholders})"
        );
        $neutralize->execute($catalogEmails);

        $pdo->exec(
            'DELETE FROM email_verification_tokens
             WHERE used_at IS NOT NULL OR expires_at < CURRENT_TIMESTAMP'
        );
        $pdo->exec(
            'DELETE FROM password_reset_tokens
             WHERE used_at IS NOT NULL OR expires_at < CURRENT_TIMESTAMP'
        );
        $pdo->exec(
            'DELETE FROM rate_limits
             WHERE window_started_at < (CURRENT_TIMESTAMP - INTERVAL 2 DAY)
             ORDER BY window_started_at ASC
             LIMIT 500'
        );

        $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $insert->execute([':version' => self::SECURITY_VERSION]);
    }

    private static function applyMarketplaceUpgrade(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::MARKETPLACE_VERSION]);
        if ($check->fetchColumn()) {
            return;
        }

        self::ensureColumn(
            $pdo,
            'products',
            'status',
            "ENUM('draft','active','archived') NOT NULL DEFAULT 'draft' AFTER featured"
        );
        $pdo->exec("UPDATE products SET status = 'active' WHERE status = 'draft'");
        self::ensureIndex($pdo, 'products', 'idx_products_public_feed', '(status, is_marketplace, featured, created_at)');
        self::ensureIndex($pdo, 'products', 'idx_products_seller_status', '(seller_id, status, updated_at)');

        $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $insert->execute([':version' => self::MARKETPLACE_VERSION]);
    }

    private static function applyAdminAuditUpgrade(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::ADMIN_AUDIT_VERSION]);
        if ($check->fetchColumn()) {
            return;
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS admin_audit_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                admin_user_id BIGINT UNSIGNED NULL,
                action_name VARCHAR(80) NOT NULL,
                target_type VARCHAR(40) NOT NULL,
                target_id BIGINT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_audit_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_audit_target_created (target_type, target_id, created_at),
                INDEX idx_audit_admin_created (admin_user_id, created_at)
            ) ENGINE=InnoDB'
        );
        $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $insert->execute([':version' => self::ADMIN_AUDIT_VERSION]);
    }

    private static function applyApiHardeningUpgrade(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::API_HARDENING_VERSION]);
        if ($check->fetchColumn()) {
            return;
        }

        self::ensureColumn($pdo, 'orders', 'request_hash', 'CHAR(64) NULL AFTER idempotency_key');
        self::ensureColumn($pdo, 'admin_audit_log', 'previous_value', 'VARCHAR(32) NULL AFTER target_id');
        self::ensureColumn($pdo, 'admin_audit_log', 'new_value', 'VARCHAR(32) NULL AFTER previous_value');
        self::ensureIndex(
            $pdo,
            'email_verification_tokens',
            'idx_verification_user_active',
            '(user_id, expires_at, used_at)'
        );
        self::ensureIndex(
            $pdo,
            'password_reset_tokens',
            'idx_reset_user_active',
            '(user_id, expires_at, used_at)'
        );
        self::ensureCheckConstraint($pdo, 'products', 'chk_products_price', 'price >= 0.01');
        self::ensureCheckConstraint($pdo, 'products', 'chk_products_sprite', 'image_position <= 3');
        self::ensureCheckConstraint(
            $pdo,
            'orders',
            'chk_orders_amounts',
            'subtotal >= 0 AND shipping_amount >= 0 AND total = subtotal + shipping_amount'
        );
        self::ensureCheckConstraint(
            $pdo,
            'order_items',
            'chk_order_items_values',
            'quantity BETWEEN 1 AND 20 AND unit_price >= 0.01 AND line_total = unit_price * quantity'
        );

        $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $insert->execute([':version' => self::API_HARDENING_VERSION]);
    }

    private static function applyAccountAdminUpgrade(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::ACCOUNT_ADMIN_VERSION]);
        if ($check->fetchColumn()) {
            return;
        }

        self::ensureColumn($pdo, 'users', 'is_demo', 'BOOLEAN NOT NULL DEFAULT FALSE AFTER auth_version');
        self::ensureIndex($pdo, 'users', 'idx_users_role_status', '(role, status, created_at)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_profiles (
                user_id BIGINT UNSIGNED PRIMARY KEY,
                bio VARCHAR(1000) NULL,
                city VARCHAR(100) NULL,
                state VARCHAR(100) NULL,
                country VARCHAR(100) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );

        $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $insert->execute([':version' => self::ACCOUNT_ADMIN_VERSION]);
    }

    private static function applyLocationUpgrade(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::LOCATION_VERSION]);
        if ($check->fetchColumn()) {
            return;
        }

        $pdo->exec('ALTER TABLE sellers MODIFY COLUMN state VARCHAR(100) NOT NULL');
        self::ensureColumn(
            $pdo,
            'sellers',
            'country',
            "VARCHAR(100) NOT NULL DEFAULT 'Brasil' AFTER state"
        );
        $pdo->exec('ALTER TABLE user_profiles MODIFY COLUMN state VARCHAR(100) NULL');
        self::ensureColumn($pdo, 'user_profiles', 'country', 'VARCHAR(100) NULL AFTER state');
        $pdo->exec("UPDATE sellers SET country = 'Brasil' WHERE country = ''");
        $pdo->exec(
            "UPDATE user_profiles SET country = 'Brasil'
             WHERE country IS NULL AND (city IS NOT NULL OR state IS NOT NULL)"
        );

        $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $insert->execute([':version' => self::LOCATION_VERSION]);
    }

    private static function applyCurrencyUpgrade(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::CURRENCY_VERSION]);
        if ($check->fetchColumn()) {
            return;
        }

        $pdo->exec("UPDATE orders SET currency = 'USD' WHERE currency = 'BRL'");
        $pdo->exec("ALTER TABLE orders MODIFY COLUMN currency CHAR(3) NOT NULL DEFAULT 'USD'");

        $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $insert->execute([':version' => self::CURRENCY_VERSION]);
    }

    private static function applyProductReviewsUpgrade(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::PRODUCT_REVIEWS_VERSION]);
        if ($check->fetchColumn()) {
            return;
        }

        self::ensureColumn($pdo, 'products', 'origin', 'VARCHAR(120) NULL AFTER description');
        self::ensureColumn($pdo, 'products', 'dimensions', 'VARCHAR(120) NULL AFTER origin');
        self::ensureColumn($pdo, 'products', 'materials', 'VARCHAR(200) NULL AFTER dimensions');
        self::ensureColumn($pdo, 'products', 'care_instructions', 'TEXT NULL AFTER materials');
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS product_reviews (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                rating TINYINT UNSIGNED NOT NULL,
                comment VARCHAR(1000) NOT NULL,
                status ENUM('visible','hidden') NOT NULL DEFAULT 'visible',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_review_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
                CONSTRAINT fk_review_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT chk_review_rating CHECK (rating BETWEEN 1 AND 5),
                UNIQUE INDEX uq_product_review_user (product_id, user_id),
                INDEX idx_reviews_product_status (product_id, status, updated_at)
            ) ENGINE=InnoDB"
        );

        $details = [
            'monstera-essencial' => ['Brasil', 'Aprox. 65 × 35 cm', 'Planta natural e vaso de cerâmica', 'Mantenha em luz indireta e regue quando a camada superior do solo estiver seca.'],
            'ficus-lyrata' => ['África Ocidental', 'Aprox. 80 × 30 cm', 'Planta natural e vaso de cultivo', 'Prefere luz indireta intensa; evite correntes de ar e excesso de água.'],
            'calathea-orbifolia' => ['América do Sul', 'Aprox. 45 × 35 cm', 'Planta natural e vaso de cultivo', 'Mantenha o substrato levemente úmido e ofereça boa umidade do ar.'],
            'bruma-botanica' => ['Brasil', 'Frasco de 120 ml', 'Água, álcool vegetal, eucalipto e alecrim', 'Borrife no ambiente a cerca de 30 cm de tecidos e superfícies.'],
            'costela-de-adao-jovem' => ['América Tropical', 'Aprox. 40 × 25 cm', 'Planta natural e vaso de cultivo', 'Luz indireta, regas moderadas e tutor conforme o crescimento.'],
            'oleo-de-neem' => ['Índia', 'Frasco de 100 ml', 'Óleo vegetal de neem concentrado', 'Dilua conforme o rótulo e aplique longe do sol forte, mantendo fora do alcance de crianças.'],
        ];
        $update = $pdo->prepare(
            'UPDATE products SET origin = COALESCE(origin, :origin),
                dimensions = COALESCE(dimensions, :dimensions),
                materials = COALESCE(materials, :materials),
                care_instructions = COALESCE(care_instructions, :careInstructions)
             WHERE slug = :slug'
        );
        foreach ($details as $slug => [$origin, $dimensions, $materials, $careInstructions]) {
            $update->execute(compact('origin', 'dimensions', 'materials', 'careInstructions', 'slug'));
        }

        $reviews = [
            ['monstera-essencial', 2, 5, 'Chegou muito bem embalada e com folhas lindas. O vaso também tem um acabamento excelente.'],
            ['monstera-essencial', 3, 4, 'Planta saudável e fiel às fotos. As orientações de cuidado foram muito úteis.'],
            ['ficus-lyrata', 4, 5, 'Uma peça marcante para a sala, veio muito bem protegida e no tamanho descrito.'],
            ['calathea-orbifolia', 2, 5, 'Folhagem impecável e atendimento cuidadoso. Já está se adaptando muito bem ao ambiente.'],
            ['bruma-botanica', 3, 4, 'Aroma verde e delicado, sem ficar pesado. A embalagem é bonita e prática.'],
            ['costela-de-adao-jovem', 3, 5, 'Muda forte, com raízes bem formadas e ótima apresentação.'],
            ['oleo-de-neem', 2, 4, 'Fácil de diluir e com instruções claras. Funcionou bem na rotina de cuidados.'],
        ];
        $seed = $pdo->prepare(
            "INSERT IGNORE INTO product_reviews (product_id, user_id, rating, comment, status)
             SELECT p.id, :userId, :rating, :comment, 'visible'
             FROM products p WHERE p.slug = :slug LIMIT 1"
        );
        foreach ($reviews as [$slug, $userId, $rating, $comment]) {
            $seed->execute(compact('slug', 'userId', 'rating', 'comment'));
        }

        $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $insert->execute([':version' => self::PRODUCT_REVIEWS_VERSION]);
    }

    private static function applyBpostShippingUpgrade(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::BPOST_SHIPPING_VERSION]);
        if ($check->fetchColumn()) {
            return;
        }

        self::ensureColumn(
            $pdo,
            'products',
            'shipping_weight_grams',
            'INT UNSIGNED NOT NULL DEFAULT 1000 AFTER price'
        );
        self::ensureCheckConstraint(
            $pdo,
            'products',
            'chk_products_shipping_weight',
            'shipping_weight_grams BETWEEN 50 AND 30000'
        );
        $weights = [
            'monstera-essencial' => 3500,
            'ficus-lyrata' => 6000,
            'calathea-orbifolia' => 2500,
            'bruma-botanica' => 350,
            'costela-de-adao-jovem' => 1800,
            'oleo-de-neem' => 300,
        ];
        $updateWeight = $pdo->prepare(
            'UPDATE products SET shipping_weight_grams = :weight WHERE slug = :slug'
        );
        foreach ($weights as $slug => $weight) {
            $updateWeight->execute([':weight' => $weight, ':slug' => $slug]);
        }

        self::ensureColumn($pdo, 'orders', 'region', 'VARCHAR(100) NULL AFTER city');
        self::ensureColumn($pdo, 'orders', 'country_code', "CHAR(2) NOT NULL DEFAULT 'BE' AFTER postal_code");
        self::ensureColumn(
            $pdo,
            'orders',
            'shipping_weight_grams',
            'INT UNSIGNED NOT NULL DEFAULT 0 AFTER delivery_method'
        );
        self::ensureColumn(
            $pdo,
            'orders',
            'shipping_tariff_source',
            'VARCHAR(80) NULL AFTER shipping_weight_grams'
        );
        $pdo->exec('ALTER TABLE orders MODIFY COLUMN delivery_method VARCHAR(60) NOT NULL');

        $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $insert->execute([':version' => self::BPOST_SHIPPING_VERSION]);
    }

    private static function applyBelgianCatalogUpgrade(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::BELGIAN_CATALOG_VERSION]);
        if ($check->fetchColumn()) {
            return;
        }

        $pdo->exec("ALTER TABLE sellers ALTER COLUMN country SET DEFAULT 'Belgium'");
        $stores = [
            1 => ['Botanical Essence', 'A botanical selection for living Belgian homes.', 'Brussels', 'Brussels-Capital'],
            2 => ['Urban Roots', 'Tropical plants grown locally in small batches.', 'Ghent', 'East Flanders'],
            3 => ['Flora Atelier', 'Special foliage and handmade pots from Antwerp.', 'Antwerp', 'Antwerp'],
            4 => ['Gentle Growing', 'Natural care products for healthy plants.', 'Liège', 'Liège'],
        ];
        $updateStore = $pdo->prepare(
            'UPDATE sellers SET display_name = :displayName, bio = :bio, city = :city,
                state = :region, country = \'Belgium\' WHERE id = :id AND user_id = :userId'
        );
        foreach ($stores as $id => [$displayName, $bio, $city, $region]) {
            $updateStore->execute([
                ':displayName' => $displayName,
                ':bio' => $bio,
                ':city' => $city,
                ':region' => $region,
                ':id' => $id,
                ':userId' => $id,
            ]);
        }

        $products = [
            'monstera-essencial' => ['Essential Monstera', 'Monstera deliciosa in a handcrafted ceramic pot.', 'Tropical America', 'Approx. 65 × 35 cm', 'Natural plant and ceramic pot', 'Keep in indirect light and water when the top layer of soil is dry.'],
            'ficus-lyrata' => ['Fiddle-leaf Fig', 'Medium fiddle-leaf fig, approximately 80 cm tall.', 'West Africa', 'Approx. 80 × 30 cm', 'Natural plant and nursery pot', 'Prefers bright indirect light; avoid drafts and excess water.'],
            'calathea-orbifolia' => ['Calathea Orbifolia', 'Naturally grown statement foliage in a small batch.', 'South America', 'Approx. 45 × 35 cm', 'Natural plant and nursery pot', 'Keep the soil lightly moist and provide good air humidity.'],
            'bruma-botanica' => ['Botanical Room Mist', 'Room mist with eucalyptus and rosemary, 120 ml.', 'Belgium', '120 ml bottle', 'Water, plant alcohol, eucalyptus and rosemary', 'Mist from about 30 cm away from fabrics and surfaces.'],
            'costela-de-adao-jovem' => ['Young Monstera', 'Selected young plant, approximately 40 cm tall.', 'Tropical America', 'Approx. 40 × 25 cm', 'Natural plant and nursery pot', 'Indirect light, moderate watering and a support as the plant grows.'],
            'oleo-de-neem' => ['Neem Oil', 'Concentrated natural plant care, 100 ml.', 'India', '100 ml bottle', 'Concentrated vegetable neem oil', 'Dilute as directed on the label, apply away from strong sunlight and keep out of reach of children.'],
        ];
        $updateProduct = $pdo->prepare(
            'UPDATE products SET name = :name, description = :description, origin = :origin,
                dimensions = :dimensions, materials = :materials, care_instructions = :careInstructions
             WHERE slug = :slug'
        );
        foreach ($products as $slug => [$name, $description, $origin, $dimensions, $materials, $careInstructions]) {
            $updateProduct->execute(compact('name', 'description', 'origin', 'dimensions', 'materials', 'careInstructions', 'slug'));
        }

        $reviews = [
            ['monstera-essencial', 2, 'It arrived beautifully packed with healthy leaves. The pot has an excellent finish too.'],
            ['monstera-essencial', 3, 'A healthy plant that matches the photos. The care guidance was very useful.'],
            ['ficus-lyrata', 4, 'A striking piece for the living room, very well protected and true to the listed size.'],
            ['calathea-orbifolia', 2, 'Impeccable foliage and thoughtful service. It is adapting very well.'],
            ['bruma-botanica', 3, 'A fresh and delicate scent without becoming overpowering. Beautiful, practical packaging.'],
            ['costela-de-adao-jovem', 3, 'A strong young plant with well-formed roots and excellent presentation.'],
            ['oleo-de-neem', 2, 'Easy to dilute with clear instructions. It worked well in my plant-care routine.'],
        ];
        $updateReview = $pdo->prepare(
            'UPDATE product_reviews r INNER JOIN products p ON p.id = r.product_id
             SET r.comment = :comment WHERE p.slug = :slug AND r.user_id = :userId'
        );
        foreach ($reviews as [$slug, $userId, $comment]) {
            $updateReview->execute(compact('slug', 'userId', 'comment'));
        }

        $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $insert->execute([':version' => self::BELGIAN_CATALOG_VERSION]);
    }

    private static function applyPaymentsUpgrade(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::PAYMENTS_VERSION]);
        if ($check->fetchColumn()) {
            return;
        }

        $pdo->exec("ALTER TABLE orders MODIFY COLUMN payment_status ENUM('pending','paid','failed','refunded','refund_pending') NOT NULL DEFAULT 'pending'");
        self::ensureColumn($pdo, 'orders', 'payment_provider', 'VARCHAR(24) NULL AFTER payment_method');
        self::ensureColumn($pdo, 'orders', 'payment_session_id', 'VARCHAR(128) NULL AFTER payment_provider');
        self::ensureColumn($pdo, 'orders', 'payment_intent_id', 'VARCHAR(128) NULL AFTER payment_session_id');
        self::ensureColumn($pdo, 'orders', 'payment_refund_id', 'VARCHAR(128) NULL AFTER payment_intent_id');
        self::ensureColumn($pdo, 'orders', 'payment_refund_attempt', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER payment_refund_id');
        self::ensureColumn($pdo, 'orders', 'test_payment', 'BOOLEAN NOT NULL DEFAULT FALSE AFTER payment_refund_attempt');
        $pdo->exec("UPDATE orders SET payment_refund_attempt = 1 WHERE payment_status = 'refund_pending' AND payment_refund_attempt = 0");
        self::ensureUniqueIndex($pdo, 'orders', 'uq_orders_payment_session', '(payment_session_id)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS payment_webhook_events (
                event_id VARCHAR(128) PRIMARY KEY,
                event_type VARCHAR(100) NOT NULL,
                processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_payment_events_type_date (event_type, processed_at)
            ) ENGINE=InnoDB'
        );

        $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $insert->execute([':version' => self::PAYMENTS_VERSION]);
    }
    private static function syncDemoAccounts(PDO $pdo): void
    {
        $enabled = (getenv('APP_ENV') ?: 'production') === 'development'
            && filter_var(getenv('ENABLE_DEMO_ACCOUNTS') ?: 'false', FILTER_VALIDATE_BOOLEAN);

        if (!$enabled) {
            $pdo->exec(
                'UPDATE sellers s INNER JOIN users u ON u.id = s.user_id
                 SET s.verified = FALSE WHERE u.is_demo = TRUE'
            );
            $pdo->exec(
                "UPDATE products p
                 INNER JOIN sellers s ON s.id = p.seller_id
                 INNER JOIN users u ON u.id = s.user_id
                 SET p.status = 'draft'
                 WHERE u.is_demo = TRUE AND p.status = 'active'"
            );
            $pdo->exec(
                "UPDATE users SET status = 'disabled', auth_version = auth_version + 1
                 WHERE is_demo = TRUE AND status <> 'disabled'"
            );
            return;
        }

        $accounts = [
            ['Administrador demonstração', 'demo-admin@botanical.local', 'admin'],
            ['Usuário demonstração', 'demo-user@botanical.local', 'customer'],
            ['Vendedor demonstração', 'demo-seller@botanical.local', 'seller'],
        ];
        $statement = $pdo->prepare(
            "INSERT INTO users
                (name, email, password_hash, role, status, auth_version, is_demo, email_verified_at)
             VALUES (:name, :email, :password, :role, 'active', 1, TRUE, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name), role = VALUES(role), status = 'active', is_demo = TRUE,
                email_verified_at = COALESCE(email_verified_at, CURRENT_TIMESTAMP)"
        );
        foreach ($accounts as [$name, $email, $role]) {
            $statement->execute([
                ':name' => $name,
                ':email' => $email,
                ':password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID),
                ':role' => $role,
            ]);
        }

        $sellerUser = $pdo->prepare(
            "SELECT id FROM users
             WHERE email = 'demo-seller@botanical.local' AND is_demo = TRUE
             LIMIT 1"
        );
        $sellerUser->execute();
        $sellerUserId = (int) $sellerUser->fetchColumn();
        if ($sellerUserId > 0) {
            $seller = $pdo->prepare(
                "INSERT INTO sellers
                    (user_id, display_name, slug, bio, city, state, country, rating, verified)
                 VALUES
                    (:userId, 'Estúdio Botânico Demo', 'estudio-botanico-demo',
                     'Loja de demonstração para conhecer as ferramentas de vendedor.',
                     'Brussels', 'Brussels-Capital', 'Belgium', 5.0, FALSE)
                 ON DUPLICATE KEY UPDATE
                    display_name = VALUES(display_name), slug = VALUES(slug), bio = VALUES(bio),
                    city = VALUES(city), state = VALUES(state), country = VALUES(country)"
            );
            $seller->execute([':userId' => $sellerUserId]);
        }
    }





}
