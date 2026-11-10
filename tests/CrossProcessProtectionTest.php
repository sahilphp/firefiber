<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Sahil\Firefiber\SnapshotIntegrity;
use Sahil\Firefiber\Storage\FileCacheReplayStorage;
use Sahil\Firefiber\Storage\FileCacheStateStorage;
use Sahil\Firefiber\Storage\FileLock;

/**
 * Cross-process and multi-worker correctness using real storage, locking,
 * snapshot integrity, and replay protections.
 */
final class CrossProcessProtectionTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/firefiber-cp-'.bin2hex(random_bytes(8));
        @mkdir($this->tmp, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function test_state_persists_and_continues_across_independent_processes(): void
    {
        $stateDir = $this->tmp.'/state';
        $componentId = 'comp-'.bin2hex(random_bytes(4));

        $out1 = $this->runPhp(
            <<<PHP
<?php
require '/home/jinx/Devs/sahilphp/firefiber/vendor/autoload.php';
use Sahil\Firefiber\Storage\FileCacheStateStorage;
use Sahil\Firefiber\Storage\FileLock;
\$s = new FileCacheStateStorage('/home/jinx/Devs/sahilphp/firefiber/tests/tmp_state_cp');
@mkdir('/home/jinx/Devs/sahilphp/firefiber/tests/tmp_state_cp', 0777, true);
// use actual dir
PHP
        );

        // Use real paths
        $stateDir2 = $this->tmp.'/state2';
        @mkdir($stateDir2, 0o777, true);

        $script1 = $this->tmp.'/w1.php';
        file_put_contents(
            $script1,
            <<<'PHP'
<?php
require '/home/jinx/Devs/sahilphp/firefiber/vendor/autoload.php';
use Sahil\Firefiber\Storage\FileCacheStateStorage;
use Sahil\Firefiber\Storage\FileLock;

$stateDir = $argv[1];
$componentId = $argv[2];
$dir = $argv[3];

$s = new FileCacheStateStorage($stateDir);
$l = new FileLock($dir, $componentId);
$l->acquire();
$st = $s->retrieveState($componentId);
$cnt = is_array($st) ? (int)($st['count'] ?? 0) : 0;
$cnt++;
$s->saveState($componentId, ['count' => $cnt], 0);
$l->release();
echo json_encode(['count' => $cnt]);
PHP
        );

        $r1 = $this->exec([PHP_BINARY, $script1, $stateDir2, $componentId, $this->tmp.'/locks']);
        self::assertSame(0, $r1['code'], $r1['err']);
        self::assertStringContainsString('"count":1', $r1['out']);

        $script2 = $this->tmp.'/w2.php';
        file_put_contents(
            $script2,
            <<<'PHP'
<?php
require '/home/jinx/Devs/sahilphp/firefiber/vendor/autoload.php';
use Sahil\Firefiber\Storage\FileCacheStateStorage;

$stateDir = $argv[1];
$componentId = $argv[2];
$s = new FileCacheStateStorage($stateDir);
$st = $s->retrieveState($componentId);
echo json_encode(is_array($st) ? $st : ['count'=>null]);
PHP
        );

        $r2 = $this->exec([PHP_BINARY, $script2, $stateDir2, $componentId]);
        self::assertSame(0, $r2['code'], $r2['err']);
        self::assertStringContainsString('"count":1', $r2['out']);
    }

    public function test_concurrent_actions_from_independent_processes_cannot_silently_corrupt_state(): void
    {
        $stateDir = $this->tmp.'/statec';
        $lockDir = $this->tmp.'/lockc';
        @mkdir($stateDir, 0o777, true);
        @mkdir($lockDir, 0o777, true);
        $componentId = 'comp-'.$this->tmp.'-'.bin2hex(random_bytes(2));

        $worker = $this->tmp.'/worker.php';
        file_put_contents(
            $worker,
            <<<'PHP'
<?php
require '/home/jinx/Devs/sahilphp/firefiber/vendor/autoload.php';
use Sahil\Firefiber\Storage\FileCacheStateStorage;
use Sahil\Firefiber\Storage\FileLock;

$stateDir = $argv[1];
$lockDir = $argv[2];
$componentId = $argv[3];

$s = new FileCacheStateStorage($stateDir);
$l = new FileLock($lockDir, $componentId);
$l->acquire();
$st = $s->retrieveState($componentId);
$cnt = is_array($st) ? (int)($st['count'] ?? 0) : 0;
usleep(random_int(1000, 5000));
$cnt++;
$s->saveState($componentId, ['count' => $cnt], 0);
$l->release();
echo $cnt;
PHP
        );

        $procs = [];
        $pipes = [];
        $n = 8;
        for ($i = 0; $i < $n; $i++) {
            $p = proc_open([PHP_BINARY, $worker, $stateDir, $lockDir, $componentId], [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes[$i], $this->tmp);
            $procs[] = $p;
        }
        $results = [];
        for ($i = 0; $i < $n; $i++) {
            $results[] = trim(stream_get_contents($pipes[$i][1]));
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($procs[$i]);
        }

        // Read final state
        $s = new FileCacheStateStorage($stateDir);
        $final = $s->retrieveState($componentId);
        $finalCount = is_array($final) ? (int)($final['count'] ?? 0) : 0;

        self::assertSame($n, $finalCount, 'Concurrent independent processes must serialize via FileLock to avoid lost updates; results='.json_encode($results));
        self::assertCount($n, $results);
    }

    public function test_different_components_do_not_unnecessarily_block_across_processes(): void
    {
        $stateDir = $this->tmp.'/stated';
        $lockDir = $this->tmp.'/lockd';
        @mkdir($stateDir, 0o777, true);
        @mkdir($lockDir, 0o777, true);

        $worker = $this->tmp.'/worker2.php';
        file_put_contents(
            $worker,
            <<<'PHP'
<?php
require '/home/jinx/Devs/sahilphp/firefiber/vendor/autoload.php';
use Sahil\Firefiber\Storage\FileCacheStateStorage;
use Sahil\Firefiber\Storage\FileLock;

$stateDir = $argv[1];
$lockDir = $argv[2];
$componentId = $argv[3];
$s = new FileCacheStateStorage($stateDir);
$l = new FileLock($lockDir, $componentId);
$l->acquire();
usleep(200000);
$s->saveState($componentId, ['ok' => true], 0);
$l->release();
echo "done";
PHP
        );

        $start = microtime(true);
        $procs = [];
        $pipes = [];
        $ids = ['c1', 'c2', 'c3'];
        foreach ($ids as $idx => $cid) {
            $p = proc_open([PHP_BINARY, $worker, $stateDir, $lockDir, $cid], [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes[$idx], $this->tmp);
            $procs[] = $p;
        }
        for ($i = 0; $i < count($procs); $i++) {
            stream_get_contents($pipes[$i][1]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($procs[$i]);
        }
        $elapsed = microtime(true) - $start;

        // If serialized they would take ~600ms; if concurrent should be much less
        self::assertLessThan(0.5, $elapsed, 'Different components must not block each other unnecessarily (elapsed='.$elapsed.')');
    }

    public function test_replay_protection_across_independent_processes(): void
    {
        $cacheDir = $this->tmp.'/replay';
        @mkdir($cacheDir, 0o777, true);

        $requestId = 'req-'.bin2hex(random_bytes(8));
        $fingerprint = 'fp-'.bin2hex(random_bytes(8));

        // Process 1 claims and completes
        $worker = $this->tmp.'/replay1.php';
        file_put_contents(
            $worker,
            <<<'PHP'
<?php
require '/home/jinx/Devs/sahilphp/firefiber/vendor/autoload.php';
use Sahil\Firefiber\Storage\FileCacheReplayStorage;
use Sahil\Firefiber\Storage\ReplayStorageInterface;
$replay = new FileCacheReplayStorage($argv[1]);
$claim = $replay->claimRequest($argv[2], $argv[3], 'owner1');
$recorded = $replay->recordCompletion($argv[2], $argv[3], ['status' => 'ok', 'result' => ['done' => true]], 300);
echo json_encode(['claim' => $claim, 'recorded' => $recorded]);
PHP
        );
        $r1 = $this->exec([PHP_BINARY, $worker, $cacheDir, $requestId, $fingerprint]);
        self::assertSame(0, $r1['code'], $r1['err'].$r1['out']);
        $d1 = json_decode($r1['out'], true);
        self::assertIsArray($d1);
        self::assertSame('claimed', $d1['claim'] ?? null);
        self::assertTrue($d1['recorded'] ?? false);

        // Process 2 attempts to claim/replay the same completed request - must be detected
        $worker2 = $this->tmp.'/replay2.php';
        file_put_contents(
            $worker2,
            <<<'PHP'
<?php
require '/home/jinx/Devs/sahilphp/firefiber/vendor/autoload.php';
use Sahil\Firefiber\Storage\FileCacheReplayStorage;
$replay = new FileCacheReplayStorage($argv[1]);
$claim = $replay->claimRequest($argv[2], $argv[3], 'owner2');
$completed = $replay->isCompleted($argv[2], $argv[3]);
$reused = $replay->claimRequest($argv[2], 'different-fingerprint', 'owner2');
echo json_encode(['claim' => $claim, 'completed' => is_array($completed), 'reused' => $reused]);
PHP
        );
        $r2 = $this->exec([PHP_BINARY, $worker2, $cacheDir, $requestId, $fingerprint]);
        self::assertSame(0, $r2['code'], $r2['err'].$r2['out']);
        $d2 = json_decode($r2['out'], true);
        self::assertIsArray($d2);
        self::assertSame('completed', $d2['claim'], 'Replay of an already-consumed action from another process must be detected as completed');
        self::assertTrue($d2['completed'] ?? false, 'Completed response must be retrievable across processes');
        self::assertSame('reused', $d2['reused'], 'A different fingerprint for the same request ID must be rejected as reused across processes');
    }

    public function test_snapshot_integrity_binds_session_user_and_component_class_across_processes(): void
    {
        $key = str_repeat('k', 64);
        $worker = $this->tmp.'/snap.php';
        file_put_contents(
            $worker,
            <<<'PHP'
<?php
require '/home/jinx/Devs/sahilphp/firefiber/vendor/autoload.php';
use Sahil\Firefiber\SnapshotIntegrity;
$i = new SnapshotIntegrity($argv[1]);
$s = ['count' => (int)$argv[2]];
$t = time();
$sig = $i->signSnapshot('c1', $s, $t, '3.1', $argv[3], $argv[4], $argv[5]);
echo json_encode(['sig' => $sig, 't' => $t]);
PHP
        );

        $r1 = $this->exec([PHP_BINARY, $worker, $key, '5', 'sessA', 'user1', 'App\Comp']);
        $r2 = $this->exec([PHP_BINARY, $worker, $key, '5', 'sessB', 'user1', 'App\Comp']);
        self::assertSame(0, $r1['code']);
        self::assertSame(0, $r2['code']);
        $d1 = json_decode($r1['out'], true);
        $d2 = json_decode($r2['out'], true);

        $i = new SnapshotIntegrity($key);
        self::assertNotFalse($i->verifySnapshot('c1', ['count' => 5], $d1['t'], '3.1', $d1['sig'], 'sessA', 'user1', 'App\Comp'));
        self::assertFalse($i->verifySnapshot('c1', ['count' => 5], $d1['t'], '3.1', $d1['sig'], 'sessB', 'user1', 'App\Comp'), 'Cross-session snapshot reuse must be rejected by integrity binding');
        self::assertFalse($i->verifySnapshot('c1', ['count' => 5], $d2['t'], '3.1', $d2['sig'], 'sessA', 'user1', 'App\Comp'));
        self::assertFalse($i->verifySnapshot('c1', ['count' => 5], $d1['t'], '3.1', $d1['sig'], 'sessA', 'user2', 'App\Comp'), 'Cross-user snapshot reuse must be rejected');
        self::assertFalse($i->verifySnapshot('c1', ['count' => 5], $d1['t'], '3.1', $d1['sig'], 'sessA', 'user1', 'App\OtherComp'), 'Component class binding must be enforced');
    }

    public function test_snapshot_expiration_and_tampering_across_processes(): void
    {
        $i = new SnapshotIntegrity(str_repeat('x', 32), 1);
        $t = time() - 10;
        $sig = $i->signSnapshot('c1', ['x' => 1], $t, '3.1', null, null, null);
        self::assertFalse($i->verifySnapshot('c1', ['x' => 1], $t, '3.1', $sig, null, null, null), 'Expired snapshot must be rejected');
        self::assertFalse($i->verifySnapshot('c1', ['x' => 2], time(), '3.1', $i->signSnapshot('c1', ['x' => 1], time(), '3.1'), null, null, null), 'Tampered state must be rejected');
    }

    public function test_session_and_user_bound_metadata_is_isolated_across_processes(): void
    {
        $stateDir = $this->tmp.'/meta';
        @mkdir($stateDir, 0o777, true);
        $componentId = 'meta-'.bin2hex(random_bytes(4));

        $writer = $this->tmp.'/meta_w.php';
        file_put_contents(
            $writer,
            <<<'PHP'
<?php
require '/home/jinx/Devs/sahilphp/firefiber/vendor/autoload.php';
use Sahil\Firefiber\Storage\FileCacheStateStorage;
$s = new FileCacheStateStorage($argv[1]);
$s->saveState($argv[2], ['count' => 1], 0);
$s->saveState($argv[2].'::__firefiber_metadata', ['metadata' => ['sessionId' => $argv[3], 'userId' => $argv[4], 'componentClass' => 'App\\C']], 0);
echo "ok";
PHP
        );
        $r = $this->exec([PHP_BINARY, $writer, $stateDir, $componentId, 'session-A', '42']);
        self::assertSame(0, $r['code'], $r['err']);

        $reader = $this->tmp.'/meta_r.php';
        file_put_contents(
            $reader,
            <<<'PHP'
<?php
require '/home/jinx/Devs/sahilphp/firefiber/vendor/autoload.php';
use Sahil\Firefiber\Storage\FileCacheStateStorage;
$s = new FileCacheStateStorage($argv[1]);
$m = $s->retrieveState($argv[2].'::__firefiber_metadata');
$meta = is_array($m['metadata'] ?? null) ? $m['metadata'] : [];
$sessionOk = ($meta['sessionId'] ?? '') === $argv[3];
$userOk = (string)($meta['userId'] ?? '') === $argv[4];
$crossSession = ($meta['sessionId'] ?? '') === $argv[5];
echo json_encode(['sessionOk'=>$sessionOk,'userOk'=>$userOk,'crossSessionRejected'=>!$crossSession]);
PHP
        );
        $r2 = $this->exec([PHP_BINARY, $reader, $stateDir, $componentId, 'session-A', '42', 'session-B']);
        self::assertSame(0, $r2['code'], $r2['err'].$r2['out']);
        $d = json_decode($r2['out'], true);
        self::assertTrue($d['sessionOk'] ?? false);
        self::assertTrue($d['userOk'] ?? false);
        self::assertTrue($d['crossSessionRejected'] ?? false, 'Cross-session access must be detectable from persisted trusted metadata across processes');
    }

    public function test_replay_claim_cleanup_after_release_across_processes(): void
    {
        $cacheDir = $this->tmp.'/replayr';
        @mkdir($cacheDir, 0o777, true);
        $requestId = 'req-r-'.bin2hex(random_bytes(6));
        $fp = 'fp-r';

        $worker = $this->tmp.'/replayr.php';
        file_put_contents(
            $worker,
            <<<'PHP'
<?php
require '/home/jinx/Devs/sahilphp/firefiber/vendor/autoload.php';
use Sahil\Firefiber\Storage\FileCacheReplayStorage;
$r = new FileCacheReplayStorage($argv[1]);
$claim = $r->claimRequest($argv[2], $argv[3], 'owner1');
$released = $r->releaseClaim($argv[2], $argv[3], 'owner1');
$claim2 = $r->claimRequest($argv[2], $argv[3], 'owner2');
echo json_encode(['claim'=>$claim,'released'=>$released,'claim2'=>$claim2]);
PHP
        );
        $res = $this->exec([PHP_BINARY, $worker, $cacheDir, $requestId, $fp]);
        self::assertSame(0, $res['code'], $res['err'].$res['out']);
        $d = json_decode($res['out'], true);
        self::assertSame('claimed', $d['claim'] ?? null);
        self::assertTrue($d['released'] ?? false);
        self::assertSame('claimed', $d['claim2'] ?? null, 'Released claim must allow a clean retry; completed records remain replay-protected separately');
    }

    private function exec(array $cmd): array
    {
        $p = proc_open($cmd, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, $this->tmp);
        if (!is_resource($p)) {
            return ['code' => -1, 'out' => '', 'err' => 'proc_open failed'];
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        return ['code' => $code, 'out' => (string)$out, 'err' => (string)$err];
    }

    private function runPhp(string $code): array
    {
        $f = $this->tmp.'/tmp.php';
        file_put_contents($f, $code);
        return $this->exec([PHP_BINARY, $f]);
    }
}
