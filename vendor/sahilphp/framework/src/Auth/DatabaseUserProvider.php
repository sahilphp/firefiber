<?php

declare(strict_types=1);

namespace Sahil\Auth;

use Sahil\Contracts\Auth\AuthenticatableContract;
use Sahil\Contracts\Auth\UserProviderContract;
use Sahil\Database\Connection;

/**
 * Database user provider.
 *
 * Retrieves users from the database and validates their credentials.
 * This is a basic implementation that can be extended for specific
 * application requirements.
 */
final class DatabaseUserProvider implements UserProviderContract
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = 'users',
    ) {
    }

    public function retrieveByIdentifier(int|string $identifier): ?AuthenticatableContract
    {
        $query = sprintf(
            'SELECT * FROM %s WHERE id = :id LIMIT 1',
            $this->connection->driver()->quoteIdentifier($this->table),
        );

        $result = $this->connection->execute($query, ['id' => $identifier]);

        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return new SimpleUser($row);
    }

    public function retrieveByCredentials(array $credentials): ?AuthenticatableContract
    {
        // Extract the identifier (email, username, etc.)
        $identifier = $credentials['email'] ?? $credentials['username'] ?? null;

        if ($identifier === null) {
            return null;
        }

        $query = sprintf(
            'SELECT * FROM %s WHERE email = :email LIMIT 1',
            $this->connection->driver()->quoteIdentifier($this->table),
        );

        $result = $this->connection->execute($query, ['email' => $identifier]);

        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return new SimpleUser($row);
    }

    public function validateCredentials(AuthenticatableContract $user, array $credentials): bool
    {
        $password = $credentials['password'] ?? null;

        if ($password === null) {
            return false;
        }

        return Password::verify($password, $user->getAuthPassword());
    }

    /**
     * @param array<string, mixed> $options
     */
    public function rehashPasswordIfRequired(AuthenticatableContract $user, string $password, array $options): void
    {
        if (Password::needsRehash($user->getAuthPassword(), $options)) {
            $newHash = Password::hash($password, $options);

            $query = sprintf(
                'UPDATE %s SET password = :password WHERE id = :id',
                $this->connection->driver()->quoteIdentifier($this->table),
            );

            $this->connection->execute($query, [
                'password' => $newHash,
                'id' => $user->getAuthIdentifier(),
            ]);
        }
    }
}
