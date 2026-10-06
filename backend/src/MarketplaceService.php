<?php

declare(strict_types=1);

final class MarketplaceService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RateLimiter $limiter,
        private readonly AdminNotificationService $adminNotifications
    ) {
    }

    public function dashboard(array $user): array
    {
        $store = $this->storeForUser((int) $user['id'], false);
        if ($store === null) {
            return ['store' => null, 'products' => []];
        }
        $products = $this->pdo->prepare(
            "SELECT id, name, description, origin, dimensions, materials,
                    care_instructions AS careInstructions, category, price,
                    shipping_weight_grams AS shippingWeightGrams, stock, image_position AS sprite,
                    badge, status, updated_at AS updatedAt
             FROM products
             WHERE seller_id = :sellerId AND status <> 'archived'
             ORDER BY updated_at DESC, id DESC"
        );
        $products->execute([':sellerId' => $store['id']]);

        return [
            'store' => $this->serializeStore($store),
            'products' => array_map(fn (array $product): array => $this->serializeProduct($product), $products->fetchAll()),
        ];
    }

    public function updateStore(array $input, array $user): array
    {
        Validation::allowedFields($input, ['displayName', 'bio', 'city', 'region', 'country']);
        $displayName = Validation::requiredString($input, 'displayName', 2, 120);
        $bio = Validation::optionalString($input, 'bio', 1000);
        $city = Validation::requiredString($input, 'city', 2, 100);
        $region = Validation::requiredString($input, 'region', 2, 100);
        $country = Validation::requiredString($input, 'country', 2, 100);

        $this->limiter->assertAllowed('seller-write', (string) $user['id'], 120, 3600);
        $existing = $this->storeForUser((int) $user['id'], false);
        if ($existing === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO sellers (user_id, display_name, slug, bio, city, state, country, verified)
                 VALUES (:userId, :displayName, :slug, :bio, :city, :region, :country, FALSE)'
            );
            $statement->execute([
                ':userId' => $user['id'],
                ':displayName' => $displayName,
                ':slug' => $this->uniqueSlug($displayName),
                ':bio' => $bio,
                ':city' => $city,
                ':region' => $region,
                ':country' => $country,
            ]);
            $message = 'Perfil de vendas criado e enviado para aprovação.';
            $status = 201;
            $this->adminNotifications->sellerAwaitingApprovalForUser((int) $user['id']);
        } else {
            $statement = $this->pdo->prepare(
                'UPDATE sellers
                 SET display_name = :displayName, bio = :bio, city = :city,
                     state = :region, country = :country
                 WHERE user_id = :userId'
            );
            $statement->execute([
                ':displayName' => $displayName,
                ':bio' => $bio,
                ':city' => $city,
                ':region' => $region,
                ':country' => $country,
                ':userId' => $user['id'],
            ]);
            $message = 'Dados da loja atualizados.';
            $status = 200;
        }

        return [
            'message' => $message,
            'store' => $this->serializeStore($this->storeForUser((int) $user['id'])),
            '_status' => $status,
        ];
    }

    public function saveProduct(array $input, array $user): array
    {
        Validation::allowedFields($input, [
            'id', 'name', 'description', 'origin', 'dimensions', 'materials', 'careInstructions',
            'category', 'price', 'shippingWeightGrams', 'stock', 'sprite', 'badge', 'status',
        ]);
        $name = Validation::requiredString($input, 'name', 2, 140);
        $description = Validation::requiredString($input, 'description', 10, 2000);
        $origin = Validation::optionalString($input, 'origin', 120);
        $dimensions = Validation::optionalString($input, 'dimensions', 120);
        $materials = Validation::optionalString($input, 'materials', 200);
        $careInstructions = Validation::optionalString($input, 'careInstructions', 2000);
        $category = Validation::oneOf($input, 'category', ['plants', 'home', 'care']);
        $price = Validation::money($input);
        $shippingWeightGrams = Validation::integer($input, 'shippingWeightGrams', 50, 30_000);
        $stock = Validation::integer($input, 'stock', 0, 100_000);
        $sprite = Validation::integer($input, 'sprite', 0, 3);
        $badge = Validation::optionalString($input, 'badge', 40);
        $status = Validation::oneOf($input, 'status', ['draft', 'active']);
        $this->limiter->assertAllowed('seller-write', (string) $user['id'], 120, 3600);
        $databaseCategory = [
            'plants' => 'Plantas',
            'home' => 'Casa & aromas',
            'care' => 'Cuidados',
        ][$category];

        $id = $input['id'] ?? null;
        if ($id !== null && (!is_int($id) || $id < 1)) {
            throw new HttpException('Produto inválido.', 422, ['id' => 'Identificador inválido.']);
        }

        $this->pdo->beginTransaction();
        try {
            // Serialize seller writes with approval/revocation. Otherwise a stale
            // seller request could reactivate a listing after an admin has
            // unpublished it, and a future approval would expose it again.
            $store = $this->lockStoreForUser((int) $user['id']);
            if (!(bool) $store['verified']) {
                $status = 'draft';
            }

            if ($id !== null) {
                $statement = $this->pdo->prepare(
                    "UPDATE products
                     SET name = :name, description = :description, origin = :origin,
                         dimensions = :dimensions, materials = :materials, care_instructions = :careInstructions,
                         category = :category, price = :price, shipping_weight_grams = :shippingWeightGrams,
                         stock = :stock, image_position = :sprite, badge = :badge, status = :status,
                         is_marketplace = TRUE
                     WHERE id = :id AND seller_id = :sellerId AND status <> 'archived'"
                );
                $statement->execute([
                    ':name' => $name,
                    ':description' => $description,
                    ':origin' => $origin,
                    ':dimensions' => $dimensions,
                    ':materials' => $materials,
                    ':careInstructions' => $careInstructions,
                    ':category' => $databaseCategory,
                    ':price' => $price,
                    ':shippingWeightGrams' => $shippingWeightGrams,
                    ':stock' => $stock,
                    ':sprite' => $sprite,
                    ':badge' => $badge,
                    ':status' => $status,
                    ':id' => $id,
                    ':sellerId' => $store['id'],
                ]);
                if ($statement->rowCount() === 0) {
                    $check = $this->pdo->prepare(
                        "SELECT 1 FROM products WHERE id = :id AND seller_id = :sellerId AND status <> 'archived'"
                    );
                    $check->execute([':id' => $id, ':sellerId' => $store['id']]);
                    if (!$check->fetchColumn()) {
                        throw new HttpException('Produto não encontrado.', 404);
                    }
                }
                $productId = $id;
                $httpStatus = 200;
            } else {
                $count = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM products WHERE seller_id = :sellerId AND status <> 'archived'"
                );
                $count->execute([':sellerId' => $store['id']]);
                if ((int) $count->fetchColumn() >= 250) {
                    throw new HttpException('Limite de produtos da loja atingido.', 409);
                }
                $statement = $this->pdo->prepare(
                    'INSERT INTO products
                       (seller_id, name, slug, description, origin, dimensions, materials, care_instructions,
                        category, price, shipping_weight_grams, stock, image_position, badge,
                        is_marketplace, featured, status)
                     VALUES
                       (:sellerId, :name, :slug, :description, :origin, :dimensions, :materials, :careInstructions,
                        :category, :price, :shippingWeightGrams, :stock, :sprite, :badge,
                        TRUE, FALSE, :status)'
                );
                $statement->execute([
                    ':sellerId' => $store['id'],
                    ':name' => $name,
                    ':slug' => $this->uniqueSlug($name),
                    ':description' => $description,
                    ':origin' => $origin,
                    ':dimensions' => $dimensions,
                    ':materials' => $materials,
                    ':careInstructions' => $careInstructions,
                    ':category' => $databaseCategory,
                    ':price' => $price,
                    ':shippingWeightGrams' => $shippingWeightGrams,
                    ':stock' => $stock,
                    ':sprite' => $sprite,
                    ':badge' => $badge,
                    ':status' => $status,
                ]);
                $productId = (int) $this->pdo->lastInsertId();
                $httpStatus = 201;
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'message' => $httpStatus === 201 ? 'Produto criado.' : 'Produto atualizado.',
            'product' => $this->productForSeller($productId, (int) $store['id']),
            '_status' => $httpStatus,
        ];
    }

    public function archiveProduct(array $input, array $user): array
    {
        Validation::allowedFields($input, ['id']);
        $id = Validation::integer($input, 'id', 1, PHP_INT_MAX);
        $store = $this->storeForUser((int) $user['id']);
        $this->limiter->assertAllowed('seller-write', (string) $user['id'], 120, 3600);
        $statement = $this->pdo->prepare(
            "UPDATE products SET status = 'archived', stock = 0
             WHERE id = :id AND seller_id = :sellerId AND status <> 'archived'"
        );
        $statement->execute([':id' => $id, ':sellerId' => $store['id']]);
        if ($statement->rowCount() !== 1) {
            throw new HttpException('Produto não encontrado.', 404);
        }
        return ['message' => 'Produto arquivado.'];
    }

    public function adminSellers(array $query = []): array
    {
        Validation::allowedFields($query, ['page', 'limit']);
        $page = Validation::queryInteger($query, 'page', 1, 1, 1_000_000);
        $limit = Validation::queryInteger($query, 'limit', 50, 1, 100);
        $offset = ($page - 1) * $limit;
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM sellers')->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT s.id, s.display_name AS displayName, s.bio, s.city, s.state AS region, s.country, s.verified,
                    u.name AS ownerName, u.email, u.status AS accountStatus,
                    COUNT(p.id) AS productCount
             FROM sellers s
             INNER JOIN users u ON u.id = s.user_id
             LEFT JOIN products p ON p.seller_id = s.id AND p.status <> \'archived\'
             GROUP BY s.id, u.id
             ORDER BY s.verified ASC, s.created_at ASC, s.id ASC
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        return [
            'data' => array_map(static fn (array $seller): array => [
                ...$seller,
                'id' => (int) $seller['id'],
                'verified' => (bool) $seller['verified'],
                'productCount' => (int) $seller['productCount'],
            ], $statement->fetchAll()),
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'hasMore' => $offset + $limit < $total,
        ];
    }

    public function setSellerVerification(array $input, array $admin): array
    {
        Validation::allowedFields($input, ['sellerId', 'verified']);
        $sellerId = Validation::integer($input, 'sellerId', 1, PHP_INT_MAX);
        $verified = Validation::boolean($input, 'verified');
        $this->limiter->assertAllowed('admin-marketplace', (string) $admin['id'], 200, 3600);

        $this->pdo->beginTransaction();
        try {
            $target = $this->pdo->prepare(
                'SELECT s.verified, u.role, u.status, u.email_verified_at AS emailVerifiedAt
                 FROM sellers s INNER JOIN users u ON u.id = s.user_id
                 WHERE s.id = :id LIMIT 1 FOR UPDATE'
            );
            $target->execute([':id' => $sellerId]);
            $seller = $target->fetch();
            if (!$seller) {
                throw new HttpException('Vendedor não encontrado.', 404);
            }
            if ($verified && ($seller['status'] !== 'active' || $seller['emailVerifiedAt'] === null)) {
                throw new HttpException('A conta do vendedor precisa estar ativa e com e-mail confirmado.', 409);
            }
            $previous = (bool) $seller['verified'];
            if ($previous === $verified) {
                $this->pdo->commit();
                return ['message' => $verified ? 'Vendedor já estava aprovado.' : 'Aprovação já estava removida.'];
            }
            $statement = $this->pdo->prepare('UPDATE sellers SET verified = :verified WHERE id = :id');
            $statement->execute([':verified' => $verified ? 1 : 0, ':id' => $sellerId]);
            if (!$verified) {
                $unpublish = $this->pdo->prepare(
                    "UPDATE products SET status = 'draft' WHERE seller_id = :sellerId AND status = 'active'"
                );
                $unpublish->execute([':sellerId' => $sellerId]);
            }
            $audit = $this->pdo->prepare(
                'INSERT INTO admin_audit_log
                    (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                 VALUES (:adminId, :action, \'seller\', :sellerId, :previousValue, :newValue)'
            );
            $audit->execute([
                ':adminId' => $admin['id'],
                ':action' => $verified ? 'seller.approved' : 'seller.approval_removed',
                ':sellerId' => $sellerId,
                ':previousValue' => $previous ? 'approved' : 'pending',
                ':newValue' => $verified ? 'approved' : 'pending',
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return ['message' => $verified ? 'Vendedor aprovado.' : 'Aprovação do vendedor removida.'];
    }

    private function storeForUser(int $userId, bool $required = true): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, display_name AS displayName, slug, bio, city, state AS region, country, rating, verified
             FROM sellers WHERE user_id = :userId LIMIT 1'
        );
        $statement->execute([':userId' => $userId]);
        $store = $statement->fetch();
        if (!$store) {
            if (!$required) {
                return null;
            }
            throw new HttpException('Loja de vendedor não encontrada.', 404);
        }
        return $store;
    }

    private function lockStoreForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, display_name AS displayName, slug, bio, city, state AS region, country, rating, verified
             FROM sellers WHERE user_id = :userId LIMIT 1 FOR UPDATE'
        );
        $statement->execute([':userId' => $userId]);
        $store = $statement->fetch();
        if (!$store) {
            throw new HttpException('Loja de vendedor não encontrada.', 404);
        }
        return $store;
    }

    private function productForSeller(int $id, int $sellerId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, name, description, origin, dimensions, materials,
                    care_instructions AS careInstructions, category, price,
                    shipping_weight_grams AS shippingWeightGrams, stock, image_position AS sprite,
                    badge, status, updated_at AS updatedAt
             FROM products WHERE id = :id AND seller_id = :sellerId AND status <> 'archived' LIMIT 1"
        );
        $statement->execute([':id' => $id, ':sellerId' => $sellerId]);
        $product = $statement->fetch();
        if (!$product) {
            throw new RuntimeException('O produto salvo não pôde ser recuperado.');
        }
        return $this->serializeProduct($product);
    }

    private function serializeStore(array $store): array
    {
        return [
            ...$store,
            'id' => (int) $store['id'],
            'rating' => (float) $store['rating'],
            'verified' => (bool) $store['verified'],
        ];
    }

    private function serializeProduct(array $product): array
    {
        $category = [
            'Plantas' => 'plants',
            'Casa & aromas' => 'home',
            'Cuidados' => 'care',
        ][(string) $product['category']] ?? 'care';
        return [
            ...$product,
            'id' => (int) $product['id'],
            'category' => $category,
            'price' => (float) $product['price'],
            'shippingWeightGrams' => (int) $product['shippingWeightGrams'],
            'stock' => (int) $product['stock'],
            'sprite' => (int) $product['sprite'],
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name);
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', $base), '-');
        return substr($base !== '' ? $base : 'produto', 0, 145) . '-' . bin2hex(random_bytes(5));
    }
}
