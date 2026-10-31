<?php
require __DIR__ . '/tests/bootstrap.php';
use Sahil\Firefiber\Http\FirefiberMiddleware;
use Sahil\Firefiber\Storage\FileCacheReplayStorage;
use Sahil\Firefiber\Storage\FileCacheStateStorage;
use Sahil\Firefiber\SnapshotIntegrity;
use Sahil\Firefiber\Tests\Support\FakeServerRequest;

$stateStorage = new FileCacheStateStorage(sys_get_temp_dir() . '/ff_debug6_state/');
$stateStorage->saveState("ff-click-integration", ["count" => 1]);

$middleware = new FirefiberMiddleware(
    new FileCacheReplayStorage(sys_get_temp_dir() . '/ff_debug6_replay/'),
    "phase-4.2-action-verification-key",
    300,
    "worker",
    $stateStorage,
    static function (string $id, array $state, array $data): Component { return new ClickIntegrationCounter(); },
    0
);

$requestId = 'req-exp-' . bin2hex(random_bytes(8));
$signedSnapshot = (new SnapshotIntegrity("phase-4.2-action-verification-key", 300))
    ->signSnapshot("ff-click-integration", ["count" => 1], time() - 600, "3.1");

$body = [
    'protocolVersion' => '3.1',
    'type' => 'handleAction',
    'componentId' => 'ff-click-integration',
    'action' => 'increment',
    'payload' => [],
    'requestId' => $requestId,
    'csrfToken' => 'verification-csrf-token',
    'signature' => (new SnapshotIntegrity("phase-4.2-action-verification-key", 300))->signSnapshot("ff-click-integration", ["count" => 1], time(), "3.1"),
    'snapshot' => $signedSnapshot,
];
$bodyJson = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$signature = base64_encode(hash_hmac('sha256', $bodyJson, "phase-4.2-action-verification-key", true));

$req = new FakeServerRequest(
    $bodyJson,
    ['X-Firefiber-Target' => '1', 'X-Csrf-Token' => 'verification-csrf-token', 'X-Firefiber-Signature' => $signature],
    ['firefiber_csrf' => 'verification-csrf-token']
);

class DbgHandler implements Psr\Http\Server\RequestHandlerInterface {
    public function handle(Psr\Http\Message\ServerRequestInterface $request): Psr\Http\Message\ResponseInterface {
        return new Slim\Psr7\Response(200, [], 'handler');
    }
}
$res = $middleware->process($req, new DbgHandler());
echo "STATUS: " . $res->getStatusCode() . "\n";
echo "BODY: " . $res->getBody() . "\n";
echo "--- exp check: " . (time() - 600 >= time() - 300 ? "IN" : "OUT") . " window ---\n";
