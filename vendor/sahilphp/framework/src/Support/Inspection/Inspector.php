<?php

declare(strict_types=1);

namespace Sahil\Support\Inspection;

use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Support\Redactor;

/**
 * CLI-facing, secret-safe application inspection helpers.
 *
 * Anything these classes print is safe to show on a terminal:
 * - configuration => dotted keys, values, secrets as `[redacted]`,
 * - environment   => variable NAMES only (never values),
 * - routing       => names / verbs / paths, never handler internals.
 */
final class Inspector
{
    public const SECRET_MARKER = '[redacted]';

    private function __construct()
    {
    }

    /**
     * "about"-style one-liner status map (no secret material involved).
     *
     * Values are scalars suitable for key/value console output.
     *
     * @return array<string, string>
     */
    public static function about(ApplicationContract $application): array
    {
        $config = $application->config();

        return [
            'app name' => (string) $config->get('app.name', 'SahilPHP'),
            'environment' => (string) $config->get('app.environment', '(unset)'),
            'debug mode' => $config->get('app.debug') === true ? 'on' : 'off',
            'timezone' => (string) $config->get('app.timezone', '(unset)'),
            'php' => PHP_VERSION,
            'booted' => $application->isBooted() ? 'yes' : 'no',
        ];
    }

    /**
     * Safe configuration inspection: dotted keys with redacted values.
     * Optionally restricted to one top-level namespace (e.g. `app`).
     *
     * @return array<string, string|int|float|bool|null>
     */
    public static function configSafe(ApplicationContract $application, ?string $namespace = null): array
    {
        $config = $application->config();

        if ($namespace !== null && $namespace !== '') {
            $tree = $config->get($namespace);

            if (!is_array($tree)) {
                throw new \InvalidArgumentException(
                    sprintf('Config namespace [%s] is not an array (not found or scalar).', $namespace),
                );
            }

            return Redactor::flattenSafely([$namespace => $tree]);
        }

        return Redactor::flattenSafely($config->all());
    }

    /**
     * Environment validation for the documented application variables.
     *
     * Prints NAMES ONLY — values must never reach the terminal.
     *
     * @return array{status: 'ok'|'issues', exit: int, report: list<string>}
     */
    public static function environmentCheck(ApplicationContract $application): array
    {
        $environment = $application->environment();

        /**
         * Documented, application-known variables; true = required (in
         * production deployments).
         *
         * The database variables are documented in `.env.example` and are
         * deliberately NOT required: a deployment may legitimately run
         * without a database (or authenticate as a passwordless local
         * socket user), so their absence is reported, never failed.
         */
        $known = [
            'APP_ENV' => false,
            'APP_DEBUG' => true,
            'APP_URL' => false,
            'APP_LOCALE' => false,
            'APP_TIMEZONE' => false,
            'DB_DRIVER' => false,
            'DB_HOST' => false,
            'DB_PORT' => false,
            'DB_DATABASE' => false,
            'DB_USERNAME' => false,
            'DB_PASSWORD' => false,
            'DB_CHARSET' => false,
            'MAIL_TRANSPORT' => false,
            'MAIL_HOST' => false,
            'MAIL_PORT' => false,
            'MAIL_SECURITY' => false,
            'MAIL_USERNAME' => false,
            'MAIL_PASSWORD' => false,
            'MAIL_FROM_ADDRESS' => false,
            'MAIL_FROM_NAME' => false,
            'MAIL_QUEUE' => false,
            'MAIL_QUEUE_NAME' => false,
            'ASSET_PROFILE' => false,
            'ASSET_MODE' => false,
            'ASSET_DEV_SERVER' => false,
            'ASSET_URL' => false,
            'ASSET_VERIFY' => false,
        ];

        $report = [];
        $exitCode = 0;

        $appEnvironment = (string) $environment->get('APP_ENV', 'development');
        $isProduction = strcasecmp($appEnvironment, 'production') === 0;

        foreach ($known as $name => $required) {
            if (!$environment->has($name)) {
                $report[] = sprintf(
                    'MISSING %-20s %s',
                    $name,
                    $required && $isProduction ? '(REQUIRED in production)' : '(not set, framework defaults apply)',
                );

                if ($required && $isProduction) {
                    $exitCode = 1;
                }

                continue;
            }

            $report[] = sprintf('SET     %-20s (value hidden)', $name);
        }

        // Deployment safety: production must run with debug explicitly off.
        if ($isProduction
            && filter_var((string) $environment->get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL) === true
        ) {
            $report[] = 'WARNING APP_DEBUG=true while APP_ENV=production — diagnostics would be exposed.';
            $exitCode = 1;
        }

        return [
            'status' => $exitCode === 0 ? 'ok' : 'issues',
            'exit' => $exitCode,
            'report' => $report,
        ];
    }
}
