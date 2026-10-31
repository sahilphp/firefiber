<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// PHPUnit framework classes live in the extracted source tree. Load the
// PHPUnit autoloader mapping before the test classes boot, so that
// PHPUnit\Framework\TestCase resolves to vendor/phpunit/phpunit/src.
require __DIR__ . '/../vendor/phpunit-bootstrap.php';

// The package's PSR-7/Slim dependencies are not installed in this workspace.
// Provide the small surface used by FirefiberMiddleware so its real code can
// still be exercised. Real dependencies win when they are present.
if (!interface_exists('Psr\Http\Message\StreamInterface')) {
    require_once __DIR__ . '/Support/Stubs/PsrStreamInterface.php';
}

if (!interface_exists('Psr\Http\Message\ServerRequestInterface')) {
    require_once __DIR__ . '/Support/Stubs/PsrServerRequestInterface.php';
}

if (!interface_exists('Psr\Http\Message\ResponseInterface')) {
    require_once __DIR__ . '/Support/Stubs/PsrResponseInterface.php';
}

if (!interface_exists('Psr\Http\Server\RequestHandlerInterface')) {
    require_once __DIR__ . '/Support/Stubs/PsrRequestHandlerInterface.php';
}

if (!interface_exists('Psr\Http\Server\MiddlewareInterface')) {
    require_once __DIR__ . '/Support/Stubs/PsrMiddlewareInterface.php';
}

if (!class_exists('Slim\Psr7\Response')) {
    require_once __DIR__ . '/Support/Stubs/SlimPsr7.php';
}

require_once __DIR__ . '/Support/FakeServerRequest.php';
