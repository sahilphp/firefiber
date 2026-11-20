<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Attributes;

use Attribute;

/**
 * Declare a component method as a listener for a Firefiber event.
 *
 * `#[On('post-created')]` methods receive the event payload spread as call
 * arguments — an `['postId' => 7]` payload invokes `listener(postId: 7)`.
 * The older `$listeners` map remains supported and receives the payload
 * array as a single argument instead.
 *
 * Listener methods must be public and declared on the component class:
 * framework internals can never be registered or reached as listeners.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class On
{
    public function __construct(
        public readonly string $event,
    ) {
    }
}
