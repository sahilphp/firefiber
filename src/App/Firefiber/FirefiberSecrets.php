<?php

declare(strict_types=1);

namespace Sahil\Firefiber;

use RuntimeException;
use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Contracts\Session\SessionContract;

/**
 * Derives the keys used by the secure action protocol.
 *
 * The master key is server-only. It is either the configured
 * `FIREFIBER_HMAC_KEY` or an HMAC derived from the application key, so a
 * fresh SahilPHP installation does not need a second secret when it has
 * already generated an application key.
 *
 * Rendered pages do not receive any signing secret. The session-bound
 * client key remains as a legacy helper for optional body-integrity
 * signatures, but it is never rendered to the browser and it cannot be used
 * to sign snapshots or authorize component actions.
 */
final class FirefiberSecrets
{
    public static function masterKey(ApplicationContract $application): string
    {
        $configured = $application->config()->get('firefiber.hmac_key');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $applicationKey = $application->config()->get('app.key');

        if (is_string($applicationKey) && trim($applicationKey) !== '') {
            return hash_hmac('sha256', 'firefiber/master', trim($applicationKey));
        }

        throw new RuntimeException(
            'Firefiber requires FIREFIBER_HMAC_KEY or a generated APP_KEY. Run php sahil key:generate.',
        );
    }

    /** @deprecated Legacy optional body-integrity helper; never render this value. */
    public static function clientKey(ApplicationContract $application, SessionContract $session): string
    {
        $sessionId = $session->isStarted() ? $session->id() : '';

        if ($sessionId === '') {
            throw new RuntimeException('A started session is required to derive a Firefiber client signing key.');
        }

        return hash_hmac('sha256', 'firefiber/client/'.$sessionId, self::masterKey($application));
    }
}
