<?php

declare(strict_types=1);

namespace Sahil\Testing;

use PHPUnit\Framework\TestCase;
use Sahil\Contracts\Session\SessionContract;
use Sahil\Database\Connection;
use Sahil\Foundation\Application;
use Sahil\Foundation\ProviderManifest;
use Sahil\Foundation\ServiceProvider;
use Sahil\Http\Kernel;
use Sahil\Http\Middleware\MiddlewareContract;
use Sahil\Http\Middleware\StartSession;
use Sahil\Http\Middleware\VerifyCsrfToken;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Http\Router;
use Throwable;

/**
 * Base class for application tests.
 *
 * It owns one application per test method, boots it from the installation's
 * real bootstrap manifest, and tears it down afterwards. On top of that it
 * offers the framework-aware tools an application test needs:
 *
 * - simulated HTTP requests with {@see TestResponse} assertions;
 * - isolated environment and configuration overrides applied BEFORE providers
 *   boot, so singletons built during boot see the test values;
 * - optional database transactions rolled back after each test;
 * - dependency replacement through the container ({@see swap()});
 * - console-command execution with exit-code and output assertions.
 *
 * Subclasses that override `setUp()`/`tearDown()` MUST call the parent
 * implementation, otherwise the application is not released between tests.
 *
 * ```php
 * final class HealthTest extends ApplicationTestCase
 * {
 *     protected function basePath(): string
 *     {
 *         return __DIR__.'/Fixtures/app-root';
 *     }
 *
 *     public function test_health_is_ok(): void
 *     {
 *         $this->get('/health')->assertOk()->assertJsonPath('status', 'ok');
 *     }
 * }
 * ```
 */
abstract class ApplicationTestCase extends TestCase
{
    private ?Application $application = null;

    private ?Kernel $kernel = null;

    private ?Connection $transactionConnection = null;

    private bool $usesDatabaseTransactions = false;

    private bool $withoutCsrfProtection = false;

    private bool $withoutGlobalMiddleware = false;

    private bool $withoutExceptionHandler = false;

    /** @var list<MiddlewareContract> */
    private array $extraGlobalMiddleware = [];

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();

