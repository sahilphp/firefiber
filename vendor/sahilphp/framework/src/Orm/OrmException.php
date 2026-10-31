<?php

declare(strict_types=1);

namespace Sahil\Orm;

use RuntimeException;

/**
 * Thrown for model misuse: unknown relations, relation names clashing
 * with attributes, missing primary keys — always loud, never silent.
 */
final class OrmException extends RuntimeException
{
    public static function unknownRelation(string $model, string $relation): self
    {
        return new self(sprintf(
            'Relation [%s] is not defined on model [%s]; define a public method returning Relation::hasOne/hasMany/belongsTo/belongsToMany.',
            $relation,
            $model,
        ));
    }

    public static function relationClashesWithAttribute(string $model, string $relation): self
    {
        return new self(sprintf(
            'Relation [%s] on [%s] collides with an existing attribute of the same name.',
            $relation,
            $model,
        ));
    }

    public static function missingPrimaryKey(string $model, string $column): self
    {
        return new self(sprintf(
            'Model [%s] cannot act on the database: primary key [%s] has no value — is the shared connection set and the row persisted?',
            $model,
            $column,
        ));
    }
}
