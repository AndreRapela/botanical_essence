<?php

declare(strict_types=1);

final class AccountService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RateLimiter $limiter
    ) {
    }

    public function profile(array $user): array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.name, u.email, p.bio, p.city, p.state AS region, p.country
             FROM users u
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE u.id = :id LIMIT 1'
        );
        $statement->execute([':id' => $user['id']]);
        $profile = $statement->fetch();
        if (!$profile) {
            throw new HttpException('Conta não encontrada.', 404);
        }
        return ['profile' => $profile];
    }

    public function updateProfile(array $input, array $user): array
    {
        Validation::allowedFields($input, ['name', 'bio', 'city', 'region', 'country']);
        $name = Validation::requiredString($input, 'name', 2, 120);
        $bio = Validation::optionalString($input, 'bio', 1000);
        $city = Validation::optionalString($input, 'city', 100);
        $region = Validation::optionalString($input, 'region', 100);
        $country = Validation::optionalString($input, 'country', 100);

        $this->limiter->assertAllowed('account-profile', (string) $user['id'], 40, 3600);
        $this->pdo->beginTransaction();
        try {
            $account = $this->pdo->prepare('UPDATE users SET name = :name WHERE id = :id AND status = \'active\'');
            $account->execute([':name' => $name, ':id' => $user['id']]);
            $profile = $this->pdo->prepare(
                'INSERT INTO user_profiles (user_id, bio, city, state, country)
                 VALUES (:userId, :bio, :city, :region, :country)
                 ON DUPLICATE KEY UPDATE bio = VALUES(bio), city = VALUES(city),
                    state = VALUES(state), country = VALUES(country)'
            );
            $profile->execute([
                ':userId' => $user['id'],
                ':bio' => $bio,
                ':city' => $city,
                ':region' => $region,
                ':country' => $country,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $updatedUser = [
            ...$user,
            'name' => $name,
        ];
        return [
            'message' => 'Perfil atualizado.',
            'profile' => ['name' => $name, 'email' => $user['email'], 'bio' => $bio, 'city' => $city, 'region' => $region, 'country' => $country],
            'user' => $updatedUser,
        ];
    }
}
