<?php

declare(strict_types=1);

namespace Sahil\Frontend;

/**
 * Invalid frontend rendering configuration: a response targets a frontend
 * but has no rendered view, a mount id or component name is unsafe, a
 * payload cannot be serialized, or an asset URL is not allowed.
 *
 * These are configuration/programming errors, so they always fail loudly
 * with a message that names the offending value.
 */
final class InvalidFrontendConfigurationException extends FrontendException
{
}
