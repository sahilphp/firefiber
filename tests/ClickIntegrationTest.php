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
use Sahil\Firefiber\Tests\Support\FakeServerRequest;

final class ClickIntegrationTest extends TestCase
{
    private const string HMAC_KEY = 'phase-4.2-click-integration-key';
    private const string COMPONENT_ID = 'ff-click-integration';
    private const string CSRF_TOKEN = 'integration-csrf-token';

    private string $baseDir;
    private string $stateDir;
    private string $replayDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/firefiber_click_' . bin2hex(random_bytes(8));
        $this->stateDir = $this->baseDir . '/state';
        $this->replayDir = $this->baseDir . '/replay';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->replayDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
    }

    public function test_click_dispatches_one_valid_request_and_server_executes_the_action(): void
    {
        $stateStorage = new FileCacheStateStorage($this->stateDir);
        $stateStorage->saveState(self::COMPONENT_ID, ['count' => 1]);

        $snapshot = $this->snapshot(['count' => 1]);
        $runner = realpath(__DIR__ . '/js/support/click-integration-runner.cjs');
        $runtime = realpath(__DIR__ . '/../js/firefiber.js');

        $this->assertNotFalse($runner);
        $this->assertNotFalse($runtime);

        $outputFile = $this->baseDir . '/click-runner.json';
        $errorFile = $this->baseDir . '/click-runner.err';
        $nodeBinary = getenv('NODE_BINARY') ?: 'node';

        $process = proc_open(
            [
                $nodeBinary,
                $runner,
                $runtime,
                self::COMPONENT_ID,
                json_encode($snapshot, JSON_THROW_ON_ERROR),
                self::CSRF_TOKEN,
                self::HMAC_KEY,
            ],
            [
                1 => ['file', $outputFile, 'w'],
                2 => ['file', $errorFile, 'w'],
            ],
            $pipes,
        );

        $this->assertIsResource($process);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, is_file($errorFile) ? file_get_contents($errorFile) : '');
        $this->assertFileExists($outputFile);

        $runnerResult = json_decode(
            (string) file_get_contents($outputFile),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(0, $runnerResult['requestsBeforeClick'], 'Click must be the first request trigger');
        $this->assertCount(1, $runnerResult['requests'], 'One click must dispatch exactly one action request');
        $this->assertSame('firefiber:success', $runnerResult['event']['type']);
        $this->assertSame(2, $runnerResult['event']['detail']['data']['result']['count']);

        $request = $runnerResult['requests'][0];
        $headers = $request['options']['headers'];
        $body = $request['options']['body'];

        $this->assertSame('https://firefiber.test/firefiber', $request['url']);
        $this->assertSame('POST', $request['options']['method']);
        $this->assertSame('1', $headers['X-Firefiber-Target']);
        $this->assertSame(self::CSRF_TOKEN, $headers['X-Csrf-Token']);
        $this->assertSame(
            base64_encode(hash_hmac('sha256', $body, self::HMAC_KEY, true)),
            $headers['X-Firefiber-Signature'],
            'Browser request must carry a valid body HMAC',
        );

        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('3.1', $payload['protocolVersion']);
        $this->assertSame('handleAction', $payload['type']);
        $this->assertNotSame('', $payload['requestId']);
        $this->assertSame(self::COMPONENT_ID, $payload['componentId']);
        $this->assertSame('increment', $payload['action']);
        $this->assertSame(self::CSRF_TOKEN, $payload['csrfToken']);
        $this->assertSame($snapshot['signature'], $payload['snapshot']['signature']);
        $this->assertSame(['count' => 1], $payload['snapshot']['state']);

        $factoryCalls = 0;
        $middleware = new FirefiberMiddleware(
            new FileCacheReplayStorage($this->replayDir),
            self::HMAC_KEY,
            300,
            'click-integration-worker',
            $stateStorage,
            static function (string $id, array $state, array $data) use (&$factoryCalls): Component {
                $factoryCalls++;

                return new ClickIntegrationCounter();
            },
        );

        $response = $middleware->process(
            new FakeServerRequest(
                $body,
                [
                    'X-Firefiber-Target' => $headers['X-Firefiber-Target'],
                    'X-Csrf-Token' => $headers['X-Csrf-Token'],
                    'X-Firefiber-Signature' => $headers['X-Firefiber-Signature'],
                ],
                ['firefiber_csrf' => self::CSRF_TOKEN],
            ),
            new ClickIntegrationHandler(),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $factoryCalls, 'Server must hydrate and execute the component once');

        $responseBody = json_decode(
            (string) $response->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(1, $responseBody['result']['previous']);
        $this->assertSame(2, $responseBody['result']['count']);
        $this->assertSame(2, $responseBody['snapshot']['state']['count']);
        $this->assertSame(2, $stateStorage->retrieveState(self::COMPONENT_ID)['count']);
    }

    /** @return array{componentId: string, state: array, timestamp: int, protocolVersion: string, signature: string} */
    private function snapshot(array $state): array
    {
        $timestamp = time();
        $integrity = new SnapshotIntegrity(self::HMAC_KEY, 300);

        return [
            'componentId' => self::COMPONENT_ID,
            'state' => $state,
            'timestamp' => $timestamp,
            'protocolVersion' => '3.1',
            'signature' => $integrity->signSnapshot(self::COMPONENT_ID, $state, $timestamp, '3.1'),
        ];
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

