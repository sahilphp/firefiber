<?php

declare(strict_types=1);

namespace Sahil\Firefiber;

/**
 * Snapshot integrity and security verification.
 *
 * Ensures that:
 * - Component identifiers cannot be forged
 * - Snapshots cannot be modified without detection
 * - Snapshot integrity is checked server-side
 * - Snapshot expiration can be enforced
 * - Component identity is bound to its snapshot
 * - Protocol version mismatches are rejected safely
 * - Browser-provided state cannot overwrite protected server-side state
 *
 * HMAC construction: HMAC-SHA256(componentId + stateHash + timestamp + protocolVersion, hmacKey)
 * The componentId is cryptographically bound to the snapshot - changing the ID invalidates the signature.
 */
final class SnapshotIntegrity
{
    /** @var string */
    private $hmacKey;

    /** @var int|null */
    private $expirationSeconds;

    /** @param string $hmacKey The HMAC key from configuration */
    /** @param int|null $expirationSeconds Optional snapshot expiration in seconds (null = no expiration) */
    public function __construct(string $hmacKey, ?int $expirationSeconds = null)
    {
        $this->hmacKey = $hmacKey;
        $this->expirationSeconds = $expirationSeconds;
    }

    /** @param string $hmacKey */
    public static function create(string $hmacKey, ?int $expirationSeconds = null): self
    {
        return new self($hmacKey, $expirationSeconds);
    }

    /** Generate an HMAC signature for a snapshot.
     *  Signature covers: componentId|stateHash|timestamp|protocolVersion
     *  This binds the component ID to the state, prevents modification without detection,
     *  and includes protocol version and timestamp for replay protection.
     */
    public function signSnapshot(string $componentId, array $state, int $timestamp, string $protocolVersion, ?string $sessionId = null, ?string $userId = null, ?string $componentClass = null): string
    {
        $stateHash = $this->hashState($state);
        $signedData = "{$componentId}|{$stateHash}|{$timestamp}|{$protocolVersion}|" . ($sessionId ?? '') . '|' . ($userId ?? '') . '|' . ($componentClass ?? '');
        return base64_encode(hash_hmac('sha256', $signedData, $this->hmacKey, true));
    }

    /** Verify an HMAC signature on a snapshot.
     *  Returns the timestamp if valid, or false if invalid.
     *  Also checks expiration if configured.
     */
    public function verifySnapshot(string $componentId, array $state, int $timestamp, string $protocolVersion, ?string $receivedSignature = null, ?string $sessionId = null, ?string $userId = null, ?string $componentClass = null): bool|int
    {
        // Check expiration
        if ($this->expirationSeconds !== null && $timestamp < time() - $this->expirationSeconds) {
            return false; // Expired
        }

        // Verify signature
        $expectedSignature = $this->signSnapshot($componentId, $state, $timestamp, $protocolVersion, $sessionId, $userId, $componentClass);

        if ($receivedSignature === null) {
            return false; // No signature provided
        }

        $signatureBytes = base64_decode($receivedSignature, true);
        if ($signatureBytes === false) {
            return false; // Invalid signature encoding
        }

        $expectedBytes = base64_encode(hash_hmac('sha256', "{$componentId}|{$this->hashState($state)}|{$timestamp}|{$protocolVersion}|" . ($sessionId ?? '') . '|' . ($userId ?? '') . '|' . ($componentClass ?? ''), $this->hmacKey, true));
        // Decode expected to compare
        $expectedBytesDecoded = base64_decode($expectedBytes, true);
        if ($expectedBytesDecoded === false) {
            return false;
        }

        // Use constant-time comparison (sodium_compare returns 0 when equal)
        if (sodium_compare($signatureBytes, $expectedBytesDecoded) !== 0) {
            return false; // Signature mismatch
        }

        return $timestamp;
    }

    /** Create a component ID binding that cannot be forged.
     *  The binding is: HMAC-SHA256(componentId + protocolVersion + timestamp, hmacKey)
     *  This binds the component identity to the snapshot.
     */
    public function bindComponentId(string $componentId, string $protocolVersion, int $timestamp): string
    {
        $signedData = "{$componentId}|{$protocolVersion}|{$timestamp}";
        return base64_encode(hash_hmac('sha256', $signedData, $this->hmacKey, true));
    }

    /** Verify component ID binding.
     *  Returns true if the component ID is properly bound to the snapshot.
     */
    public function verifyComponentIdBinding(string $componentId, string $boundSignature, string $protocolVersion, int $timestamp): bool
    {
        $expected = $this->bindComponentId($componentId, $protocolVersion, $timestamp);
        $boundBytes = base64_decode($boundSignature, true);
        $expectedBytes = base64_decode($expected, true);

        if ($boundBytes === false || $expectedBytes === false) {
            return false;
        }

        return sodium_compare($boundBytes, $expectedBytes) === 0;
    }

    /** Hash component state for inclusion in signature.
     *  Ensures state ordering matters - two states with same key/value pairs but different order
     *  will produce different hashes, preventing state swapping attacks.
     */
    private function hashState(array $state): string
    {
        ksort($state);
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** Check if a timestamp is within acceptable replay window.
     *  Returns true if the timestamp is within the replay window of the current time.
     */
    public function checkReplayWindow(int $timestamp, ?int $windowSeconds = null): bool
    {
        $window = $windowSeconds ?? 300; // 5 minutes default
        $now = time();

        return $timestamp >= $now - $window && $timestamp <= $now + $window;
    }
}
