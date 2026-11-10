<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Generator;

/**
 * Built-in Firefiber code templates.
 */
final class Stubs
{
    private function __construct()
    {
    }

    public static function component(string $namespace, string $class, string $kind = 'counter'): string
    {
        if ($kind === 'hero') {
            return strtr(<<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}};

use Sahil\Firefiber\Component;
use Sahil\Firefiber\Exceptions\UnknownActionException;

/**
 * {{class}}.
 *
 * This starter component is intentionally small: replace its state, actions
 * and markup as the real page requires.
 */
final class {{class}} extends Component
{
    public function __construct()
    {
        parent::__construct(['message' => 'Build something reactive.'], ['updateMessage']);
    }

    /** @var list<string> */
    protected array $modelable = ['message'];

    protected function formRules(): array
    {
        return ['message' => 'required|string|max:255'];
    }

    public function render(): string
    {
        $message = htmlspecialchars((string) ($this->state['message'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div class="firefiber-hero"><h2>Firefiber</h2><p data-firefiber-bind="message">'.$message.'</p>'
            .'<label>Message <input type="text" value="'.$message.'" fiber:model="message"></label>'
            .'<p data-firefiber-error="message"></p></div>';
    }

    public function handleAction(string $action, array $payload): array
    {
        if ($action !== 'updateMessage') {
            throw new UnknownActionException($action, $this->id());
        }

        $message = (string) ($payload['message'] ?? $this->state['message'] ?? '');
        $this->updateState(['message' => $message]);

        return ['message' => $message];
    }
}
PHP, [
                '{{namespace}}' => $namespace,
                '{{class}}' => $class,
            ]);
        }

        if ($kind === 'stats') {
            return strtr(<<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}};

use Sahil\Firefiber\Component;
use Sahil\Firefiber\Exceptions\UnknownActionException;

/**
 * {{class}}.
 *
 * A small interactive counter with secure server-side state.
 */
final class {{class}} extends Component
{
    protected array $propertyDefinitions = [
        'step' => ['type' => 'int', 'default' => 1, 'min' => 1],
    ];

    protected array $emits = ['count-changed'];

    public function __construct(array $properties = [])
    {
        parent::__construct(['count' => 0], ['increment', 'decrement'], $properties);
    }

    protected function eventPayloadRules(): array
    {
        return [
            'count-changed' => [
                'count' => 'required|integer',
                'step' => 'required|integer|min:1',
            ],
        ];
    }

    public function render(): string
    {
        $count = (int) ($this->state['count'] ?? 0);

        return '<div class="firefiber-stats"><span data-firefiber-bind="count">'.$count.'</span>'
            .'<div><button type="button" fiber:click="increment">Increment</button>'
            .'<button type="button" fiber:click="decrement">Decrement</button></div></div>';
    }

    public function handleAction(string $action, array $payload): array
    {
        $count = (int) ($this->state['count'] ?? 0);
        $step = (int) $this->property('step');

        if ($action === 'increment') {
            $count += $step;
        } elseif ($action === 'decrement') {
            $count -= $step;
        } else {
            throw new UnknownActionException($action, $this->id());
        }

        $this->updateState(['count' => $count]);
        $this->emit('count-changed', ['count' => $count, 'step' => $step]);

        return ['count' => $count];
    }
}
PHP, [
                '{{namespace}}' => $namespace,
                '{{class}}' => $class,
            ]);
        }

        return strtr(<<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}};

use Sahil\Firefiber\Component;
use Sahil\Firefiber\Exceptions\UnknownActionException;

/**
 * {{class}}.
 *
 * This is a small interactive starter. Replace its state, actions and markup
 * as the real component requires.
 */
final class {{class}} extends Component
{
    public function __construct()
    {
        parent::__construct(['count' => 0], ['increment', 'decrement']);
    }

