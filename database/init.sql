-- The MySQL image creates and selects MYSQL_DATABASE before executing init files.
-- Keep this schema-neutral so DB_DATABASE can safely vary between environments.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(180) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('customer', 'seller', 'admin') NOT NULL DEFAULT 'customer',
    status ENUM('pending', 'active', 'disabled') NOT NULL DEFAULT 'pending',
    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
    is_demo BOOLEAN NOT NULL DEFAULT FALSE,
    avatar_url VARCHAR(255) NULL,
    email_verified_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_profiles (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    bio VARCHAR(1000) NULL,
    city VARCHAR(100) NULL,
    state VARCHAR(100) NULL,
    country VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sellers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    display_name VARCHAR(120) NOT NULL,
    slug VARCHAR(140) NOT NULL UNIQUE,
    bio TEXT NULL,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(100) NOT NULL,
    country VARCHAR(100) NOT NULL DEFAULT 'Belgium',
    rating DECIMAL(2,1) NOT NULL DEFAULT 5.0,
    verified BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_seller_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seller_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(140) NOT NULL,
    slug VARCHAR(160) NOT NULL UNIQUE,
    description TEXT NOT NULL,
    origin VARCHAR(120) NULL,
    dimensions VARCHAR(120) NULL,
    materials VARCHAR(200) NULL,
    care_instructions TEXT NULL,
    category ENUM('Plantas', 'Casa & aromas', 'Cuidados') NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    shipping_weight_grams INT UNSIGNED NOT NULL DEFAULT 1000,
    stock INT UNSIGNED NOT NULL DEFAULT 0,
    image_position TINYINT UNSIGNED NOT NULL DEFAULT 0,
    badge VARCHAR(40) NULL,
    is_marketplace BOOLEAN NOT NULL DEFAULT FALSE,
    featured BOOLEAN NOT NULL DEFAULT FALSE,
    status ENUM('draft', 'active', 'archived') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_seller FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE,
    CONSTRAINT chk_products_price CHECK (price >= 0.01),
    CONSTRAINT chk_products_shipping_weight CHECK (shipping_weight_grams BETWEEN 50 AND 30000),
    CONSTRAINT chk_products_sprite CHECK (image_position <= 3),
    INDEX idx_products_category (category),
    INDEX idx_products_marketplace (is_marketplace),
    INDEX idx_products_public_feed (status, is_marketplace, featured, created_at),
    INDEX idx_products_seller_status (seller_id, status, updated_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment VARCHAR(1000) NOT NULL,
    status ENUM('visible', 'hidden') NOT NULL DEFAULT 'visible',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_review_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_review_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_review_rating CHECK (rating BETWEEN 1 AND 5),
    UNIQUE INDEX uq_product_review_user (product_id, user_id),
    INDEX idx_reviews_product_status (product_id, status, updated_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_user_id BIGINT UNSIGNED NULL,
    action_name VARCHAR(80) NOT NULL,
    target_type VARCHAR(40) NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    previous_value VARCHAR(32) NULL,
    new_value VARCHAR(32) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_target_created (target_type, target_id, created_at),
    INDEX idx_audit_admin_created (admin_user_id, created_at)
) ENGINE=InnoDB;

-- Contas de catálogo não possuem uma senha pública e ficam desabilitadas para login.
INSERT INTO users (id, name, email, password_hash, role, status, email_verified_at) VALUES
    (1, 'Botanical Essence Team', 'shop@botanicalessence.be', '!disabled-catalog-account!', 'seller', 'disabled', CURRENT_TIMESTAMP),
    (2, 'Urban Roots Team', 'hello@urbanroots.be', '!disabled-catalog-account!', 'seller', 'disabled', CURRENT_TIMESTAMP),
    (3, 'Flora Atelier Team', 'hello@flora-atelier.be', '!disabled-catalog-account!', 'seller', 'disabled', CURRENT_TIMESTAMP),
    (4, 'Gentle Growing Team', 'hello@gentlegrowing.be', '!disabled-catalog-account!', 'seller', 'disabled', CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE name = VALUES(name), status = VALUES(status);

INSERT INTO sellers (id, user_id, display_name, slug, bio, city, state, country, rating, verified) VALUES
    (1, 1, 'Botanical Essence', 'botanical-essence', 'A botanical selection for living Belgian homes.', 'Brussels', 'Brussels-Capital', 'Belgium', 5.0, TRUE),
    (2, 2, 'Urban Roots', 'raizes-urbanas', 'Tropical plants grown locally in small batches.', 'Ghent', 'East Flanders', 'Belgium', 4.9, TRUE),
    (3, 3, 'Flora Atelier', 'flora-da-vila', 'Special foliage and handmade pots from Antwerp.', 'Antwerp', 'Antwerp', 'Belgium', 5.0, TRUE),
    (4, 4, 'Gentle Growing', 'cultivo-gentil', 'Natural care products for healthy plants.', 'Liège', 'Liège', 'Belgium', 4.8, TRUE)
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

INSERT INTO products (seller_id, name, slug, description, category, price, shipping_weight_grams, stock, image_position, badge, is_marketplace, featured, status) VALUES
    (1, 'Essential Monstera', 'monstera-essencial', 'Monstera deliciosa in a handcrafted ceramic pot.', 'Plantas', 189.90, 3500, 12, 0, 'Most loved', FALSE, TRUE, 'active'),
    (1, 'Fiddle-leaf Fig', 'ficus-lyrata', 'Medium fiddle-leaf fig, approximately 80 cm tall.', 'Plantas', 229.90, 6000, 8, 1, 'New', FALSE, TRUE, 'active'),
    (3, 'Calathea Orbifolia', 'calathea-orbifolia', 'Naturally grown statement foliage in a small batch.', 'Plantas', 119.90, 2500, 5, 2, NULL, TRUE, TRUE, 'active'),
    (1, 'Botanical Room Mist', 'bruma-botanica', 'Room mist with eucalyptus and rosemary, 120 ml.', 'Casa & aromas', 69.90, 350, 30, 3, 'Exclusive', FALSE, TRUE, 'active'),
    (2, 'Young Monstera', 'costela-de-adao-jovem', 'Selected young plant, approximately 40 cm tall.', 'Plantas', 79.90, 1800, 16, 0, NULL, TRUE, FALSE, 'active'),
    (4, 'Neem Oil', 'oleo-de-neem', 'Concentrated natural plant care, 100 ml.', 'Cuidados', 42.90, 300, 25, 3, NULL, TRUE, FALSE, 'active')
ON DUPLICATE KEY UPDATE price = VALUES(price), shipping_weight_grams = VALUES(shipping_weight_grams), stock = VALUES(stock), status = VALUES(status);