        // A previous test may have aborted before its tearDown; never inherit
        // a configured application.
        Application::free();
    }

    protected function tearDown(): void
    {
        $this->rollBackDatabaseTransactions();

        $this->kernel = null;
        $this->application = null;
        Application::free();

        $this->removeTemporaryDirectories();

        parent::tearDown();
    }

    /**
     * Application root used to boot the test application.
     *
     * There is deliberately no package-root fallback: the framework repository
     * is a library, not a runnable application. Every application test must
     * override this with an explicit root. Framework-owned tests can extend
     * {@see \Sahil\Tests\FrameworkTestCase}, which points at the controlled
     * `tests/Fixtures/app-root` fixture instead of the starter-kit.
     */
    protected function basePath(): string
    {
        throw new TestingException(
            'ApplicationTestCase requires an explicit application root. Override basePath() in the test case.',
        );
    }

    /**
     * The booted application for the current test. Boots on first use.
     */
    protected function app(): Application
    {
        if ($this->application === null) {
            $this->application = $this->createApplication();
        }

        return $this->application;
    }

    protected function container(): \Sahil\Container\Container
    {
        return $this->app()->container();
    }

    /**
     * Replace a container binding with a test double. Controllers and
     * middleware are resolved per request, so a swap made before the request
     * is what they receive.
     */
    protected function swap(string $abstract, object $instance): static
    {
        $this->container()->instance($abstract, $instance);

        return $this;
    }

    /**
     * Environment values applied after the `.env` file loads and before any
     * provider registers. `null` unsets a variable.
     *
     * @return array<string, bool|int|float|string|null>
     */
    protected function environmentOverrides(): array
    {
        return [];
    }

    /**
     * Dotted configuration overrides applied after configuration loads and
     * before any provider registers.
     *
     * @return array<string, mixed>
     */
    protected function configurationOverrides(): array
    {
        return [];
    }

    /**
     * Providers registered for a test. Defaults to the installation's real
     * manifest, so tests exercise the same graph as production.
     *
     * @return list<ServiceProvider>
     */
    protected function providers(Application $application): array
    {
        return ProviderManifest::loadFor($this->basePath(), $application);
    }

    /**
     * Compose the application. Override only when the boot sequence itself is
     * under test.
     */
    protected function createApplication(): Application
    {
        Application::free();

        $application = Application::configure($this->basePath());
        $application->loadEnvironment($this->environmentFile());

        foreach ($this->environmentOverrides() as $key => $value) {
            $application->environment()->set((string) $key, $value);
        }

        $application->loadConfiguration();

        foreach ($this->configurationOverrides() as $key => $value) {
            $application->config()->set((string) $key, $value);
        }

        foreach ($this->providers($application) as $provider) {
            $application->register($provider);
        }

        $application->boot();

        if ($this->usesDatabaseTransactions) {
            $this->beginDatabaseTransaction();
        }

        return $application;
    }

    /**
     * `.env.testing` wins when present; otherwise the regular `.env` loads.
     */
    protected function environmentFile(): string
    {
        return is_file($this->basePath().'/.env.testing') ? '.env.testing' : '.env';
    }

    /**
     * Run every database write for this test inside a transaction that is
     * rolled back in `tearDown()`. Requires a configured, reachable database
     * and the database service provider.
     */
    protected function usingDatabaseTransactions(): static
    {
        $this->usesDatabaseTransactions = true;

        if ($this->application !== null) {
            $this->beginDatabaseTransaction();
        }

        return $this;
    }

    /**
     * Skip the current test unless a database is configured for tests.
     *
     * Accepts the repository's integration-test convention:
     * `SAHIL_DB_TEST_MYSQL` / `SAHIL_DB_TEST_PGSQL` URLs.
     *
     * @return array<string, mixed> parsed database configuration
     */
    protected function requireTestDatabase(string $variable = 'SAHIL_DB_TEST_MYSQL'): array
    {
        $url = getenv($variable);

        if (!is_string($url) || trim($url) === '') {
            self::markTestSkipped(sprintf('%s is not set; skipping the database integration example.', $variable));
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            self::markTestSkipped(sprintf('%s is not a usable database URL.', $variable));
        }

        return [
            'driver' => $parts['scheme'],
            'host' => $parts['host'],
            'port' => $parts['port'] ?? null,
            'database' => isset($parts['path']) ? ltrim($parts['path'], '/') : null,
            'username' => $parts['user'] ?? null,
            'password' => $parts['pass'] ?? null,
        ];
    }

    // ------------------------------------------------------------------
    // HTTP simulation
    // ------------------------------------------------------------------

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    protected function get(string $uri, array $headers = [], array $cookies = []): TestResponse
    {
        return $this->call('GET', $uri, [], $headers, $cookies);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    protected function post(string $uri, array $data = [], array $headers = [], array $cookies = []): TestResponse
    {
        return $this->call('POST', $uri, $data, $headers, $cookies);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    protected function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('PUT', $uri, $data, $headers);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    protected function patch(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('PATCH', $uri, $data, $headers);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    protected function delete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('DELETE', $uri, $data, $headers);
    }

    /**
     * Send a JSON request body.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    protected function jsonRequest(string $method, string $uri, array $data = [], array $headers = [], array $cookies = []): TestResponse
    {
        return $this->call($method, $uri, $data, $headers, $cookies, 'application/json');
    }

    /**
     * Send one simulated request through the HTTP kernel. No sockets, no
     * superglobals and no output are involved.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    protected function call(
        string $method,
        string $uri,
        array $data = [],
        array $headers = [],
        array $cookies = [],
        ?string $contentType = null,
    ): TestResponse {
        $method = strtoupper($method);

        $uri = $uri === '' ? '/' : $uri;
        $queryString = (string) (parse_url($uri, PHP_URL_QUERY) ?? '');
        $query = [];
        parse_str($queryString, $query);

        $contentType ??= 'application/x-www-form-urlencoded';
        $form = [];
        $body = '';

        if ($data !== []) {
            if ($contentType === 'application/json') {
                $encoded = json_encode($data, JSON_THROW_ON_ERROR);
                $body = $encoded;
            } else {
                $form = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $data);
                $body = http_build_query($form);
            }
        }

        if ($this->injectsCsrfToken($method, $headers)) {
            try {
                /** @var SessionContract $session */
                $session = $this->container()->make(SessionContract::class);
                $headers['X-CSRF-TOKEN'] = $session->token();
            } catch (Throwable) {
                // No session service in this application: nothing to inject.
            }
        }

        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'QUERY_STRING' => $queryString,
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/index.php',
        ];

        foreach ($headers as $name => $value) {
            $normalized = strtoupper(str_replace('-', '_', $name));

            if ($normalized === 'CONTENT_TYPE') {
                $server['CONTENT_TYPE'] = $value;

                continue;
            }

            if ($normalized === 'CONTENT_LENGTH') {
                $server['CONTENT_LENGTH'] = $value;

                continue;
            }

            $server['HTTP_'.$normalized] = $value;
        }

        if ($body !== '') {
            $server['CONTENT_TYPE'] = $contentType;
            $server['CONTENT_LENGTH'] = (string) strlen($body);
        }

        $request = Request::fromGlobals($server, $query, $cookies, $form, $body);

        $response = $this->kernel()->handle($request);

        return TestResponse::fromResponse($method, $uri, $response);
    }

    /**
     * Add routes on top of the installation's table before the first request.
     *
     * Minimal test fixtures do not have to bind a router. When none is bound,
     * this installs a fresh instance so the same router is used by the kernel.
     *
     * @param callable(Router): void $definition
     */
    protected function withRoutes(callable $definition): static
    {
        $container = $this->container();

        if (!$container->bound(Router::class)) {
            $container->instance(Router::class, new Router());
        }

        $router = $container->make(Router::class);
        $definition($router);

        return $this;
    }

    /**
     * Register additional global middleware. Call before the first request.
     */
    protected function withGlobalMiddleware(MiddlewareContract ...$middleware): static
    {
        if ($this->kernel !== null) {
            throw new TestingException('Global middleware must be added before the first simulated request.');
        }

        $this->extraGlobalMiddleware = [...$this->extraGlobalMiddleware, ...$middleware];

        return $this;
    }

    /**
     * Run without the CSRF middleware. Use for form flows whose token handling
     * is not what the test is about; keep CSRF in tests that prove rejection.
     */
    protected function withoutCsrf(): static
    {
        if ($this->kernel !== null) {
            throw new TestingException('withoutCsrf() must be called before the first simulated request.');
        }

        $this->withoutCsrfProtection = true;

        return $this;
    }

    /**
     * Run without any of the application's global middleware. Route
     * middleware still runs, so per-route behavior stays testable.
     */
    protected function withoutMiddleware(): static
    {
        if ($this->kernel !== null) {
            throw new TestingException('withoutMiddleware() must be called before the first simulated request.');
        }

        $this->withoutGlobalMiddleware = true;

        return $this;
    }

    /**
     * Rethrow application exceptions instead of rendering them. PHPUnit then
     * shows the original exception and stack trace.
     */
    protected function withoutExceptionHandling(): static
    {
        if ($this->kernel !== null) {
            throw new TestingException('withoutExceptionHandling() must be called before the first simulated request.');
        }

        $this->withoutExceptionHandler = true;

        return $this;
    }

    /**
     * Resolve an in-memory logger as the application's logger, for assertions.
     */
    protected function replaceLogger(): TestLogger
    {
        $logger = new TestLogger();
        $this->swap(\Sahil\Support\Logging\LoggerContract::class, $logger);

        return $logger;
    }

    /**
     * A fresh, automatically removed temporary directory.
     */
    protected function tempDirectory(string $prefix = 'sahil-test'): string
    {
        $directory = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(6));

        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new TestingException(sprintf('Unable to create temporary directory [%s].', $directory));
        }

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    // ------------------------------------------------------------------
    // Console
    // ------------------------------------------------------------------

    /**
     * Run the installation's console binary in a child process and capture
     * stdout, stderr and the exit code.
     *
     * @param string|list<string>  $arguments
     * @param array<string, string> $environment extra environment variables
     */
    protected function runCommand(string|array $arguments, array $environment = []): TestConsoleResult
    {
        $tokens = is_array($arguments)
            ? array_values($arguments)
            : (preg_split('/\s+/', trim($arguments)) ?: []);

        $binary = $this->consoleBinary();

        if (!is_file($binary)) {
            throw new TestingException(sprintf('Console binary [%s] does not exist.', $binary));
        }

        $command = [PHP_BINARY, $binary, ...$tokens];

        $stdoutFile = $this->tempDirectory('sahil-console').'/stdout.log';
        $stderrFile = dirname($stdoutFile).'/stderr.log';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->basePath(), [
            ...$this->processEnvironment(),
            ...$environment,
        ]);

        if (!is_resource($process)) {
            throw new TestingException(sprintf('Unable to start console command [%s].', $this->describeCommand($tokens)));
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $exitCode = proc_close($process);

        return new TestConsoleResult(
            $this->describeCommand($tokens),
            $exitCode,
            (string) file_get_contents($stdoutFile),
            (string) file_get_contents($stderrFile),
        );
    }

    /**
     * Console entry point for the application under test.
     *
     * The conventional location is `<application-root>/bin/sahil`. Override
     * this when an application keeps its CLI elsewhere. The framework test
     * fixture has no CLI, so {@see \Sahil\Tests\FrameworkTestCase} overrides
     * this with an explicit failure instead of probing a nonexistent binary.
     */
    protected function consoleBinary(): string
    {
        return $this->basePath().'/bin/sahil';
    }

    /**
     * Environment for console child processes. Explicit `$environment` values
     * passed to {@see runCommand()} win over these.
     *
     * @return array<string, string>
     */
    protected function processEnvironment(): array
    {
        /** @var array<string, string> $inherited */
        $inherited = [];

        foreach (getenv() as $key => $value) {
            $inherited[$key] = $value;
        }

        return $inherited;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function kernel(): Kernel
    {
        if ($this->kernel !== null) {
            return $this->kernel;
        }

        $application = $this->app();
        $container = $application->container();

        $custom = $this->withoutGlobalMiddleware
            || $this->withoutCsrfProtection
            || $this->withoutExceptionHandler
            || $this->extraGlobalMiddleware !== [];

        if (!$custom) {
            return $this->kernel = $container->make(Kernel::class);
        }

        $kernel = (new Kernel(
            $container->make(Router::class),
            $container,
            $application,
        ))->useDebugMode($application->config()->get('app.debug') === true);

        if ($this->withoutExceptionHandler) {
            $kernel = $kernel->usingExceptionHandler(static fn (Throwable $throwable): Response => throw $throwable);
        }

        if (!$this->withoutGlobalMiddleware) {
            if (!$this->withoutCsrfProtection) {
                $kernel = $kernel->using($container->make(StartSession::class), $container->make(VerifyCsrfToken::class));
            } else {
                $kernel = $kernel->using($container->make(StartSession::class));
            }

            foreach ($this->extraGlobalMiddleware as $middleware) {
                $kernel = $kernel->using($middleware);
            }
        }

        return $this->kernel = $kernel;
    }

    /**
     * @param array<string, string> $headers
     */
    private function injectsCsrfToken(string $method, array $headers): bool
    {
        if ($this->withoutCsrfProtection || $this->withoutGlobalMiddleware) {
            return false;
        }

        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        foreach (array_keys($headers) as $name) {
            if (in_array(strtoupper($name), ['X-CSRF-TOKEN', 'X-XSRF-TOKEN'], true)) {
                return false;
            }
        }

        return true;
    }

    private function beginDatabaseTransaction(): void
    {
        if ($this->transactionConnection !== null) {
            return;
        }

        /** @var Connection $connection */
        $connection = $this->container()->make(Connection::class);
        $connection->beginTransaction();

        $this->transactionConnection = $connection;
    }

    private function rollBackDatabaseTransactions(): void
    {
        if ($this->transactionConnection === null) {
            return;
        }

        try {
            while ($this->transactionConnection->transactionDepth() > 0) {
                $this->transactionConnection->rollBack();
            }
        } catch (Throwable) {
            // A broken connection must not mask the test result; the database
            // side is disposable test data.
        } finally {
            $this->transactionConnection = null;
        }
    }

    private function removeTemporaryDirectories(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }

        $this->temporaryDirectories = [];
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
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());

                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($directory);
    }

    /**
     * @param list<string> $tokens
     */
    private function describeCommand(array $tokens): string
    {
        return implode(' ', array_map(
            static fn (string $token): string => escapeshellarg($token),
            ['sahil', ...$tokens],
        ));
    }
}
