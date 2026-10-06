<?php

declare(strict_types=1);

final class ReviewService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RateLimiter $limiter
    ) {
    }

    public function list(array $query, ?array $currentUser): array
    {
        Validation::allowedFields($query, ['productId', 'page', 'limit']);
        $productId = Validation::queryInteger($query, 'productId', 0, 1, PHP_INT_MAX);
        $page = Validation::queryInteger($query, 'page', 1, 1, 1_000_000);
        $limit = Validation::queryInteger($query, 'limit', 20, 1, 100);
        $offset = ($page - 1) * $limit;
        $product = $this->publicProduct($productId);

        $statement = $this->pdo->prepare(
            "SELECT r.id, r.rating, r.comment, r.created_at AS createdAt, r.updated_at AS updatedAt,
                    'Verified customer' AS authorName,
                    EXISTS(
                        SELECT 1 FROM order_items oi
                        INNER JOIN orders o ON o.id = oi.order_id
                        WHERE oi.product_id = r.product_id AND o.user_id = r.user_id
                          AND o.payment_status = 'paid'
                          AND o.status IN ('confirmed', 'preparing', 'shipped', 'delivered')
                    ) AS verifiedPurchase
             FROM product_reviews r
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.product_id = :productId AND r.status = 'visible'
             ORDER BY r.updated_at DESC, r.id DESC
             LIMIT :limit OFFSET :offset"
        );
        $statement->bindValue(':productId', $productId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $reviews = array_map(fn (array $review): array => $this->serializeReview($review), $statement->fetchAll());

        $myReview = null;
        $canReview = false;
        if ($currentUser !== null) {
            $canReview = ($currentUser['emailVerifiedAt'] ?? null) !== null
                && (int) $product['sellerUserId'] !== (int) $currentUser['id']
                && $this->hasVerifiedPurchase($productId, (int) $currentUser['id']);
            $mine = $this->pdo->prepare(
                "SELECT id, rating, comment, status, created_at AS createdAt, updated_at AS updatedAt
                 FROM product_reviews WHERE product_id = :productId AND user_id = :userId LIMIT 1"
            );
            $mine->execute([':productId' => $productId, ':userId' => $currentUser['id']]);
            $row = $mine->fetch();
            if ($row) {
                $myReview = [
                    ...$row,
                    'id' => (int) $row['id'],
                    'rating' => (int) $row['rating'],
                ];
            }
        }

        $summary = $this->summary($productId);
        return [
            'data' => $reviews,
            'summary' => $summary,
            'page' => $page,
            'limit' => $limit,
            'total' => $summary['count'],
            'hasMore' => $offset + $limit < $summary['count'],
            'canReview' => $canReview,
            'myReview' => $myReview,
        ];
    }

    public function save(array $input, array $user): array
    {
        Validation::allowedFields($input, ['productId', 'rating', 'comment']);
        if (($user['emailVerifiedAt'] ?? null) === null) {
            throw new HttpException('Confirme seu e-mail antes de avaliar.', 403);
        }
        $productId = Validation::integer($input, 'productId', 1, PHP_INT_MAX);
        $rating = Validation::integer($input, 'rating', 1, 5);
        $comment = Validation::requiredString($input, 'comment', 10, 1000);
        $product = $this->publicProduct($productId);
        if ((int) $product['sellerUserId'] === (int) $user['id']) {
            throw new HttpException('Você não pode avaliar um produto da sua própria loja.', 409);
        }
        if (!$this->hasVerifiedPurchase($productId, (int) $user['id'])) {
            throw new HttpException('Somente clientes que compraram este produto podem avaliá-lo.', 403);
        }

        $this->limiter->assertAllowed('product-review', (string) $user['id'], 30, 3600);
        $statement = $this->pdo->prepare(
            "INSERT INTO product_reviews (product_id, user_id, rating, comment, status)
             VALUES (:productId, :userId, :rating, :comment, 'visible')
             ON DUPLICATE KEY UPDATE
                rating = VALUES(rating), comment = VALUES(comment),
                status = IF(status = 'hidden', 'hidden', 'visible'), updated_at = CURRENT_TIMESTAMP"
        );
        $statement->execute([
            ':productId' => $productId,
            ':userId' => $user['id'],
            ':rating' => $rating,
            ':comment' => $comment,
        ]);

        $own = $this->pdo->prepare(
            'SELECT id, status FROM product_reviews WHERE product_id = :productId AND user_id = :userId LIMIT 1'
        );
        $own->execute([':productId' => $productId, ':userId' => $user['id']]);
        $saved = $own->fetch();
        return [
            'message' => $saved['status'] === 'visible'
                ? 'Avaliação publicada.'
                : 'Avaliação atualizada e mantida em moderação.',
            'reviewId' => (int) $saved['id'],
            'status' => $saved['status'],
        ];
    }

    public function adminReviews(array $query = []): array
    {
        Validation::allowedFields($query, ['page', 'limit']);
        $page = Validation::queryInteger($query, 'page', 1, 1, 1_000_000);
        $limit = Validation::queryInteger($query, 'limit', 50, 1, 100);
        $offset = ($page - 1) * $limit;
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM product_reviews')->fetchColumn();
        $queryReviews = $this->pdo->prepare(
            "SELECT r.id, r.product_id AS productId, r.rating, r.comment, r.status,
                    r.created_at AS createdAt, r.updated_at AS updatedAt,
                    u.name AS authorName, u.email AS authorEmail, p.name AS productName
             FROM product_reviews r
             INNER JOIN users u ON u.id = r.user_id
             INNER JOIN products p ON p.id = r.product_id
             ORDER BY r.id DESC
             LIMIT :limit OFFSET :offset"
        );
        $queryReviews->bindValue(':limit', $limit, PDO::PARAM_INT);
        $queryReviews->bindValue(':offset', $offset, PDO::PARAM_INT);
        $queryReviews->execute();
        $reviews = $queryReviews->fetchAll();

        return [
            'data' => array_map(static fn (array $review): array => [
                ...$review,
                'id' => (int) $review['id'],
                'productId' => (int) $review['productId'],
                'rating' => (int) $review['rating'],
            ], $reviews),
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'hasMore' => $offset + $limit < $total,
        ];
    }

    public function moderate(array $input, array $admin): array
    {
        Validation::allowedFields($input, ['reviewId', 'status']);
        $reviewId = Validation::integer($input, 'reviewId', 1, PHP_INT_MAX);
        $status = Validation::oneOf($input, 'status', ['visible', 'hidden']);
        $this->limiter->assertAllowed('admin-write', (string) $admin['id'], 300, 3600);

        $this->pdo->beginTransaction();
        try {
            $target = $this->pdo->prepare('SELECT status FROM product_reviews WHERE id = :id LIMIT 1 FOR UPDATE');
            $target->execute([':id' => $reviewId]);
            $previous = $target->fetchColumn();
            if ($previous === false) {
                throw new HttpException('Avaliação não encontrada.', 404);
            }
            $update = $this->pdo->prepare('UPDATE product_reviews SET status = :status WHERE id = :id');
            $update->execute([':status' => $status, ':id' => $reviewId]);
            $audit = $this->pdo->prepare(
                'INSERT INTO admin_audit_log
                    (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                 VALUES (:adminId, :action, \'review\', :reviewId, :previousValue, :newValue)'
            );
            $audit->execute([
                ':adminId' => $admin['id'],
                ':action' => 'review.moderated',
                ':reviewId' => $reviewId,
                ':previousValue' => $previous,
                ':newValue' => $status,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return ['message' => $status === 'visible' ? 'Avaliação restaurada.' : 'Avaliação ocultada.'];
    }

    private function publicProduct(int $productId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT p.id, s.user_id AS sellerUserId
             FROM products p INNER JOIN sellers s ON s.id = p.seller_id
             WHERE p.id = :id AND p.status = 'active' AND s.verified = TRUE LIMIT 1"
        );
        $statement->execute([':id' => $productId]);
        $product = $statement->fetch();
        if (!$product) {
            throw new HttpException('Produto não encontrado.', 404);
        }
        return $product;
    }

    private function summary(int $productId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT COALESCE(ROUND(AVG(rating), 1), 0) AS average, COUNT(*) AS count
             FROM product_reviews WHERE product_id = :productId AND status = 'visible'"
        );
        $statement->execute([':productId' => $productId]);
        $summary = $statement->fetch();
        return ['average' => (float) $summary['average'], 'count' => (int) $summary['count']];
    }

    private function hasVerifiedPurchase(int $productId, int $userId): bool
    {
        $purchase = $this->pdo->prepare(
            "SELECT 1 FROM order_items oi
             INNER JOIN orders o ON o.id = oi.order_id
             WHERE oi.product_id = :productId AND o.user_id = :userId
               AND o.payment_status = 'paid'
               AND o.status IN ('confirmed', 'preparing', 'shipped', 'delivered')
             LIMIT 1"
        );
        $purchase->execute([':productId' => $productId, ':userId' => $userId]);
        return (bool) $purchase->fetchColumn();
    }

    private function serializeReview(array $review): array
    {
        return [
            ...$review,
            'id' => (int) $review['id'],
            'rating' => (int) $review['rating'],
            'verifiedPurchase' => (bool) $review['verifiedPurchase'],
        ];
    }
}
