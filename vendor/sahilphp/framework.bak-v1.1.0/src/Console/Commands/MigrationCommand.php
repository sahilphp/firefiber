<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use Sahil\Console\Command;
use Sahil\Console\CommandInput;
use Sahil\Console\CommandOutput;
use Sahil\Console\ConsoleException;
use Sahil\Database\ConnectionException;
use Sahil\Database\Schema\Schema;
use Sahil\Migrations\DestructiveOperationException;
use Sahil\Migrations\MigrationException;
use Sahil\Migrations\MigrationReport;
use Sahil\Migrations\MigrationRepository;
use Sahil\Migrations\Migrator;
use Sahil\Migrations\MutationGuard;
use Sahil\Migrations\SeederRunner;
use Throwable;

/**
 * Migration and seeding commands.
 *
 * Destructive operations require deliberate intent (`--force`), and database
 * failures are reported as one clear line rather than a stack trace.
 */
final class MigrationCommand extends Command
{
    public function __construct(private readonly string $command)
    {
    }

    public function name(): string
    {
        return $this->command;
    }

    public function description(): string
    {
        return match ($this->command) {
            'migrate' => 'Apply every pending migration.',
            'migrate:status' => 'List applied, pending and missing migrations.',
            'migrate:rollback' => 'Reverse the newest migration batch.',
            'db:seed' => 'Run registered seeders.',
            default => 'Run migrations.',
        };
    }

    public function usage(): string
    {
        return match ($this->command) {
            'migrate' => 'php sahil migrate [--force]',
            'migrate:status' => 'php sahil migrate:status',
            'migrate:rollback' => 'php sahil migrate:rollback [--steps=N] [--force]',
            'db:seed' => 'php sahil db:seed [--class=Name] [--force]',
            default => 'php sahil '.$this->command,
        };
    }

    public function category(): string
    {
        return 'Database';
    }

    public function optionSpecs(): array
    {
        return [
            'force' => null,
            'steps' => 'N',
            'class' => 'Name',
        ];
    }

    /**
     * @return list<string>
     */
    public function optionHelp(): array
    {
        return [
            '--force           Authorise a destructive operation without prompting.',
            '--steps=N         migrate:rollback: reverse the newest N batches.',
            '--class=Name      db:seed: run only the named seeder.',
        ];
    }

    /**
     * @return list<string>
     */
    public function examples(): array
    {
        return [
            'php sahil migrate',
            'php sahil migrate:status',
            'php sahil migrate:rollback --steps=1',
            'php sahil db:seed --class=UserSeeder',
        ];
    }

    public function execute(CommandInput $input, CommandOutput $output): int
    {
        $input->rejectExtraArguments(0, $this->usage());
        $force = $input->flag('force');
        $steps = max(1, (int) ($input->option('steps') ?? '1'));
        $seeder = $input->option('class');

        try {
            $container = $this->app()->container();

            if ($this->command === 'migrate:status') {
                /** @var Migrator $migrator */
                $migrator = $container->make(Migrator::class);
                $status = $migrator->status();

                /** @var MigrationRepository $repository */
                $repository = $container->make(MigrationRepository::class);
                /** @var Schema $schema */
                $schema = $container->make(Schema::class);

                $output->line(sprintf(
                    'migrations ledger: %s (%s)',
                    $repository->table(),
                    $schema->dialect(),
                ));

                foreach ($status['applied'] as $row) {
                    $output->line(sprintf(
                        '  applied  %-52s batch %-3d %s',
                        $row['name'],
                        $row['batch'],
                        $row['applied_at'] ?? '(no timestamp)',
                    ));
                }

                foreach ($status['pending'] as $name) {
                    $output->line(sprintf('  pending  %s', $name));
                }

                foreach ($status['missing'] as $name) {
                    $output->line(sprintf('  missing  %s (recorded but no file on disk)', $name));
                }

                if ($status['applied'] === [] && $status['pending'] === [] && $status['missing'] === []) {
                    $output->line('  no migrations discovered');
                }

                return 0;
            }

            if ($this->command === 'db:seed') {
                /** @var SeederRunner $runner */
                $runner = $container->make(SeederRunner::class);
                /** @var MutationGuard $guard */
                $guard = $container->make(MutationGuard::class);

                $guard->authorise(
                    'db:seed',
                    $force,
                    $seeder === null ? 'Run every registered seeder?' : sprintf('Run seeder [%s]?', $seeder),
                );

                $report = $seeder === null
                    ? $runner->runAll()
                    : new MigrationReport(
                        command: 'db:seed',
                        applied: array_values(array_filter([$runner->runOne($seeder)])),
                        notes: ['single seeder requested'],
                    );
            } elseif ($this->command === 'migrate') {
                /** @var Migrator $migrator */
                $migrator = $container->make(Migrator::class);
                $report = $migrator->migrate($force);
            } else {
                /** @var Migrator $migrator */
                $migrator = $container->make(Migrator::class);
                $report = $migrator->rollback($force, $steps);
            }
        } catch (ConnectionException $error) {
            throw new ConsoleException($error->getMessage());
        } catch (DestructiveOperationException $refusal) {
            throw new ConsoleException($refusal->getMessage());
        } catch (MigrationException $error) {
            throw new ConsoleException($error->getMessage());
        } catch (Throwable $error) {
            throw new ConsoleException($error->getMessage());
        }

        foreach ($report->lines() as $line) {
            $output->line($line);
        }

        return 0;
    }
}
