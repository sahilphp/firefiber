<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Http;

use function base64_decode;
use function base64_encode;
use function hash_hmac;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function random_bytes;

use Sahil\Auth\AuthManager;
use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Contracts\Session\SessionContract;
use Sahil\Contracts\Storage\StorageContract;
use Sahil\Firefiber\Component;
use Sahil\Firefiber\Exceptions\CsrfException;
use Sahil\Firefiber\Exceptions\InvalidSnapshotException;
use Sahil\Firefiber\Exceptions\InvalidStateException;
use Sahil\Firefiber\Exceptions\ValidationException;
use Sahil\Firefiber\FirefiberSecrets;
use Sahil\Firefiber\PageRenderer;
use Sahil\Firefiber\Protocol;
use Sahil\Firefiber\SnapshotIntegrity;
use Sahil\Firefiber\Storage\FileCacheReplayStorage;
use Sahil\Firefiber\Storage\FileCacheStateStorage;
use Sahil\Firefiber\Storage\FileLock;
use Sahil\Firefiber\Storage\ReplayStorageInterface;
use Sahil\Firefiber\Storage\StateStorageInterface;
use Sahil\Http\Middleware\MiddlewareContract;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Storage\MimeTypeDetector;
use Sahil\Storage\StorageManager;
use Slim\Psr7\Response as PsrResponse;

use function sodium_compare;

