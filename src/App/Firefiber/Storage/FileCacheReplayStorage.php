<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Storage;

use Sahil\Cache\FileCache;

final class FileCacheReplayStorage implements ReplayStorageInterface
{
    /** @var string */
    private $cachePath;

    /** @var FileCache */
    private $cache;

    public function __construct(string $cachePath)
    {
        $this->cachePath = rtrim($cachePath, '/') . '/';
        $this->cache = new FileCache($this->cachePath);

        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
    }

    public function claimRequest(
        string $requestId,
        string $fingerprint,
        string|int $ownerIdOrMaxAge = 300,
        int $maxAgeSeconds = 300,
    ): string {
        if (is_string($ownerIdOrMaxAge)) {
            $ownerId = $ownerIdOrMaxAge;
        } else {
            // Legacy API: the request ID was the implicit claim owner.
            $ownerId = $requestId;
            $maxAgeSeconds = $ownerIdOrMaxAge;
        }

        return $this->claimRequestWithOwner($requestId, $fingerprint, $ownerId, $maxAgeSeconds);
    }

    public function claimRequestWithOwner(
        string $requestId,
        string $fingerprint,
        string $ownerId,
        int $maxAgeSeconds = 300,
    ): string {
        if ($requestId === '' || $fingerprint === '' || $ownerId === '' || $maxAgeSeconds <= 0) {
            return self::INVALID_PARAMETERS;
        }

        $lock = $this->acquireLock($requestId, LOCK_EX);
        if ($lock === null) {
            return self::LOCK_FAILURE;
        }

        try {
            $completed = $this->readCompleted($requestId);
            if ($completed !== null) {
                return ($completed['fingerprint'] ?? null) === $fingerprint
                    ? self::COMPLETED
                    : self::REUSED;
            }

            $claim = $this->cache->get($this->claimKey($requestId));
            if ($claim !== null) {
                if (!is_array($claim)) {
                    return self::STORAGE_ERROR;
                }

                return ($claim['fingerprint'] ?? null) === $fingerprint
                    ? self::PROCESSING
                    : self::REUSED;
            }

            $claimRecord = [
                'requestId' => $requestId,
                'fingerprint' => $fingerprint,
                'ownerId' => $ownerId,
                'timestamp' => time(),
            ];

            if ($this->cache->add($this->claimKey($requestId), $claimRecord, $maxAgeSeconds)) {
                return self::CLAIMED;
            }

            // Another writer may have won the race even though the lock was
            // held (for example on a cache backend that ignores locks).
            $claim = $this->cache->get($this->claimKey($requestId));
            if (is_array($claim)) {
                return ($claim['fingerprint'] ?? null) === $fingerprint
                    ? self::PROCESSING
                    : self::REUSED;
            }

            return self::STORAGE_ERROR;
        } catch (\Throwable $exception) {
            return self::STORAGE_ERROR;
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function isCompleted(string $requestId, string $fingerprint): array|false
    {
        if ($requestId === '' || $fingerprint === '') {
            return false;
        }

        $lock = $this->acquireLock($requestId, LOCK_SH);
        if ($lock === null) {
            return false;
        }

        try {
            $record = $this->readCompleted($requestId);
            if ($record === null || ($record['fingerprint'] ?? null) !== $fingerprint) {
                return false;
            }

            $response = $record['response'] ?? null;
            if (!is_array($response)) {
                return false;
            }

            return [
                $record['status'] ?? ($response['status'] ?? 'ok'),
                $response,
                (int) ($record['timestamp'] ?? 0),
            ];
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function recordCompletion(string $requestId, string $fingerprint, array $responseData, int $ttlSeconds = 300): bool
    {
        if ($requestId === '' || $fingerprint === '' || $ttlSeconds <= 0) {
            return false;
        }

        $lock = $this->acquireLock($requestId, LOCK_EX);
        if ($lock === null) {
            return false;
        }

        try {
            $existing = $this->readCompleted($requestId);
            if ($existing !== null && ($existing['fingerprint'] ?? null) !== $fingerprint) {
                // Never overwrite a completed response for a different payload.
                return false;
            }

            $record = [
                'requestId' => $requestId,
                'fingerprint' => $fingerprint,
                'status' => $responseData['status'] ?? ($responseData[0] ?? 'ok'),
                'response' => $responseData,
                'timestamp' => time(),
            ];

            $completed = $this->cache->put($this->completedKey($requestId), $record, $ttlSeconds);

            // Preserve the legacy response key for callers that inspect it.
            $legacyResponseKey = 'ff_replay_response_' . $requestId . '_' . substr($fingerprint, 0, 8);
            $this->cache->put($legacyResponseKey, serialize($responseData), $ttlSeconds);

            // Fingerprint index is used by getOldestRecordAge().
            $this->cache->put(
                $this->fingerprintKey($fingerprint),
                (int) $record['timestamp'],
                $ttlSeconds,
            );

            return $completed;
        } catch (\Throwable $exception) {
            return false;
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function releaseClaim(string $requestId, string $fingerprint, ?string $ownerId = null): bool
    {
        if ($requestId === '' || $fingerprint === '') {
            return false;
        }

        $lock = $this->acquireLock($requestId, LOCK_EX);
        if ($lock === null) {
            return false;
        }

        try {
            $claim = $this->cache->get($this->claimKey($requestId));
            if (!is_array($claim) || ($claim['fingerprint'] ?? null) !== $fingerprint) {
                return false;
            }

            $expectedOwner = $ownerId ?? $requestId;
            if (($claim['ownerId'] ?? null) !== $expectedOwner) {
                return false;
            }

            // Only the claim is removed. Completion records are deliberately
            // left intact so a completed request remains replayable.
            return $this->cache->forget($this->claimKey($requestId));
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function getOldestRecordAge(string $fingerprint): int|false
    {
        if ($fingerprint === '') {
            return false;
        }

        $timestamp = $this->cache->get($this->fingerprintKey($fingerprint));
        if (!is_int($timestamp) && !is_numeric($timestamp)) {
            return false;
        }

        return max(0, time() - (int) $timestamp);
    }

    /** @return array<string, mixed>|null */
    private function readCompleted(string $requestId): ?array
    {
        $record = $this->cache->get($this->completedKey($requestId));
        if (!is_array($record) || !isset($record['fingerprint'], $record['response'])) {
            return null;
        }

        return $record;
    }

    private function claimKey(string $requestId): string
    {
        return 'ff_replay_claim_' . hash('sha256', $requestId);
    }

    private function completedKey(string $requestId): string
    {
        return 'ff_replay_completed_' . hash('sha256', $requestId);
    }

    private function fingerprintKey(string $fingerprint): string
    {
        return 'ff_replay_fingerprint_' . hash('sha256', $fingerprint);
    }

    /** @return resource|null */
    private function acquireLock(string $requestId, int $operation)
    {
        $path = $this->cachePath . 'ff_replay_' . hash('sha256', $requestId) . '.lock';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return null;
        }

        if (!@flock($handle, $operation)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /** @param resource $handle */
    private function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }
}
