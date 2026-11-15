<?php

declare(strict_types=1);

namespace Sahil\Config;

use RuntimeException;

/**
 * Raised for configuration problems the operator must fix (bad types,
 * missing required keys, invalid values). Deliberately NOT part of the
 * HTTP exception tree: configuration errors are boot/startup failures and
 * never render as HTTP status codes.
 */
final class ConfigurationException extends RuntimeException
{
}
