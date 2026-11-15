<?php

declare(strict_types=1);

namespace Sahil\Orm;

use InvalidArgumentException;
use RuntimeException;

use function sort;

/**
 * Value object describing one declared relationship. Created only
 * through `Model::belongsTo/hasOne/hasMany/belongsToMany`.
 */
final class Relation
{
    private function __construct(
        public readonly string $type,
        public readonly string $parentClass,
        public readonly string $relatedClass,
        public readonly ?string $foreignKey,
        public readonly ?string $ownerKey,
        public readonly ?string $pivotTable,
        public readonly ?string $pivotSelfKey,
        public readonly ?string $pivotRelatedKey,
    ) {
        if (!class_exists($relatedClass)) {
            throw new InvalidArgumentException("Related model [$relatedClass] does not exist.");
        }

        if (!in_array($type, ['belongsTo', 'hasOne', 'hasMany', 'belongsToMany'], true)) {
            throw new RuntimeException("Unknown relation type [$type].");
        }
    }

    /**
     * Conventional pivot table: "role_user" — both basenames sorted.
     */
    private static function standardPivot(string $parentClass, string $relatedClass): string
    {
        $names = [self::basename($parentClass), self::basename($relatedClass)];
        sort($names);

        return implode('_', $names);
    }

    /**
     * Sane snake-cased naming helper (used by Model's factories).
     */
    public static function snake(string $value): string
    {
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $value) ?? '');

        return str_replace('__', '_', $snake);
    }

    public static function basename(string $class): string
    {
        $segments = explode('\\', $class);

        return (string) end($segments);
    }

    /** @internal used by Model */
    public static function make(
        string $type,
        string $parentClass,
        string $relatedClass,
        ?string $foreignKey = null,
        ?string $ownerKey = null,
        ?string $pivotTable = null,
        ?string $pivotSelfKey = null,
        ?string $pivotRelatedKey = null,
    ): self {
        return match ($type) {
            'belongsTo' => new self(
                $type,
                $parentClass,
                $relatedClass,
                $foreignKey ?? self::snake(self::basename($relatedClass)).'_id',
                $ownerKey ?? 'id',
                null,
                null,
                null,
            ),
            'hasOne', 'hasMany' => new self(
                $type,
                $parentClass,
                $relatedClass,
                $foreignKey ?? self::snake(self::basename($parentClass)).'_id',
                $ownerKey ?? 'id',
                null,
                null,
                null,
            ),
            'belongsToMany' => new self(
                $type,
                $parentClass,
                $relatedClass,
                null,
                null,
                $pivotTable ?? self::standardPivot($parentClass, $relatedClass),
                $pivotSelfKey ?? self::snake(self::basename($parentClass)).'_id',
                $pivotRelatedKey ?? self::snake(self::basename($relatedClass)).'_id',
            ),
            default => throw new RuntimeException("Unknown relation type [$type]."),
        };
    }
}
