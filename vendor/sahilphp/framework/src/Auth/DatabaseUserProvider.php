<?php

declare(strict_types=1);

namespace Sahil\Auth;

use InvalidArgumentException;
use Sahil\Contracts\Auth\AuthenticatableContract;
use Sahil\Contracts\Auth\UserProviderContract;
use Sahil\Database\Connection;
use Sahil\Orm\Model;

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
        private readonly string $modelClass = SimpleUser::class,
    ) {
        if ($modelClass !== SimpleUser::class
            && (!class_exists($modelClass)
                || !is_subclass_of($modelClass, Model::class)
                || !is_subclass_of($modelClass, AuthenticatableContract::class))) {
            throw new InvalidArgumentException(sprintf(
                'Database user model [%s] must extend [%s] and implement [%s].',
                $modelClass,
                Model::class,
                AuthenticatableContract::class,
            ));
        }
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

        return $this->userFromRow($row);
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

        return $this->userFromRow($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function userFromRow(array $row): AuthenticatableContract
    {
        if ($this->modelClass === SimpleUser::class) {
            return new SimpleUser($row);
        }

        if (!is_a($this->modelClass, Model::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Database user model [%s] must extend [%s].',
                $this->modelClass,
                Model::class,
            ));
        }

        $modelClass = $this->modelClass;
        $user = new $modelClass($row, true);

        if (!$user instanceof AuthenticatableContract) {
            throw new InvalidArgumentException(sprintf(
                'Database user model [%s] must implement [%s].',
                $modelClass,
                AuthenticatableContract::class,
            ));
        }

        return $user;
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
