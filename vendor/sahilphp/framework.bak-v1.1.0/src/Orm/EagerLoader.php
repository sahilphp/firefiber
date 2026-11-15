<?php

declare(strict_types=1);

namespace Sahil\Orm;

use InvalidArgumentException;

/**
 * Eager relation loading: each dotted path level adds exactly ONE extra
 * query — `whereIn` over the collected parent keys — replacing the N+1
 * fan-out for the whole loaded graph. belongsToMany runs two queries
 * (pivot + related).
 */
final class EagerLoader
{
    /**
     * Attach relation results (and nested paths) onto the models.
     *
     * @param class-string<Model> $modelClass
     * @param list<Model> $models
     * @param list<string> $paths
     */
    public function attach(string $modelClass, array $models, array $paths): void
    {
        if ($models === [] || $paths === []) {
            return;
        }

        foreach ($paths as $path) {
            [$name, $rest] = $this->splitPath($path);
            $relation = $models[0]->relation($name);

            $maps = $this->loadLevel($modelClass, $models, $relation);

            foreach ($models as $model) {
                $this->attachOne($model, $name, $relation, $maps);
            }

            if ($rest !== null) {
                $children = [];

                foreach ($models as $model) {
                    $value = $model->loadedRelation($name);

                    if ($value instanceof Model) {
                        $children[] = $value;
                    } elseif ($value instanceof OrmResult) {
                        foreach ($value as $child) {
                            $children[] = $child;
                        }
                    }
                }

                $this->attach($relation->relatedClass, $children, [$rest]);
            }
        }
    }

    /**
     * One batched query (or two, for pivot joins) per relation level.
     *
     * Returns [valueMap, pairMap]; valueMap: match key → Model (for
     * belongsTo/hasOne) or never used (hasMany/belongsToMany use
     * children lists), childrenMap: the parents' ids → list<related>.
     *
     * @param list<Model> $parents
     *
     * @return array{valueMap: array<string, Model>, childrenMap: array<string, list<Model>>}
     */
    private function loadLevel(string $parentClass, array $parents, Relation $relation): array
    {
        $elementMap = [];
        $children = [];

        $parentIds = $this->collectParentIds($parents);

        switch ($relation->type) {
            case 'belongsTo':
                $ids = [];
                foreach ($parents as $parent) {
                    $value = $parent->getAttribute($relation->foreignKey ?? 'id');
                    $ids[] = $value;
                }
                $ids = $this->uniqueIds($ids);

                if ($ids !== []) {
                    $query = new ModelQuery($relation->relatedClass);
                    $related = $query->whereIn($relation->ownerKey ?? 'id', $ids)->get();

                    foreach ($related->all() as $model) {
                        $elementMap[(string) $model->getId()] = $model;
                    }
                }
                break;

            case 'hasOne':
            case 'hasMany':
                if ($parentIds !== []) {
                    $query = new ModelQuery($relation->relatedClass);
                    $related = $query->whereIn($relation->foreignKey ?? 'id', $parentIds)->get();

                    foreach ($related->all() as $model) {
                        $children[(string) $model->getAttribute($relation->foreignKey ?? 'id')][] = $model;
                    }
                }
                break;

            case 'belongsToMany':
                $this->loadManyToMany($parentClass, $parents, $relation, $elementMap, $children);
                break;

            default:
                throw new InvalidArgumentException("Unknown relation type [{$relation->type}].");
        }

        return ['valueMap' => $elementMap, 'childrenMap' => $children];
    }

    /**
     * @param list<Model> $parents
     * @param array<string, Model> $elementMap
     * @param array<string, list<Model>> $children
     */
    private function loadManyToMany(
        string $parentClass,
        array $parents,
        Relation $relation,
        array &$elementMap,
        array &$children,
    ): void {
        $selfId = $relation->pivotSelfKey ?? 'self_id';
        $relatedId = $relation->pivotRelatedKey ?? 'related_id';
        $pivotTable = $relation->pivotTable ?? throw new \RuntimeException('pivot required');
        $connection = $parentClass::shareConnection();

        $parentIds = $this->collectParentIds($parents);

        if ($parentIds === [] || $connection === null) {
            return;
        }

        $driver = $connection->driver();

        $sql = sprintf(
            'SELECT %s, %s FROM %s WHERE %s IN (%s)',
            $driver->quoteIdentifier($selfId),
            $driver->quoteIdentifier($relatedId),
            $driver->quoteIdentifier($pivotTable),
            $driver->quoteIdentifier($selfId),
            implode(', ', array_fill(0, count($parentIds), '?')),
        );

        $pairs = $connection?->execute($sql, $parentIds)->rows();

        $relatedIds = [];
        $links = [];

        foreach ($pairs as $pair) {
            $self = $pair[$selfId] ?? null;
            $relatedRow = $pair[$relatedId] ?? null;
            $relatedIds[] = $relatedRow;
            $links[] = [$self, $relatedRow];
        }

        $relatedIds = $this->uniqueIds($relatedIds);

        if ($relatedIds !== []) {
            $query = new ModelQuery($relation->relatedClass);
            $related = $query->whereIn($this->relatedKey($relation), $relatedIds)->get();

            foreach ($related->all() as $model) {
                $elementMap[(string) $model->getId()] = $model;
            }
        }

        foreach ($parents as $parent) {
            $link = $this->parentIdOf($parent);
            $matched = [];

            foreach ($links as [$self, $rid]) {
                if ((string) $self === $link) {
                    $model = $elementMap[(string) $rid] ?? null;
                    if ($model !== null) {
                        $matched[] = $model;
                    }
                }
            }

            $children[$link] = $matched;
        }
    }

    private function relatedKey(Relation $relation): string
    {
        return (new $relation->relatedClass())->getPrimaryKey();
    }

    private function parentIdOf(Model $model): string
    {
        return (string) $model->getId();
    }

    /**
     * @param list<Model> $parents
     *
     * @return list<mixed> unique parent ids (string-castable scalars)
     */
    private function collectParentIds(array $parents): array
    {
        $ids = [];
        $seen = [];

        foreach ($parents as $parent) {
            $id = $parent->getId();

            if ($id === null) {
                continue;
            }

            $key = (string) $id;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * De-duplicate scalar ids.
     *
     * @param list<mixed> $ids
     *
     * @return list<mixed>
     */
    private function uniqueIds(array $ids): array
    {
        $seen = [];
        $unique = [];

        foreach ($ids as $id) {
            $key = (string) $id;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $id;
        }

        return $unique;
    }

    /**
     * Attach the resolved related values to one parent.
     *
     * @param array{valueMap: array<string, Model>, childrenMap: array<string, list<Model>>} $maps
     */
    private function attachOne(Model $model, string $name, Relation $relation, array $maps): void
    {
        switch ($relation->type) {
            case 'belongsTo':
                $fk = (string) ($model->getAttribute($relation->foreignKey ?? 'id'));
                $model->attachRelation($name, $maps['valueMap'][$fk] ?? null);
                return;

            case 'hasOne':
                $fk = (string) $model->getId();
                $singles = $maps['childrenMap'][$fk] ?? [];
                $model->attachRelation($name, $singles[0] ?? null);
                return;

            case 'hasMany':
            case 'belongsToMany':
                $fk = (string) $model->getId();
                $model->attachRelation($name, new OrmResult($maps['childrenMap'][$fk] ?? []));
                return;
        }
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function splitPath(string $path): array
    {
        $dot = strpos($path, '.');

        if ($dot === false) {
            return [$path, null];
        }

        return [substr($path, 0, $dot), substr($path, $dot + 1)];
    }
}
