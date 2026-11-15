<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use Sahil\Console\Command;
use Sahil\Console\CommandInput;
use Sahil\Console\CommandOutput;
use Sahil\Console\Generator\GeneratedFile;
use Sahil\Console\Generator\Generator;
use Sahil\Console\Generator\GeneratorException;

/**
 * One `unda:*` code generator.
 *
 * Every generator shares {@see Generator}: namespace/path resolution,
 * name validation, conflict detection and syntax-checked writes are identical
 * for all of them.
 */
final class GeneratorCommand extends Command
{
    /** @var array<string, array{description: string, argument: string, examples: list<string>}> */
    private const TYPES = [
        'controller' => [
            'description' => 'Create a controller in the application controller directory.',
            'argument' => '<Name>',
            'examples' => [
                'php sahil unda:controller UserController',
                'php sahil unda:controller UserController --resources',
                'php sahil unda:controller Admin/UserController -r',
            ],
        ],
        'model' => [
            'description' => 'Create an ORM model (optionally with its migration and controller).',
            'argument' => '<Name>',
            'examples' => [
                'php sahil unda:model User',
                'php sahil unda:model User -cm',
                'php sahil unda:model User -cmr',
                'php sahil unda:model Billing/Invoice --controller --migration --resource',
            ],
        ],
        'migration' => [
            'description' => 'Create a migration that the migration runner can execute.',
            'argument' => '<name>',
            'examples' => [
                'php sahil unda:migration create_users_table',
                'php sahil unda:migration add_email_to_users_table',
            ],
        ],
        'middleware' => [
            'description' => 'Create middleware implementing the request pipeline contract.',
            'argument' => '<Name>',
            'examples' => [
                'php sahil unda:middleware Authenticate',
                'php sahil unda:middleware VerifySubscription',
            ],
        ],
        'policy' => [
            'description' => 'Create an authorization policy extending Sahil\\Auth\\Policy.',
            'argument' => '<Name>',
            'examples' => ['php sahil unda:policy UserPolicy'],
        ],
        'provider' => [
            'description' => 'Create a service provider for bootstrap/providers.php.',
            'argument' => '<Name>',
            'examples' => ['php sahil unda:provider PaymentServiceProvider'],
        ],
        'command' => [
            'description' => 'Create an application console command for bootstrap/commands.php.',
            'argument' => '<Name>',
            'examples' => ['php sahil unda:command SendInvoice'],
        ],
        'job' => [
            'description' => 'Create a queued job implementing the SahilPHP Job contract.',
            'argument' => '<Name>',
            'examples' => ['php sahil unda:job ProcessPayment'],
        ],
        'seeder' => [
            'description' => 'Create a database seeder in database/seeders.',
            'argument' => '<Name>',
            'examples' => ['php sahil unda:seeder UserSeeder'],
        ],
    ];

    public function __construct(private readonly string $type)
    {
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException(sprintf('Unknown generator type [%s].', $type));
        }
    }

    public function name(): string
    {
        return 'unda:'.$this->type;
    }

    public function description(): string
    {
        return self::TYPES[$this->type]['description'];
    }

    public function usage(): string
    {
        return sprintf('php sahil unda:%s %s [options]', $this->type, self::TYPES[$this->type]['argument']);
    }

    public function category(): string
    {
        return 'Code Generation';
    }

    public function optionSpecs(): array
    {
        $options = [
            'force' => null,
            'path' => 'DIR',
        ];

        if ($this->type === 'model') {
            $options['migration'] = null;
            $options['controller'] = null;
            $options['resource'] = null;
        }

        if ($this->type === 'controller') {
            $options['resources'] = null;
            $options['resource'] = null;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function shortOptions(): array
    {
        return match ($this->type) {
            'model' => ['m' => 'migration', 'c' => 'controller', 'r' => 'resource'],
            'controller' => ['r' => 'resources'],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public function optionHelp(): array
    {
        $lines = [
            '--force          Overwrite an existing file after backing it up.',
            '--path=DIR       Application root to generate into (default: this application).',
        ];

        if ($this->type === 'model') {
            $lines[] = '-m, --migration  Also create create_<table>_table for this model.';
            $lines[] = '-c, --controller Also create <Name>Controller.';
            $lines[] = '-r, --resource   Create the controller as a resourceful one (implies --controller).';
            $lines[] = 'Combined shorthands: -cm = --controller --migration; -cmr adds --resource.';
        }

        if ($this->type === 'controller') {
            $lines[] = '-r, --resources  Generate a resourceful controller (index, create, store,';
            $lines[] = '                 show, edit, update, destroy). --resource is an alias.';
        }

        return $lines;
    }

    public function requiresApplication(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function examples(): array
    {
        return self::TYPES[$this->type]['examples'];
    }

    public function execute(CommandInput $input, CommandOutput $output): int
    {
        $name = $input->requireArgument(0, sprintf(
            'a name is required (for example: %s)',
            $this->type === 'migration' ? 'create_users_table' : 'User'.ucfirst($this->type),
        ));
        $input->rejectExtraArguments(1, $this->usage());

        $root = $input->option('path') ?? $this->basePath();

        if (str_starts_with($root, DIRECTORY_SEPARATOR) === false
            && preg_match('/^[A-Za-z]:[\\\\\/]/', $root) !== 1
        ) {
            $root = $this->basePath().'/'.trim($root, '/');
        }

        try {
            $result = (new Generator($root))->generate($this->type, $name, [
                'force' => $input->flag('force'),
                'migration' => $input->flag('migration'),
                'controller' => $input->flag('controller'),
                'resource' => $input->flag('resource') || $input->flag('resources'),
            ]);
        } catch (GeneratorException $error) {
            throw new \Sahil\Console\ConsoleException($error->getMessage());
        }

        foreach ($result->files as $file) {
            $this->report($file, $output);
        }

        foreach ($this->nextSteps($result->files) as $line) {
            $output->line($line);
        }

        return 0;
    }

    private function report(GeneratedFile $file, CommandOutput $output): void
    {
        $output->line(sprintf(
            '%s %s',
            $file->backupPath === null ? 'Created' : 'Updated',
            $file->relativePath,
        ));

        if ($file->namespace !== null) {
            $output->line('  namespace '.$file->namespace);
        }

        if ($file->backupPath !== null) {
            $output->line('  backup    '.$file->backupPath);
        }
    }

    /**
     * @param list<GeneratedFile> $files
     *
     * @return list<string>
     */
    private function nextSteps(array $files): array
    {
        $paths = array_map(static fn (GeneratedFile $file): string => $file->relativePath, $files);
        $steps = [];

        if ($this->type === 'migration' || $this->type === 'model') {
            $hasMigration = false;

            foreach ($paths as $path) {
                if (str_starts_with($path, 'database/migrations/')) {
                    $hasMigration = true;
                }
            }

            if ($hasMigration) {
                $steps[] = '';
                $steps[] = 'Next: php sahil migrate';
            }
        }

        if ($this->type === 'provider') {
            $steps[] = '';
            $steps[] = 'Next: add the class to bootstrap/providers.php';
        }

        if ($this->type === 'command') {
            $steps[] = '';
            $steps[] = 'Next: add the class to bootstrap/commands.php';
        }

        if ($this->type === 'policy') {
            $steps[] = '';
            $steps[] = 'Next: register the policy with Sahil\\Auth\\PolicyManager';
        }

        return $steps;
    }
}
