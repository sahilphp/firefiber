<?php

declare(strict_types=1);

namespace Sahil\Http;

use Sahil\Container\Container;
use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Http\Exception\HttpException;
use Sahil\View\TemplateCompiler;
use Sahil\View\TemplateResolver;
use Sahil\View\ViewEngine;
use Throwable;

/**
 * Turns an uncaught throwable into the response a client of that request
 * expects.
 *
 * ### HTML by default, JSON for machine clients
 *
 * A browser (or any client that does not ask for JSON) gets a designed HTML
 * error page. A client that asks for JSON — `Accept: application/json`, a JSON
 * request body, or `X-Requested-With: XMLHttpRequest` — keeps the stable JSON
 * contract:
 *
 *     {"error":"http_error","status":404}      404 from an HttpException
 *     {"error":"server_error","message":"…"}   500
 *
 * In debug mode (never default) the JSON contract switches to a structured
 * diagnostic payload — `{"error":{"type","message","file","line","status",
 * "previous","trace"}}` — and the HTML page shows the same details. In
 * production, diagnostics stay server-side: the log records everything, the
 * client only ever sees the generic contract.
 *
 * ### Where the pages come from
 *
 * The framework ships the error pages and renders them through the normal
 * template engine, so an application that wants its own design runs
 * `php sahil error:publish` and edits `resources/views/errors/*.sahil.php`.
 * Published views win because the application's view roots are searched
 * before the framework's.
 *
 * Rendering an error page must never fail (the storage directory may be
 * unwritable exactly when a 500 is being reported), so a template failure
 * falls back to a self-contained page built here.
 */
final class ErrorRenderer
{
    /** Views shipped with the framework (the last search root). */
    private const PACKAGE_VIEWS = '/resources/views';

    public function __construct(private readonly ?ViewEngine $views = null)
    {
    }

    /**
     * Build the renderer for a booted installation.
     *
     * Any failure to wire the view layer (a project mid-install, an unreadable
     * config) degrades to the built-in page instead of escalating: this code
     * runs while an error is already being reported.
     */
    public static function fromContainer(Container $container): self
    {
        try {
            /** @var ApplicationContract $application */
            $application = $container->make(ApplicationContract::class);
            $config = $application->config();

            $roots = [];

            foreach ((array) $config->get('view.paths', ['resources/views']) as $path) {
                $path = (string) $path;

                if ($path === '') {
                    continue;
                }

                $roots[] = self::isAbsolute($path) ? rtrim($path, '/') : $application->basePath($path);
            }

            $packageViews = dirname(__DIR__, 2).self::PACKAGE_VIEWS;

            if (is_dir($packageViews)) {
                $roots[] = $packageViews;
            }

            $roots = array_values(array_unique($roots));

            if ($roots === []) {
                return new self();
            }

            $extension = (string) $config->get('view.extension', '.sahil.php');

            $compiler = $container->bound(TemplateCompiler::class)
                ? $container->make(TemplateCompiler::class)
                : new TemplateCompiler(self::cachePath($application));

            if (!$compiler instanceof TemplateCompiler) {
                return new self();
            }

            return new self(new ViewEngine(new TemplateResolver($roots, $extension), $compiler));
        } catch (Throwable) {
            return new self();
        }
    }

    /**
     * Render the throwable as a response.
     *
     * A null request (used by standalone callers) keeps the JSON contract.
     */
    public function respond(Throwable $throwable, ?Request $request = null, bool $debug = false): Response
    {
        $status = ExceptionDiagnostics::status($throwable);
        $headers = $throwable instanceof HttpException ? $throwable->getHeaders() : [];

        $response = $request !== null && !self::expectsJson($request)
            ? $this->html($throwable, $status, $debug)
            : self::json($throwable, $status, $debug);

        return $response->withHeaders($headers);
    }

