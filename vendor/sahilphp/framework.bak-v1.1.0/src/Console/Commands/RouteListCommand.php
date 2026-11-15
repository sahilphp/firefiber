<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use Closure;
use Sahil\Console\Command;
use Sahil\Console\CommandInput;
use Sahil\Console\CommandOutput;
use Sahil\Http\Router;

/**
 * Lists every route registered by the application.
 */
final class RouteListCommand extends Command
{
    public function name(): string
    {
        return 'route:list';
    }

    public function description(): string
    {
        return 'List registered routes, their verbs, names and handlers.';
    }

    public function usage(): string
    {
        return 'php sahil route:list';
    }

    public function category(): string
    {
        return 'Routing';
    }

    /**
     * @return list<string>
     */
    public function examples(): array
    {
        return ['php sahil route:list'];
    }

    public function execute(CommandInput $input, CommandOutput $output): int
    {
        $input->rejectExtraArguments(0, $this->usage());

        /** @var Router $router */
        $router = $this->app()->container()->make(Router::class);
        $routes = $router->routes();

        if ($routes === []) {
            $output->line('no routes registered');

            return 0;
        }

        $output->line(sprintf('%-9s  %-32s  %-20s  %s', 'METHODS', 'PATH', 'NAME', 'HANDLER'));

        foreach ($routes as $route) {
            $handler = $route->handler();

            if ($handler instanceof Closure) {
                $label = 'Closure';
            } else {
                $label = $handler[0].'@'.$handler[1];
            }

            $output->line(sprintf(
                '%-9s  %-32s  %-20s  %s',
                implode('|', $route->methods()),
                $route->path(),
                $route->routeName() ?? '-',
                $label,
            ));
        }

        return 0;
    }
}