class FirefiberMiddleware implements MiddlewareContract
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

    private ?ApplicationContract $application;

    private ?SessionContract $session;

    private ?AuthManager $auth;

    private ?StorageContract $storage;

    private ?string $lockDirectory;

    public function __construct(
        ?ReplayStorageInterface $replayStorage = null,
        ?string $hmacKey = null,
        ?int $snapshotExpiration = null,
        ?string $ownerId = null,
        ?StateStorageInterface $stateStorage = null,
        $componentFactory = null,
        ?int $stateTtl = null,
        ?ApplicationContract $application = null,
        ?SessionContract $session = null,
        ?AuthManager $auth = null,
        ?string $lockDirectory = null,
        ?StorageContract $storage = null,
    ) {
        $this->application = $application;
        $this->session = $session;
        $this->auth = $auth;
        $this->lockDirectory = $lockDirectory;
        $this->storage = $storage;

        if ($application !== null && $hmacKey === null) {
            try {
                $hmacKey = FirefiberSecrets::masterKey($application);
            } catch (\Throwable) {
                $hmacKey = false;
            }
        }
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

        $headers = $this->psrHeaders($request);
        $cookies = $this->psrCookies($request);

        $isUpload = ($headers['X-Firefiber-Upload'] ?? '') === '1'
            || str_starts_with(strtolower($headers['Content-Type'] ?? $headers['content-type'] ?? ''), 'multipart/form-data');

        if ($isUpload) {
            return $this->toPsrResponse($this->runMultipartUpload(
                $this->frameworkRequestForMultipart($request, $headers, $cookies),
            ));
        }

        $body = (string) $request->getBody();
        $response = $this->handleTargetedRequest($body, $headers, $cookies);

        return $this->toPsrResponse($response);
    }

    /**
     * Build the framework request used by the multipart upload pipeline.
     *
     * Multipart bodies are parsed by PHP into `$_POST`/`$_FILES` before any
     * body stream is read, so the form fields come from the superglobal
     * while headers and cookies come from the PSR-7 request.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    private function frameworkRequestForMultipart(ServerRequestInterface $request, array $headers, array $cookies): Request
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
        ];

        foreach ($headers as $name => $value) {
            $upper = str_replace('-', '_', strtoupper((string) $name));

            if ($upper === 'CONTENT_TYPE' || $upper === 'CONTENT_LENGTH') {
                $server[$upper] = $value;
                continue;
            }

            $server['HTTP_'.$upper] = $value;
        }

        return Request::fromGlobals($server, [], $cookies, $_POST, '');
    }

    /** Multipart upload pipeline with the shared error mapping. */
    private function runMultipartUpload(Request $request): Response
    {
        try {
            return $this->handleMultipartUpload($request);
        } catch (ValidationException $exception) {
            return Response::json([
                'status' => 'error',
                'error' => $exception->getMessage(),
                'errorCode' => Protocol::ERROR_VALIDATION,
                'errors' => $exception->errors(),
            ], 422);
        } catch (CsrfException $exception) {
            return Response::json([
                'status' => 'error',
                'error' => $exception->getMessage(),
                'errorCode' => Protocol::ERROR_UNAUTHORIZED,
            ], 403);
        } catch (InvalidSnapshotException $exception) {
            return Response::json([
                'status' => 'error',
                'error' => $exception->getMessage(),
                'errorCode' => Protocol::ERROR_MODIFIED_SNAPSHOT,
            ], 400);
        } catch (InvalidStateException $exception) {
            return Response::json([
                'status' => 'error',
                'error' => $exception->getMessage(),
                'errorCode' => Protocol::ERROR_INVALID_COMPONENT,
            ], 409);
        } catch (\Sahil\Firefiber\Exceptions\UnknownActionException $exception) {
            return Response::json([
                'status' => 'error',
                'error' => $exception->getMessage(),
                'errorCode' => Protocol::ERROR_INVALID_ACTION,
            ], 404);
        }
    }

    public function handle(Request $request, \Closure $next): Response
    {
        if ($request->header('X-Firefiber-Target') !== '1') {
            return $next($request);
        }

        if ($request->header('X-Firefiber-Upload') === '1' || str_starts_with((string) $request->contentType(), 'multipart/form-data')) {
            return $this->runMultipartUpload($request);
        }

        try {
            return $this->handleTargetedRequest($request->body(), $request->headers(), $request->cookies());
        } catch (ValidationException $exception) {
            return Response::json([
                'status' => 'error',
                'error' => $exception->getMessage(),
                'errorCode' => Protocol::ERROR_VALIDATION,
                'errors' => $exception->errors(),
            ], 422);
        } catch (CsrfException $exception) {
            return Response::json([
                'status' => 'error',
                'error' => $exception->getMessage(),
                'errorCode' => Protocol::ERROR_UNAUTHORIZED,
            ], 403);
        } catch (InvalidSnapshotException $exception) {
            return Response::json([
                'status' => 'error',
                'error' => $exception->getMessage(),
                'errorCode' => Protocol::ERROR_MODIFIED_SNAPSHOT,
            ], 400);
        } catch (InvalidStateException $exception) {
            return Response::json([
                'status' => 'error',
                'error' => $exception->getMessage(),
                'errorCode' => Protocol::ERROR_INVALID_COMPONENT,
            ], 409);
        } catch (\Sahil\Firefiber\Exceptions\UnknownActionException $exception) {
            return Response::json([
                'status' => 'error',
                'error' => $exception->getMessage(),
                'errorCode' => Protocol::ERROR_INVALID_ACTION,
            ], 404);
        }
    }

    private function handleMultipartUpload(Request $request): Response
    {
        $form = $request->form();
        $data = $this->normalizeUploadForm($form);
        $componentId = (string) ($data['componentId'] ?? '');
        $requestId = (string) ($data['requestId'] ?? '');
        $csrf = $request->header('X-Csrf-Token') ?? $data['csrfToken'] ?? '';
        $cookies = $request->cookies();

        $this->validateCsrf((string) $csrf, $cookies['firefiber_csrf'] ?? null);

        if ($componentId === '' || $requestId === '' || !isset($data['componentClass']) || !isset($data['field'])) {
            return Response::json([
                'status' => 'error',
                'error' => 'Missing upload fields.',
            ], 400);
        }

        $componentLock = $this->acquireComponentLock($data, 'upload');

        try {
            $snapshot = $this->extractSnapshot($data);
            if ($snapshot === null) {
                return $this->stateErrorResponse($requestId, 'Missing or invalid component snapshot', Protocol::ERROR_MISSING_FIELD, 400);
            }

            $hydration = $this->hydrateComponent($data, $snapshot, (string) ($data['protocolVersion'] ?? Protocol::PROTOCOL_VERSION), $requestId);
            if ($hydration instanceof Response) {
                return $hydration;
            }

            $component = $hydration['component'];
            $definitions = $component->uploadDefinitions();
            $field = (string) ($data['field'] ?? '');
            $definition = $definitions[$field] ?? null;

            if (!is_array($definition)) {
                return Response::json([
                    'status' => 'error',
                    'error' => 'Unknown upload field.',
                    'errorCode' => Protocol::ERROR_INVALID_ACTION,
                ], 404);
            }

            if (!$component->authorize($this->currentUser(), 'upload:'.$field)) {
                return Response::json([
                    'status' => 'error',
                    'error' => 'Upload is not authorized.',
                    'errorCode' => Protocol::ERROR_UNAUTHORIZED,
                ], 403);
            }

            $uploads = $this->acceptUploads(
                $componentId,
                $field,
                $definition,
                $component->trustedMetadata(),
                $this->stagedUploadCount($component->state(), $field),
            );
            $records = $this->uploadsForState($uploads);

            // Upload metadata becomes authoritative component state so the
            // component can render it and the browser receives it inside the
            // signed snapshot, exactly like every other state change.
            if ($records !== []) {
                $current = $component->state()['uploads'] ?? [];
                $component->updateState([
                    'uploads' => $this->mergeUploadRecords(is_array($current) ? $current : [], $records),
                ]);

                $state = $component->state();

                if (!$this->stateStorage->saveState($componentId, $state, $this->stateTtl)) {
                    throw new \RuntimeException('Unable to persist component state');
                }

                // Refresh the companion record in lockstep: if it expired while
                // state lived on, hydration would silently drop the session and
                // class binding that authorize every later request.
                if (!$this->stateStorage->saveState($componentId.'::__firefiber_metadata', [
                    'properties' => $component->properties(),
                    'metadata' => $component->trustedMetadata(),
                ], $this->stateTtl)) {
                    throw new \RuntimeException('Unable to persist component metadata');
                }
            } else {
                $state = $component->state();
            }

            $responseData = [
                'status' => 'ok',
                'componentId' => $componentId,
                'field' => $field,
                'uploads' => $records,
                'snapshot' => $this->freshSnapshot($componentId, $state, (string) ($data['protocolVersion'] ?? Protocol::PROTOCOL_VERSION)),
            ];

            // Components that opt into server-driven re-rendering receive fresh
            // markup on upload too, so a staged file list updates immediately.
            if ($component->rerenderOnAction()) {
                $html = $this->renderComponentHtml($component);

                if ($html !== null) {
                    $responseData['html'] = $html;
                }
            }

            return Response::json($responseData, 200);
        } finally {
            $componentLock?->release();
        }
    }

    /**
     * Upload records cross the trust boundary: they are stored inside signed
     * snapshots and echoed back to the browser, so the session and user
     * binding stays server-side in the sidecar record only.
     *
     * @param list<array<string, mixed>> $uploads
     * @return list<array<string, mixed>>
     */
    /**
     * Count the live records a field already holds in authoritative state.
     *
     * `maxFiles` describes the field's capacity, so it must count what is
     * already staged rather than only the incoming request. Records whose
     * staged lifetime has lapsed are dropped by mergeUploadRecords() and so
     * do not consume a slot here either; promoted records are committed
     * contents of the field and continue to occupy it until removed.
     *
     * @param array<string, mixed> $state
     */
    private function stagedUploadCount(array $state, string $field): int
    {
        $entries = $state['uploads'] ?? null;

        if (!is_array($entries)) {
            return 0;
        }

        $count = 0;
        $now = time();

        foreach ($entries as $entry) {
            if (!is_array($entry) || (string) ($entry['field'] ?? '') !== $field) {
                continue;
            }

            $expiresAt = (int) ($entry['expiresAt'] ?? 0);
            if ($expiresAt > 0 && $expiresAt <= $now) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    private function uploadsForState(array $uploads): array
    {
        $records = [];

        foreach ($uploads as $record) {
            unset($record['sessionId'], $record['userId']);
            $records[] = $record;
        }

        return $records;
    }

    /**
     * Merge accepted records into the component's upload list, dropping staged
     * records whose sidecar has expired so repeated uploads cannot grow the
     * signed snapshot without bound. Records are keyed by id: re-uploading
     * replaces rather than duplicates.
     *
     * @param array<mixed> $current
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    private function mergeUploadRecords(array $current, array $records): array
    {
        $now = time();
        $merged = [];

        foreach ($current as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $expiresAt = (int) ($entry['expiresAt'] ?? 0);
            $promoted = !empty($entry['promoted']);

            if (!$promoted && $expiresAt > 0 && $expiresAt <= $now) {
                continue;
            }

            $merged[(string) ($entry['id'] ?? '')] = $entry;
        }

        foreach ($records as $record) {
            $merged[(string) ($record['id'] ?? '')] = $record;
        }

        return array_values($merged);
    }

    private function normalizeUploadForm(array $form): array
    {
        $data = $form;

        if (is_string($form['snapshot'] ?? null)) {
            try {
                $decoded = json_decode($form['snapshot'], true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $data['snapshot'] = $decoded;
                }
            } catch (\JsonException) {
                unset($data['snapshot']);
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $trustedMetadata
     * @param int $staged live records the field already holds in authoritative state
     * @return list<array<string, mixed>>
     */
    private function acceptUploads(string $componentId, string $field, array $definition, array $trustedMetadata, int $staged): array
    {
        $files = $_FILES[$field] ?? null;

        if (!is_array($files)) {
            return [];
        }

        $entries = $this->normalizeUploadedFiles($files);
        $maxFiles = max(1, (int) ($definition['maxFiles'] ?? $definition['max_files'] ?? 1));
        $maxSize = max(1, (int) ($definition['maxSize'] ?? $definition['max_size'] ?? 10_485_760));
        $allowed = $this->allowedExtensions($definition);
        $lifetime = max(60, (int) ($definition['lifetime'] ?? $definition['expires'] ?? 3600));
        $disk = is_string($definition['disk'] ?? null) && $definition['disk'] !== '' ? $definition['disk'] : 'local';

        // maxFiles caps what the field may contain, not what one request may
        // carry: a file input delivers files one change event at a time, so a
        // per-request check alone lets the staged list grow without bound.
        if ($staged + count($entries) > $maxFiles) {
            throw new ValidationException('Validation failed', [
                $field => ['Too many uploaded files.'],
            ]);
        }

        $storage = $this->storageForUploads();
        $uploaded = [];
        $now = time();

        foreach ($entries as $entry) {
            $error = (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                throw new ValidationException('Validation failed', [
                    $field => ['The uploaded file failed validation.'],
                ]);
            }

            $tmpName = (string) ($entry['tmp_name'] ?? '');
            if (!is_file($tmpName)) {
                throw new ValidationException('Validation failed', [
                    $field => ['The uploaded file is not available.'],
                ]);
            }

            $size = (int) (filesize($tmpName) ?: 0);
            if ($size <= 0 || $size > $maxSize) {
                throw new ValidationException('Validation failed', [
                    $field => ['The uploaded file exceeds the maximum allowed size.'],
                ]);
            }

            $detector = new MimeTypeDetector();
            $mimeType = $detector->detectFile($tmpName);
            $extension = MimeTypeDetector::extension($mimeType);

            if ($extension === null || MimeTypeDetector::isExecutableExtension($extension) || ($allowed !== [] && !in_array($extension, $allowed, true))) {
                throw new ValidationException('Validation failed', [
                    $field => ['The uploaded file type is not permitted.'],
                ]);
            }

            $uploadId = bin2hex(random_bytes(16));
            $storedName = bin2hex(random_bytes(16)).'.'.$extension;
            $path = 'firefiber/uploads/'.$uploadId.'/'.$storedName;
            $handle = fopen($tmpName, 'rb');

            if ($handle === false) {
                throw new ValidationException('Validation failed', [
                    $field => ['The uploaded file cannot be read.'],
                ]);
            }

            try {
                $storage->put($path, $handle, $disk);
            } finally {
                fclose($handle);
            }

            if ($storage->size($path, $disk) !== $size || $storage->mimeType($path, $disk) !== $mimeType) {
                $storage->delete($path, $disk);
                throw new ValidationException('Validation failed', [
                    $field => ['The uploaded file failed verification.'],
                ]);
            }

            $metadata = [
                'id' => $uploadId,
                'componentId' => $componentId,
                'field' => $field,
                'disk' => $disk,
                'path' => $path,
                'storedName' => $storedName,
                'originalName' => $this->sanitizeFileName((string) ($entry['name'] ?? 'upload')),
                'size' => $size,
                'mimeType' => $mimeType,
                'extension' => $extension,
                'createdAt' => $now,
                'expiresAt' => $now + $lifetime,
                'sessionId' => $trustedMetadata['sessionId'] ?? null,
                'userId' => $trustedMetadata['userId'] ?? null,
                'promoted' => false,
                'permanentPath' => null,
            ];

            $this->stateStorage->saveState($componentId.'::__firefiber_uploads::'.$uploadId, $metadata, $lifetime);
            $uploaded[] = $metadata;
        }

        return $uploaded;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function promoteUploads(Component $component, string $componentId, array $payload): array
    {
        $ids = $payload['ids'] ?? $payload['id'] ?? [];
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $ids = array_slice(array_values(array_filter(array_map(
            static fn (mixed $id): string => is_string($id) ? $id : '',
            $ids,
        ))), 0, 20);
        $promoted = [];

        if ($ids === []) {
            throw new ValidationException('Validation failed', ['ids' => ['At least one upload id is required.']]);
        }

        foreach ($ids as $id) {
            $record = $this->stateStorage->retrieveState($componentId.'::__firefiber_uploads::'.$id);

            if (!is_array($record)) {
                throw new ValidationException('Validation failed', ['ids' => ['The uploaded file is missing or expired.']]);
            }

            if (!empty($record['promoted'])) {
                continue;
            }

            if (($record['componentId'] ?? null) !== $componentId) {
                throw new ValidationException('Validation failed', ['ids' => ['The uploaded file belongs to another component.']]);
            }

            $field = (string) ($record['field'] ?? '');
            $definition = $component->uploadDefinitions()[$field] ?? [];

            if (!$component->authorize($this->currentUser(), 'upload:promote:'.$field)) {
                throw new \Sahil\Firefiber\Exceptions\InvalidStateException('Upload promotion is not authorized.');
            }

            $disk = is_string($record['disk'] ?? null) ? $record['disk'] : 'local';
            $directory = $this->safeUploadDirectory(is_string($definition['permanentDirectory'] ?? null) ? $definition['permanentDirectory'] : 'firefiber/permanent');
            $permanentPath = $directory.'/'.$id.'/'.$record['storedName'];
            $storage = $this->storageForUploads();
            $storage->move($record['path'], $permanentPath, $disk);

            $record['promoted'] = true;
            $record['permanentPath'] = $permanentPath;
            $record['expiresAt'] = 0;
            $this->stateStorage->saveState($componentId.'::__firefiber_uploads::'.$id, $record, 0);
            $promoted[] = [
                'id' => $id,
                'disk' => $disk,
                'path' => $permanentPath,
                'originalName' => $record['originalName'] ?? '',
                'size' => $record['size'] ?? 0,
                'mimeType' => $record['mimeType'] ?? '',
            ];
        }

        $current = $component->state()['promotedUploads'] ?? [];
        $current = is_array($current) ? $current : [];
        $component->updateState(['promotedUploads' => array_merge($current, $promoted)]);

        // Keep the component's upload list in step: promoted records are now
        // permanent and must not expire or render as staged again.
        $listed = $component->state()['uploads'] ?? [];
        if (is_array($listed) && $listed !== []) {
            $promotedById = [];
            foreach ($promoted as $entry) {
                $promotedById[(string) ($entry['id'] ?? '')] = $entry;
            }

            foreach ($listed as $index => $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $id = (string) ($entry['id'] ?? '');
                if (!isset($promotedById[$id])) {
                    continue;
                }

                $entry['promoted'] = true;
                $entry['expiresAt'] = 0;
                $entry['permanentPath'] = $promotedById[$id]['path'];
                $listed[$index] = $entry;
            }

            $component->updateState(['uploads' => $listed]);
        }

        return ['promotedUploads' => $promoted];
    }

    private function safeUploadDirectory(string $directory): string
    {
        $directory = trim(str_replace('\\', '/', $directory), '/');

        if ($directory === '') {
            $directory = 'firefiber/permanent';
        }

        if (preg_match('/^[A-Za-z0-9._\/-]+$/', $directory) !== 1 || str_contains($directory, '..')) {
            throw new ValidationException('Validation failed', ['destination' => ['The permanent upload directory is not permitted.']]);
        }

        return $directory;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function removeUploads(Component $component, string $componentId, array $payload): array
    {
        $ids = $payload['ids'] ?? $payload['id'] ?? [];
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $removed = [];

        foreach (array_values(array_filter(array_map(
            static fn (mixed $id): string => is_string($id) ? $id : '',
            $ids,
        ))) as $id) {
            $record = $this->stateStorage->retrieveState($componentId.'::__firefiber_uploads::'.$id);

            if (!is_array($record)) {
                continue;
            }

            if (($record['componentId'] ?? null) !== $componentId) {
                throw new ValidationException('Validation failed', ['ids' => ['The uploaded file belongs to another component.']]);
            }

            $field = (string) ($record['field'] ?? '');

            if (!$component->authorize($this->currentUser(), 'upload:remove:'.$field)) {
                throw new ValidationException('Validation failed', ['ids' => ['The uploaded file cannot be removed.']]);
            }

            $storage = $this->storageForUploads();
            $storage->delete($record['path'], $record['disk'] ?? 'local');
            if (!empty($record['permanentPath'])) {
                $storage->delete($record['permanentPath'], $record['disk'] ?? 'local');
            }

            $this->stateStorage->deleteState($componentId.'::__firefiber_uploads::'.$id);
            $removed[] = $id;
        }

        if ($removed !== []) {
            // The file and its sidecar are gone: the component's own upload
            // list must not keep rendering an entry that no longer exists.
            $listed = $component->state()['uploads'] ?? [];

            if (is_array($listed) && $listed !== []) {
                $component->updateState([
                    'uploads' => array_values(array_filter($listed, static function (mixed $entry) use ($removed): bool {
                        return !is_array($entry) || !in_array((string) ($entry['id'] ?? ''), $removed, true);
                    })),
                ]);
            }
        }

        return ['removedUploads' => $removed];
    }

    private function allowedExtensions(array $definition): array
    {
        $configured = $definition['allowedExtensions'] ?? $definition['allowed_extensions'] ?? $definition['extensions'] ?? $definition['mimes'] ?? [];

        if (!is_array($configured)) {
            return [];
        }

        $extensions = [];

        foreach ($configured as $value) {
            $value = strtolower(trim((string) $value));

            if ($value === '') {
                continue;
            }

            if (str_contains($value, '/')) {
                $extension = MimeTypeDetector::extension($value);
                if ($extension !== null) {
                    $extensions[] = $extension;
                }
                continue;
            }

            $extensions[] = $value;
        }

        return array_values(array_unique($extensions));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeUploadedFiles(array $file): array
    {
        if (!isset($file['name']) || !isset($file['tmp_name'])) {
            return [];
        }

        if (!is_array($file['name']) && !is_array($file['tmp_name'])) {
            return [$file];
        }

        $entries = [];
        $names = is_array($file['name']) ? array_values($file['name']) : [$file['name']];
        $tmpNames = is_array($file['tmp_name']) ? array_values($file['tmp_name']) : [$file['tmp_name']];
        $errors = is_array($file['error'] ?? null) ? array_values((array) $file['error']) : ($file['error'] ?? null);
        $types = is_array($file['type'] ?? null) ? array_values((array) $file['type']) : ($file['type'] ?? null);
        $sizes = is_array($file['size'] ?? null) ? array_values((array) $file['size']) : ($file['size'] ?? null);

        foreach ($names as $index => $name) {
            $entries[] = [
                'name' => $name,
                'tmp_name' => $tmpNames[$index] ?? null,
                'error' => is_array($errors) ? ($errors[$index] ?? UPLOAD_ERR_NO_FILE) : $errors,
                'type' => is_array($types) ? ($types[$index] ?? null) : $types,
                'size' => is_array($sizes) ? ($sizes[$index] ?? 0) : $sizes,
            ];
        }

        return $entries;
    }

    private function sanitizeFileName(string $name): string
    {
        $name = basename(str_replace("\0", '', $name));
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?: '';
        $name = trim($name, '.-');

        return $name === '' ? 'upload' : substr($name, 0, 200);
    }

    private function storageForUploads(): StorageContract
    {
        if ($this->storage !== null) {
            return $this->storage;
        }

        if ($this->application !== null) {
            $container = $this->application->container();

            if ($container->bound(StorageContract::class)) {
                return $this->storage = $container->make(StorageContract::class);
            }
        }

        return $this->storage = new StorageManager(new \Sahil\Config\Repository([
            'storage' => [
                'default' => 'local',
                'disks' => [
                    'local' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/firefiber-uploads/', 'public' => false],
                ],
            ],
        ]));
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    private function handleTargetedRequest(string $body, array $headers, array $cookies): Response
    {
        if (strlen($body) > Protocol::DEFAULT_MAX_REQUEST_SIZE) {
            return Response::json([
                'status' => 'error',
                'error' => 'Request body exceeds the maximum size.',
                'errorCode' => Protocol::ERROR_OVERSIZED,
            ], 413);
        }

        $this->validateCsrf($headers['X-Csrf-Token'] ?? $headers['X-CSRF-TOKEN'] ?? '', $cookies['firefiber_csrf'] ?? null);
        $this->validateSignature($body, $headers['X-Firefiber-Signature'] ?? '');

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return Response::json([
                'status' => 'error',
                'error' => 'Malformed JSON request body.',
                'errorCode' => Protocol::ERROR_INVALID_CONTENT_TYPE,
            ], 400);
        }

        if (!is_array($data)) {
            return Response::json([
                'status' => 'error',
                'error' => 'Invalid JSON body',
            ], 400);
        }

        // Validate protocol version (backward compatible with 1.0 and 3.1)
        $protocolVersion = $data['protocolVersion'] ?? null;
        if ($protocolVersion === null || !self::isProtocolVersionSupported($protocolVersion)) {
            return Response::json([
                'status' => 'error',
                'error' => 'Unsupported protocol version: ' . ($protocolVersion ?? 'missing'),
            ], 400);
        }

        $requestType = $data['type'] ?? 'handleAction';

        // Type-specific required field validation
        // Register does not require signature (CSRF-protected instead - server generates identity)
        if ($requestType !== Protocol::TYPE_REGISTER) {
            $requiredFields = ['protocolVersion', 'requestId', 'componentId', 'csrfToken', 'signature'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field])) {
                    return Response::json([
                        'status' => 'error',
                        'error' => 'Missing required field: ' . $field,
                    ], 400);
                }
            }
        } else {
            // Register requires: protocolVersion, requestId, componentName, csrfToken
            // (no signature needed - server generates identity and snapshot)
            $registerRequired = ['protocolVersion', 'requestId', 'componentName', 'csrfToken'];
            foreach ($registerRequired as $field) {
                if (!isset($data[$field])) {
                    return Response::json([
                        'status' => 'error',
                        'error' => 'Missing required field: ' . $field,
                    ], 400);
                }
            }
        }

        $componentLock = $this->acquireComponentLock($data, $requestType);

        try {
            switch ($requestType) {
                case 'getSnapshot':
                    $componentId = $data['componentId'] ?? null;
                    if ($componentId === null) {
                        return Response::json([
                            'status' => 'error',
                            'error' => 'Missing componentId',
                        ], 400);
                    }

                    try {
                        $storedState = $this->stateStorage->retrieveState((string) $componentId);
                    } catch (\Throwable $exception) {
                        $storedState = null;
                    }

                    // Preserve the legacy empty-snapshot response for unknown
                    // components, but never echo browser-provided state here.
                    if ($storedState === null) {
                        return Response::json([
                            'status' => 'snapshot',
                            'componentId' => $componentId,
                            'state' => [],
                        ], 200);
                    }

                    return Response::json([
                        'status' => 'snapshot',
                        'componentId' => $componentId,
                        'state' => $storedState,
                        'snapshot' => $this->freshSnapshot((string) $componentId, $storedState, (string) $protocolVersion),
                    ], 200);
                case 'handleAction':
                default:
                    $componentId = $data['componentId'] ?? null;
                    $action = $data['action'] ?? null;

                    if ($componentId === null || $action === null) {
                        return Response::json([
                            'status' => 'error',
                            'error' => 'Missing componentId or action',
                        ], 400);
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
                                400,
                            );
                        }

                        $hydration = $this->hydrateComponent($data, $snapshot, (string) $protocolVersion, $requestId);
                        if ($hydration instanceof Response) {
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
                        return Response::json([
                            'status' => 'error',
                            'error' => 'Replay protection storage unavailable',
                            'errorCode' => Protocol::ERROR_REPLAY,
                            'requestId' => $requestId,
                        ], 503);
                    }

                    try {
                        if ($component !== null) {
                            $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];

                            // fiber:model is the only built-in action. It is routed
                            // through Component::updateModel() so browser input can
                            // never reach an arbitrary component method, and only
                            // the component's declared modelable keys are writable.
                            $component->callUpdating((string) $action, $payload);

                            $isBuiltInUploadAction = in_array((string) $action, ['promoteUpload', 'removeUpload'], true);
                            $isLoadAction = (string) $action === Component::LOAD_ACTION;
                            $isPollAction = (string) $action === Component::POLL_ACTION;
                            $isQueryAction = (string) $action === Component::QUERY_ACTION;

                            if ($isLoadAction && $component->lazyConfiguration() === []) {
                                return Response::json([
                                    'status' => 'error',
                                    'error' => 'This component does not support lazy loading.',
                                    'errorCode' => Protocol::ERROR_INVALID_ACTION,
                                    'requestId' => $requestId,
                                    'componentId' => $componentId,
                                ], 404);
                            }

                            if ($isPollAction && $component->pollingConfiguration()['intervalMs'] <= 0) {
                                return Response::json([
                                    'status' => 'error',
                                    'error' => 'This component does not support polling.',
                                    'errorCode' => Protocol::ERROR_INVALID_ACTION,
                                    'requestId' => $requestId,
                                    'componentId' => $componentId,
                                ], 404);
                            }

                            if ($isQueryAction && $component->querySyncConfiguration() === []) {
                                return Response::json([
                                    'status' => 'error',
                                    'error' => 'This component does not synchronize URL state.',
                                    'errorCode' => Protocol::ERROR_INVALID_ACTION,
                                    'requestId' => $requestId,
                                    'componentId' => $componentId,
                                ], 404);
                            }

                            $isBuiltInAction = $isBuiltInUploadAction || $isLoadAction || $isPollAction || $isQueryAction;

                            if ($component->registeredActions() !== [] && (string) $action !== Component::MODEL_ACTION && !$isBuiltInAction && !in_array((string) $action, $component->registeredActions(), true)) {
                                throw new \Sahil\Firefiber\Exceptions\UnknownActionException((string) $action, (string) $componentId);
                            }

                            // Query synchronization is authorized with the same
                            // "query" ability the renderer uses before applying
                            // URL values, so render and navigation share one rule.
                            $ability = $isQueryAction ? 'query' : (string) $action;

                            if (!$component->authorize($this->currentUser(), $ability)) {
                                return Response::json([
                                    'status' => 'error',
                                    'error' => 'This action is not authorized.',
                                    'errorCode' => Protocol::ERROR_UNAUTHORIZED,
                                    'requestId' => $requestId,
                                    'componentId' => $componentId,
                                    'action' => $action,
                                ], 403);
                            }

                            if ((string) $action === Component::LOAD_ACTION) {
                                $component->load();
                                $result = ['loaded' => true];
                            } elseif ((string) $action === Component::POLL_ACTION) {
                                $component->poll();
                                $result = ['polled' => true];
                            } elseif ($isQueryAction) {
                                $result = $component->applyQuerySyncPayload($payload);
                            } elseif ((string) $action === 'promoteUpload') {
                                $result = $this->promoteUploads($component, (string) $componentId, $payload);
                            } elseif ((string) $action === 'removeUpload') {
                                $result = $this->removeUploads($component, (string) $componentId, $payload);
                            } elseif ((string) $action === Component::MODEL_ACTION) {
                                try {
                                    $result = $component->updateModel($payload);
                                } catch (ValidationException $exception) {
                                    return new Response(
                                        422,
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

                            $component->callUpdated((string) $action, $payload, is_array($result) ? $result : []);
                            $events = $component->queuedEvents();
                            $component->clearQueuedEvents();
                            $state = $component->state();

                            // Persist authoritative state only after the action succeeds.
                            if (!$this->stateStorage->saveState((string) $componentId, $state, $this->stateTtl)) {
                                throw new \RuntimeException('Unable to persist component state');
                            }

                            if (!$this->stateStorage->saveState((string) $componentId.'::__firefiber_metadata', [
                                'properties' => $component->properties(),
                                'metadata' => $component->trustedMetadata(),
                            ], $this->stateTtl)) {
                                throw new \RuntimeException('Unable to persist component metadata');
                            }

                            $parentSnapshots = $this->dispatchParentEvents($component, $events, (string) $protocolVersion);
                            $responseData = [
                                'status' => 'ok',
                                'componentId' => $componentId,
                                'action' => $action,
                                'result' => $result,
                                'snapshot' => $this->freshSnapshot((string) $componentId, $state, (string) $protocolVersion),
                                'events' => $events,
                                'parentSnapshots' => $parentSnapshots,
                            ];

                            // Lazy loads always return fresh markup so the
                            // browser can swap the fragment in. Polling,
                            // query-driven navigation and ordinary actions do so
                            // only when the component opts into server-driven
                            // re-rendering.
                            if ($isLoadAction || $isPollAction || $isQueryAction || $component->rerenderOnAction()) {
                                $html = $this->renderComponentHtml($component);

                                if ($html !== null) {
                                    $responseData['html'] = $html;
                                }
                            }

                            if ($isPollAction) {
                                $responseData['polling'] = $component->shouldContinuePolling();
                            }

                            if ($isLoadAction) {
                                $responseData['loaded'] = true;
                            }
                        } else {
                            $responseData = $this->executeAction($data);
                        }

                        $response = new Response(
                            200,
                            ['Content-Type' => 'application/json'],
                            json_encode($responseData),
                        );

                        $this->replayStorage->recordCompletion($requestId, $fingerprint, [
                            'status' => $response->status(),
                            'headers' => ['Content-Type' => 'application/json'],
                            'body' => $response->body(),
                        ]);

                        return $response;
                    } finally {
                        // Always release the claim, including when the action throws.
                        // recordCompletion() has already persisted any completed response.
                        $this->replayStorage->releaseClaim($requestId, $fingerprint, $this->ownerId);
                    }
            }
        } finally {
            $componentLock?->release();
        }
    }

    /**
     * Render the component's current fragment for lazy, polling or
     * re-render responses. Rendering failures never break the action
     * pipeline: the caller falls back to state/snapshot-only responses.
     */
    private function renderComponentHtml(Component $component): ?string
    {
        try {
            $container = $this->application?->container();

            if ($container === null || !$container->bound(PageRenderer::class)) {
                return null;
            }

            return $container->make(PageRenderer::class)->renderFragment($component);
        } catch (\Throwable) {
            return null;
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

        $result = [
            'componentId' => (string) ($componentId ?? ''),
            'state' => $state,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'protocolVersion' => (string) ($protocolVersion ?? ''),
        ];

        if (is_array($snapshot)) {
            foreach (['sessionId', 'userId', 'componentClass'] as $field) {
                if (array_key_exists($field, $snapshot)) {
                    $result[$field] = $snapshot[$field];
                }
            }
        }

        return $result;
    }

    /**
     * Load authoritative state, verify the client snapshot against it, and
     * hydrate a fresh component instance.
     *
     * @param array{componentId: string, state: array, timestamp: mixed, signature: string, protocolVersion: string} $snapshot
     * @return array{component: Component}|Response
     */
    private function hydrateComponent(
        array $data,
        array $snapshot,
        string $protocolVersion,
        string $requestId,
    ): array|Response {
        $componentId = (string) ($data['componentId'] ?? '');
        $snapshotComponentId = $snapshot['componentId'] !== ''
            ? $snapshot['componentId']
            : $componentId;

        if ($snapshotComponentId !== $componentId) {
            return $this->stateErrorResponse(
                $requestId,
                'Snapshot component identity does not match the requested component',
                Protocol::ERROR_MODIFIED_SNAPSHOT,
                409,
            );
        }

        if ($snapshot['protocolVersion'] !== '' && $snapshot['protocolVersion'] !== $protocolVersion) {
            return $this->stateErrorResponse(
                $requestId,
                'Snapshot protocol version does not match the request',
                Protocol::ERROR_INVALID_PROTOCOL,
                400,
            );
        }

        try {
            $storedState = $this->stateStorage->retrieveState($componentId);
        } catch (\Throwable $exception) {
            return $this->stateErrorResponse(
                $requestId,
                'Component state is invalid',
                Protocol::ERROR_MODIFIED_SNAPSHOT,
                409,
            );
        }

        if ($storedState === null) {
            return $this->stateErrorResponse(
                $requestId,
                'Component state is missing or expired',
                Protocol::ERROR_EXPIRED_SNAPSHOT,
                409,
            );
        }

        $companion = $this->stateStorage->retrieveState($componentId.'::__firefiber_metadata');
        $metadata = is_array($companion['metadata'] ?? null) ? $companion['metadata'] : [];
        $properties = is_array($companion['properties'] ?? null) ? $companion['properties'] : [];

        if (is_string($metadata['sessionId'] ?? null) && $metadata['sessionId'] !== '') {
            if ($this->session === null || !$this->session->isStarted() || $this->session->id() !== $metadata['sessionId']) {
                return $this->stateErrorResponse(
                    $requestId,
                    'Component state belongs to another session',
                    Protocol::ERROR_UNAUTHORIZED,
                    403,
                );
            }
        }

        if (array_key_exists('userId', $metadata) && $metadata['userId'] !== null && $metadata['userId'] !== '') {
            $auth = $this->resolvedAuth();
            $currentUserId = $auth !== null && !$auth->guest() ? (string) $auth->id() : null;

            if ($currentUserId === null || !hash_equals((string) $metadata['userId'], $currentUserId)) {
                return $this->stateErrorResponse(
                    $requestId,
                    'Component state belongs to another user',
                    Protocol::ERROR_UNAUTHORIZED,
                    403,
                );
            }
        }

        if (is_string($metadata['componentClass'] ?? null) && $metadata['componentClass'] !== '') {
            $requestedClass = $data['componentClass'] ?? $data['componentName'] ?? null;

            if (!is_string($requestedClass) || $requestedClass !== $metadata['componentClass']) {
                return $this->stateErrorResponse(
                    $requestId,
                    'Component class does not match the registered component',
                    Protocol::ERROR_INVALID_COMPONENT,
                    400,
                );
            }
        }

        $timestamp = $this->normalizeTimestamp($snapshot['timestamp']);
        if ($timestamp === null) {
            return $this->stateErrorResponse(
                $requestId,
                'Invalid snapshot timestamp',
                Protocol::ERROR_REPLAY,
                400,
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
                409,
            );
        }

        if (!$integrity->verifySnapshot(
            $snapshotComponentId,
            $snapshot['state'],
            $timestamp,
            $protocolVersion,
            $snapshot['signature'],
            is_string($metadata['sessionId'] ?? null) && $metadata['sessionId'] !== '' ? $metadata['sessionId'] : null,
            isset($metadata['userId']) && is_scalar($metadata['userId']) ? (string) $metadata['userId'] : null,
            is_string($metadata['componentClass'] ?? null) && $metadata['componentClass'] !== '' ? $metadata['componentClass'] : null,
        )) {
            return $this->stateErrorResponse(
                $requestId,
                'Invalid snapshot signature',
                Protocol::ERROR_MODIFIED_SNAPSHOT,
                400,
            );
        }

        if (!$this->statesMatch($storedState, $snapshot['state'])) {
            return $this->stateErrorResponse(
                $requestId,
                'Snapshot state does not match authoritative state',
                Protocol::ERROR_MODIFIED_SNAPSHOT,
                409,
            );
        }

        try {
            $component = $this->createComponent($componentId, $storedState, $data);
        } catch (\Throwable $exception) {
            return $this->stateErrorResponse(
                $requestId,
                'Unable to hydrate component',
                Protocol::ERROR_INVALID_COMPONENT,
                400,
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

            $component = $componentClass::newForHydration();
        }

        if (!$component instanceof Component) {
            throw new InvalidStateException('Component factory must return a Firefiber Component');
        }

        $component = $component->hydrate($componentId, $state);
        $companion = $this->stateStorage->retrieveState($componentId.'::__firefiber_metadata');

        if (is_array($companion)) {
            if (is_array($companion['metadata'] ?? null)) {
                $component->setTrustedMetadata($companion['metadata']);
            }

            if (is_array($companion['properties'] ?? null)) {
                $component->setProperties($companion['properties']);
            }

            if (is_string($companion['metadata']['componentClass'] ?? null) && $companion['metadata']['componentClass'] !== '' && $component::class !== $companion['metadata']['componentClass']) {
                throw new InvalidStateException('Component class does not match the registered component');
            }
        }

        return $component;
    }

    /** Issue a fresh signed snapshot for the persisted state. */
    private function freshSnapshot(string $componentId, array $state, string $protocolVersion): array
    {
        $timestamp = time();
        $integrity = new SnapshotIntegrity(
            is_string($this->hmacKey) ? $this->hmacKey : '',
            $this->snapshotExpiration,
        );

        $sessionId = $this->session !== null && $this->session->isStarted() ? $this->session->id() : null;
        $auth = $this->resolvedAuth();
        $userId = $auth !== null && !$auth->guest() ? (string) $auth->id() : null;
        $componentClass = $this->stateStorage->retrieveState($componentId.'::__firefiber_metadata')['metadata']['componentClass'] ?? null;
        $snapshot = [
            'componentId' => $componentId,
            'state' => $state,
            'timestamp' => $timestamp,
            'protocolVersion' => $protocolVersion,
            'signature' => $integrity->signSnapshot($componentId, $state, $timestamp, $protocolVersion, $sessionId, $userId, is_string($componentClass) ? $componentClass : null),
        ];

        if (is_string($componentClass) && $componentClass !== '') {
            $snapshot['componentClass'] = $componentClass;
        }

        return $snapshot;
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
    ): Response {
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
    private function responseFromCompleted(array $completed): Response
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

    private function conflictResponse(string $requestId, string $message): Response
    {
        return Response::json([
            'status' => 'error',
            'error' => $message,
            'errorCode' => Protocol::ERROR_REPLAY,
            'requestId' => $requestId,
        ], 409);
    }

    /** Reject stale timestamps and tampered snapshot signatures before execution. */
    private function validateSnapshotIntegrity(array $data, string $protocolVersion): ?Response
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
                return Response::json([
                    'status' => 'error',
                    'error' => 'Invalid snapshot timestamp',
                    'errorCode' => Protocol::ERROR_REPLAY,
                    'requestId' => $data['requestId'] ?? null,
                ], 400);
            }

            $timestamp = (int) $data['timestamp'];
            if (!$integrity->checkReplayWindow($timestamp, $this->snapshotExpiration)) {
                return Response::json([
                    'status' => 'error',
                    'error' => 'Snapshot timestamp is outside the replay window',
                    'errorCode' => Protocol::ERROR_REPLAY,
                    'requestId' => $data['requestId'] ?? null,
                ], 409);
            }
        }

        if ($hasState && $hasSnapshotSignature && $timestamp !== null) {
            $signature = $data['snapshotSignature'] ?? $data['signature'];
            $componentId = (string) ($data['componentId'] ?? '');

            if (!$integrity->verifySnapshot($componentId, $data['state'], $timestamp, $protocolVersion, $signature)) {
                return Response::json([
                    'status' => 'error',
                    'error' => 'Invalid snapshot signature',
                    'errorCode' => Protocol::ERROR_MODIFIED_SNAPSHOT,
                    'requestId' => $data['requestId'] ?? null,
                ], 400);
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

    protected function validateCsrf(string $requestToken, ?string $cookieToken): void
    {
        if ($this->session !== null && $this->session->isStarted()) {
            if (!hash_equals($this->session->token(), $requestToken)) {
                throw new CsrfException('CSRF token mismatch between request and session');
            }
            return;
        }

        $sessionToken = $cookieToken;

        if ($requestToken !== $sessionToken) {
            throw new CsrfException(
                'CSRF token mismatch between request and session'
            );
        }
    }

    protected function validateSignature(string $body, string $signatureHeader): void
    {
        // Body signatures are optional transport integrity, not authentication.
        // Firefiber's trust boundary is the session/CSRF state plus
        // server-held component state and server-signed snapshots.
        if ($signatureHeader === '') {
            return;
        }

        $signatureBytes = base64_decode($signatureHeader, true);
        if ($signatureBytes === false) {
            throw new InvalidSnapshotException(
                'Invalid signature encoding'
            );
        }

        $hmacKey = $this->requestSigningKey();
        if ($hmacKey === false || $hmacKey === '') {
            throw new InvalidSnapshotException(
                'HMAC key not configured. Set FIREFIBER_HMAC_KEY environment variable.'
            );
        }

        $expectedSignature = hash_hmac('sha256', $body, $hmacKey, true);

        if (sodium_compare($signatureBytes, $expectedSignature) !== 0) {
            throw new InvalidSnapshotException(
                'Request signature validation failed'
            );
        }
    }

    /**
     * Collect the request headers needed by the secure protocol.
     *
     * @return array<string, string>
     */
    private function psrHeaders(ServerRequestInterface $request): array
    {
        $headers = [];

        if (method_exists($request, 'getHeaders')) {
            foreach ($request->getHeaders() as $name => $values) {
                $headers[$name] = is_array($values) ? implode(',', $values) : (string) $values;
            }
        }

        foreach (['X-Firefiber-Target', 'X-Csrf-Token', 'X-Firefiber-Signature', 'Content-Type'] as $name) {
            $value = $request->getHeaderLine($name);
            if ($value !== '' || !array_key_exists($name, $headers)) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function psrCookies(ServerRequestInterface $request): array
    {
        if (method_exists($request, 'getCookieParams')) {
            $cookies = $request->getCookieParams();
            if (is_array($cookies)) {
                return array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $cookies);
            }
        }

        $cookies = [];
        if (method_exists($request, 'getCookieParam')) {
            $token = $request->getCookieParam('firefiber_csrf');
            if (is_string($token) && $token !== '') {
                $cookies['firefiber_csrf'] = $token;
            }
        }

        return $cookies;
    }

    private function toPsrResponse(Response $response): PsrResponse
    {
        $headers = [];
        foreach ($response->headers() as $name => $values) {
            $headers[$name] = is_array($values) ? implode(',', $values) : (string) $values;
        }

        return new PsrResponse($response->status(), $headers, $response->body());
    }

    private function acquireComponentLock(array $data, string $requestType): ?FileLock
    {
        if ($requestType === 'getSnapshot') {
            return null;
        }

        $componentId = $data['componentId'] ?? null;

        if (!is_string($componentId) || $componentId === '') {
            return null;
        }

        $directory = $this->lockDirectory;
        if ($directory === null || $directory === '') {
            $directory = sys_get_temp_dir().'/firefiber_locks/';
        }

        $lock = new FileLock($directory, $componentId);
        $lock->acquire();

        return $lock;
    }

    private function resolvedAuth(): ?AuthManager
    {
        if ($this->auth !== null) {
            return $this->auth;
        }

        if ($this->application === null) {
            return null;
        }

        try {
            $container = $this->application->container();

            return $container->bound(AuthManager::class)
                ? $container->make(AuthManager::class)
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function currentUser(): ?\Sahil\Contracts\Auth\AuthenticatableContract
    {
        $auth = $this->resolvedAuth();

        return $auth !== null && !$auth->guest() ? $auth->user() : null;
    }

    /**
     * @param list<array{name: string, payload: array}> $events
     * @return list<array{id: string, state: array, timestamp: int, protocolVersion: string, signature: string, sessionId?: string, userId?: string, componentClass?: string}>
     */
    private function dispatchParentEvents(Component $source, array $events, string $protocolVersion): array
    {
        $registry = new \Sahil\Firefiber\ComponentParentRegistry($this->stateStorage);
        $queue = [];
        $snapshots = [];
        $seen = [];

        foreach ($events as $event) {
            $queue[] = ['source' => $source, 'event' => $event];
        }

        while ($queue !== []) {
            $entry = array_shift($queue);
            $source = $entry['source'];
            $event = $entry['event'];

            if (!is_array($event) || !is_string($event['name'] ?? null) || !is_array($event['payload'] ?? null)) {
                continue;
            }

            foreach ($registry->parentsFor($source->id()) as $parentLink) {
                $dedupKey = $source->id()."\0".$parentLink['id']."\0".$event['name']."\0".json_encode($event['payload']);

                if (isset($seen[$dedupKey])) {
                    continue;
                }

                $seen[$dedupKey] = true;

                if (!class_exists($parentLink['class']) || !is_subclass_of($parentLink['class'], Component::class)) {
                    continue;
                }

                $parent = $this->hydrateById($parentLink['id'], $parentLink['class']);

                if ($parent === null || !$parent->hasListener($event['name'])) {
                    continue;
                }

                $parent->handleEvent($event['name'], $event['payload']);

                if (!$this->stateStorage->saveState($parent->id(), $parent->state(), $this->stateTtl)) {
                    throw new \RuntimeException('Unable to persist parent component state');
                }

                if (!$this->stateStorage->saveState($parent->id().'::__firefiber_metadata', [
                    'properties' => $parent->properties(),
                    'metadata' => $parent->trustedMetadata(),
                ], $this->stateTtl)) {
                    throw new \RuntimeException('Unable to persist parent component metadata');
                }

                $snapshots[] = $this->freshSnapshot($parent->id(), $parent->state(), $protocolVersion);

                foreach ($parent->queuedEvents() as $queuedEvent) {
                    $queue[] = ['source' => $parent, 'event' => $queuedEvent];
                }

                $parent->clearQueuedEvents();
            }
        }

        return $snapshots;
    }

    private function hydrateById(string $componentId, string $componentClass): ?Component
    {
        if (!class_exists($componentClass) || !is_subclass_of($componentClass, Component::class)) {
            return null;
        }

        $storedState = $this->stateStorage->retrieveState($componentId);

        if ($storedState === null) {
            return null;
        }

        /** @var Component $component */
        $component = $componentClass::newForHydration();
        $component = $component->hydrate($componentId, $storedState);
        $companion = $this->stateStorage->retrieveState($componentId.'::__firefiber_metadata');

        if (is_array($companion)) {
            if (is_array($companion['properties'] ?? null)) {
                $component->setProperties($companion['properties']);
            }

            if (is_array($companion['metadata'] ?? null)) {
                $component->setTrustedMetadata($companion['metadata']);
            }
        }

        return $component;
    }

    private function requestSigningKey(): string|false
    {
        if ($this->application !== null && $this->session !== null && $this->session->isStarted()) {
            try {
                return FirefiberSecrets::clientKey($this->application, $this->session);
            } catch (\Throwable) {
                return $this->hmacKey;
            }
        }

        return $this->hmacKey;
    }
}
