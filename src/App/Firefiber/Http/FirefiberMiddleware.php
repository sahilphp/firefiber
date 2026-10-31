<?php
declare(strict_types=1);
namespace Sahil\Firefiber\Http;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sahil\Firefiber\Component;
use Sahil\Firefiber\Exceptions\CsrfException;
use Sahil\Firefiber\Exceptions\InvalidSnapshotException;
use Sahil\Firefiber\Exceptions\InvalidStateException;
use Sahil\Firefiber\Exceptions\ValidationException;
use Sahil\Firefiber\Protocol;
use Sahil\Firefiber\SnapshotIntegrity;
use Sahil\Firefiber\Storage\FileCacheReplayStorage;
use Sahil\Firefiber\Storage\FileCacheStateStorage;
use Sahil\Firefiber\Storage\ReplayStorageInterface;
use Sahil\Firefiber\Storage\StateStorageInterface;
use function base64_decode;
use function base64_encode;
use function hash_hmac;
use function random_bytes;
use function sodium_compare;
use Slim\Psr7\HttpCodes;
use Slim\Psr7\Response;

class FirefiberMiddleware implements MiddlewareInterface
{
    public const string CSRF_TOKEN_NAME = 'firefiber_csrf';
    public const string HMAC_KEY_NAME = 'firefiber_hmac_key';

    /** @var string|false */
    private $hmacKey;

    /** @var int|null */
    private $snapshotExpiration;

    /** @var ReplayStorageInterface */
    private $replayStorage;

    /** @var string */
    private $ownerId;

    /** @var StateStorageInterface */
    private $stateStorage;

    /** @var callable|null */
    private $componentFactory;

    /** @var bool */
    private $hydrationEnabled;

    /** @var int */
    private $stateTtl;

