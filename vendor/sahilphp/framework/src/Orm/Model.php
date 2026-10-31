<?php

declare(strict_types=1);

namespace Sahil\Orm;

use Sahil\Database\Connection;

/**
 * Active-record-ish base model. All real SQL goes through the Phase-7
 * query builder; models carry their identity in attributes only.
 *
 * Attribute semantics:
 *  - `attributes` are the CURRENT values; `original` is the snapshot
 *    taken when the row was loaded (or right after save) — `isDirty()`
 *    compares the two and `refresh()` re-syncs from the DB.
 *  - `fillable` is the mass-assignment allowlist (empty = nothing can be
 *    mass-assigned — loud {@see MassAssignmentException} otherwise;
 *    `$guarded` entries are refused even when listed in fillable).
 *  - `casts` may specify per-attribute `int|float|bool|string` (applied
 *    explicitly — the ORM never guesses types).
 *  - `$hidden` attributes (and loaded relations marked hidden) never
 *    appear in `toArray()` / `json_encode()`.
 *  - `$timestamps` maintains `created_at`/`updated_at` (SQL DATETIME
 *    textual form, column names configurable via `$createdAtColumn` /
 *    `$updatedAtColumn`).
 *
 * Restriction: connection wiring is a static, app-level decision made
 * once in a provider (`Model::useConnection()`); tests set their own
 * connection. Models bound to no connection throw on use.
 */
/**
 * @phpstan-consistent-constructor
 */
abstract class Model
{
    /** Shared connection — set once by the app (provider) or a test. */
    private static ?Connection $sharedConnection = null;

    /** Row data the ORM knows about. */

    /** @var array<string, mixed> */
    protected array $attributes = [];

    /**
     * @var array<string, mixed>
     */
    protected array $original = [];

    /** @var array<string, mixed> attribute → loaded relation result */
    protected array $relations = [];

    protected ?string $table = null;

    protected string $primaryKey = 'id';

    /** @var list<string> mass-assignment allowlist */
    protected array $fillable = [];

    /**
     * @var list<string> always-refused keys
     */
    protected array $guarded = ['*'];

    /**
     * @var list<string> never serialized
     */
    protected array $hidden = [];

    /**
     * @var array<string, string> attribute → int|float|bool|string
     */
    protected array $casts = [];

    public bool $timestamps = true;

    protected string $createdAtColumn = 'created_at';

