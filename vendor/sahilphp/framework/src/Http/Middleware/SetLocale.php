<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

use Closure;
use Sahil\Config\Repository;
use Sahil\Container\Container;
use Sahil\Contracts\Session\SessionContract;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\I18n\Locale;
use Sahil\I18n\LocaleNegotiator;

/**
 * Resolves the locale for the current request.
 *
 * Precedence (each step only fires when the produced value is in the
 * application's `app.available_locales` — arbitrary request input never
 * activates an unsupported locale):
 *
 *   1. an explicit `?{param}=` query selection (parameter name from
 *      `app.locale_parameter`, default `lang`) — also persisted into the
 *      session when sessions are in use,
 *   2. the session's stored preference (`app.locale_session_key`, default
 *      `locale`) when a session is bound,
 *   3. `Accept-Language` negotiation (disable via `app.locale_negotiate`),
 *   4. the configured default (`app.locale`, `sw` unless changed).
 *
 * The selected locale is written to the request-scoped
 * {@see \Sahil\I18n\RequestLocale} binding, which the container discards
 * when the request scope ends:
 * long-running workers cannot leak one request's locale into the next.
 */
final class SetLocale implements MiddlewareContract
{
    public function __construct(
        private readonly Locale $locale,
        private readonly Container $container,
        private readonly Repository $config,
        private readonly ?LocaleNegotiator $negotiator = null,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $selected = $this->resolve($request);

        if ($selected !== null) {
            $this->locale->setIfSupported($selected);
        }

        return $next($request);
    }

    private function resolve(Request $request): ?string
    {
        $parameter = (string) $this->config->get('app.locale_parameter', 'lang');

        if ($parameter !== '') {
            $explicit = $request->queryParameter($parameter);

            if (is_string($explicit) && $explicit !== '' && $this->locale->isSupported($explicit)) {
                $this->persist($explicit);

                return $explicit;
            }
        }

        $stored = $this->stored();

        if (is_string($stored) && $stored !== '' && $this->locale->isSupported($stored)) {
            return $stored;
        }

        if ($this->config->get('app.locale_negotiate', true) !== false) {
            $negotiated = ($this->negotiator ?? new LocaleNegotiator())
                ->negotiate($request->header('Accept-Language'), $this->locale->supported());

            if ($negotiated !== null) {
                return $negotiated;
            }
        }

        return null;
    }

    /**
     * Remember an explicit selection for later requests when sessions are
     * configured; silently skipped in session-less applications.
     */
    private function persist(string $locale): void
    {
        $session = $this->session();

        if ($session !== null) {
            $session->put($this->sessionKey(), $locale);
        }
    }

    private function stored(): mixed
    {
        return $this->session()?->get($this->sessionKey());
    }

    private function sessionKey(): string
    {
        return (string) $this->config->get('app.locale_session_key', 'locale');
    }

    private function session(): ?SessionContract
    {
        if (!$this->container->bound(SessionContract::class)) {
            return null;
        }

        $session = $this->container->make(SessionContract::class);

        return $session instanceof SessionContract && $session->isStarted() ? $session : null;
    }
}
