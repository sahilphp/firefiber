<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Tests;

use PHPUnit\Framework\TestCase;
use Sahil\Config\Repository;
use Sahil\Console\CommandInput;
use Sahil\Console\CommandOutput;
use Sahil\Contracts\Session\SessionContract;
use Sahil\Contracts\Storage\StorageContract;
use Sahil\Firefiber\Component;
use Sahil\Firefiber\Console\Commands\FirefiberUploadsCleanupCommand;
use Sahil\Firefiber\Exceptions\InvalidStateException;
use Sahil\Firefiber\FirefiberServiceProvider;
use Sahil\Firefiber\Storage\StateStorageInterface;
use Sahil\Firefiber\Uploads\UploadedFile;
use Sahil\Foundation\Application;
use Sahil\Http\Request;
use Sahil\Session\FileSessionDriver;
use Sahil\Session\Session;
use Sahil\Storage\StorageManager;

/**
 * The developer-facing upload API: `uploadedFile()`/`uploadedFiles()`
 * accessors on trusted state, `UploadedFile::store()`/`delete()` over the
 * SahilPHP storage contract, and the staged-upload cleanup command.
 */
final class UploadedFileTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/firefiber-uploads-'.bin2hex(random_bytes(6));
        foreach (['/app', '/config', '/storage/sessions', '/resources/views', '/disk', '/state'] as $dir) {
            self::assertTrue(mkdir($this->root.$dir, 0o700, true));
        }
    }

    protected function tearDown(): void
    {
        Application::free();
        $this->removeDirectory($this->root);
    }

    private function application(): Application
    {
        $application = Application::configure($this->root);
        $application->config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $application->config()->set('filesystems.disks', [
            'local' => ['driver' => 'local', 'root' => $this->root.'/disk', 'public' => false],
        ]);

        $session = new Session(new FileSessionDriver($this->root.'/storage/sessions'));
        $session->startFromRequest(Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'REQUEST_SCHEME' => 'http',
            'HTTP_HOST' => 'uploads.test',
        ], [], [], [], ''));
        $application->container()->instance(SessionContract::class, $session);

        $application->container()->instance(StorageContract::class, new StorageManager(new Repository([
            'storage' => [
                'default' => 'local',
                'disks' => ['local' => ['driver' => 'local', 'root' => $this->root.'/disk', 'public' => false]],
            ],
        ])));

        $provider = new FirefiberServiceProvider($application);
        $provider->register();
        $provider->boot();

        // Route the state store into the test root so it cannot touch the
        // package's own working directory.
        $application->config()->set('cache.stores.file.path', $this->root.'/state');

        return $application;
    }

    /**
     * A component carrying one staged upload record, with the backing file
     * and sidecar persisted the way the upload middleware leaves them.
     *
     * @return array{0: UploadHostComponent, 1: array<string, mixed>}
     */
    private function stagedComponent(Application $application, array $recordOverrides = []): array
    {
        $storage = $application->container()->make(StorageContract::class);
        $stateStorage = $application->container()->make(StateStorageInterface::class);

        $component = new UploadHostComponent();
        $uploadId = bin2hex(random_bytes(16));
        $path = 'firefiber/uploads/'.$uploadId.'/'.bin2hex(random_bytes(16)).'.png';
        $storage->put($path, 'png-bytes');

        $record = array_merge([
            'id' => $uploadId,
            'componentId' => $component->id(),
            'field' => 'photo',
            'disk' => 'local',
            'path' => $path,
            'storedName' => basename($path),
            'originalName' => 'avatar.png',
            'size' => 9,
            'mimeType' => 'image/png',
            'extension' => 'png',
            'createdAt' => time(),
            'expiresAt' => time() + 3600,
            'promoted' => false,
            'permanentPath' => null,
        ], $recordOverrides);

        $component->updateState(['uploads' => [$record]]);
        $stateStorage->saveState($component->id().'::__firefiber_uploads::'.$uploadId, $record, 3600);

        return [$component, $record];
    }

    public function test_uploaded_file_accessors_read_trusted_state(): void
    {
        $application = $this->application();
        [$component, $record] = $this->stagedComponent($application);

        $file = $component->uploadedFile('photo');
        self::assertInstanceOf(UploadedFile::class, $file);
        self::assertSame($record['id'], $file->id());
        self::assertSame('photo', $file->field());
        self::assertSame('avatar.png', $file->originalName());
        self::assertSame(9, $file->size());
        self::assertSame('image/png', $file->mimeType());
        self::assertSame('png', $file->extension());
        self::assertSame($record['path'], $file->temporaryPath());
        self::assertFalse($file->isPromoted());
        self::assertFalse($file->isExpired());

        // Other fields have no uploads.
        self::assertNull($component->uploadedFile('document'));
        self::assertSame([], $component->uploadedFiles('document'));
    }

    public function test_store_promotes_to_permanent_storage_and_syncs_state(): void
    {
        $application = $this->application();
        [$component, $record] = $this->stagedComponent($application);
        $storage = $application->container()->make(StorageContract::class);

        $file = $component->uploadedFile('photo');
        $permanentPath = $file->store('profiles');

        self::assertSame('profiles/'.$record['id'].'/'.$record['storedName'], $permanentPath);
        self::assertTrue($file->isPromoted());
        self::assertSame($permanentPath, $file->permanentPath());

        // The file moved; the staged path is gone.
        self::assertTrue($storage->has($permanentPath, 'local'));
        self::assertFalse($storage->has($record['path'], 'local'));

        // The sidecar record is marked promoted server-side.
        $stateStorage = $application->container()->make(StateStorageInterface::class);
        $sidecar = $stateStorage->retrieveState($component->id().'::__firefiber_uploads::'.$record['id']);
        self::assertTrue($sidecar['promoted']);
        self::assertSame($permanentPath, $sidecar['permanentPath']);
        self::assertSame(0, $sidecar['expiresAt']);

        // The component's signed uploads state is synced too.
        $listed = $component->state()['uploads'];
        self::assertTrue($listed[0]['promoted']);
        self::assertSame($permanentPath, $listed[0]['permanentPath']);

        // Storing twice is a no-op returning the same path.
        self::assertSame($permanentPath, $component->uploadedFile('photo')->store('other-dir'));
    }

    public function test_store_rejects_unsafe_directories(): void
    {
        $application = $this->application();
        [$component] = $this->stagedComponent($application);

        $this->expectException(InvalidStateException::class);
        $component->uploadedFile('photo')->store('../escape');
    }

    public function test_expired_uploads_are_filtered_and_cannot_be_stored(): void
    {
        $application = $this->application();
        [$component] = $this->stagedComponent($application, ['expiresAt' => time() - 10]);

        // Expired staged records never reach the developer.
        self::assertNull($component->uploadedFile('photo'));
    }

    public function test_expired_record_store_fails_loudly(): void
    {
        $application = $this->application();
        [$component, $record] = $this->stagedComponent($application, ['expiresAt' => time() + 3600]);

        // Force expiry inside the record the UploadedFile wraps.
        $file = UploadedFile::fromRecord(array_merge($record, ['expiresAt' => time() - 5]), $component);
        self::assertNotNull($file);

        $this->expectException(InvalidStateException::class);
        $file->store('profiles');
    }

    public function test_delete_removes_file_record_and_state_entry(): void
    {
        $application = $this->application();
        [$component, $record] = $this->stagedComponent($application);
        $storage = $application->container()->make(StorageContract::class);
        $stateStorage = $application->container()->make(StateStorageInterface::class);

        $component->uploadedFile('photo')->delete();

        self::assertFalse($storage->has($record['path'], 'local'));
        self::assertNull($stateStorage->retrieveState($component->id().'::__firefiber_uploads::'.$record['id']));
        self::assertSame([], $component->state()['uploads']);
    }

    public function test_promoted_uploads_survive_expiration_filtering(): void
    {
        $application = $this->application();
        [$component] = $this->stagedComponent($application, [
            'promoted' => true,
            'expiresAt' => 0,
            'permanentPath' => 'profiles/x/y.png',
        ]);

        $file = $component->uploadedFile('photo');
        self::assertNotNull($file);
        self::assertTrue($file->isPromoted());
        self::assertSame('profiles/x/y.png', $file->permanentPath());
        self::assertSame('profiles/x/y.png', $file->store('ignored'));
    }

    public function test_cleanup_command_removes_only_expired_staging_directories(): void
    {
        $application = $this->application();
        $storage = $application->container()->make(StorageContract::class);

        $old = 'firefiber/uploads/'.bin2hex(random_bytes(16));
        $fresh = 'firefiber/uploads/'.bin2hex(random_bytes(16));
        $storage->put($old.'/file.png', 'old');
        $storage->put($fresh.'/file.png', 'fresh');
        $storage->put('firefiber/uploads/keep-me.txt', 'not-a-dir');

        // Age the old directory past the cutoff.
        touch($this->root.'/disk/'.$old, time() - 7200);

        $command = new FirefiberUploadsCleanupCommand();
        $command->bindBasePath($this->root);
        $command->bindApplication($application);

        $exit = $command->execute(
            CommandInput::parse(['--max-age=3600'], $command->optionSpecs()),
            new CommandOutput(),
        );

        self::assertSame(0, $exit);
        self::assertFalse($storage->has($old.'/file.png', 'local'));
        self::assertTrue($storage->has($fresh.'/file.png', 'local'));
        self::assertTrue($storage->has('firefiber/uploads/keep-me.txt', 'local'));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}

final class UploadHostComponent extends Component
{
    protected array $uploadDefinitions = [
        'photo' => ['maxFiles' => 1, 'maxSize' => 1048576, 'extensions' => ['png']],
    ];

    public function __construct()
    {
        parent::__construct([]);
    }

    public function render(): string
    {
        return '<form></form>';
    }
}