    public function __construct(
        ?ReplayStorageInterface $replayStorage = null,
        ?string $hmacKey = null,
        ?int $snapshotExpiration = null,
        ?string $ownerId = null,
        ?StateStorageInterface $stateStorage = null,
        $componentFactory = null,
        ?int $stateTtl = null,
    ) {
        $config = $this->loadConfig();
        $firefiber = $config['firefiber'] ?? $config;
        $environmentKey = getenv('FIREFIBER_HMAC_KEY');

        $this->hmacKey = $hmacKey
            ?? ($firefiber['hmac_key'] ?? ($environmentKey !== false ? $environmentKey : false));
        $this->snapshotExpiration = $snapshotExpiration
            ?? ($firefiber['expiration_seconds'] ?? null);

        $cachePath = $firefiber['replay_cache_path']
            ?? sys_get_temp_dir() . '/firefiber_replay/';
        $this->replayStorage = $replayStorage ?? new FileCacheReplayStorage($cachePath);
        $this->ownerId = $ownerId ?? bin2hex(random_bytes(16));

        $stateCachePath = $firefiber['state_cache_path']
            ?? sys_get_temp_dir() . '/firefiber_state/';
        $this->stateStorage = $stateStorage ?? new FileCacheStateStorage($stateCachePath);
        $this->componentFactory = is_callable($componentFactory) ? $componentFactory : null;
        $this->hydrationEnabled = $stateStorage !== null || $this->componentFactory !== null;
        $this->stateTtl = $stateTtl ?? (int) ($firefiber['state_ttl_seconds'] ?? 0);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $targetHeader = $request->getHeaderLine('X-Firefiber-Target');
        if ($targetHeader !== '1') {
            return $handler->handle($request);
        }

        $this->validateCsrf($request);
        $this->validateSignature($request);

        $body = (string) $request->getBody();
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if ($data === null) {
            return new Response(HttpCodes::BAD_REQUEST, [], json_encode([
                'status' => 'error',
                'error' => 'Invalid JSON body',
            ]));
        }

        // Validate protocol version (backward compatible with 1.0 and 3.1)
        $protocolVersion = $data['protocolVersion'] ?? null;
        if ($protocolVersion === null || !self::isProtocolVersionSupported($protocolVersion)) {
            return new Response(HttpCodes::BAD_REQUEST, [], json_encode([
                'status' => 'error',
                'error' => 'Unsupported protocol version: ' . ($protocolVersion ?? 'missing'),
            ]));
        }

        $requestType = $data['type'] ?? 'handleAction';

        // Type-specific required field validation
        // Register does not require signature (CSRF-protected instead - server generates identity)
        if ($requestType !== Protocol::TYPE_REGISTER) {
            $requiredFields = ['protocolVersion', 'requestId', 'componentId', 'csrfToken', 'signature'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field])) {
                    return new Response(HttpCodes::BAD_REQUEST, [], json_encode([
                        'status' => 'error',
                        'error' => 'Missing required field: ' . $field,
                    ]));
                }
            }
        } else {
            // Register requires: protocolVersion, requestId, componentName, csrfToken
            // (no signature needed - server generates identity and snapshot)
            $registerRequired = ['protocolVersion', 'requestId', 'componentName', 'csrfToken'];
            foreach ($registerRequired as $field) {
                if (!isset($data[$field])) {
                    return new Response(HttpCodes::BAD_REQUEST, [], json_encode([
                        'status' => 'error',
                        'error' => 'Missing required field: ' . $field,
                    ]));
                }
            }
        }

        switch ($requestType) {
            case 'getSnapshot':
                $componentId = $data['componentId'] ?? null;
                if ($componentId === null) {
                    return new Response(HttpCodes::BAD_REQUEST, [], json_encode([
                        'status' => 'error',
                        'error' => 'Missing componentId',
                    ]));
                }

                try {
                    $storedState = $this->stateStorage->retrieveState((string) $componentId);
                } catch (\Throwable $exception) {
                    $storedState = null;
                }

                // Preserve the legacy empty-snapshot response for unknown
                // components, but never echo browser-provided state here.
                if ($storedState === null) {
                    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                        'status' => 'snapshot',
                        'componentId' => $componentId,
                        'state' => [],
                    ]));
                }

                return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'status' => 'snapshot',
                    'componentId' => $componentId,
                    'state' => $storedState,
                    'snapshot' => $this->freshSnapshot((string) $componentId, $storedState, (string) $protocolVersion),
                ]));
            case 'handleAction':
            default:
                $componentId = $data['componentId'] ?? null;
                $action = $data['action'] ?? null;

                if ($componentId === null || $action === null) {
                    return new Response(HttpCodes::BAD_REQUEST, [], json_encode([
                        'status' => 'error',
                        'error' => 'Missing componentId or action',
                    ]));
                }

                $requestId = (string) $data['requestId'];
                $fingerprint = $this->calculateFingerprint($data);

                // Replays return the stored response even if the original
                // snapshot timestamp is now outside the replay window.
                $completed = $this->replayStorage->isCompleted($requestId, $fingerprint);
                if ($completed !== false) {
                    return $this->responseFromCompleted($completed);
                }

                // Hydration is enabled when state storage/a component factory
                // is injected, or when the client sends the secure snapshot
                // envelope. Legacy action requests keep the Phase 3.2.2
                // integrity-only path so replay behaviour is unchanged.
                $component = null;
                $hydrationRequested = $this->hydrationEnabled || array_key_exists('snapshot', $data);

                if ($hydrationRequested) {
                    $snapshot = $this->extractSnapshot($data);
                    if ($snapshot === null) {
                        return $this->stateErrorResponse(
                            $requestId,
                            'Missing or invalid component snapshot',
                            Protocol::ERROR_MISSING_FIELD,
                            HttpCodes::BAD_REQUEST,
                        );
                    }

                    $hydration = $this->hydrateComponent($data, $snapshot, (string) $protocolVersion, $requestId);
                    if ($hydration instanceof ResponseInterface) {
                        return $hydration;
                    }

                    $component = $hydration['component'];
                } else {
                    // Legacy top-level snapshot integrity validation.
                    $snapshotError = $this->validateSnapshotIntegrity($data, (string) $protocolVersion);
                    if ($snapshotError !== null) {
                        return $snapshotError;
                    }
                }

                $claim = $this->replayStorage->claimRequestWithOwner(
                    $requestId,
                    $fingerprint,
                    $this->ownerId,
                );

                if ($claim === ReplayStorageInterface::COMPLETED) {
                    $completed = $this->replayStorage->isCompleted($requestId, $fingerprint);
                    if ($completed !== false) {
                        return $this->responseFromCompleted($completed);
                    }

                    return $this->conflictResponse(
                        $requestId,
                        'Request ID reused with a different payload',
                    );
                }

                if ($claim === ReplayStorageInterface::REUSED) {
                    return $this->conflictResponse(
                        $requestId,
                        'Request ID reused with a different payload',
                    );
                }

                if ($claim === ReplayStorageInterface::PROCESSING) {
                    return $this->conflictResponse(
                        $requestId,
                        'Request is already processing',
                    );
                }

                if ($claim !== ReplayStorageInterface::CLAIMED) {
                    return new Response(HttpCodes::SERVICE_UNAVAILABLE, ['Content-Type' => 'application/json'], json_encode([
                        'status' => 'error',
                        'error' => 'Replay protection storage unavailable',
                        'errorCode' => Protocol::ERROR_REPLAY,
                        'requestId' => $requestId,
                    ]));
                }

                try {
                    if ($component !== null) {
                        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];

                        // fiber:model is the only built-in action. It is routed
                        // through Component::updateModel() so browser input can
                        // never reach an arbitrary component method, and only
                        // the component's declared modelable keys are writable.
                        if ((string) $action === Component::MODEL_ACTION) {
                            try {
                                $result = $component->updateModel($payload);
                            } catch (ValidationException $exception) {
                                return new Response(
                                    HttpCodes::UNPROCESSABLE_ENTITY,
                                    ['Content-Type' => 'application/json'],
                                    json_encode([
                                        'status' => 'error',
                                        'error' => $exception->getMessage(),
                                        'errorCode' => Protocol::ERROR_VALIDATION,
                                        'errors' => $exception->errors(),
                                        'requestId' => $requestId,
                                        'componentId' => $componentId,
                                        'action' => $action,
                                    ]),
                                );
                            }
                        } else {
                            $result = $component->handleAction((string) $action, $payload);
                        }

                        $state = $component->state();

                        // Persist authoritative state only after the action succeeds.
                        if (!$this->stateStorage->saveState((string) $componentId, $state, $this->stateTtl)) {
                            throw new \RuntimeException('Unable to persist component state');
                        }

                        $responseData = [
                            'status' => 'ok',
                            'componentId' => $componentId,
                            'action' => $action,
                            'result' => $result,
                            'snapshot' => $this->freshSnapshot((string) $componentId, $state, (string) $protocolVersion),
                        ];
                    } else {
                        $responseData = $this->executeAction($data);
                    }

                    $response = new Response(
                        200,
                        ['Content-Type' => 'application/json'],
                        json_encode($responseData),
                    );

                    $this->replayStorage->recordCompletion($requestId, $fingerprint, [
                        'status' => $response->getStatusCode(),
                        'headers' => ['Content-Type' => 'application/json'],
                        'body' => (string) $response->getBody(),
                    ]);

                    return $response;
                } finally {
                    // Always release the claim, including when the action throws.
                    // recordCompletion() has already persisted any completed response.
                    $this->replayStorage->releaseClaim($requestId, $fingerprint, $this->ownerId);
                }
        }
    }

    /** Execute a validated action and return its response payload. */
    protected function executeAction(array $data): array
    {
        $componentId = $data['componentId'] ?? null;
        $action = $data['action'] ?? null;
        $payload = $data['payload'] ?? [];

        return [
            'status' => 'ok',
            'componentId' => $componentId,
            'action' => $action,
            'payload' => $payload,
        ];
    }

    /**
     * Normalise the secure snapshot envelope (or legacy top-level fields).
     *
     * @return array{componentId: string, state: array, timestamp: mixed, signature: string, protocolVersion: string}|null
     */
    private function extractSnapshot(array $data): ?array
    {
        $snapshot = $data['snapshot'] ?? null;

        if (is_array($snapshot)) {
            $state = $snapshot['state'] ?? null;
            $timestamp = $snapshot['timestamp'] ?? null;
            $signature = $snapshot['signature'] ?? $snapshot['snapshotSignature'] ?? null;
            $componentId = $snapshot['componentId'] ?? ($data['componentId'] ?? null);
            $protocolVersion = $snapshot['protocolVersion'] ?? ($data['protocolVersion'] ?? null);
        } else {
            if (!array_key_exists('state', $data) && !array_key_exists('timestamp', $data)) {
                return null;
            }

            $state = $data['state'] ?? null;
            $timestamp = $data['timestamp'] ?? null;
            $signature = $data['snapshotSignature'] ?? ($data['signature'] ?? null);
            $componentId = $data['componentId'] ?? null;
            $protocolVersion = $data['protocolVersion'] ?? null;
        }

        if (!is_array($state) || $timestamp === null || !is_string($signature) || $signature === '') {
            return null;
        }

        return [
            'componentId' => (string) ($componentId ?? ''),
            'state' => $state,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'protocolVersion' => (string) ($protocolVersion ?? ''),
        ];
    }

    /**
     * Load authoritative state, verify the client snapshot against it, and
     * hydrate a fresh component instance.
     *
     * @param array{componentId: string, state: array, timestamp: mixed, signature: string, protocolVersion: string} $snapshot
     * @return array{component: Component}|ResponseInterface
     */
    private function hydrateComponent(
        array $data,
        array $snapshot,
        string $protocolVersion,
        string $requestId,
    ): array|ResponseInterface {
        $componentId = (string) ($data['componentId'] ?? '');
        $snapshotComponentId = $snapshot['componentId'] !== ''
            ? $snapshot['componentId']
            : $componentId;

        if ($snapshotComponentId !== $componentId) {
            return $this->stateErrorResponse(
                $requestId,
                'Snapshot component identity does not match the requested component',
                Protocol::ERROR_MODIFIED_SNAPSHOT,
                HttpCodes::CONFLICT,
            );
        }

        if ($snapshot['protocolVersion'] !== '' && $snapshot['protocolVersion'] !== $protocolVersion) {
            return $this->stateErrorResponse(
                $requestId,
                'Snapshot protocol version does not match the request',
                Protocol::ERROR_INVALID_PROTOCOL,
                HttpCodes::BAD_REQUEST,
            );
        }

        try {
            $storedState = $this->stateStorage->retrieveState($componentId);
        } catch (\Throwable $exception) {
            return $this->stateErrorResponse(
                $requestId,
                'Component state is invalid',
                Protocol::ERROR_MODIFIED_SNAPSHOT,
                HttpCodes::CONFLICT,
            );
        }

        if ($storedState === null) {
            return $this->stateErrorResponse(
                $requestId,
                'Component state is missing or expired',
                Protocol::ERROR_EXPIRED_SNAPSHOT,
                HttpCodes::CONFLICT,
            );
        }

        $timestamp = $this->normalizeTimestamp($snapshot['timestamp']);
        if ($timestamp === null) {
            return $this->stateErrorResponse(
                $requestId,
                'Invalid snapshot timestamp',
                Protocol::ERROR_REPLAY,
                HttpCodes::BAD_REQUEST,
            );
        }

        $integrity = new SnapshotIntegrity(
            is_string($this->hmacKey) ? $this->hmacKey : '',
            $this->snapshotExpiration,
        );

        if (!$integrity->checkReplayWindow($timestamp, $this->snapshotExpiration)) {
            return $this->stateErrorResponse(
                $requestId,
                'Snapshot timestamp is outside the replay window',
                Protocol::ERROR_EXPIRED_SNAPSHOT,
                HttpCodes::CONFLICT,
            );
        }

        if (!$integrity->verifySnapshot(
            $snapshotComponentId,
            $snapshot['state'],
            $timestamp,
            $protocolVersion,
            $snapshot['signature'],
        )) {
            return $this->stateErrorResponse(
                $requestId,
                'Invalid snapshot signature',
                Protocol::ERROR_MODIFIED_SNAPSHOT,
                HttpCodes::BAD_REQUEST,
            );
        }

        if (!$this->statesMatch($storedState, $snapshot['state'])) {
            return $this->stateErrorResponse(
                $requestId,
                'Snapshot state does not match authoritative state',
                Protocol::ERROR_MODIFIED_SNAPSHOT,
                HttpCodes::CONFLICT,
            );
        }

        try {
            $component = $this->createComponent($componentId, $storedState, $data);
        } catch (\Throwable $exception) {
            return $this->stateErrorResponse(
                $requestId,
                'Unable to hydrate component',
                Protocol::ERROR_INVALID_COMPONENT,
                HttpCodes::BAD_REQUEST,
            );
        }

        return ['component' => $component];
    }

    /** Create and hydrate a component from authoritative stored state. */
    protected function createComponent(string $componentId, array $state, array $data): Component
    {
        if ($this->componentFactory !== null) {
            $component = ($this->componentFactory)($componentId, $state, $data);
        } else {
            $componentClass = $data['componentClass'] ?? $data['componentName'] ?? null;

            if (
                !is_string($componentClass)
                || !class_exists($componentClass)
                || !is_subclass_of($componentClass, Component::class)
            ) {
                throw new InvalidStateException(
                    'No component factory or valid Component subclass configured',
                );
            }

            $component = new $componentClass();
        }

        if (!$component instanceof Component) {
            throw new InvalidStateException('Component factory must return a Firefiber Component');
        }

        return $component->hydrate($componentId, $state);
    }

    /** Issue a fresh signed snapshot for the persisted state. */
    private function freshSnapshot(string $componentId, array $state, string $protocolVersion): array
    {
        $timestamp = time();
        $integrity = new SnapshotIntegrity(
            is_string($this->hmacKey) ? $this->hmacKey : '',
            $this->snapshotExpiration,
        );

        return [
            'componentId' => $componentId,
            'state' => $state,
            'timestamp' => $timestamp,
            'protocolVersion' => $protocolVersion,
            'signature' => $integrity->signSnapshot($componentId, $state, $timestamp, $protocolVersion),
        ];
    }

    private function statesMatch(array $storedState, array $snapshotState): bool
    {
        return $this->canonicalize($storedState) === $this->canonicalize($snapshotState);
    }

    private function normalizeTimestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return null;
    }

    private function stateErrorResponse(
        string $requestId,
        string $message,
        int $errorCode,
        int $status,
    ): ResponseInterface {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode([
            'status' => 'error',
            'error' => $message,
            'errorCode' => $errorCode,
            'requestId' => $requestId,
        ]));
    }

    /** Calculate the canonical payload fingerprint bound to a request ID. */
    protected function calculateFingerprint(array $data): string
    {
        $relevant = [
            'protocolVersion' => $data['protocolVersion'] ?? null,
            'type' => $data['type'] ?? Protocol::TYPE_ACTION,
            'componentId' => $data['componentId'] ?? null,
            'action' => $data['action'] ?? null,
            'payload' => $data['payload'] ?? [],
            'state' => $data['state'] ?? null,
            'snapshot' => $data['snapshot'] ?? null,
            'timestamp' => $data['timestamp'] ?? null,
            'snapshotSignature' => $data['snapshotSignature'] ?? null,
        ];

        return hash(
            'sha256',
            json_encode(
                $this->canonicalize($relevant),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /** @param array{0: mixed, 1: mixed, 2: int} $completed */
    private function responseFromCompleted(array $completed): ResponseInterface
    {
        $responseData = $completed[1] ?? null;

        if (is_array($responseData) && isset($responseData['body'])) {
            return new Response(
                (int) ($responseData['status'] ?? 200),
                is_array($responseData['headers'] ?? null) ? $responseData['headers'] : [],
                (string) $responseData['body'],
            );
        }

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($responseData ?? ['status' => 'ok']),
        );
    }

    private function conflictResponse(string $requestId, string $message): ResponseInterface
    {
        return new Response(HttpCodes::CONFLICT, ['Content-Type' => 'application/json'], json_encode([
            'status' => 'error',
            'error' => $message,
            'errorCode' => Protocol::ERROR_REPLAY,
            'requestId' => $requestId,
        ]));
    }

    /** Reject stale timestamps and tampered snapshot signatures before execution. */
    private function validateSnapshotIntegrity(array $data, string $protocolVersion): ?ResponseInterface
    {
        $hasTimestamp = array_key_exists('timestamp', $data);
        $hasState = array_key_exists('state', $data) && is_array($data['state']);
        $hasSnapshotSignature = isset($data['snapshotSignature'])
            || ($hasState && isset($data['signature']));

        if (!$hasTimestamp && !$hasState && !$hasSnapshotSignature) {
            return null;
        }

        $integrity = new SnapshotIntegrity(
            is_string($this->hmacKey) ? $this->hmacKey : '',
            $this->snapshotExpiration,
        );

        $timestamp = null;
        if ($hasTimestamp) {
            if (!is_int($data['timestamp']) && !(is_string($data['timestamp']) && ctype_digit($data['timestamp']))) {
                return new Response(HttpCodes::BAD_REQUEST, ['Content-Type' => 'application/json'], json_encode([
                    'status' => 'error',
                    'error' => 'Invalid snapshot timestamp',
                    'errorCode' => Protocol::ERROR_REPLAY,
                    'requestId' => $data['requestId'] ?? null,
                ]));
            }

            $timestamp = (int) $data['timestamp'];
            if (!$integrity->checkReplayWindow($timestamp, $this->snapshotExpiration)) {
                return new Response(HttpCodes::CONFLICT, ['Content-Type' => 'application/json'], json_encode([
                    'status' => 'error',
                    'error' => 'Snapshot timestamp is outside the replay window',
                    'errorCode' => Protocol::ERROR_REPLAY,
                    'requestId' => $data['requestId'] ?? null,
                ]));
            }
        }

        if ($hasState && $hasSnapshotSignature && $timestamp !== null) {
            $signature = $data['snapshotSignature'] ?? $data['signature'];
            $componentId = (string) ($data['componentId'] ?? '');

            if (!$integrity->verifySnapshot($componentId, $data['state'], $timestamp, $protocolVersion, $signature)) {
                return new Response(HttpCodes::BAD_REQUEST, ['Content-Type' => 'application/json'], json_encode([
                    'status' => 'error',
                    'error' => 'Invalid snapshot signature',
                    'errorCode' => Protocol::ERROR_MODIFIED_SNAPSHOT,
                    'requestId' => $data['requestId'] ?? null,
                ]));
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function loadConfig(): array
    {
        try {
            if (function_exists('config')) {
                $config = config('firefiber');
                if (is_array($config)) {
                    return ['firefiber' => $config];
                }
            }
        } catch (\Throwable $exception) {
            // Configuration is optional for direct/test construction.
        }

        return [];
    }

    /** Check if protocol version is supported */
    private static function isProtocolVersionSupported(string $version): bool
    {
        // Support 1.0 (Phase 1) and 3.1 (Phase 3) for backward compatibility
        return in_array($version, ['1.0', '3.1', '3'], true);
    }

    protected function validateCsrf(ServerRequestInterface $request): void
    {
        $requestToken = $request->getHeaderLine('X-Csrf-Token');

        if (method_exists($request, 'getCookieParams')) {
            $cookies = $request->getCookieParams();
            $sessionToken = is_array($cookies) ? ($cookies['firefiber_csrf'] ?? null) : null;
        } else {
            $sessionToken = $request->getCookieParam('firefiber_csrf');
        }

        if ($requestToken !== $sessionToken) {
            throw new CsrfException(
                'CSRF token mismatch between request and session'
            );
        }
    }

    protected function validateSignature(ServerRequestInterface $request): void
    {
        $signatureHeader = $request->getHeaderLine('X-Firefiber-Signature');

        if ($signatureHeader === '') {
            throw new InvalidSnapshotException(
                'Missing X-Firefiber-Signature header'
            );
        }

        $signatureBytes = base64_decode($signatureHeader, true);
        if ($signatureBytes === false) {
            throw new InvalidSnapshotException(
                'Invalid signature encoding'
            );
        }

        $hmacKey = $this->hmacKey;
        if ($hmacKey === false || $hmacKey === '') {
            throw new InvalidSnapshotException(
                'HMAC key not configured. Set FIREFIBER_HMAC_KEY environment variable.'
            );
        }

        $expectedSignature = hash_hmac('sha256', (string) $request->getBody(), $hmacKey, true);

        if (sodium_compare($signatureBytes, $expectedSignature) !== 0) {
            throw new InvalidSnapshotException(
                'Request signature validation failed'
            );
        }
    }
}
