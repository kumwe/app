<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessDefinition\Application;

use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\App\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the byte budget a bounded-JSON default has to fit inside before the definition can be published.
 *
 * The budget is measured against the canonical encoding rather than the literal the author wrote, so the
 * same document judged twice has to reach the same verdict however its keys were ordered. The refusal
 * belongs at publication: a default that does not fit is written into every record created from then on,
 * and discovering that at the first create would strand the definition already in storage.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessDefinitionValidator::class)]
#[CoversClass(EntityTypeDefinition::class)]
final class BoundedJsonDefaultBudgetTest extends TestCase
{
    /**
     * A default inside the declared budget publishes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADefaultInsideTheDeclaredBudgetPublishes(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validate(['layout' => 'compact'], ['max_bytes' => 1_024]);
    }

    /**
     * A default larger than the declared budget is refused at publication.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADefaultLargerThanTheDeclaredBudgetIsRefused(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessageMatches('/invalid default/');

        $this->validate(['note' => str_repeat('a', 128)], ['max_bytes' => 32]);
    }

    /**
     * Key order is not what the budget measures, so re-ordering a default cannot change the verdict.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testKeyOrderDoesNotChangeWhetherADefaultFits(): void
    {
        $this->expectNotToPerformAssertions();

        $budget = ['max_bytes' => 48];
        $this->validate(['alpha' => 1, 'beta' => 2], $budget);
        $this->validate(['beta' => 2, 'alpha' => 1], $budget);
    }

    /**
     * A budget that is not an integer is refused as a bad bound, before any default is measured against it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testANonIntegerBudgetIsRefusedAsABadBoundRatherThanAsABadDefault(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessageMatches('/invalid JSON byte bound/');

        $this->validate(['layout' => 'compact'], ['max_bytes' => '1024']);
    }

    /**
     * Validate a one-entity graph whose bounded-JSON field carries the given default and budget.
     *
     * @param   array<string, mixed>  $default        Declared default, as the definition carries it.
     * @param   array<string, mixed>  $configuration  Field configuration supplying `max_bytes`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function validate(array $default, array $configuration): void
    {
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([
            EntityTypeDefinition::fromArray([
                'id' => '0191574f-f0b8-7bf3-a9aa-91c6b8244f02',
                'owner' => ['type' => 'site', 'identifier' => 'default'],
                'site' => 'default',
                'handle' => 'site.default.bounded_json_budget',
                'singular_label' => 'Bounded JSON holder',
                'plural_label' => 'Bounded JSON holders',
                'status' => 'draft',
                'definition_version' => 0,
                'storage_mode' => 'relational',
                'identity_strategy' => 'uuid',
                'scope' => 'site',
                'fields' => [
                    [
                        'handle' => 'id',
                        'label' => 'ID',
                        'type' => 'core.uuid',
                        'required' => true,
                        'nullable' => false,
                        'unique' => true,
                        'indexed' => true,
                        'immutable_after_create' => true,
                        'server_only' => true,
                        'read_only' => true,
                    ],
                    [
                        'handle' => 'name',
                        'label' => 'Name',
                        'type' => 'core.text',
                        'required' => true,
                        'nullable' => false,
                        'length' => 160,
                    ],
                    [
                        'handle' => 'preferences',
                        'label' => 'Preferences',
                        'type' => 'core.bounded_json',
                        'default' => $default,
                        'configuration' => $configuration,
                    ],
                ],
            ]),
        ]);
    }
}
