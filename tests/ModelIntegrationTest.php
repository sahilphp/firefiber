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
use Sahil\Firefiber\Exceptions\InvalidStateException;
use Sahil\Firefiber\Exceptions\UnknownActionException;
use Sahil\Firefiber\Exceptions\ValidationException;
use Sahil\Firefiber\Http\FirefiberMiddleware;
use Sahil\Firefiber\Protocol;
use Sahil\Firefiber\SnapshotIntegrity;
use Sahil\Firefiber\Storage\FileCacheReplayStorage;
use Sahil\Firefiber\Storage\FileCacheStateStorage;
use Sahil\Firefiber\Tests\Support\FakeServerRequest;

final class ModelIntegrationTest extends TestCase
{
    private const string HMAC_KEY = 'phase-4.4-model-integration-key';
    private const string CSRF_TOKEN = 'model-csrf-token';
    private const string COMPONENT_ID = 'ff-model-integration';

    /** @var array<string, mixed> */
    private const array INITIAL_STATE = [
        'email' => 'old@example.com',
        'nickname' => 'ada',
        'plan' => 'basic',
        'tags' => ['one'],
        'notify' => true,
        'secret' => 'internal-value',
        'count' => 7,
    ];

    private string $baseDir;
    private string $stateDir;
    private string $replayDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/firefiber_model_integration_' . bin2hex(random_bytes(8));
        $this->stateDir = $this->baseDir . '/state';
        $this->replayDir = $this->baseDir . '/replay';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->replayDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
    }

    public function test_model_update_persists_state_and_returns_updated_snapshot(): void
    {
        $stateStorage = $this->saveInitialState();
        $snapshot = $this->snapshot(self::INITIAL_STATE);
        $requestId = 'req-model-ok-' . bin2hex(random_bytes(8));

        $response = $this->middleware()->process(
            $this->modelRequest(
                $this->modelBody(['email' => 'new@example.com', 'nickname' => 'grace'], $snapshot, $requestId),
            ),
            new ModelIntegrationHandler(),
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->json($response);

        // The browser sends the binding key as the payload key and the action
        // name is the built-in updateModel action.
        $this->assertSame('ok', $body['status']);
        $this->assertSame(Component::MODEL_ACTION, $body['action']);
        $this->assertSame(
            ['email' => 'new@example.com', 'nickname' => 'grace'],
            $body['result']['updated'],
        );

        $this->assertSame('new@example.com', $body['snapshot']['state']['email']);
        $this->assertSame('grace', $body['snapshot']['state']['nickname']);
        $this->assertSame('internal-value', $body['snapshot']['state']['secret'], 'Unbound keys must be untouched');
        $this->assertSame(7, $body['snapshot']['state']['count']);

        $persisted = $stateStorage->retrieveState(self::COMPONENT_ID);
        $this->assertSame('new@example.com', $persisted['email']);
        $this->assertSame('grace', $persisted['nickname']);

        $integrity = new SnapshotIntegrity(self::HMAC_KEY, 300);
        $this->assertSame(
            (int) $body['snapshot']['timestamp'],
            $integrity->verifySnapshot(
                self::COMPONENT_ID,
                $body['snapshot']['state'],
                (int) $body['snapshot']['timestamp'],
                '3.1',
                $body['snapshot']['signature'],
            ),
            'The returned snapshot must be signed with the updated state',
        );
    }

    public function test_model_update_preserves_list_and_boolean_values(): void
    {
        $stateStorage = $this->saveInitialState();
        $snapshot = $this->snapshot(self::INITIAL_STATE);

        $response = $this->middleware()->process(
            $this->modelRequest(
                $this->modelBody(
                    ['tags' => ['one', 'two'], 'notify' => false],
                    $snapshot,
                    'req-model-values-' . bin2hex(random_bytes(8)),
                ),
            ),
            new ModelIntegrationHandler(),
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->json($response);

        $this->assertSame(['one', 'two'], $body['snapshot']['state']['tags']);
        $this->assertFalse($body['snapshot']['state']['notify']);
        $this->assertSame(['one', 'two'], $stateStorage->retrieveState(self::COMPONENT_ID)['tags']);
        $this->assertFalse($stateStorage->retrieveState(self::COMPONENT_ID)['notify']);
    }

    public function test_model_update_rejects_a_non_modelable_property(): void
    {
        $stateStorage = $this->saveInitialState();
        $snapshot = $this->snapshot(self::INITIAL_STATE);

        try {
            $this->middleware()->process(
                $this->modelRequest(
                    $this->modelBody(['secret' => 'leaked'], $snapshot, 'req-model-secret-' . bin2hex(random_bytes(8))),
                ),
                new ModelIntegrationHandler(),
            );
            $this->fail('A non-modelable property must be rejected');
        } catch (UnknownActionException $exception) {
            $this->assertStringContainsString('not bindable', $exception->getMessage());
            $this->assertStringContainsString('secret', $exception->getMessage());
        }

        $this->assertSame('internal-value', $stateStorage->retrieveState(self::COMPONENT_ID)['secret']);
    }

    public function test_model_update_rejects_internal_identity_state(): void
    {
        $stateStorage = $this->saveInitialState();
        $snapshot = $this->snapshot(self::INITIAL_STATE);

        try {
            $this->middleware()->process(
                $this->modelRequest(
                    $this->modelBody(['id' => 'ff-hijacked'], $snapshot, 'req-model-id-' . bin2hex(random_bytes(8))),
                ),
                new ModelIntegrationHandler(),
            );
            $this->fail('Internal identity state must not be writable');
        } catch (UnknownActionException $exception) {
            $this->assertStringContainsString('not bindable: id', $exception->getMessage());
        }

        $this->assertArrayNotHasKey('id', $stateStorage->retrieveState(self::COMPONENT_ID));
    }

    public function test_model_update_rejects_an_empty_payload(): void
    {
        $this->saveInitialState();
        $snapshot = $this->snapshot(self::INITIAL_STATE);

        $this->expectException(InvalidStateException::class);
        $this->expectExceptionMessage('Model update payload is empty');

        $this->middleware()->process(
            $this->modelRequest(
                $this->modelBody([], $snapshot, 'req-model-empty-' . bin2hex(random_bytes(8))),
            ),
            new ModelIntegrationHandler(),
        );
    }

    public function test_model_validation_failure_returns_422_without_persisting(): void
    {
        $stateStorage = $this->saveInitialState();
        $snapshot = $this->snapshot(self::INITIAL_STATE);

        $response = $this->middleware()->process(
            $this->modelRequest(
                $this->modelBody(['email' => 'not-an-email'], $snapshot, 'req-model-invalid-' . bin2hex(random_bytes(8))),
            ),
            new ModelIntegrationHandler(),
        );

        $this->assertSame(422, $response->getStatusCode());
        $body = $this->json($response);

        $this->assertSame('error', $body['status']);
        $this->assertSame(Protocol::ERROR_VALIDATION, $body['errorCode']);
        $this->assertSame('The email is invalid', $body['error']);
        $this->assertSame(['The email is invalid'], $body['errors']['email']);
        $this->assertArrayNotHasKey('snapshot', $body);

        $persisted = $stateStorage->retrieveState(self::COMPONENT_ID);
        $this->assertSame('old@example.com', $persisted['email'], 'Rejected values must not be persisted');
    }

    public function test_model_update_with_tampered_snapshot_is_rejected(): void
    {
        $stateStorage = $this->saveInitialState();

        // Signed for a state that differs from the authoritative stored state.
        $tampered = self::INITIAL_STATE;
        $tampered['email'] = 'attacker@example.com';
        $snapshot = $this->snapshot($tampered);

        $response = $this->middleware()->process(
            $this->modelRequest(
                $this->modelBody(['email' => 'attacker@example.com'], $snapshot, 'req-model-tampered-' . bin2hex(random_bytes(8))),
            ),
            new ModelIntegrationHandler(),
        );

        $this->assertSame(409, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertSame(Protocol::ERROR_MODIFIED_SNAPSHOT, $body['errorCode']);
        $this->assertStringContainsString('does not match authoritative state', $body['error']);

        $this->assertSame('old@example.com', $stateStorage->retrieveState(self::COMPONENT_ID)['email']);
    }

    public function test_model_update_with_forged_component_identity_is_rejected(): void
    {
        $stateStorage = $this->saveInitialState();
        $forgedId = 'ff-model-forged-' . bin2hex(random_bytes(4));
        $stateStorage->saveState($forgedId, self::INITIAL_STATE);

        $snapshot = $this->snapshot(self::INITIAL_STATE, $forgedId);

        $response = $this->middleware()->process(
            $this->modelRequest(
                $this->modelBody(['email' => 'attacker@example.com'], $snapshot, 'req-model-forged-' . bin2hex(random_bytes(8))),
            ),
            new ModelIntegrationHandler(),
        );

        $this->assertSame(409, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertSame(Protocol::ERROR_MODIFIED_SNAPSHOT, $body['errorCode']);
        $this->assertStringContainsString('identity', $body['error']);

        $this->assertSame('old@example.com', $stateStorage->retrieveState(self::COMPONENT_ID)['email']);
    }

    public function test_model_update_rejects_a_bad_csrf_token(): void
    {
        $this->saveInitialState();
        $snapshot = $this->snapshot(self::INITIAL_STATE);

        $this->expectException(CsrfException::class);

        $this->middleware()->process(
            $this->modelRequest(
                $this->modelBody(['email' => 'new@example.com'], $snapshot, 'req-model-csrf-' . bin2hex(random_bytes(8))),
                'wrong-csrf-token',
                cookieToken: self::CSRF_TOKEN,
            ),
            new ModelIntegrationHandler(),
        );
    }

    public function test_model_update_rejects_a_bad_request_signature(): void
    {
        $this->saveInitialState();
        $snapshot = $this->snapshot(self::INITIAL_STATE);

        $this->expectException(InvalidSnapshotException::class);

        $this->middleware()->process(
            $this->modelRequest(
                $this->modelBody(['email' => 'new@example.com'], $snapshot, 'req-model-signature-' . bin2hex(random_bytes(8))),
                signatureHeader: base64_encode(str_repeat(chr(0), 32)),
            ),
            new ModelIntegrationHandler(),
        );
    }

    public function test_replayed_model_request_is_not_applied_twice(): void
    {
        $stateStorage = $this->saveInitialState();
        $snapshot = $this->snapshot(self::INITIAL_STATE);
        $requestId = 'req-model-replay-' . bin2hex(random_bytes(8));

        $middleware = $this->middleware();
        $body = $this->modelBody(['email' => 'new@example.com'], $snapshot, $requestId);

        $first = $middleware->process($this->modelRequest($body), new ModelIntegrationHandler());
        $second = $middleware->process($this->modelRequest($body), new ModelIntegrationHandler());

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());

        $firstBody = $this->json($first);
        $secondBody = $this->json($second);
        $this->assertSame($firstBody['snapshot']['state'], $secondBody['snapshot']['state']);
        $this->assertSame('new@example.com', $stateStorage->retrieveState(self::COMPONENT_ID)['email']);
    }

    public function test_model_action_cannot_invoke_an_arbitrary_component_method(): void
    {
        $stateStorage = $this->saveInitialState();
        $snapshot = $this->snapshot(self::INITIAL_STATE);

        $this->expectException(UnknownActionException::class);
        $this->expectExceptionMessage('Unknown action: deleteEverything');

        try {
            $this->middleware()->process(
                $this->modelRequest(
                    $this->modelBody(['email' => 'new@example.com'], $snapshot, 'req-model-action-' . bin2hex(random_bytes(8)), 'deleteEverything'),
                ),
                new ModelIntegrationHandler(),
            );
        } finally {
            $this->assertSame('old@example.com', $stateStorage->retrieveState(self::COMPONENT_ID)['email']);
        }
    }

    private function middleware(): FirefiberMiddleware
    {
        return new FirefiberMiddleware(
            new FileCacheReplayStorage($this->replayDir),
            self::HMAC_KEY,
            300,
            'model-integration-worker',
            new FileCacheStateStorage($this->stateDir),
            static fn (string $id, array $state, array $data): Component => new ModelIntegrationComponent(),
        );
    }

    private function saveInitialState(): FileCacheStateStorage
    {
        $stateStorage = new FileCacheStateStorage($this->stateDir);
        $stateStorage->saveState(self::COMPONENT_ID, self::INITIAL_STATE);

        return $stateStorage;
    }

    /** @return array<string, mixed> */
    private function modelBody(
        array $payload,
        array $snapshot,
        string $requestId,
        string $action = Component::MODEL_ACTION,
    ): array {
        return [
            'protocolVersion' => '3.1',
            'type' => Protocol::TYPE_ACTION,
            'requestId' => $requestId,
            'componentId' => self::COMPONENT_ID,
            'action' => $action,
            'payload' => $payload,
            'csrfToken' => self::CSRF_TOKEN,
            'signature' => $snapshot['signature'],
            'snapshot' => $snapshot,
        ];
    }

    /** @param array<string, mixed> $body */
    private function modelRequest(
        array $body,
        string $csrfToken = self::CSRF_TOKEN,
        ?string $signatureHeader = null,
        string $cookieToken = self::CSRF_TOKEN,
    ): FakeServerRequest {
        $bodyJson = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($signatureHeader === null) {
            $signatureHeader = base64_encode(hash_hmac('sha256', $bodyJson, self::HMAC_KEY, true));
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

    /** @return array{componentId: string, state: array, timestamp: int, protocolVersion: string, signature: string} */
    private function snapshot(
        array $state,
        string $componentId = self::COMPONENT_ID,
    ): array {
        $timestamp = time();
        $integrity = new SnapshotIntegrity(self::HMAC_KEY, 300);

        return [
            'componentId' => $componentId,
            'state' => $state,
            'timestamp' => $timestamp,
            'protocolVersion' => '3.1',
            'signature' => $integrity->signSnapshot($componentId, $state, $timestamp, '3.1'),
        ];
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
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

final class ModelIntegrationComponent extends Component
{
    /** @var list<string> */
    protected array $modelable = ['email', 'nickname', 'plan', 'tags', 'notify'];

    protected function validateModelValue(string $field, mixed $value): void
    {
        if ($field === 'email' && (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false)) {
            throw new ValidationException('The email is invalid', ['email' => ['The email is invalid']]);
        }

        if ($field === 'plan' && !in_array($value, ['basic', 'pro'], true)) {
            throw new ValidationException('The plan is invalid', ['plan' => ['The plan is invalid']]);
        }
    }

    public function handleAction(string $action, array $payload): array
    {
        throw new UnknownActionException('Unknown action: ' . $action);
    }
}

final class ModelIntegrationHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw new RuntimeException('Handler must not be called for targeted requests');
    }
}
