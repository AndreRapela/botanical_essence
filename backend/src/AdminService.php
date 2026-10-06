<?php

declare(strict_types=1);

final class AdminService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RateLimiter $limiter,
        private readonly ?Mailer $mailer = null,
        private readonly ?\Closure $verificationEmailSender = null
    ) {
    }

    public function overview(array $query = []): array
    {
        Validation::allowedFields($query, ['page', 'limit']);
        $page = Validation::queryInteger($query, 'page', 1, 1, 1_000_000);
        $limit = Validation::queryInteger($query, 'limit', 50, 1, 100);
        $offset = ($page - 1) * $limit;

        $userTotal = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $userQuery = $this->pdo->prepare(
            'SELECT id, name, email, role, status, is_demo AS isDemo,
                    email_verified_at AS emailVerifiedAt, created_at AS createdAt
             FROM users ORDER BY id DESC LIMIT :limit OFFSET :offset'
        );
        $userQuery->bindValue(':limit', $limit, PDO::PARAM_INT);
        $userQuery->bindValue(':offset', $offset, PDO::PARAM_INT);
        $userQuery->execute();
        $users = $userQuery->fetchAll();

        $productTotal = (int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
        $productQuery = $this->pdo->prepare(
            'SELECT p.id, p.name, p.description, p.origin, p.dimensions, p.materials,
                    p.care_instructions AS careInstructions, p.category, p.price,
                    p.shipping_weight_grams AS shippingWeightGrams, p.stock,
                    p.image_position AS sprite, p.badge, p.status,
                    p.is_marketplace AS marketplace, p.featured, p.updated_at AS updatedAt,
                    s.id AS sellerId, s.display_name AS sellerName
             FROM products p INNER JOIN sellers s ON s.id = p.seller_id
             ORDER BY p.id DESC LIMIT :limit OFFSET :offset'
        );
        $productQuery->bindValue(':limit', $limit, PDO::PARAM_INT);
        $productQuery->bindValue(':offset', $offset, PDO::PARAM_INT);
        $productQuery->execute();
        $products = $productQuery->fetchAll();

        return [
            'users' => array_map(fn (array $user): array => $this->serializeUser($user), $users),
            'products' => array_map(fn (array $product): array => $this->serializeProduct($product), $products),
            'page' => $page,
            'limit' => $limit,
            'usersTotal' => $userTotal,
            'usersHasMore' => $offset + $limit < $userTotal,
            'productsTotal' => $productTotal,
            'productsHasMore' => $offset + $limit < $productTotal,
        ];
    }

    public function auditLog(array $query = []): array
    {
        Validation::allowedFields($query, ['page', 'limit']);
        $page = Validation::queryInteger($query, 'page', 1, 1, 1_000_000);
        $limit = Validation::queryInteger($query, 'limit', 50, 1, 100);
        $offset = ($page - 1) * $limit;
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT log.id, log.admin_user_id AS adminUserId, log.action_name AS action,
                    log.target_type AS targetType, log.target_id AS targetId,
                    log.previous_value AS previousValue, log.new_value AS newValue,
                    log.created_at AS createdAt, actor.name AS adminName, actor.email AS adminEmail
             FROM admin_audit_log log
             LEFT JOIN users actor ON actor.id = log.admin_user_id
             ORDER BY log.id DESC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [
            'data' => array_map(static fn (array $entry): array => [
                ...$entry,
                'id' => (int) $entry['id'],
                'adminUserId' => $entry['adminUserId'] === null ? null : (int) $entry['adminUserId'],
                'targetId' => (int) $entry['targetId'],
            ], $statement->fetchAll()),
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'hasMore' => $offset + $limit < $total,
        ];
    }

    public function updateUser(array $input, array $admin): array
    {
        Validation::allowedFields($input, ['id', 'name', 'email', 'role', 'status', 'emailVerified']);
        $id = Validation::integer($input, 'id', 1, PHP_INT_MAX);
        $name = Validation::requiredString($input, 'name', 2, 120);
        $email = Validation::email($input);
        $role = Validation::oneOf($input, 'role', ['customer', 'seller', 'admin']);
        $status = Validation::oneOf($input, 'status', ['pending', 'active', 'disabled']);
        $emailVerified = Validation::boolean($input, 'emailVerified');
        if ($status === 'active' && !$emailVerified) {
            throw new HttpException('Uma conta ativa precisa ter o e-mail confirmado.', 422);
        }
        $this->limiter->assertAllowed('admin-write', (string) $admin['id'], 300, 3600);

        // Serialize membership changes across all app workers. Row locks alone do not
        // protect the "at least one active admin" invariant when two admins demote
        // different accounts concurrently.
        $membershipLock = $this->pdo->query("SELECT GET_LOCK('botanical_admin_membership', 10)")->fetchColumn();
        if ((int) $membershipLock !== 1) {
            throw new HttpException('Não foi possível reservar a operação administrativa; tente novamente.', 503);
        }
        $verificationUrl = null;
        try {
            $this->pdo->beginTransaction();
            try {
                // Authorization before waiting for the membership lock can become stale.
                // Lock the actor as well, so a revoked session cannot restore its own role.
                $actorStatement = $this->pdo->prepare(
                    'SELECT role, status, email_verified_at, auth_version
                     FROM users WHERE id = :id LIMIT 1 FOR UPDATE'
                );
                $actorStatement->execute([':id' => $admin['id']]);
                $actor = $actorStatement->fetch();
                if (!$actor || $actor['role'] !== 'admin' || $actor['status'] !== 'active'
                    || $actor['email_verified_at'] === null
                    || (int) $actor['auth_version'] !== (int) ($admin['authVersion'] ?? 0)) {
                    throw new HttpException('Seu acesso administrativo mudou; entre novamente.', 403);
                }
                $targetStatement = $this->pdo->prepare(
                    'SELECT id, name, email, role, status, is_demo AS isDemo,
                            email_verified_at AS emailVerifiedAt
                     FROM users WHERE id = :id LIMIT 1 FOR UPDATE'
                );
                $targetStatement->execute([':id' => $id]);
                $target = $targetStatement->fetch();
                if (!$target) {
                    throw new HttpException('Conta não encontrada.', 404);
                }
                if ((bool) $target['isDemo']) {
                    throw new HttpException('As contas de demonstração são protegidas e recriadas automaticamente.', 409);
                }
                $emailChanged = $target['email'] !== $email;
                if ($id === (int) $admin['id'] && $emailChanged) {
                    throw new HttpException('Outro administrador precisa alterar seu e-mail para evitar bloquear seu próprio acesso.', 409);
                }
                $effectiveStatus = $emailChanged ? 'pending' : $status;
                $effectiveEmailVerified = $emailChanged ? false : $emailVerified;
                if ($id === (int) $admin['id'] && ($role !== 'admin' || $effectiveStatus !== 'active' || !$effectiveEmailVerified)) {
                    throw new HttpException('Você não pode remover seu próprio acesso administrativo.', 409);
                }
                $removesAdmin = $target['role'] === 'admin'
                    && $target['status'] === 'active'
                    && $target['emailVerifiedAt'] !== null
                    && ($role !== 'admin' || $effectiveStatus !== 'active' || !$effectiveEmailVerified);
                if ($removesAdmin) {
                    $otherAdmins = $this->pdo->prepare(
                        "SELECT COUNT(*) FROM users
                         WHERE id <> :id AND role = 'admin' AND status = 'active' AND email_verified_at IS NOT NULL"
                    );
                    $otherAdmins->execute([':id' => $id]);
                    if ((int) $otherAdmins->fetchColumn() < 1) {
                        throw new HttpException('É necessário manter ao menos um administrador ativo.', 409);
                    }
                }

                $securityChanged = $emailChanged
                    || $target['role'] !== $role
                    || $target['status'] !== $effectiveStatus
                    || ($target['emailVerifiedAt'] !== null) !== $effectiveEmailVerified;
                $verifiedAt = $effectiveEmailVerified
                    ? ($target['email'] === $email && $target['emailVerifiedAt'] !== null
                        ? $target['emailVerifiedAt']
                        : date('Y-m-d H:i:s'))
                    : null;
                $update = $this->pdo->prepare(
                    'UPDATE users
                     SET name = :name, email = :email, role = :role, status = :status,
                         email_verified_at = :emailVerifiedAt, auth_version = auth_version + :authVersionIncrement
                     WHERE id = :id'
                );
                $update->execute([
                    ':name' => $name,
                    ':email' => $email,
                    ':role' => $role,
                    ':status' => $effectiveStatus,
                    ':emailVerifiedAt' => $verifiedAt,
                    ':authVersionIncrement' => $securityChanged ? 1 : 0,
                    ':id' => $id,
                ]);
                if ($securityChanged) {
                    $tokens = $this->pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = :userId');
                    $tokens->execute([':userId' => $id]);
                    $resetTokens = $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :userId');
                    $resetTokens->execute([':userId' => $id]);
                }
                if ($effectiveStatus === 'pending' && !$effectiveEmailVerified) {
                    $this->pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = :userId')
                        ->execute([':userId' => $id]);
                    $plainToken = bin2hex(random_bytes(32));
                    $token = $this->pdo->prepare(
                        'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
                         VALUES (:userId, :hash, CURRENT_TIMESTAMP + INTERVAL 24 HOUR)'
                    );
                    $token->execute([':userId' => $id, ':hash' => hash('sha256', $plainToken)]);
                    $verificationUrl = rtrim(getenv('APP_URL') ?: 'http://localhost:4300', '/')
                        . '/verify-email#token=' . rawurlencode($plainToken);
                }
                if ($effectiveStatus !== 'active') {
                    $revokeStore = $this->pdo->prepare(
                        'UPDATE sellers SET verified = FALSE WHERE user_id = :userId'
                    );
                    $revokeStore->execute([':userId' => $id]);
                    $hideProducts = $this->pdo->prepare(
                        "UPDATE products p INNER JOIN sellers s ON s.id = p.seller_id
                         SET p.status = 'draft'
                         WHERE s.user_id = :userId AND p.status = 'active'"
                    );
                    $hideProducts->execute([':userId' => $id]);
                }
                $this->audit(
                    (int) $admin['id'],
                    'user.updated',
                    'user',
                    $id,
                    $this->auditSnapshot([
                        'name' => $target['name'],
                        'email' => $target['email'],
                        'role' => $target['role'],
                        'status' => $target['status'],
                        'emailVerified' => $target['emailVerifiedAt'] !== null,
                    ]),
                    $this->auditSnapshot([
                        'name' => $name,
                        'email' => $email,
                        'role' => $role,
                        'status' => $effectiveStatus,
                        'emailVerified' => $effectiveEmailVerified,
                    ])
                );
                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
        } finally {
            try {
                $this->pdo->query("SELECT RELEASE_LOCK('botanical_admin_membership')");
            } catch (Throwable $exception) {
                error_log('Falha ao liberar trava de associação administrativa: ' . $exception->getMessage());
            }
        }

        if ($verificationUrl !== null) {
            try {
                if ($this->verificationEmailSender !== null) {
                    ($this->verificationEmailSender)($email, $name, $verificationUrl);
                } else {
                    ($this->mailer ?? new Mailer())->send(
                        $email,
                        'Confirme seu e-mail Botanical Essence',
                        "Olá, {$name}!\n\nConfirme o endereço de e-mail da conta: {$verificationUrl}\n\nSua conta ficará pendente até essa confirmação. O link expira em 24 horas."
                    );
                }
            } catch (Throwable $exception) {
                error_log('Falha ao enviar verificação do novo e-mail: ' . $exception->getMessage());
            }
        }

        return [
            'message' => $verificationUrl === null
                ? 'Conta atualizada.'
                : 'Conta atualizada; o novo e-mail precisa ser confirmado.',
            'user' => $this->userById($id),
        ];
    }

    public function updateProduct(array $input, array $admin): array
    {
        Validation::allowedFields($input, [
            'id', 'name', 'description', 'origin', 'dimensions', 'materials', 'careInstructions',
            'category', 'price', 'shippingWeightGrams', 'stock', 'sprite', 'badge',
            'status', 'marketplace', 'featured',
        ]);
        $id = Validation::integer($input, 'id', 1, PHP_INT_MAX);
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
        $status = Validation::oneOf($input, 'status', ['draft', 'active', 'archived']);
        $marketplace = Validation::boolean($input, 'marketplace');
        $featured = Validation::boolean($input, 'featured');
        $this->limiter->assertAllowed('admin-write', (string) $admin['id'], 300, 3600);
        $databaseCategory = ['plants' => 'Plantas', 'home' => 'Casa & aromas', 'care' => 'Cuidados'][$category];

        $this->pdo->beginTransaction();
        try {
            $target = $this->pdo->prepare(
                'SELECT name, description, origin, dimensions, materials, care_instructions AS careInstructions,
                        category, price, shipping_weight_grams AS shippingWeightGrams, stock,
                        image_position AS sprite, badge, status, is_marketplace AS marketplace, featured
                 FROM products WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $target->execute([':id' => $id]);
            $previous = $target->fetch();
            if (!$previous) {
                throw new HttpException('Produto não encontrado.', 404);
            }
            $update = $this->pdo->prepare(
                'UPDATE products SET name = :name, description = :description, origin = :origin,
                    dimensions = :dimensions, materials = :materials, care_instructions = :careInstructions,
                    category = :category,
                    price = :price, shipping_weight_grams = :shippingWeightGrams,
                    stock = :stock, image_position = :sprite, badge = :badge,
                    status = :status, is_marketplace = :marketplace, featured = :featured
                 WHERE id = :id'
            );
            $update->execute([
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
                ':marketplace' => $marketplace ? 1 : 0,
                ':featured' => $featured ? 1 : 0,
                ':id' => $id,
            ]);
            $this->audit(
                (int) $admin['id'],
                'product.updated',
                'product',
                $id,
                $this->auditSnapshot($previous),
                $this->auditSnapshot([
                    'name' => $name,
                    'description' => $description,
                    'origin' => $origin,
                    'dimensions' => $dimensions,
                    'materials' => $materials,
                    'careInstructions' => $careInstructions,
                    'category' => $databaseCategory,
                    'price' => $price,
                    'shippingWeightGrams' => $shippingWeightGrams,
                    'stock' => $stock,
                    'sprite' => $sprite,
                    'badge' => $badge,
                    'status' => $status,
                    'marketplace' => $marketplace,
                    'featured' => $featured,
                ])
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return ['message' => 'Produto atualizado.', 'product' => $this->productById($id)];
    }

    public function updateStore(array $input, array $admin): array
    {
        Validation::allowedFields($input, ['id', 'displayName', 'bio', 'city', 'region', 'country']);
        $id = Validation::integer($input, 'id', 1, PHP_INT_MAX);
        $displayName = Validation::requiredString($input, 'displayName', 2, 120);
        $bio = Validation::optionalString($input, 'bio', 1000);
        $city = Validation::requiredString($input, 'city', 2, 100);
        $region = Validation::requiredString($input, 'region', 2, 100);
        $country = Validation::requiredString($input, 'country', 2, 100);
        $this->limiter->assertAllowed('admin-write', (string) $admin['id'], 300, 3600);
        $this->pdo->beginTransaction();
        try {
            $target = $this->pdo->prepare(
                'SELECT display_name AS displayName, bio, city, state AS region, country
                 FROM sellers WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $target->execute([':id' => $id]);
            $previous = $target->fetch();
            if (!$previous) {
                throw new HttpException('Loja não encontrada.', 404);
            }
            $statement = $this->pdo->prepare(
                'UPDATE sellers SET display_name = :displayName, bio = :bio, city = :city,
                    state = :region, country = :country WHERE id = :id'
            );
            $statement->execute(compact('displayName', 'bio', 'city', 'region', 'country', 'id'));
            $this->audit(
                (int) $admin['id'],
                'seller.updated',
                'seller',
                $id,
                $this->auditSnapshot($previous),
                $this->auditSnapshot(compact('displayName', 'bio', 'city', 'region', 'country'))
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return ['message' => 'Loja atualizada.'];
    }

    private function userById(int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, email, role, status, is_demo AS isDemo,
                    email_verified_at AS emailVerifiedAt, created_at AS createdAt
             FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute([':id' => $id]);
        $user = $statement->fetch();
        if (!$user) {
            throw new RuntimeException('A conta salva não pôde ser recuperada.');
        }
        return $this->serializeUser($user);
    }

    private function productById(int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.name, p.description, p.origin, p.dimensions, p.materials,
                    p.care_instructions AS careInstructions, p.category, p.price,
                    p.shipping_weight_grams AS shippingWeightGrams, p.stock,
                    p.image_position AS sprite, p.badge, p.status,
                    p.is_marketplace AS marketplace, p.featured, p.updated_at AS updatedAt,
                    s.id AS sellerId, s.display_name AS sellerName
             FROM products p INNER JOIN sellers s ON s.id = p.seller_id
             WHERE p.id = :id LIMIT 1'
        );
        $statement->execute([':id' => $id]);
        $product = $statement->fetch();
        if (!$product) {
            throw new RuntimeException('O produto salvo não pôde ser recuperado.');
        }
        return $this->serializeProduct($product);
    }

    private function serializeUser(array $user): array
    {
        return [
            ...$user,
            'id' => (int) $user['id'],
            'isDemo' => (bool) $user['isDemo'],
            'emailVerified' => $user['emailVerifiedAt'] !== null,
        ];
    }

    private function serializeProduct(array $product): array
    {
        $category = ['Plantas' => 'plants', 'Casa & aromas' => 'home', 'Cuidados' => 'care'][(string) $product['category']] ?? 'care';
        return [
            ...$product,
            'id' => (int) $product['id'],
            'sellerId' => (int) $product['sellerId'],
            'category' => $category,
            'price' => (float) $product['price'],
            'shippingWeightGrams' => (int) $product['shippingWeightGrams'],
            'stock' => (int) $product['stock'],
            'sprite' => (int) $product['sprite'],
            'marketplace' => (bool) $product['marketplace'],
            'featured' => (bool) $product['featured'],
        ];
    }

    private function audit(
        int $adminId,
        string $action,
        string $targetType,
        int $targetId,
        ?string $previous,
        ?string $new
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO admin_audit_log
                (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
             VALUES (:adminId, :action, :targetType, :targetId, :previousValue, :newValue)'
        );
        $statement->execute([
            ':adminId' => $adminId,
            ':action' => $action,
            ':targetType' => $targetType,
            ':targetId' => $targetId,
            ':previousValue' => $previous,
            ':newValue' => $new,
        ]);
    }

    private function auditSnapshot(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
