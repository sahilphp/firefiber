<?php

declare(strict_types=1);

/*
 * `composer create-project sahilphp/firefiber` is never supported: this
 * package is a library. post-create-project-cmd runs only for
 * create-project, so requiring or installing the package is unaffected.
 */

fwrite(STDERR, "sahilphp/firefiber cannot be installed with composer create-project.\n");
fwrite(STDERR, "This package cannot be used as a project skeleton.\n");
fwrite(STDERR, "Install it as part of a SahilPHP project:\n");
fwrite(STDERR, "  sahilphp new my-app\n");
fwrite(STDERR, "Then choose your UI kit during setup.\n");

exit(1);