    protected string $updatedAtColumn = 'updated_at';

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [], bool $exists = false)
    {
        if ($attributes !== []) {
            $this->fill($attributes);
        }

        if ($exists) {
            // Loaded rows are already canonical — snapshot without casts.
            $this->original = $this->attributes;
        }
    }

    /* ---------- connection wiring ---------- */

    public static function useConnection(Connection $connection): void
    {
        self::$sharedConnection = $connection;
    }

    /**
     * Detach the shared connection (test teardown).
     */
    public static function forgetConnection(): void
    {
        self::$sharedConnection = null;
    }

    public static function shareConnection(): ?Connection
    {
        return self::$sharedConnection;
    }

    protected static function connection(): Connection
    {
        $connection = self::$sharedConnection;

        if ($connection === null) {
            throw OrmException::missingPrimaryKey(static::class, '(no shared connection; call '.static::class.'::useConnection($db) or pass one to the repository)');
        }

        return $connection;
    }

    /* ---------- mass assignment ---------- */

    /**
     * Mass-assignment: keys must be fillable and not guarded. Fails
     * LOUDLY (collecting every offending key, not just the first) and
     * ATOMICALLY — a rejected call leaves attributes untouched.
     */
    /**
     * @param array<string, mixed> $attributes
     */
    public function fill(array $attributes): self
    {
        $rejected = [];
        $accepted = [];

        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $accepted[$key] = $value;
                continue;
            }

            $rejected[] = $key;
        }

        if ($rejected !== []) {
            throw MassAssignmentException::forKeys($rejected, static::class);
        }

        foreach ($accepted as $key => $value) {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    private function isFillable(string $key): bool
    {
        if (in_array($key, $this->guarded, true)) {
            return false;
        }

        if (in_array($key, $this->fillable, true)) {
            return true;
        }

        if (in_array('*', $this->guarded, true)) {
            return false;
        }

        return $this->guarded === [];
    }

    /* ---------- attribute access ---------- */

    public function __set(string $key, mixed $value): void
    {
        $this->setRawAttribute($key, $value, true);
    }

    public function __get(string $key): mixed
    {
        $value = $this->getAttribute($key);

        if ($value === null && !array_key_exists($key, $this->attributes) && method_exists($this, $key)) {
            return $this->load($key); // explicit lazy relation access (see N+1 notes)
        }

        return $value;
    }

    public function __isset(string $key): bool
    {
        return $this->getAttribute($key) !== null;
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }

    /**
     * Attribute getter: an explicitly cast value, a loaded relation, or
     * the raw stored attribute.
     */
    public function getAttribute(string $key): mixed
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->castAttribute($key);
        }

        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        return null;
    }

    /**
     * @internal callers with a known-mutated value.
     */
    public function setRawAttribute(string $key, mixed $value, bool $track = true): self
    {
        $this->attributes[$key] = $value;

        if (!$track) {
            $this->original[$key] = $value;
        }

        return $this;
    }

    /**
     * Explicit per-attribute casting (int|float|bool|string). No-op for
     * attributes that aren't declared — the ORM never guesses.
     */
    private function castAttribute(string $key): mixed
    {
        $value = $this->attributes[$key];

        if ($value === null) {
            return null;
        }

        return match ($this->casts[$key] ?? null) {
            'int' => (int) $value,           // documented lossless intent for DB ints
            'float' => (float) $value,
            'bool' => (bool) $value,         // DB ints 0/1 → false/true
            'string' => (string) $value,
            default => $value,
        };
    }

    /**
     * Raw stored value (no casts) — serialization casts via getAttribute.
     */
    public function rawAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * @return list<string> attribute names present in the row data
     */
    public function attributeNames(): array
    {
        return array_keys($this->attributes);
    }

    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    public function getId(): mixed
    {
        $key = $this->primaryKey;

        return $this->attributes[$key] ?? null;
    }

    private function requireId(): mixed
    {
        $id = $this->getId();

        if ($id === null) {
            throw OrmException::missingPrimaryKey(static::class, $this->primaryKey);
        }

        return $id;
    }

    /**
     * Query facade for THIS model class (ctx of the shared connection).
     */
    public static function modelQuery(): ModelQuery
    {
        return new ModelQuery(static::class);
    }

    /** @return ModelQuery fluent entry point */
    public static function query(): ModelQuery
    {
        return self::modelQuery();
    }

    public static function all(): OrmResult
    {
        return self::modelQuery()->get();
    }

    public static function find(mixed $id): ?static
    {
        /** @var ?static */
        return self::modelQuery()->find($id);
    }

    public static function with(string ...$relationPaths): ModelQuery
    {
        return self::modelQuery()->with(...$relationPaths);
    }

    /* ---------- dirty tracking ---------- */

    /**
     * @return list<string> names of changed (and not-yet-saved) attributes
     */
    public function dirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original)
                || $this->original[$key] !== $this->attributes[$key]) {
                $dirty[] = $key;
            }
        }

        return $dirty;
    }

    public function isDirty(?string $key = null): bool
    {
        return $key === null
            ? $this->dirty() !== []
            : in_array($key, $this->dirty(), true);
    }

    /* ---------- naming ---------- */

    public function table(): string
    {
        return $this->table ?? Relation::snake(Relation::basename(static::class)).'s';
    }

    /**
     * Column-name list used for INSERT of newly created rows: exactly
     * the fillable attributes that have values + timestamps (never the
     * whole raw payload).
     */
    /* ---------- lifecycle ---------- */

    /**
     * Static factory: fill (mass assignment rules apply) → persist →
     * same instance marked as not-new.
     */
    /**
     * @param array<string, mixed> $attributes
     */
    public static function create(array $attributes): static
    {
        $model = new static();
        $model->fill($attributes);
        $model->save();

        return $model;
    }

    /**
     * INSERT when the row is new, UPDATE the dirty subset otherwise.
     */
    public function save(): bool
    {
        $query = static::modelQuery();

        if ($this->getId() === null) {
            return $this->performInsert($query);
        }

        return $this->performUpdate($query);
    }

    private function performInsert(ModelQuery $query): bool
    {
        $persist = $this->attributes;

        if ($this->usesTimestamps()) {
            $now = date('Y-m-d H:i:s');
            $this->attributes[$this->createdAtColumn] ??= $now;
            $this->attributes[$this->updatedAtColumn] ??= $now;
            $persist = $this->attributes;
        }

        $result = $query->insertRow($persist);

        if ($this->getId() === null) {
            $insertId = $result->lastInsertId();

            if ($insertId !== null) {
                $this->attributes[$this->primaryKey] = (int) $insertId;
            }
        }

        $this->original = $this->attributes;

        return true;
    }

    private function performUpdate(ModelQuery $query): bool
    {
        $dirty = $this->computeDirtyAttributes();

        if ($this->usesTimestamps()) {
            $dirty[$this->updatedAtColumn] = date('Y-m-d H:i:s');
            $this->attributes[$this->updatedAtColumn] = $dirty[$this->updatedAtColumn];
        }

        if ($dirty === []) {
            return false; // nothing to persist — not an error
        }

        $query->where($this->primaryKey, '=', $this->requireId())->update($dirty);
        $this->original = $this->attributes;

        return true;
    }

    /**
     * Dirty Deltas stripped of the hidden/pk bookkeeping that never
     * reaches SQL (pk identifiers are kept for the WHERE clause only).
     *
     * @return array<string, mixed>
     */
    private function computeDirtyAttributes(): array
    {
        $dirty = [];

        foreach ($this->dirty() as $key) {
            if ($key === $this->primaryKey) {
                continue; // identity column is not assigned
            }

            $dirty[$key] = $this->attributes[$key];
        }

        return $dirty;
    }

    private function usesTimestamps(): bool
    {
        return $this->timestamps;
    }

    /**
     * Discard in-memory attribute changes not saved yet.
     */
    public function rollbackEdits(): void
    {
        $this->attributes = $this->original;
    }

    /**
     * Re-read this row from the database, discarding unsaved changes —
     * and re-snapshot as original.
     */
    public function refresh(): static
    {
        $id = $this->requireId();

        $row = static::modelQuery()
            ->where($this->primaryKey, '=', $id)
            ->first();

        if ($row === null) {
            throw OrmException::missingPrimaryKey(static::class, $this->primaryKey);
        }

        $this->attributes = $row->attributes;
        $this->original = $this->attributes;

        return $this;
    }

    /**
     * DELETE this row (requires a persisted identity). The instance
     * still carries its attributes afterwards (soft-delete-free class).
     */
    public function delete(): bool
    {
        static::modelQuery()
            ->where($this->primaryKey, '=', $this->requireId())
            ->delete();

        return true;
    }

    /* ---------- relations ---------- */

    /**
     * Relation factory (belongsTo): this row holds `{related}_id`.
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): Relation
    {
        return Relation::make('belongsTo', static::class, $related, $foreignKey, $ownerKey);
    }

    /**
     * Relation factory (hasOne): the related row holds `{thisClass}_id`.
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): Relation
    {
        return Relation::make('hasOne', static::class, $related, $foreignKey, $localKey);
    }

    /**
     * Relation factory (hasMany): rows of `$related` with FK → this pk.
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): Relation
    {
        return Relation::make('hasMany', static::class, $related, $foreignKey, $localKey);
    }

    /**
     * Relation factory (belongsToMany) with conventional pivot table.
     */
    protected function belongsToMany(
        string $related,
        ?string $pivotTable = null,
        ?string $pivotSelfKey = null,
        ?string $pivotRelatedKey = null,
    ): Relation {
        return Relation::make(
            'belongsToMany',
            static::class,
            $related,
            null,
            null,
            $pivotTable,
            $pivotSelfKey,
            $pivotRelatedKey,
        );
    }

    /**
     * The declared relation object for a name (method exists?), or a
     * loud failure.
     */
    public function relation(string $name): Relation
    {
        if (!method_exists($this, $name)) {
            throw OrmException::unknownRelation(static::class, $name);
        }

        $result = $this->{$name}();

        if (!$result instanceof Relation) {
            throw OrmException::unknownRelation(static::class, $name);
        }

        return $result;
    }

    /**
     * Lazy load a relation by name (method-defined) and cache it:
     * `$user->load('profile')`; `$user->profile` goes through __get.
     */
    public function load(string $name): mixed
    {
        if (array_key_exists($name, $this->relations)) {
            return $this->relations[$name];
        }

        // One query per relationship (same path the eager loader uses).
        (new EagerLoader())->attach(static::class, [$this], [$name]);

        return $this->loadedRelation($name);
    }

    /**
     * Attach a resolved relation result directly (loader internal use).
     */
    public function attachRelation(string $name, mixed $value): self
    {
        $this->relations[$name] = $value;

        return $this;
    }

    /**
     * Already-loaded relation value or null (empty OrmResult means the
     * relation produced no rows but WAS loaded).
     */
    public function loadedRelation(string $name): mixed
    {
        return $this->relations[$name] ?? null;
    }

    /**
     * Serialization: attributes (minus $hidden) plus loaded relations;
     * nested models serialize via their own rules.
     */
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $serialized = [];

        foreach ($this->attributes as $key => $value) {
            if (in_array($key, $this->hidden, true)) {
                continue;
            }

            $serialized[$key] = $this->castAttribute($key);
        }

        foreach ($this->relations as $name => $value) {
            if (in_array($name, $this->hidden, true)) {
                continue;
            }

            if ($value instanceof Model) {
                $serialized[$name] = $value->toArray();
                continue;
            }

            if ($value instanceof OrmResult) {
                $serialized[$name] = $value->toArray();
                continue;
            }

            $serialized[$name] = $value;
        }

        return $serialized;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
