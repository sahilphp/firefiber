<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Storage;

use Sahil\Firefiber\Exceptions\InvalidStateException;

/** @internal */
final class FileCacheStateStorage implements StateStorageInterface
{
    /** @var string */
    private $cachePath;

    /** @var int */
    private $maxStateSize;

    /** @param string $cachePath Directory where cache files will be stored */
    /** @param int $maxStateSize Maximum state size in bytes (default: 256KB) */
    public function __construct(string $cachePath, int $maxStateSize = 262144)
    {
        $this->cachePath = rtrim($cachePath, '/') . '/';
        $this->maxStateSize = $maxStateSize;

        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
    }

    /** {@inheritDoc} */
    public function saveState(string $componentId, array $state, int $ttl = 0): bool
    {
        // Validate state size
        $stateSize = strlen(json_encode($state, JSON_THROW_ON_ERROR));
        if ($stateSize > $this->maxStateSize) {
            throw new InvalidStateException(
                'Component state exceeds maximum size limit of ' . $this->maxStateSize . ' bytes'
            );
        }

        $key = 'ff_state_' . $componentId;

        // Store: ['state' => $state, 'last_modified' => time(), 'ttl' => $ttl + time()]
        $data = [
            'state' => $state,
            'last_modified' => time(),
            'ttl' => $ttl > 0 ? time() + $ttl : 0,
        ];

        $serialized = var_export($data, true);
        $file = $this->cacheFile($key);
        $temporary = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';

        // Write to a temporary file and rename atomically so readers never
        // observe a partially written state document.
        $result = file_put_contents($temporary, "<?php\nreturn {$serialized};", LOCK_EX);
        if ($result === false) {
            @unlink($temporary);

            return false;
        }

        if (!@rename($temporary, $file)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    /** {@inheritDoc} */
    public function retrieveState(string $componentId): array|null
    {
        $data = $this->readRecord($componentId);

        return $data === null ? null : $data['state'];
    }

    /** {@inheritDoc} */
    public function hasState(string $componentId): bool
    {
        return $this->readRecord($componentId) !== null;
    }

    /** {@inheritDoc} */
    public function deleteState(string $componentId): bool
    {
        $file = $this->cacheFile('ff_state_' . $componentId);

        return is_file($file) && @unlink($file);
    }

    /** {@inheritDoc} */
    public function getTtl(string $componentId): int|false
    {
        $data = $this->readRecord($componentId);

        if ($data === null) {
            return false;
        }

        if ($data['ttl'] === 0) {
            return 0; // No expiration
        }

        return max(0, (int) $data['ttl'] - time());
    }

    /** {@inheritDoc} */
    public function getLastModified(string $componentId): int|false
    {
        $data = $this->readRecord($componentId);

        if ($data === null) {
            return false;
        }

        return (int) $data['last_modified'];
    }

    /**
     * Read and validate a state record, removing expired records.
     *
     * @return array{state: array, last_modified: int, ttl: int}|null
     */
    private function readRecord(string $componentId): ?array
    {
        $file = $this->cacheFile('ff_state_' . $componentId);

        if (!is_file($file)) {
            return null;
        }

        $data = @include $file;

        if (!is_array($data) || !isset($data['state']) || !is_array($data['state'])) {
            return null;
        }

        if (isset($data['ttl']) && (int) $data['ttl'] > 0 && time() > (int) $data['ttl']) {
            @unlink($file);

            return null;
        }

        return [
            'state' => $data['state'],
            'last_modified' => (int) ($data['last_modified'] ?? 0),
            'ttl' => (int) ($data['ttl'] ?? 0),
        ];
    }

    /** Get the cache file path for a key */
    private function cacheFile(string $key): string
    {
        $hash = md5($key);
        return $this->cachePath . 'cache_' . $hash . '.php';
    }

    /** {@inheritDoc} */
    public function jsonSerialize(): mixed
    {
        return [
            'cache_path' => $this->cachePath,
            'max_state_size' => $this->maxStateSize,
        ];
    }
}
