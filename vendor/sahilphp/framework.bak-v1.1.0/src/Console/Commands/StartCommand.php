<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use Sahil\Console\Command;
use Sahil\Console\CommandInput;
use Sahil\Console\CommandOutput;
use Sahil\Console\ConsoleException;

/**
 * Runs PHP's built-in development server for the application's `public/`
 * directory. A busy port moves to the next free one instead of failing.
 */
final class StartCommand extends Command
{
    public function __construct(
        private readonly string $command = 'start',
        private readonly bool $isHidden = false,
    ) {
    }

    public function name(): string
    {
        return $this->command;
    }

    public function description(): string
    {
        return 'Run the PHP development server (default 127.0.0.1:8000).';
    }

    public function usage(): string
    {
        return 'php sahil start [--host=HOST] [--port=PORT]';
    }

    public function category(): string
    {
        return 'Application';
    }

    public function optionSpecs(): array
    {
        return [
            'host' => 'HOST',
            'port' => 'PORT',
        ];
    }

    /**
     * @return list<string>
     */
    public function optionHelp(): array
    {
        return [
            '--host=HOST   Interface to bind (default 127.0.0.1).',
            '--port=PORT   TCP port to bind (default 8000).',
        ];
    }

    public function requiresApplication(): bool
    {
        return false;
    }

    public function hidden(): bool
    {
        return $this->isHidden;
    }

    /**
     * @return list<string>
     */
    public function examples(): array
    {
        return [
            'php sahil start',
            'php sahil start --port=8088',
            'php sahil start --host=0.0.0.0 --port=8088',
        ];
    }

    public function execute(CommandInput $input, CommandOutput $output): int
    {
        $input->rejectExtraArguments(0, $this->usage());
        $host = trim($input->option('host') ?? '127.0.0.1');
        $portValue = $input->option('port') ?? '8000';
        $portText = trim($portValue);

        if (preg_match('/^\d{1,5}$/', $portText) !== 1 || (int) $portText < 1 || (int) $portText > 65535) {
            throw new ConsoleException(sprintf(
                'invalid port [%s]; use a number between 1 and 65535.',
                $portValue,
            ));
        }

        if ($host === '' || preg_match('/^[A-Za-z0-9._:\[\]-]+$/', $host) !== 1) {
            throw new ConsoleException(sprintf(
                'invalid host [%s]; use an address such as 127.0.0.1, localhost or 0.0.0.0.',
                $host,
            ));
        }

        $port = (int) $portText;
        $bindHost = str_contains($host, ':') && !str_starts_with($host, '[') ? '['.$host.']' : $host;
        $bindAddress = $bindHost.':'.$port;

        $socketError = 0;
        $socketMessage = '';
        $probe = @stream_socket_server('tcp://'.$bindAddress, $socketError, $socketMessage);

        if ($probe === false) {
            $requestedPort = $port;
            $fallback = null;

            for ($candidate = $port + 1; $candidate <= min(65535, $port + 100); $candidate++) {
                $free = @stream_socket_server('tcp://'.$bindHost.':'.$candidate);

                if ($free !== false) {
                    fclose($free);
                    $fallback = $candidate;

                    break;
                }
            }

            if ($fallback === null) {
                throw new ConsoleException(sprintf(
                    '%s:%d is already in use or unavailable%s. No free port was found in the next 100; stop the process using it or pass --port=<another-port>.',
                    $host,
                    $requestedPort,
                    $socketMessage === '' ? '' : ' ('.$socketMessage.')',
                ));
            }

            $port = $fallback;
            $bindAddress = $bindHost.':'.$port;

            $output->line(sprintf(
                'start: %s:%d is already in use%s; using %d instead.',
                $host,
                $requestedPort,
                $socketMessage === '' ? '' : ' ('.$socketMessage.')',
                $port,
            ));
            $output->line('  pick a specific port with: php sahil start --port=<port>');
        } else {
            fclose($probe);
        }

        $root = $this->basePath();

        $output->line('SahilPHP development server');
        $output->line(sprintf('  http://%s:%d', $bindHost, $port));
        $output->line(sprintf('  document root: %s/public', $root));
        $output->line('  press Ctrl-C to stop');
        $output->line();

        if (function_exists('fflush')) {
            fflush($output->stdout());
        }

        $serverArguments = ['-S', $bindAddress, '-t', $root.'/public', $root.'/public/index.php'];

        if (function_exists('pcntl_exec')) {
            pcntl_exec(PHP_BINARY, $serverArguments);
        }

        $server = proc_open(
            [PHP_BINARY, ...$serverArguments],
            [0 => STDIN, 1 => STDOUT, 2 => STDERR],
            $pipes,
            $root,
        );

        if (!is_resource($server)) {
            throw new ConsoleException('unable to launch the PHP development server.');
        }

        return (int) proc_close($server);
    }
}