    public function render(): string
    {
        $count = (int) ($this->state['count'] ?? 0);

        return '<div class="firefiber-counter"><span data-firefiber-bind="count">'.$count.'</span>'
            .'<div><button type="button" fiber:click="increment">Increment</button>'
            .'<button type="button" fiber:click="decrement">Decrement</button></div></div>';
    }

    public function handleAction(string $action, array $payload): array
    {
        $count = (int) ($this->state['count'] ?? 0);

        if ($action === 'increment') {
            $count++;
        } elseif ($action === 'decrement') {
            $count--;
        } else {
            throw new UnknownActionException($action, $this->id());
        }

        $this->updateState(['count' => $count]);

        return ['count' => $count];
    }
}
PHP, [
            '{{namespace}}' => $namespace,
            '{{class}}' => $class,
        ]);
    }

    /**
     * Generate an inline component stub.
     * Inline components are rendered directly within a page's components() method
     * and do not have their own dedicated file. They use the Component base class
     * but are designed to be composed into pages rather than generated as standalone
     * files.
     *
     * @param string $namespace The component namespace
     * @param string $class The component class name
     * @return string The inline component PHP source code
     */
    public static function inlineComponent(string $namespace, string $class): string
    {
        return strtr(<<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}};

use Sahil\Firefiber\Component;
use Sahil\Firefiber\Exceptions\UnknownActionException;

/**
 * {{class}} - inline component
 *
 * An inline component rendered directly within a page. Insert it with
 * `<fiber:component name="{{class}}" />` or `@component('{{class}}')`.
 */
final class {{class}} extends Component
{
    public function __construct()
    {
        parent::__construct(['message' => ''], ['updateMessage']);
    }

    protected function formRules(): array
    {
        return [];
    }

    public function render(): string
    {
        return '<div data-firefiber-bind="message">test</div>';
    }

    public function handleAction(string $action, array $payload): array
    {
        if ($action !== 'updateMessage') {
            throw new UnknownActionException($action, $this->id());
        }

        return ['message' => (string) ($payload['message'] ?? '')];
    }
}
PHP, [
                    '{{namespace}}' => $namespace,
                    '{{class}}' => $class,
                ]);
    }

    /**
     * @param list<array{0: string, 1: string}> $components
     */
    public static function page(
        string $namespace,
        string $class,
        string $route,
        string $view,
        array $components = [],
    ): string {
        $uses = '';
        $instances = '';

        if ($components !== []) {
            $uses .= "\n";
            $instances = "\n        ";

            foreach ($components as [$componentNamespace, $componentClass]) {
                $uses .= sprintf("use %s\\%s;\n", $componentNamespace, $componentClass);
                $instances .= sprintf('new %s(), ', $componentClass);
            }

            $instances = rtrim($instances, ' ')."\n";
        }

        return strtr(<<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}};

use Sahil\Firefiber\Component;
use Sahil\Firefiber\Page;
{{uses}}
/**
 * {{class}}.
 *
 * This page is discovered automatically by the Firefiber provider. Add
 * component classes above to compose reusable reactive islands.
 */
final class {{class}} extends Page
{
    public function path(): string
    {
        return '{{route}}';
    }

    public function title(): string
    {
        return '{{class}}';
    }

    /**
     * @return list<Component>
     */
    public function components(): array
    {
        return [{{instances}}];
    }

    public function view(): ?string
    {
        return '{{view}}';
    }
}
PHP, [
            '{{namespace}}' => $namespace,
            '{{class}}' => $class,
            '{{route}}' => $route,
            '{{view}}' => $view,
            '{{uses}}' => $uses,
            '{{instances}}' => $instances === '' ? '' : substr($instances, 9, -2),
        ]);
    }

    public static function pageView(string $class): string
    {
        return strtr(<<<'PHP'
@extends('layouts.app')

@section('title'){{ page_title }}@endsection

@section('content')
<main>
    <h1>{{ page_title }}</h1>

    @foreach (components as component)
        {!! component !!}
    @endforeach
</main>
@endsection
PHP, [
            '{{class}}' => $class,
        ]);
    }
}
