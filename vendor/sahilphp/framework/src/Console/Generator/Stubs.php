<?php

declare(strict_types=1);

namespace Sahil\Console\Generator;

/**
 * Built-in code templates.
 *
 * Templates are deliberately small and use only documented SahilPHP
 * contracts: no Eloquent imports, no Laravel-only methods, no invented
 * authorization APIs.
 */
final class Stubs
{
    private function __construct()
    {
    }

    public static function controller(string $namespace, string $class): string
    {
        return strtr(<<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}};

use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * {{class}}.
 *
 * Handler methods receive the current request and return a response; the
 * HTTP kernel resolves this class through the container.
 */
final class {{class}}
{
    public function index(Request $request): Response
    {
        return Response::json([
            'message' => '{{class}}@index',
        ]);
    }
}
PHP, [
            '{{namespace}}' => $namespace,
            '{{class}}' => $class,
        ]);
    }

    public static function model(string $namespace, string $class, string $table): string
    {
        return strtr(<<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}};

use Sahil\Orm\Model;

/**
 * {{class}} model.
 *
 * Add the attributes that may be mass assigned to `$fillable`; an empty
 * allowlist rejects every mass assignment by design.
 */
final class {{class}} extends Model
{
    protected ?string $table = '{{table}}';

    /** @var list<string> */
    protected array $fillable = [];

    /** @var array<string, string> */
    protected array $casts = [];
}
PHP, [
            '{{namespace}}' => $namespace,
            '{{class}}' => $class,
            '{{table}}' => $table,
        ]);
    }

    public static function middleware(string $namespace, string $class): string
    {
        return strtr(<<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}};

use Closure;
use Sahil\Http\Middleware\MiddlewareContract;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * {{class}} middleware.
 *
 * Call `$next($request)` exactly once; middleware must not emit a second
 * response.
 */
final class {{class}} implements MiddlewareContract
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
PHP, [
            '{{namespace}}' => $namespace,
            '{{class}}' => $class,
        ]);
    }

    public static function policy(string $namespace, string $class, string $modelClass): string
    {
        return strtr(<<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}};

use Sahil\Auth\Policy;
use Sahil\Contracts\Auth\AuthenticatableContract;

/**
 * {{class}}.
 *
 * @see \{{modelClass}}
 */
final class {{class}} extends Policy
{
    public function view(AuthenticatableContract $user, mixed $model): bool
    {
        return false;
    }

    public function create(AuthenticatableContract $user): bool
    {
        return false;
    }

    public function update(AuthenticatableContract $user, mixed $model): bool
    {
        return false;
    }

    public function delete(AuthenticatableContract $user, mixed $model): bool
    {
        return false;
    }
}
PHP, [
            '{{namespace}}' => $namespace,
            '{{class}}' => $class,
            '{{modelClass}}' => $modelClass,
        ]);
    }

    public static function provider(string $namespace, string $class): string
    {
        return strtr(<<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}};

use Sahil\Foundation\ServiceProvider;

/**
 * {{class}}.
 *
 * Bind services in `register()` and wire them in `boot()`. Add the class to
 * `bootstrap/providers.php` to register it for this application.
 */
final class {{class}} extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
    }
}
PHP, [
            '{{namespace}}' => $namespace,
            '{{class}}' => $class,
        ]);
    }

    public static function command(string $namespace, string $class, string $commandName): string
    {
        return strtr(<<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}};

use Sahil\Console\Command;
use Sahil\Console\CommandInput;
use Sahil\Console\CommandOutput;

/**
 * {{class}}.
 *
 * Add this class to `bootstrap/commands.php` to register it with the console.
 */
final class {{class}} extends Command
{
    public function name(): string
    {
        return '{{commandName}}';
    }

    public function description(): string
    {
        return 'The {{class}} command.';
    }

    public function execute(CommandInput $input, CommandOutput $output): int
    {
        $output->line('{{class}} ran.');

        return 0;
    }
}
PHP, [
            '{{namespace}}' => $namespace,
            '{{class}}' => $class,
            '{{commandName}}' => $commandName,
        ]);
    }

    public static function seeder(string $class): string
    {
        return strtr(<<<'PHP'
<?php

declare(strict_types=1);

use Sahil\Migrations\SeedContext;
use Sahil\Migrations\Seeder;

/**
 * {{class}}.
 *
 * Seeders return an instance; the file name is the seeder name used by
 * `php sahil db:seed --class={{name}}`.
 */
return new class () extends Seeder {
    public function run(SeedContext $context): void
    {
    }
};
PHP, [
            '{{class}}' => $class,
            '{{name}}' => $class,
        ]);
    }

    /**
     * @param list<string> $extraColumns
     */
    public static function migrationCreate(string $table, array $extraColumns = []): string
    {
        $columns = '';

        foreach ($extraColumns as $column) {
            $columns .= sprintf("\n            \$table->string('%s', 255);", $column);
        }

        return strtr(<<<'PHP'
<?php

declare(strict_types=1);

use Sahil\Database\Schema\Schema;
use Sahil\Database\Schema\Table;
use Sahil\Migrations\Migration;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('{{table}}', static function (Table $table): void {
            $table->id();{{columns}}
            $table->timestamps();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('{{table}}');
    }
};
PHP, [
            '{{table}}' => $table,
            '{{columns}}' => $columns,
        ]);
    }

    /**
     * @param list<string> $columns
     */
    public static function migrationAdd(string $table, array $columns): string
    {
        $up = '';
        $down = '';

        foreach ($columns as $column) {
            $up .= sprintf("\n            \$table->string('%s', 255);", $column);
            $down .= sprintf("\n            \$table->dropColumn('%s');", $column);
        }

        return strtr(<<<'PHP'
<?php

declare(strict_types=1);

use Sahil\Database\Schema\Schema;
use Sahil\Database\Schema\Table;
use Sahil\Migrations\Migration;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->table('{{table}}', static function (Table $table): void {{{up}}
        });
    }

    public function down(Schema $schema): void
    {
        $schema->table('{{table}}', static function (Table $table): void {{{down}}
        });
    }
};
PHP, [
            '{{table}}' => $table,
            '{{up}}' => $up,
            '{{down}}' => $down,
        ]);
    }
}
