<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Sahil\Firefiber\Component;
use Sahil\Firefiber\Http\FirefiberMiddleware;
use Sahil\Firefiber\SnapshotIntegrity;
use Sahil\Firefiber\Storage\FileCacheReplayStorage;
use Sahil\Firefiber\Storage\FileCacheStateStorage;
use Sahil\Firefiber\Storage\StateStorageInterface;
use Sahil\Firefiber\Tests\Support\FakeServerRequest;

final class StateHydrationTest extends TestCase
{
    private const string HMAC_KEY = 'phase-3.2.3-hydration-key';

    private string $baseDir;
    private string $stateDir;
    private string $replayDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/firefiber_hydration_' . bin2hex(random_bytes(8));
        $this->stateDir = $this->baseDir . '/state';
        $this->replayDir = $this->baseDir . '/replay';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->replayDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
    }

    public function test_successful_restoration_hydrates_component_from_server_state(): void
    {
        $stateStorage = $this->stateStorage();
        $stateStorage->saveState('ff-counter', ['count' => 5]);

        $snapshot = $this->snapshot('ff-counter', ['count' => 5]);
        $middleware = $this->middleware(
            $stateStorage,
            static fn (string $id, array $state, array $data): Component => new CounterComponent(),
        );

        $response = $middleware->process(
            $this->request($this->payload('req-restore', 'ff-counter', 'increment', ['by' => 2], $snapshot)),
            new HydrationHandler(),
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->json($response);

        $this->assertSame(5, $body['result']['previous'], 'Component must be hydrated before the action runs');
        $this->assertSame(7, $body['result']['count']);
        $this->assertSame(7, $body['snapshot']['state']['count']);
        $this->assertSame(7, $stateStorage->retrieveState('ff-counter')['count']);

        $integrity = new SnapshotIntegrity(self::HMAC_KEY, 300);
        $this->assertSame(
            (int) $body['snapshot']['timestamp'],
            $integrity->verifySnapshot(
                'ff-counter',
                $body['snapshot']['state'],
                (int) $body['snapshot']['timestamp'],
                '3.1',
                $body['snapshot']['signature'],
            ),
            'Response snapshot must carry a valid fresh signature',
        );
    }

    public function test_missing_state_is_rejected_without_executing_action(): void
    {
        $stateStorage = $this->stateStorage();
        $factoryCalls = 0;
        $middleware = $this->middleware(
            $stateStorage,
            static function (string $id, array $state, array $data) use (&$factoryCalls): Component {
                $factoryCalls++;

                return new CounterComponent();
            },
        );

        $snapshot = $this->snapshot('ff-missing', ['count' => 0]);
        $response = $middleware->process(
            $this->request($this->payload('req-missing', 'ff-missing', 'increment', ['by' => 1], $snapshot)),
            new HydrationHandler(),
        );

        $this->assertSame(409, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertSame('error', $body['status']);
        $this->assertSame(5, $body['errorCode']);
        $this->assertStringContainsString('missing or expired', $body['error']);
        $this->assertSame(0, $factoryCalls, 'Missing state must not hydrate or execute a component');
        $this->assertFalse($stateStorage->hasState('ff-missing'));
    }

    public function test_expired_state_is_rejected_without_executing_action(): void
    {
        $stateStorage = $this->stateStorage();
        $stateStorage->saveState('ff-expired', ['count' => 9], 1);
        sleep(2);

        $factoryCalls = 0;
        $middleware = $this->middleware(
            $stateStorage,
            static function (string $id, array $state, array $data) use (&$factoryCalls): Component {
                $factoryCalls++;

                return new CounterComponent();
            },
        );

        $snapshot = $this->snapshot('ff-expired', ['count' => 9]);
        $response = $middleware->process(
            $this->request($this->payload('req-expired', 'ff-expired', 'increment', ['by' => 1], $snapshot)),
            new HydrationHandler(),
        );

        $this->assertSame(409, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertSame(5, $body['errorCode']);
        $this->assertSame(0, $factoryCalls, 'Expired state must not hydrate or execute a component');
        $this->assertFalse($stateStorage->hasState('ff-expired'));
    }

    public function test_tampered_snapshot_is_rejected_without_executing_action(): void
    {
        $stateStorage = $this->stateStorage();
        $stateStorage->saveState('ff-tampered', ['count' => 5]);

        $snapshot = $this->snapshot('ff-tampered', ['count' => 5]);
        $snapshot['state']['count'] = 999;

        $factoryCalls = 0;
        $middleware = $this->middleware(
            $stateStorage,
            static function (string $id, array $state, array $data) use (&$factoryCalls): Component {
                $factoryCalls++;

                return new CounterComponent();
            },
        );

        $response = $middleware->process(
            $this->request($this->payload('req-tampered', 'ff-tampered', 'increment', ['by' => 1], $snapshot)),
            new HydrationHandler(),
        );

        $this->assertSame(400, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertSame(6, $body['errorCode']);
        $this->assertStringContainsString('Invalid snapshot signature', $body['error']);
        $this->assertSame(0, $factoryCalls);
        $this->assertSame(5, $stateStorage->retrieveState('ff-tampered')['count']);
    }

    public function test_component_identity_mismatch_is_rejected_without_executing_action(): void
    {
        $stateStorage = $this->stateStorage();
        $stateStorage->saveState('ff-owner', ['count' => 3]);
        $stateStorage->saveState('ff-other', ['count' => 3]);

        // Snapshot is validly signed, but belongs to another component.
        $snapshot = $this->snapshot('ff-other', ['count' => 3]);

        $factoryCalls = 0;
        $middleware = $this->middleware(
            $stateStorage,
            static function (string $id, array $state, array $data) use (&$factoryCalls): Component {
                $factoryCalls++;

                return new CounterComponent();
            },
        );

        $response = $middleware->process(
            $this->request($this->payload('req-identity', 'ff-owner', 'increment', ['by' => 1], $snapshot)),
            new HydrationHandler(),
        );

        $this->assertSame(409, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertSame(6, $body['errorCode']);
        $this->assertStringContainsString('identity', $body['error']);
        $this->assertSame(0, $factoryCalls);
        $this->assertSame(3, $stateStorage->retrieveState('ff-owner')['count']);
        $this->assertSame(3, $stateStorage->retrieveState('ff-other')['count']);
    }

    public function test_state_is_persisted_and_fresh_snapshot_is_issued_after_action(): void
    {
        $stateStorage = $this->stateStorage();
        $stateStorage->saveState('ff-persist', ['count' => 1]);

        $middleware = $this->middleware(
            $stateStorage,
            static fn (string $id, array $state, array $data): Component => new CounterComponent(),
        );

        $firstSnapshot = $this->snapshot('ff-persist', ['count' => 1]);
        $first = $middleware->process(
            $this->request($this->payload('req-persist-1', 'ff-persist', 'increment', ['by' => 4], $firstSnapshot)),
            new HydrationHandler(),
        );

        $this->assertSame(200, $first->getStatusCode());
        $firstBody = $this->json($first);
        $this->assertSame(5, $firstBody['snapshot']['state']['count']);
        $this->assertSame(5, $stateStorage->retrieveState('ff-persist')['count']);

        // The fresh snapshot from the first response restores the next action.
        $second = $middleware->process(
            $this->request(
                $this->payload(
                    'req-persist-2',
                    'ff-persist',
                    'increment',
                    ['by' => 2],
                    $firstBody['snapshot'],
                ),
            ),
            new HydrationHandler(),
        );

        $this->assertSame(200, $second->getStatusCode());
        $secondBody = $this->json($second);
        $this->assertSame(5, $secondBody['result']['previous']);
        $this->assertSame(7, $secondBody['result']['count']);
        $this->assertSame(7, $stateStorage->retrieveState('ff-persist')['count']);
    }

    public function test_get_snapshot_returns_authoritative_persisted_state(): void
    {
        $stateStorage = $this->stateStorage();
        $stateStorage->saveState('ff-snapshot', ['count' => 42]);

        $middleware = $this->middleware($stateStorage);
        $response = $middleware->process(
            $this->request([
                'protocolVersion' => '3.1',
                'type' => 'getSnapshot',
                'requestId' => 'req-get-snapshot',
                'componentId' => 'ff-snapshot',
                'csrfToken' => 'csrf-token',
                'signature' => 'body-signature',
            ]),
            new HydrationHandler(),
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertSame(42, $body['state']['count']);
        $this->assertSame(42, $body['snapshot']['state']['count']);

        $integrity = new SnapshotIntegrity(self::HMAC_KEY, 300);
        $this->assertSame(
            (int) $body['snapshot']['timestamp'],
            $integrity->verifySnapshot(
                'ff-snapshot',
                $body['snapshot']['state'],
                (int) $body['snapshot']['timestamp'],
                '3.1',
                $body['snapshot']['signature'],
            ),
        );
    }

    public function test_state_is_restored_and_persisted_across_separate_php_processes(): void
    {
        $stateStorage = $this->stateStorage();
        $stateStorage->saveState('ff-cross', ['count' => 10]);

        $snapshot = $this->snapshot('ff-cross', ['count' => 10]);
        $workerScript = $this->baseDir . '/cross_process_worker.php';
        $output = $this->baseDir . '/cross_process_output.json';
        $error = $this->baseDir . '/cross_process_error.log';

        file_put_contents($workerScript, <<<'PHP'
<?php
require $argv[1];

$stateDir = $argv[2];
$replayDir = $argv[3];
$componentId = $argv[4];
$requestId = $argv[5];
$snapshot = json_decode($argv[6], true, 512, JSON_THROW_ON_ERROR);
$key = $argv[7];
$output = $argv[8];

final class CrossProcessCounter extends \Sahil\Firefiber\Component
{
    public function handleAction(string $action, array $payload): array
    {
        $previous = (int) ($this->state['count'] ?? 0);

        if ($action !== 'increment') {
            throw new \RuntimeException('Unknown action: ' . $action);
        }

        $by = (int) ($payload['by'] ?? 1);
        $this->updateState(['count' => $previous + $by]);

        return ['previous' => $previous, 'count' => $previous + $by];
    }
}

$stateStorage = new \Sahil\Firefiber\Storage\FileCacheStateStorage($stateDir);
$replayStorage = new \Sahil\Firefiber\Storage\FileCacheReplayStorage($replayDir);
$middleware = new \Sahil\Firefiber\Http\FirefiberMiddleware(
    $replayStorage,
    $key,
    300,
    'cross-process-worker',
    $stateStorage,
    static fn (string $id, array $state, array $data): \Sahil\Firefiber\Component => new CrossProcessCounter(),
);

$data = [
    'protocolVersion' => '3.1',
    'type' => 'handleAction',
    'requestId' => $requestId,
    'componentId' => $componentId,
    'action' => 'increment',
    'payload' => ['by' => 1],
    'csrfToken' => 'csrf-token',
    'signature' => 'body-signature',
    'snapshot' => $snapshot,
];

$body = json_encode($data, JSON_THROW_ON_ERROR);
$signature = base64_encode(hash_hmac('sha256', $body, $key, true));
$request = new \Sahil\Firefiber\Tests\Support\FakeServerRequest(
    $body,
    [
        'X-Firefiber-Target' => '1',
        'X-Csrf-Token' => 'csrf-token',
        'X-Firefiber-Signature' => $signature,
    ],
    ['firefiber_csrf' => 'csrf-token'],
);

$handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
    public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        throw new \RuntimeException('Handler must not be called for targeted requests');
    }
};

$response = $middleware->process($request, $handler);
file_put_contents($output, json_encode([
    'status' => $response->getStatusCode(),
    'body' => (string) $response->getBody(),
], JSON_THROW_ON_ERROR));
PHP);

        $bootstrap = realpath(__DIR__ . '/bootstrap.php');
        $this->assertNotFalse($bootstrap);

        $process = proc_open(
            [
                PHP_BINARY,
                $workerScript,
                $bootstrap,
                $this->stateDir,
                $this->replayDir,
                'ff-cross',
                'req-cross-process',
                json_encode($snapshot, JSON_THROW_ON_ERROR),
                self::HMAC_KEY,
                $output,
            ],
            [
                1 => ['file', $error, 'a'],
                2 => ['file', $error, 'a'],
            ],
            $pipes,
        );

        $this->assertIsResource($process);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, file_get_contents($error));
        $this->assertFileExists($output);

        $result = json_decode(file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(200, $result['status']);

        $body = json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(10, $body['result']['previous'], 'Separate process must restore persisted state');
        $this->assertSame(11, $body['result']['count']);
        $this->assertSame(11, $body['snapshot']['state']['count']);

        $this->assertSame(
            11,
            $stateStorage->retrieveState('ff-cross')['count'],
            'Separate process must persist the updated state',
        );
    }

    private function middleware(StateStorageInterface $stateStorage, ?callable $componentFactory = null): FirefiberMiddleware
    {
        return new FirefiberMiddleware(
            new FileCacheReplayStorage($this->replayDir),
            self::HMAC_KEY,
            300,
            'hydration-worker',
            $stateStorage,
            $componentFactory,
        );
    }

    private function stateStorage(): FileCacheStateStorage
    {
        return new FileCacheStateStorage($this->stateDir);
    }

    /** @return array{componentId: string, state: array, timestamp: int, protocolVersion: string, signature: string} */
    private function snapshot(string $componentId, array $state, ?int $timestamp = null): array
    {
        $timestamp ??= time();
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
    private function payload(
        string $requestId,
        string $componentId,
        string $action,
        array $payload,
        array $snapshot,
    ): array {
        return [
            'protocolVersion' => '3.1',
            'type' => 'handleAction',
            'requestId' => $requestId,
            'componentId' => $componentId,
            'action' => $action,
            'payload' => $payload,
            'csrfToken' => 'csrf-token',
            'signature' => 'body-signature',
            'snapshot' => $snapshot,
        ];
    }

    /** @param array<string, mixed> $data */
    private function request(array $data): FakeServerRequest
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $signature = base64_encode(hash_hmac('sha256', $body, self::HMAC_KEY, true));

        return new FakeServerRequest(
            $body,
            [
                'X-Firefiber-Target' => '1',
                'X-Csrf-Token' => 'csrf-token',
                'X-Firefiber-Signature' => $signature,
            ],
            ['firefiber_csrf' => 'csrf-token'],
        );
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

final class CounterComponent extends Component
{
    public function handleAction(string $action, array $payload): array
    {
        $previous = (int) ($this->state['count'] ?? 0);

        if ($action === 'increment') {
            $by = (int) ($payload['by'] ?? 1);
            $this->updateState(['count' => $previous + $by]);

            return ['previous' => $previous, 'count' => $previous + $by];
        }

        if ($action === 'read') {
            return ['previous' => $previous, 'count' => $previous];
        }

        throw new RuntimeException('Unknown action: ' . $action);
    }
}

final class HydrationHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw new RuntimeException('Handler must not be called for targeted requests');
    }
}
