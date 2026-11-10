<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Sahil\Firefiber\Component;

/**
 * Test case for the Firefiber Component base class.
 *
 * Tests the fundamental component functionality:
 * - ID generation
 - State management
 - Action registration
 - State serialization
 */
final class ComponentTest extends TestCase
{
    public function test_component_has_auto_generated_id(): void
    {
        $component = new class () extends Component {
            public function handleAction(string $action, array $payload): array
            {
                return ['status' => 'ok'];
            }
        };

        $this->assertNotSame('', $component->id());
        $this->assertMatchesRegularExpression('/^ff-[0-9a-f]{24}$/', $component->id());
    }

    public function test_component_can_update_state(): void
    {
        $component = new class () extends Component {
            public function handleAction(string $action, array $payload): array
            {
                return ['status' => 'ok'];
            }
        };

        $component->updateState(['count' => 5]);

        $this->assertEquals(['count' => 5], $component->state());
    }

    public function test_component_can_register_action(): void
    {
        $component = new class () extends Component {
            public function handleAction(string $action, array $payload): array
            {
                return ['status' => 'ok'];
            }
        };

        $component->registerAction('test_action', fn ($p) => []);

        $this->assertContains('test_action', $component->registeredActions());
    }

    public function test_component_can_serialize_state(): void
    {
        $component = new class () extends Component {
            public function handleAction(string $action, array $payload): array
            {
                return ['status' => 'ok'];
            }
        };

        $component->updateState(['name' => 'Test', 'count' => 42]);

        $serialized = $component->serializeState();

        $this->assertArrayHasKey('id', $serialized);
        $this->assertArrayHasKey('state', $serialized);
        $this->assertEquals('Test', $serialized['state']['name']);
        $this->assertEquals(42, $serialized['state']['count']);
    }

    public function test_component_is_action_registered(): void
    {
        $component = new class () extends Component {
            public function handleAction(string $action, array $payload): array
            {
                return ['status' => 'ok'];
            }

            public function registered(string $action): bool
            {
                return $this->isActionRegistered($action);
            }
        };

        $component->registerAction('valid_action', fn ($p) => []);

        $this->assertTrue($component->registered('valid_action'));
        $this->assertFalse($component->registered('unknown_action'));
    }

    public function test_component_initial_state(): void
    {
        $component = new class (['existing' => 'value'], ['init_action']) extends Component {
            public function handleAction(string $action, array $payload): array
            {
                return ['status' => 'ok'];
            }
        };

        $this->assertEquals(['existing' => 'value'], $component->state());
        $this->assertContains('init_action', $component->registeredActions());
    }
}
