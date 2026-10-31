<?php

declare(strict_types=1);

namespace Sahil\Migrations;

/**
 * The one place that decides whether a destructive operation may proceed.
 *
 * Policy (deliberately conservative, and identical for every command):
 *
 *  1. in `production`, an interactive "yes" is NOT sufficient — the
 *     documented `--force` flag is required, so a production schema change
 *     is always an explicit, recorded act of operator intent (and the
 *     operator is expected to have a verified backup first);
 *  2. everywhere else, `--force` authorises the operation, otherwise the
 *     operator is asked to confirm interactively;
 *  3. a refusal throws BEFORE the database is touched.
 *
 * Irreversible migrations are a second, independent gate
 * ({@see authoriseIrreversible()}) so "cannot be undone" is never silently
 * treated as "not undoable, skipping".
 */
final class MutationGuard
{
    public function __construct(
        private readonly string $environment,
        private readonly ConfirmationContract $confirmation,
    ) {
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function isProduction(): bool
    {
        return strcasecmp($this->environment, 'production') === 0;
    }

    /**
     * @throws DestructiveOperationException when the operation is not authorised
     */
    public function authorise(string $operation, bool $force, string $question): void
    {
        if ($this->isProduction()) {
            if (!$force) {
                throw DestructiveOperationException::requiresForce($operation, $this->environment);
            }

            return;
        }

        if ($force) {
            return;
        }

        if (!$this->confirmation->confirm($question)) {
            throw DestructiveOperationException::needsConfirmation($operation);
        }
    }

    /**
     * A batch that contains forward-only migrations can only be rolled back
     * with the force flag, which is documented as "leave these applied and
     * reverse the rest".
     *
     * @param list<string> $irreversible
     *
     * @throws DestructiveOperationException when $force is false
     */
    public function authoriseIrreversible(string $operation, array $irreversible, bool $force): void
    {
        if ($irreversible === []) {
            return;
        }

        if (!$force) {
            throw DestructiveOperationException::irreversible($operation, $irreversible);
        }
    }
}
