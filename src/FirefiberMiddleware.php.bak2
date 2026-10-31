<?php
declare(strict_types=1);
namespace Sahil\Firefiber\Http;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sahil\Firefiber\Exceptions\CsrfException;
use Sahil\Firefiber\Exceptions\InvalidSnapshotException;
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

    public function __construct()
    {
        $config = Firefiber\Config::get();

        $this->hmacKey = $config['firefiber']['hmac_key'] ?? false;
        $this->snapshotExpiration = $config['firefiber']['expiration_seconds'] ?? null;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $targetHeader = $request->getHeaderLine('X-Firefiber-Target');
        if ($targetHeader !== '1') {
            return $handler->handle($request);
        }

        $this->validateCsrf($request);
        $this->validateSignature($request);

        $body = $request->getBody();
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
                return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'status' => 'snapshot',
                    'componentId' => $componentId,
                    'state' => [],
                ]));
            case 'handleAction':
            default:
                $componentId = $data['componentId'] ?? null;
                $action = $data['action'] ?? null;
                $payload = $data['payload'] ?? [];

                if ($componentId === null || $action === null) {
                    return new Response(HttpCodes::BAD_REQUEST, [], json_encode([
                        'status' => 'error',
                        'error' => 'Missing componentId or action',
                    ]));
                }
                return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'status' => 'ok',
                    'componentId' => $componentId,
                    'action' => $action,
                    'payload' => $payload,
                ]));
        }
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
        $sessionToken = $request->getCookieParam('firefiber_csrf');

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

        $expectedSignature = base64_encode(
            hash_hmac('sha256', $request->getBody(true), $hmacKey, true)
        );

        if (!sodium_compare($signatureBytes, $expectedSignature)) {
            throw new InvalidSnapshotException(
                'Request signature validation failed'
            );
        }
    }
}