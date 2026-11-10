<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Storage;

/** @internal */
interface ReplayStorageInterface
{
    public const string CLAIMED = 'claimed';
    public const string PROCESSING = 'processing';
    public const string COMPLETED = 'completed';
    public const string REUSED = 'reused';
    public const string INVALID_PARAMETERS = 'invalid_parameters';
    public const string LOCK_FAILURE = 'lock_failure';
    public const string STORAGE_ERROR = 'storage_error';

    /** Claim a request ID for processing.
     *
     * The third argument is either an owner identifier (string) or, for
     * backwards compatibility, the maximum claim age in seconds (int).
     *
     * @param string $requestId Unique request identifier
     * @param string $fingerprint SHA-256 fingerprint of the request payload
     * @param string|int $ownerIdOrMaxAge Owner identifier or maximum claim age
     * @param int $maxAgeSeconds Maximum age in seconds to consider a claim valid
     * @return string|false One of the status constants above, or false on failure
     */
    public function claimRequest(
        string $requestId,
        string $fingerprint,
        string|int $ownerIdOrMaxAge = 300,
        int $maxAgeSeconds = 300,
    ): string|false;

    /** Claim a request ID for a specific worker/owner. */
    public function claimRequestWithOwner(
        string $requestId,
        string $fingerprint,
        string $ownerId,
        int $maxAgeSeconds = 300,
    ): string;

    /** Check if a request has already been completed.
     *  @param string $requestId Unique request identifier
     *  @param string $fingerprint SHA-256 fingerprint of the request payload
     *  @return array|false ['status', 'responseData', 'timestamp'] if completed, false otherwise
     */
    public function isCompleted(string $requestId, string $fingerprint): array|false;

    /** Record a completed request with its response.
     *  @param string $requestId Unique request identifier
     *  @param string $fingerprint SHA-256 fingerprint of the request payload
     *  @param array $responseData The response data to store
     *  @param int $ttlSeconds TTL in seconds for the completion record
     *  @return bool True on success
     */
    public function recordCompletion(string $requestId, string $fingerprint, array $responseData, int $ttlSeconds = 300): bool;

    /** Release a processing claim if owned by this request/worker.
     *
     * When $ownerId is null the request ID itself acts as the legacy owner
     * token, preserving the original two-argument API.
     *
     * @param string $requestId Unique request identifier
     * @param string $fingerprint SHA-256 fingerprint of the request payload
     * @param string|null $ownerId Identifier of the worker/process that owns the claim
     * @return bool True if the claim was released, false otherwise
     */
    public function releaseClaim(string $requestId, string $fingerprint, ?string $ownerId = null): bool;

    /** Get the age of the oldest completed record for a fingerprint.
     *  @param string $fingerprint SHA-256 fingerprint
     *  @return int|false Seconds, or false if no records found
     */
    public function getOldestRecordAge(string $fingerprint): int|false;
}
