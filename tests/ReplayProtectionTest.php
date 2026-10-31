<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Sahil\Firefiber\Http\FirefiberMiddleware;
use Sahil\Firefiber\SnapshotIntegrity;
use Sahil\Firefiber\Storage\FileCacheReplayStorage;
use Sahil\Firefiber\Storage\ReplayStorageInterface;
use Sahil\Firefiber\Tests\Support\FakeServerRequest;

final class ReplayProtectionTest extends TestCase
{
    private const string HMAC_KEY = 'phase-3.2.2.3-test-key';

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/firefiber_replay_' . bin2hex(random_bytes(8));
        mkdir($this->cacheDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->cacheDir);
    }

    public function test_identical_replay_returns_stored_response_without_reexecuting(): void
    {
        $storage = $this->storage();
        $handler = new RecordingHandler();

        $request = $this->payload('req-identical', ['value' => 1]);

        $first = $this->middleware($storage, 'worker-a');
        $firstResponse = $first->process($this->request($request), $handler);

        $this->assertSame(200, $firstResponse->getStatusCode());
        $this->assertSame(1, $first->executions);

        // A different worker replays the completed request from storage.
        $second = $this->middleware($storage, 'worker-b');
        $secondResponse = $second->process($this->request($request), $handler);

        $this->assertSame(200, $secondResponse->getStatusCode());
        $this->assertSame((string) $firstResponse->getBody(), (string) $secondResponse->getBody());
        $this->assertSame(0, $second->executions, 'Replayed request must not execute the action again');
        $this->assertSame(0, $handler->calls, 'Targeted requests must not fall through to the handler');
    }

    public function test_reusing_request_id_with_different_payload_is_rejected(): void
    {
        $storage = $this->storage();
        $handler = new RecordingHandler();
        $middleware = $this->middleware($storage, 'worker-a');

        $original = $this->payload('req-reuse', ['value' => 1]);
        $originalResponse = $middleware->process($this->request($original), $handler);

        $this->assertSame(200, $originalResponse->getStatusCode());
        $this->assertSame(1, $middleware->executions);

        $reused = $this->payload('req-reuse', ['value' => 2]);
        $conflict = $middleware->process($this->request($reused), $handler);

        $this->assertSame(409, $conflict->getStatusCode());
        $this->assertSame(1, $middleware->executions, 'Rejected reuse must not execute the action');

        $body = json_decode((string) $conflict->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('error', $body['status']);
        $this->assertSame(10, $body['errorCode']);
        $this->assertStringContainsString('different payload', $body['error']);

        // The original completed response is still intact and replayable.
        $replay = $this->middleware($storage, 'worker-c');
        $replayed = $replay->process($this->request($original), $handler);
        $this->assertSame((string) $originalResponse->getBody(), (string) $replayed->getBody());
        $this->assertSame(0, $replay->executions);
    }

    public function test_concurrent_requests_cannot_claim_the_same_action_simultaneously(): void
    {
        $workerScript = $this->cacheDir . '/claim_worker.php';
        file_put_contents($workerScript, <<<'PHP'
<?php
require $argv[1];

$storage = new Sahil\Firefiber\Storage\FileCacheReplayStorage($argv[2]);
while (!file_exists($argv[3])) {
    usleep(1000);
}

$status = $storage->claimRequestWithOwner($argv[4], $argv[5], $argv[6]);
file_put_contents($argv[7], json_encode($status));
PHP);

        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $start = $this->cacheDir . '/start';
        $resultA = $this->cacheDir . '/result-a.json';
        $resultB = $this->cacheDir . '/result-b.json';
        $errorA = $this->cacheDir . '/error-a.log';
        $errorB = $this->cacheDir . '/error-b.log';

        $descriptors = static fn (string $errorFile): array => [
            1 => ['file', $errorFile, 'a'],
            2 => ['file', $errorFile, 'a'],
        ];

        $processA = proc_open(
            [PHP_BINARY, $workerScript, $autoload, $this->cacheDir, $start, 'req-concurrent', 'fp-concurrent', 'worker-a', $resultA],
            $descriptors($errorA),
            $pipesA,
        );
        $processB = proc_open(
            [PHP_BINARY, $workerScript, $autoload, $this->cacheDir, $start, 'req-concurrent', 'fp-concurrent', 'worker-b', $resultB],
            $descriptors($errorB),
            $pipesB,
        );

        $this->assertIsResource($processA);
        $this->assertIsResource($processB);

        usleep(100000);
        touch($start);

        $exitA = proc_close($processA);
        $exitB = proc_close($processB);

        $this->assertSame(0, $exitA, file_get_contents($errorA));
        $this->assertSame(0, $exitB, file_get_contents($errorB));

        $statuses = [
            json_decode(file_get_contents($resultA), true, 512, JSON_THROW_ON_ERROR),
            json_decode(file_get_contents($resultB), true, 512, JSON_THROW_ON_ERROR),
        ];

        $this->assertSame(
            1,
            count(array_filter($statuses, static fn (mixed $status): bool => $status === 'claimed')),
            'Exactly one concurrent request may claim the action',
        );
        $this->assertContains('processing', $statuses);
    }

    public function test_already_processing_request_receives_conflict_response(): void
    {
        $storage = $this->storage();
        $handler = new RecordingHandler();
        $middleware = $this->middleware($storage, 'worker-a');
        $request = $this->payload('req-busy', ['value' => 1]);
        $fingerprint = $middleware->fingerprintFor($request);

        $this->assertSame(
            ReplayStorageInterface::CLAIMED,
            $storage->claimRequestWithOwner('req-busy', $fingerprint, 'worker-holder'),
        );

        $response = $middleware->process($this->request($request), $handler);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(0, $middleware->executions);

        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('error', $body['status']);
        $this->assertSame(10, $body['errorCode']);
        $this->assertStringContainsString('already processing', $body['error']);

        $this->assertTrue($storage->releaseClaim('req-busy', $fingerprint, 'worker-holder'));
    }

    public function test_completed_request_remains_replayable_after_claim_is_released(): void
    {
        $storage = $this->storage();
        $responseData = [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"status":"ok","value":1}',
        ];

        $this->assertSame(
            ReplayStorageInterface::CLAIMED,
            $storage->claimRequestWithOwner('req-completed', 'fp-completed', 'worker-a'),
        );
        $this->assertTrue($storage->recordCompletion('req-completed', 'fp-completed', $responseData));
        $this->assertTrue($storage->releaseClaim('req-completed', 'fp-completed', 'worker-a'));

        $completed = $storage->isCompleted('req-completed', 'fp-completed');
        $this->assertIsArray($completed);
        $this->assertSame(200, $completed[0]);
        $this->assertSame($responseData, $completed[1]);
        $this->assertIsInt($completed[2]);

        $this->assertSame(
            ReplayStorageInterface::COMPLETED,
            $storage->claimRequestWithOwner('req-completed', 'fp-completed', 'worker-b'),
        );
        $this->assertSame(
            ReplayStorageInterface::REUSED,
            $storage->claimRequestWithOwner('req-completed', 'fp-other', 'worker-b'),
        );
    }

    public function test_exception_releases_claim_and_does_not_lock_request_permanently(): void
    {
        $storage = $this->storage();
        $handler = new RecordingHandler();
        $middleware = $this->middleware($storage, 'worker-a');
        $request = $this->payload('req-exception', ['value' => 1]);
        $fingerprint = $middleware->fingerprintFor($request);

        $middleware->executor = static function (array $data): array {
            throw new RuntimeException('action failed');
        };

        try {
            $middleware->process($this->request($request), $handler);
            $this->fail('Expected the action exception to propagate');
        } catch (RuntimeException $exception) {
            $this->assertSame('action failed', $exception->getMessage());
        }

        $this->assertSame(1, $middleware->executions);
        $this->assertFalse($storage->isCompleted('req-exception', $fingerprint));

        // The claim must be gone, allowing a different worker to retry.
        $this->assertSame(
            ReplayStorageInterface::CLAIMED,
            $storage->claimRequestWithOwner('req-exception', $fingerprint, 'probe-worker'),
        );
        $this->assertTrue($storage->releaseClaim('req-exception', $fingerprint, 'probe-worker'));

        $retry = $this->middleware($storage, 'worker-b');
        $retry->executor = static fn (array $data): array => ['status' => 'ok', 'retried' => true];

        $response = $retry->process($this->request($request), $handler);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $retry->executions);
        $this->assertStringContainsString('retried', (string) $response->getBody());
    }

    public function test_different_worker_cannot_release_another_workers_claim(): void
    {
        $storage = $this->storage();

        $this->assertSame(
            ReplayStorageInterface::CLAIMED,
            $storage->claimRequestWithOwner('req-owned', 'fp-owned', 'worker-a'),
        );

        $this->assertFalse($storage->releaseClaim('req-owned', 'fp-owned', 'worker-b'));
        $this->assertSame(
            ReplayStorageInterface::PROCESSING,
            $storage->claimRequestWithOwner('req-owned', 'fp-owned', 'worker-b'),
        );

        // Even the owner cannot release with the wrong fingerprint.
        $this->assertFalse($storage->releaseClaim('req-owned', 'fp-wrong', 'worker-a'));

        $this->assertTrue($storage->releaseClaim('req-owned', 'fp-owned', 'worker-a'));
        $this->assertSame(
            ReplayStorageInterface::CLAIMED,
            $storage->claimRequestWithOwner('req-owned', 'fp-owned', 'worker-b'),
        );
        $this->assertTrue($storage->releaseClaim('req-owned', 'fp-owned', 'worker-b'));
    }

    public function test_invalid_timestamps_and_modified_snapshot_signatures_are_rejected_before_execution(): void
    {
        $integrity = new SnapshotIntegrity(self::HMAC_KEY, 300);
        $state = ['count' => 1];
        $timestamp = time();
        $signature = $integrity->signSnapshot('ff-component', $state, $timestamp, '3.1');

        $this->assertSame(
            $timestamp,
            $integrity->verifySnapshot('ff-component', $state, $timestamp, '3.1', $signature),
        );
        $this->assertFalse(
            $integrity->verifySnapshot('ff-component', ['count' => 2], $timestamp, '3.1', $signature),
            'Modified state must invalidate the snapshot',
        );
        $this->assertFalse(
            $integrity->verifySnapshot('ff-component', $state, $timestamp + 1, '3.1', $signature),
            'Modified timestamp must invalidate the snapshot',
        );
        $this->assertFalse(
            $integrity->verifySnapshot('ff-component', $state, $timestamp, '3.0', $signature),
            'Modified protocol version must invalidate the snapshot',
        );
        $this->assertFalse(
            $integrity->verifySnapshot('ff-component', $state, $timestamp, '3.1', 'not-a-valid-signature'),
            'Modified signature must be rejected',
        );

        $this->assertTrue($integrity->checkReplayWindow($timestamp));
        $this->assertFalse($integrity->checkReplayWindow($timestamp - 301));
        $this->assertFalse($integrity->checkReplayWindow($timestamp + 301));

        $storage = $this->storage();
        $handler = new RecordingHandler();
        $middleware = $this->middleware($storage, 'worker-a');

        $stale = $this->payload('req-stale', ['value' => 1]);
        $stale['timestamp'] = time() - 1000;
        $staleResponse = $middleware->process($this->request($stale), $handler);
        $this->assertContains($staleResponse->getStatusCode(), [400, 409]);
        $this->assertSame(0, $middleware->executions, 'Stale timestamp must be rejected before execution');

        $tampered = $this->payload('req-tampered', ['value' => 1]);
        $tampered['state'] = ['count' => 1];
        $tampered['timestamp'] = time();
        $tampered['snapshotSignature'] = $integrity->signSnapshot(
            'ff-component',
            ['count' => 2],
            $tampered['timestamp'],
            '3.1',
        );
        $tamperedResponse = $middleware->process($this->request($tampered), $handler);
        $this->assertSame(400, $tamperedResponse->getStatusCode());
        $this->assertSame(0, $middleware->executions, 'Modified snapshot must be rejected before execution');

        $valid = $this->payload('req-valid-snapshot', ['value' => 1]);
        $valid['state'] = ['count' => 1];
        $valid['timestamp'] = time();
        $valid['snapshotSignature'] = $integrity->signSnapshot(
            'ff-component',
            ['count' => 1],
            $valid['timestamp'],
            '3.1',
        );
        $validResponse = $middleware->process($this->request($valid), $handler);
        $this->assertSame(200, $validResponse->getStatusCode());
        $this->assertSame(1, $middleware->executions);
    }

    private function storage(): FileCacheReplayStorage
    {
        return new FileCacheReplayStorage($this->cacheDir);
    }

    private function middleware(ReplayStorageInterface $storage, string $ownerId): TestableFirefiberMiddleware
    {
        return new TestableFirefiberMiddleware($storage, self::HMAC_KEY, 300, $ownerId);
    }

    /** @return array<string, mixed> */
    private function payload(string $requestId, array $payload): array
    {
        return [
            'protocolVersion' => '3.1',
            'type' => 'handleAction',
            'requestId' => $requestId,
            'componentId' => 'ff-component',
            'action' => 'increment',
            'payload' => $payload,
            'csrfToken' => 'csrf-token',
            'signature' => 'body-signature',
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

final class TestableFirefiberMiddleware extends FirefiberMiddleware
{
    public int $executions = 0;

    /** @var callable|null */
    public $executor = null;

    protected function executeAction(array $data): array
    {
        $this->executions++;

        if ($this->executor !== null) {
            return ($this->executor)($data);
        }

        return parent::executeAction($data);
    }

    /** @param array<string, mixed> $data */
    public function fingerprintFor(array $data): string
    {
        return $this->calculateFingerprint($data);
    }
}

final class RecordingHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->calls++;

        throw new RuntimeException('The Firefiber handler must not be called for targeted requests');
    }
}