    /**
     * Whether the request is made by a client that wants a JSON answer rather
     * than a document.
     */
    public static function expectsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->header('Accept', ''));

        if (str_contains($accept, 'application/json')) {
            return true;
        }

        $contentType = strtolower(trim((string) $request->header('Content-Type', '')));

        if (str_starts_with($contentType, 'application/json')) {
            return true;
        }

        return strtolower(trim((string) $request->header('X-Requested-With', ''))) === 'xmlhttprequest';
    }

    private static function json(Throwable $throwable, int $status, bool $debug): Response
    {
        if ($debug) {
            // Structured diagnostics: the same information the log records —
            // type, message, location, status, cause chain and stack trace.
            return Response::json(['error' => ExceptionDiagnostics::payload($throwable)], $status);
        }

        if ($throwable instanceof HttpException) {
            return Response::json(['error' => 'http_error', 'status' => $status], $status);
        }

        return Response::json(
            ['error' => 'server_error', 'message' => self::text('errors.server_error', 'An unexpected server error occurred.')],
            500,
        );
    }

    private function html(Throwable $throwable, int $status, bool $debug): Response
    {
        $body = $this->renderPage($throwable, $status, $debug)
            ?? self::fallbackPage($status, $throwable, $debug);

        // An error page is never cacheable: the next request may succeed.
        return Response::html($body, $status)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Render `errors.{status}`, then the `errors.4xx`/`errors.5xx` group page,
     * and answer null when no template can be rendered.
     */
    private function renderPage(Throwable $throwable, int $status, bool $debug): ?string
    {
        if ($this->views === null) {
            return null;
        }

        $data = [
            'status' => $status,
            'reason' => Status::reason($status),
            'debug' => $debug,
            'exception_previous' => '',
            'exception_trace' => [],
        ];

        if ($debug) {
            $data['exception_class'] = $throwable::class;
            $data['exception_message'] = $throwable->getMessage();
            $data['exception_file'] = $throwable->getFile();
            $data['exception_line'] = $throwable->getLine();
            $data['exception_previous'] = ExceptionDiagnostics::previousSummary($throwable) ?? '';
            $data['exception_trace'] = ExceptionDiagnostics::trace($throwable);
        }

        foreach (self::candidates($status) as $view) {
            try {
                if (!$this->views->exists($view)) {
                    continue;
                }

                return $this->views->render($view, $data);
            } catch (Throwable) {
                // A broken or uncompilable page must not replace the error.
                continue;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function candidates(int $status): array
    {
        $candidates = ['errors.'.$status];

        $group = intdiv($status, 100);

        if ($group === 4 || $group === 5) {
            $candidates[] = 'errors.'.$group.'xx';
        }

        return $candidates;
    }

    /**
     * Self-contained page used when no template can be rendered. It carries
     * the status, the reason phrase and (in debug mode) the exception, and
     * needs no assets, no cache and no storage.
     */
    private static function fallbackPage(int $status, Throwable $throwable, bool $debug): string
    {
        $reason = htmlspecialchars(Status::reason($status), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escape = static fn (string $value): string
            => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $details = '';

        if ($debug) {
            $details = sprintf(
                '<div class="details">'
                .'<h2>%s</h2>'
                .'<p>%s</p>'
                .'<p class="location">%s:%d</p>'
                .'%s'
                .'%s'
                .'</div>',
                $escape($throwable::class),
                $escape($throwable->getMessage()),
                $escape($throwable->getFile()),
                $throwable->getLine(),
                ($previous = ExceptionDiagnostics::previousSummary($throwable)) !== null
                    ? '<p class="cause"><strong>Caused by:</strong> '.$escape($previous).'</p>'
                    : '',
                ($trace = ExceptionDiagnostics::trace($throwable)) !== []
                    ? '<pre class="trace">'.$escape(implode("\n", $trace)).'</pre>'
                    : '',
            );
        }

        $title = $escape(self::text('errors.server_error_title', 'Something went wrong'));
        $tryAgain = $escape(self::text('errors.try_again', 'Please try again. If the problem persists, contact the site administrator.'));
        $backHome = $escape(self::text('errors.back_home', 'Back to home'));

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <meta name="robots" content="noindex, nofollow">
                <title>{$status} · {$reason}</title>
                <style>
                    :root { color-scheme: light dark; }
                    * { box-sizing: border-box; }
                    body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 32px 20px;
                           background: #f5f7fb; color: #101828;
                           font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
                    main { width: 100%; max-width: 560px; padding: 40px; background: #fff; border: 1px solid #e3e8ef;
                           border-radius: 20px; box-shadow: 0 24px 60px -32px rgba(16, 24, 40, .35); }
                    .code { margin: 0; font-size: 13px; letter-spacing: .18em; text-transform: uppercase; color: #0b6fe8; }
                    h1 { margin: 12px 0 8px; font-size: 30px; line-height: 1.2; }
                    p { margin: 0 0 12px; color: #5b6675; line-height: 1.6; }
                    .details { padding: 14px 16px; margin-top: 20px; border-radius: 12px; background: #f5f7fb;
                               border: 1px solid #e3e8ef; color: #101828; font-size: 14px; overflow-wrap: anywhere; }
                    .details h2 { margin: 0 0 6px; font-size: 13px; letter-spacing: .05em; text-transform: uppercase;
                                  color: #5b6675; }
                    .details p { margin: 0 0 8px; color: #101828; }
                    .details .location, .details .cause { color: #5b6675; font-size: 13px; }
                    .details .trace { margin: 10px 0 0; max-height: 320px; overflow: auto; font-size: 12.5px;
                                      line-height: 1.6; white-space: pre-wrap; color: #5b6675;
                                      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
                    a { display: inline-block; margin-top: 12px; padding: 11px 18px; border-radius: 10px;
                        background: #0b6fe8; color: #fff; text-decoration: none; font-weight: 600; }
                    @media (prefers-color-scheme: dark) {
                        body { background: #0b1020; color: #eef2f9; }
                        main { background: #141a2c; border-color: #242d44; }
                        p { color: #9aa7bd; }
                        .details { background: #1b2540; border-color: #242d44; color: #eef2f9; }
                        .details h2 { color: #9aa7bd; }
                        .details p { color: #eef2f9; }
                        .details .location, .details .cause, .details .trace { color: #9aa7bd; }
                        a { background: #4c9bff; color: #08111f; }
                    }
                </style>
            </head>
            <body>
                <main>
                    <p class="code">{$status} · {$reason}</p>
                    <h1>{$title}</h1>
                    <p>{$tryAgain}</p>
                    {$details}
                    <a href="/">{$backHome}</a>
                </main>
            </body>
            </html>
            HTML;
    }

    /**
     * User-facing copy goes through the application's translator so error
     * responses follow the active locale. The lookup is deliberately soft:
     * without a booted application or a resolvable translator — exactly the
     * situations this renderer exists for — the English default is used and
     * nothing can fail here.
     */
    private static function text(string $key, string $default): string
    {
        try {
            $translated = translator()->get($key);

            return $translated === $key ? $default : $translated;
        } catch (Throwable) {
            return $default;
        }
    }

    private static function cachePath(ApplicationContract $application): string
    {
        $cache = (string) $application->config()->get('view.cache', 'storage/framework/views');

        return self::isAbsolute($cache) ? rtrim($cache, '/') : $application->basePath($cache);
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
            || str_starts_with($path, '\\\\');
    }
}
