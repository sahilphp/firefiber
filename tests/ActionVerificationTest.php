<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Sahil\Firefiber\Component;
use Sahil\Firefiber\Exceptions\CsrfException;
use Sahil\Firefiber\Exceptions\InvalidSnapshotException;
use Sahil\Firefiber\Exceptions\UnknownActionException;
use Sahil\Firefiber\Http\FirefiberMiddleware;
use Sahil\Firefiber\Protocol;
use Sahil\Firefiber\SnapshotIntegrity;
use Sahil\Firefiber\Storage\FileCacheReplayStorage;
use Sahil\Firefiber\Storage\FileCacheStateStorage;
use Sahil\Firefiber\Tests\Support\FakeServerRequest;

final class ActionVerificationTest extends TestCase
{
    private const string HMAC_KEY = 'phase-4.2-action-verification-key';
    private const string CSRF_TOKEN = 'verification-csrf-token';
    private const string COMPONENT_ID = 'ff-click-integration';

    private string $baseDir;
    private string $stateDir;
    private string $replayDir;

    /** @var int<1, max> */
    private int $stateTtl = 0;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/firefiber_action_verification_' . bin2hex(random_bytes(8));
        $this->stateDir = $this->baseDir . '/state';
        $this->replayDir = $this->baseDir . '/replay';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->replayDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
    }

    private function makeMiddleware(): FirefiberMiddleware
    {
        return new FirefiberMiddleware(
            new FileCacheReplayStorage($this->replayDir),
            self::HMAC_KEY,
            300,
            'action-verification-worker',
            new FileCacheStateStorage($this->stateDir),
            static function (string $id, array $state, array $data): Component {
                return new ClickIntegrationCounter();
            },
            $this->stateTtl,
        );
    }

    /** Build a click:handleAction payload that matches the browser runtime payload shape. */
    private function clickBody(
        array $state,
        string $action = 'increment',
        string $requestId = '',
        ?array $extra = null,
        string $protocolVersion = '3.1',
    ): array {
        $body = [
            'protocolVersion' => $protocolVersion,
            'type' => Protocol::TYPE_ACTION,
            'componentId' => self::COMPONENT_ID,
            'action' => $action,
            'payload' => [],
            'csrfToken' => self::CSRF_TOKEN,
            'signature' => $this->snapshot($state)['signature'],
            'snapshot' => $this->snapshot($state),
        ];

        if ($requestId !== '') {
            $body['requestId'] = $requestId;
        }

        if ($extra !== null) {
            // Merge the extra snapshot ENVELOPE into the top-level snapshot
            // so tampered/conflicting fields land inside the envelope instead
            // of being flattened into the request body.
            $body['snapshot'] = array_merge($body['snapshot'] ?? [], $extra);
        }

        return $body;
    }

    private function makeRequest(
        array $body,
        string $csrfToken = self::CSRF_TOKEN,
        ?string $signatureHeader = null,
        string $cookieToken = self::CSRF_TOKEN,
    ): FakeServerRequest {
        $bodyJson = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($signatureHeader === null) {
            $signatureHeader = $this->signatureOf($bodyJson, self::HMAC_KEY);
        }

        return new FakeServerRequest(
            $bodyJson,
            [
                'X-Firefiber-Target' => '1',
                'X-Csrf-Token' => $csrfToken,
                'X-Firefiber-Signature' => $signatureHeader,
            ],
            ['firefiber_csrf' => $cookieToken],
        );
    }

    private function signatureOf(string $body, string $hmacKey): string
    {
        return base64_encode(hash_hmac('sha256', $body, $hmacKey, true));
    }

    /** @return array{componentId: string, state: array, timestamp: int, protocolVersion: string, signature: string} */
    private function snapshot(array $state, string $componentId = self::COMPONENT_ID, string $protocolVersion = '3.1', ?int $timestamp = null): array
    {
        $timestamp = $timestamp ?? time();
        $integrity = new SnapshotIntegrity(self::HMAC_KEY, 300);

        return [
            'componentId' => $componentId,
            'state' => $state,
            'timestamp' => $timestamp,
            'protocolVersion' => $protocolVersion,
            'signature' => $integrity->signSnapshot($componentId, $state, $timestamp, $protocolVersion),
        ];
    }

    private function assertErrorResponse(
        ResponseInterface $response,
        int $expectedStatus,
        int $expectedErrorCode,
        string $expectedMessage,
    ): void {
        $this->assertSame($expectedStatus, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('error', $body['status']);
        $this->assertSame($expectedErrorCode, $body['errorCode']);
        $this->assertStringContainsString($expectedMessage, $body['error']);
    }

    public function test_valid_click_request_executes_the_action_exactly_once_and_returns_updated_state(): void
    {
        $stateStorage = new FileCacheStateStorage($this->stateDir);
        $stateStorage->saveState(self::COMPONENT_ID, ['count' => 1]);

        $requestId = 'req-' . bin2hex(random_bytes(8));

        $middleware = $this->makeMiddleware();
        $handler = new class ($requestId) implements RequestHandlerInterface {
            private string $requestId;

            public function __construct(string $requestId)
            {
                $this->requestId = $requestId;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('Handler must not be called for targeted requests');
            }
        };

        $middleware->process(
            $this->makeRequest($this->clickBody(['count' => 1], 'increment', $requestId)),
            $handler,
        );

        $responseBody = $this->responseBodyOf(
            $middleware->process(
                $this->makeRequest($this->clickBody(['count' => 1], 'increment', $requestId)),
                $handler,
            ),
        );

        $this->assertSame('ok', $responseBody['status']);
        $this->assertSame('increment', $responseBody['action']);
        $this->assertSame(1, $responseBody['result']['previous']);
        $this->assertSame(2, $responseBody['result']['count']);
        $this->assertSame(2, $responseBody['snapshot']['state']['count']);
        $this->assertSame(2, $stateStorage->retrieveState(self::COMPONENT_ID)['count']);
    }

    public function test_valid_click_request_dispatches_no_duplicate_executions(): void
    {
        $stateStorage = new FileCacheStateStorage($this->stateDir);
        $stateStorage->saveState(self::COMPONENT_ID, ['count' => 1]);

        $requestId = 'req-duplicate-' . bin2hex(random_bytes(8));
        $handler = new class ($requestId) implements RequestHandlerInterface {
            private string $requestId;

            public function __construct(string $requestId)
            {
                $this->requestId = $requestId;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('Handler must not be called for targeted requests');
            }
        };

        $middleware = $this->makeMiddleware();
        $first = $middleware->process(
            $this->makeRequest($this->clickBody(['count' => 1], 'increment', $requestId)),
            $handler,
        );

        $this->assertSame(200, $first->getStatusCode());

        $second = $middleware->process(
            $this->makeRequest($this->clickBody(['count' => 1], 'increment', $requestId)),
            $handler,
        );

        $this->assertSame(200, $second->getStatusCode());
        $secondBody = $this->responseBodyOf($second);

        // Replay record: the second request returns the already-completed response,
        // so the action must not execute a second time.
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame(2, $secondBody['result']['count']);
        $this->assertSame(2, $stateStorage->retrieveState(self::COMPONENT_ID)['count']);
    }

    public function test_invalid_csrf_token_is_rejected(): void
    {
        $stateStorage = new FileCacheStateStorage($this->stateDir);
        $stateStorage->saveState(self::COMPONENT_ID, ['count' => 1]);

        $this->expectException(CsrfException::class);

        $middleware = $this->makeMiddleware();
        $middleware->process(
            $this->makeRequest([
                'protocolVersion' => '3.1',
                'type' => Protocol::TYPE_ACTION,
                'componentId' => self::COMPONENT_ID,
                'action' => 'increment',
                'payload' => [],
                'requestId' => 'req-csrf-' . bin2hex(random_bytes(8)),
                'csrfToken' => 'wrong-csrf-token',
                'snapshot' => $this->snapshot(['count' => 1]),
            ], csrfToken: 'wrong-csrf-token'),
            new ClickIntegrationHandler(),
        );
    }

    public function test_invalid_csrf_from_missing_cookie_is_rejected(): void
    {
        $this->expectException(CsrfException::class);

        $middleware = $this->makeMiddleware();
        $middleware->process(
            $this->makeRequest(
                [
                    'protocolVersion' => '3.1',
                    'type' => Protocol::TYPE_ACTION,
                    'componentId' => self::COMPONENT_ID,
                    'action' => 'increment',
                    'payload' => [],
                    'requestId' => 'req-csrf2-' . bin2hex(random_bytes(8)),
                    'csrfToken' => self::CSRF_TOKEN,
                    'snapshot' => $this->snapshot(['count' => 1]),
                ],
                csrfToken: self::CSRF_TOKEN,
                cookieToken: 'mismatched-cookie',
            ),
            new ClickIntegrationHandler(),
        );
    }

    public function test_tampered_snapshot_signature_is_rejected(): void
    {
        $stateStorage = new FileCacheStateStorage($this->stateDir);
        $stateStorage->saveState(self::COMPONENT_ID, ['count' => 1]);

        $body = $this->clickBody(['count' => 1], 'increment', 'req-sig-' . bin2hex(random_bytes(8)));

        // Build an invalid but valid-length snapshot signature (same byte
        // length as the real one) so the middleware reaches its signature
        // comparison instead of failing on an encoding length mismatch.
        // Build an invalid snapshot signature that still decodes to 32 bytes
        // (same length as the real one) so the middleware reaches its signature
        // comparison instead of failing earlier on a decode error.
        // Use the base64 of 32 zero bytes: valid base64, wrong HMAC,
        // so verifySnapshot must reject it.
        $body['snapshot']['signature'] = base64_encode(chr(0) . str_repeat(chr(0), 31));

        $response = $this->makeMiddleware()->process(
            $this->makeRequest($body),
            new ClickIntegrationHandler(),
        );

        $this->assertErrorResponse(
            $response,
            400,
            Protocol::ERROR_MODIFIED_SNAPSHOT,
            'Invalid snapshot signature',
        );
    }

    public function test_snapshot_with_wrong_component_id_is_rejected(): void
    {
        $stateStorage = new FileCacheStateStorage($this->stateDir);
        $stateStorage->saveState(self::COMPONENT_ID, ['count' => 1]);

        $forgedComponentId = 'ff-click-forged-' . bin2hex(random_bytes(4));
        $snapshot = $this->snapshot(['count' => 1], $forgedComponentId);

        // handleAction requires a top-level signature field, so build the
        // request through clickBody() and let the snapshot envelope carry the
        // forged componentId.
        $body = $this->clickBody(['count' => 1], 'increment', 'req-cid-' . bin2hex(random_bytes(8)), $snapshot);

        $response = $this->makeMiddleware()->process(
            $this->makeRequest($body),
            new ClickIntegrationHandler(),
        );

        $this->assertErrorResponse(
            $response,
            409,
            Protocol::ERROR_MODIFIED_SNAPSHOT,
            'Snapshot component identity does not match',
        );
    }

    public function test_expired_snapshot_is_rejected(): void
    {
        $stateStorage = new FileCacheStateStorage($this->stateDir);
        $stateStorage->saveState(self::COMPONENT_ID, ['count' => 1]);

        $requestId = 'req-exp-' . bin2hex(random_bytes(8));
        $snapshot = $this->snapshot(['count' => 1], self::COMPONENT_ID, '3.1', time() - 600);
        $body = [
            'protocolVersion' => '3.1',
            'type' => Protocol::TYPE_ACTION,
            'componentId' => self::COMPONENT_ID,
            'action' => 'increment',
            'payload' => [],
            'requestId' => $requestId,
            'csrfToken' => self::CSRF_TOKEN,
            'signature' => $this->snapshot(['count' => 1])['signature'],
            'snapshot' => $snapshot,
        ];

        $response = $this->makeMiddleware()->process(
            $this->makeRequest($body),
            new ClickIntegrationHandler(),
        );

        $this->assertErrorResponse(
            $response,
            409,
            Protocol::ERROR_EXPIRED_SNAPSHOT,
            'Snapshot timestamp is outside the replay window',
        );
    }

    public function test_tampered_snapshot_state_is_rejected(): void
    {
        $stateStorage = new FileCacheStateStorage($this->stateDir);
        $stateStorage->saveState(self::COMPONENT_ID, ['count' => 1]);

        $requestId = 'req-state-' . bin2hex(random_bytes(8));
        $tamperedState = ['count' => 99];
        $signedSnapshot = $this->snapshot($tamperedState); // valid signature for the tampered state
        $body = $this->clickBody(['count' => 1], 'increment', $requestId, $signedSnapshot);

        $response = $this->makeMiddleware()->process(
            $this->makeRequest($body),
            new ClickIntegrationHandler(),
        );

        $this->assertErrorResponse(
            $response,
            409,
            Protocol::ERROR_MODIFIED_SNAPSHOT,
            'Snapshot state does not match authoritative state',
        );
    }

    public function test_unregistered_action_is_rejected(): void
    {
        $stateStorage = new FileCacheStateStorage($this->stateDir);
        $stateStorage->saveState(self::COMPONENT_ID, ['count' => 1]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown action: unauthorized-action');

        $middleware = $this->makeMiddleware();
        $middleware->process(
            $this->makeRequest($this->clickBody(['count' => 1], 'unauthorized-action', 'req-unreg-' . bin2hex(random_bytes(8)))),
            new ClickIntegrationHandler(),
        );
    }

    public function test_replayed_request_does_not_execute_the_action_again(): void
    {
        $stateStorage = new FileCacheStateStorage($this->stateDir);
        $stateStorage->saveState(self::COMPONENT_ID, ['count' => 1]);

        $requestId = 'req-replay-' . bin2hex(random_bytes(8));
        $handler = new class ($requestId) implements RequestHandlerInterface {
            private string $requestId;

            public function __construct(string $requestId)
            {
                $this->requestId = $requestId;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('Handler must not be called for targeted requests');
            }
        };

        $middleware = $this->makeMiddleware();
        $first = $middleware->process(
            $this->makeRequest($this->clickBody(['count' => 1], 'increment', $requestId)),
            $handler,
        );

        $this->assertSame(200, $first->getStatusCode());

        $second = $middleware->process(
            $this->makeRequest($this->clickBody(['count' => 1], 'increment', $requestId)),
            $handler,
        );

        $this->assertSame(200, $second->getStatusCode());
        $secondBody = $this->responseBodyOf($second);

        // Replay record is returned; state must not have advanced a second time.
        $this->assertSame(1, $secondBody['result']['previous']);
        $this->assertSame(2, $secondBody['result']['count']);
        $this->assertSame(2, $stateStorage->retrieveState(self::COMPONENT_ID)['count']);
    }

    public function test_response_contains_updated_state_and_signed_snapshot(): void
    {
        $stateStorage = new FileCacheStateStorage($this->stateDir);
        $stateStorage->saveState(self::COMPONENT_ID, ['count' => 5]);

        $snapshot = $this->snapshot(['count' => 5]);
        $requestId = 'req-body-' . bin2hex(random_bytes(8));

        $response = $this->makeMiddleware()->process(
            $this->makeRequest($this->clickBody(['count' => 5], 'increment', $requestId)),
            new ClickIntegrationHandler(),
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->responseBodyOf($response);

        $this->assertSame('ok', $body['status']);
        $this->assertSame(self::COMPONENT_ID, $body['componentId']);
        $this->assertSame('increment', $body['action']);
        $this->assertSame(5, $body['result']['previous']);
        $this->assertSame(6, $body['result']['count']);
        $this->assertSame(6, $body['snapshot']['state']['count']); // the persisted state is what the fresh snapshot is signed with
        $this->assertSame('3.1', $body['snapshot']['protocolVersion']);
        $this->assertSame($body['snapshot']['componentId'], self::COMPONENT_ID);

        $integrity = new SnapshotIntegrity(self::HMAC_KEY, 300);
        $this->assertTrue((bool) $integrity->verifySnapshot(
            self::COMPONENT_ID,
            $body['snapshot']['state'],
            $body['snapshot']['timestamp'],
            '3.1',
            $body['snapshot']['signature'],
        ));
    }

    private function responseBodyOf(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}

final class ClickIntegrationCounter extends Component
{
    public function handleAction(string $action, array $payload): array
    {
        $previous = (int) ($this->state['count'] ?? 0);

        if ($action !== 'increment') {
            throw new RuntimeException('Unknown action: ' . $action);
        }

        $by = (int) ($payload['by'] ?? 1);
        $this->updateState(['count' => $previous + $by]);

        return ['previous' => $previous, 'count' => $previous + $by];
    }
}

final class ClickIntegrationHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw new RuntimeException('Handler must not be called for targeted requests');
    }
}
