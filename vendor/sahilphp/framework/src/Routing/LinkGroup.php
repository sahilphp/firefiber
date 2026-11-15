<?php

declare(strict_types=1);

namespace Sahil\Routing;

use Closure;
use InvalidArgumentException;
use Sahil\Http\Router;

/**
 * Pending route group, created by {@see Link::prefix()}.
 *
 *     return [
 *         Link::get('/', $home),
 *         ...Link::prefix('/admin')
 *             ->middleware(Authenticate::class)
 *             ->group(fn () => [
 *                 Link::get('/dashboard', $dashboard),
 *                 Link::get('/users', $users),
 *             ]),
 *     ];
 *
 * The group delegates to the real {@see Router::group()} rebasing machinery,
 * so prefixes and inherited middleware behave exactly as they do for
 * `Router::group()` route tables. The returned list of links spreads into
 * the surrounding route file's array.
 */
final class LinkGroup
{
    /** @var list<object|class-string> */
    private array $middleware = [];

    public function __construct(private readonly string $prefix)
    {
    }

    /**
     * Middleware inherited by every link in the group (outermost first).
     *
     * @param object|class-string|list<object|class-string>|null $middleware
     */
    public function middleware(array|object|string|null $middleware): self
    {
        $list = $middleware === null ? [] : (is_array($middleware) ? $middleware : [$middleware]);

        $clone = clone $this;
        $clone->middleware = [...$clone->middleware, ...$list];

        return $clone;
    }

    /**
     * Resolve the group into a flat list of rebased links.
     *
     * @param Closure(): (Link|list<Link>) $definition returns the group's links
     * @return list<Link>
     */
    public function group(Closure $definition): array
    {
        $router = new Router();

        $router->group(
            $this->prefix,
            static function (Router $collector) use ($definition): void {
                /** @var mixed $inner */
                $inner = $definition();

                $links = $inner instanceof Link ? [$inner] : $inner;

                if (!is_array($links)) {
                    throw new InvalidArgumentException(
                        'HTTP: Link groups must return a Link or a list of Link definitions.',
                    );
                }

                foreach ($links as $link) {
                    if (!$link instanceof Link) {
                        throw new InvalidArgumentException(sprintf(
                            'HTTP: Link groups only accept Link definitions, got [%s].',
                            get_debug_type($link),
                        ));
                    }
                }

                $collector->register(...array_values($links));
            },
            $this->middleware,
        );

        return $router->routes();
    }
}
