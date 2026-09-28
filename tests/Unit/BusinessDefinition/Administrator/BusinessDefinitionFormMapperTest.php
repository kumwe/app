<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessDefinition\Administrator;

use Kumwe\App\BusinessDefinition\Administrator\BusinessDefinitionFormMapper;
use Kumwe\App\Tests\Support\NativeComputationContainer;
use Kumwe\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessDefinitionFormMapper::class)]
final class BusinessDefinitionFormMapperTest extends TestCase
{
    public function testGraphicalControlsProduceACompleteTypedDraftWithoutExecutableInput(): void
    {
        $definition = (new BusinessDefinitionFormMapper())->definition([
            'handle' => 'site.default.invoice',
            'singular_label' => 'Invoice',
            'plural_label' => 'Invoices',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'field_0_handle' => 'id',
            'field_0_label' => 'ID',
            'field_0_type' => 'core.uuid',
            'field_0_required' => '1',
            'field_0_unique' => '1',
            'field_0_indexed' => '1',
            'field_0_order' => '0',
            'field_1_handle' => 'net',
            'field_1_label' => 'Net',
            'field_1_type' => 'core.decimal',
            'field_1_precision' => '30',
            'field_1_scale' => '6',
            'field_1_order' => '10',
            'field_2_handle' => 'tax',
            'field_2_label' => 'Tax',
            'field_2_type' => 'core.decimal',
            'field_2_precision' => '30',
            'field_2_scale' => '6',
            'field_2_order' => '20',
            'field_3_handle' => 'gross',
            'field_3_label' => 'Gross',
            'field_3_type' => 'core.computed',
            'field_3_computed' => '1',
            'field_3_formula_type' => 'decimal',
            'field_3_formula_left' => 'net',
            'field_3_formula_operator' => 'add',
            'field_3_formula_right' => 'tax',
            'field_3_order' => '30',
            'view_0_handle' => 'detail',
            'view_0_label' => 'Invoice detail',
            'view_0_kind' => 'detail',
            'view_0_fields' => 'net, tax, gross',
        ], SiteContext::default());

        self::assertSame('site.default.invoice', $definition->handle);
        self::assertSame(['net', 'tax'], $definition->fields()[3]->formula?->dependencies());
        $formula = $definition->fields()[3]->formula;
        self::assertNotNull($formula);
        self::assertSame(
            '12.5',
            NativeComputationContainer::formulas()->evaluate($formula, ['net' => '10', 'tax' => '2.5']),
        );
        self::assertStringNotContainsString('eval', json_encode($definition->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testExactNumericGraphicalControlsRejectMissingPrecisionBeforePersistence(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        (new BusinessDefinitionFormMapper())->definition([
            'handle' => 'site.default.invalid',
            'singular_label' => 'Invalid',
            'plural_label' => 'Invalids',
            'field_0_handle' => 'id', 'field_0_label' => 'ID', 'field_0_type' => 'core.uuid',
            'field_0_required' => '1',
            'field_1_handle' => 'amount', 'field_1_label' => 'Amount', 'field_1_type' => 'core.money',
        ], SiteContext::default());
    }

    public function testGraphicalRoundTripPreservesBoundedExtensionConfigurationAndValidators(): void
    {
        $definition = (new BusinessDefinitionFormMapper())->definition([
            'handle' => 'site.default.configured',
            'singular_label' => 'Configured entity',
            'plural_label' => 'Configured entities',
            'field_0_handle' => 'id',
            'field_0_label' => 'ID',
            'field_0_type' => 'core.uuid',
            'field_0_required' => '1',
            'field_1_handle' => 'code',
            'field_1_label' => 'Code',
            'field_1_type' => 'site.default.custom_type',
            'field_1_configuration_preserved' => '{"widget":"compact"}',
            'field_1_validators_preserved' => '[{"rule":"pattern","value":"^[A-Z]+$"}]',
            'field_1_hide_update' => '1',
        ], SiteContext::default());

        self::assertSame(['widget' => 'compact'], $definition->fields()[1]->configuration);
        self::assertSame('pattern', $definition->fields()[1]->validators[0]['rule']);
        self::assertFalse($definition->fields()[1]->updateVisible);
    }

    /**
     * The editor declares immutable states, and an empty field removes the declaration again.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheEditorDeclaresAndRemovesImmutableStates(): void
    {
        $mapper = new BusinessDefinitionFormMapper();

        $declared = $mapper->definition(
            self::workflowForm(['workflow_immutable_states' => 'approved, delivered']),
            SiteContext::default(),
        );
        $binding = $declared->workflow;
        self::assertNotNull($binding);
        self::assertSame(['approved', 'delivered'], $binding->immutableStates);
        self::assertTrue($binding->immutableIn('approved'));
        self::assertFalse($binding->immutableIn('draft'));
        $workflow = $declared->toArray()['workflow'] ?? null;
        self::assertIsArray($workflow);
        self::assertSame(['approved', 'delivered'], $workflow['immutable_states']);

        $removed = $mapper->definition(
            self::workflowForm(['workflow_immutable_states' => '']),
            SiteContext::default(),
        );
        self::assertSame([], $removed->workflow?->immutableStates);
        $workflow = $removed->toArray()['workflow'] ?? null;
        self::assertIsArray($workflow);
        self::assertArrayNotHasKey('immutable_states', $workflow);
    }

    /**
     * An immutable state that is undeclared, or is the initial state, is refused before persistence.
     *
     * @param   string  $states  Submitted immutable-states field.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('invalidImmutableStates')]
    public function testInvalidImmutableStatesAreRefused(string $states): void
    {
        try {
            (new BusinessDefinitionFormMapper())->definition(
                self::workflowForm(['workflow_immutable_states' => $states]),
                SiteContext::default(),
            );
            self::fail('The editor must refuse an invalid immutable-state declaration.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertSame(
                'Immutable states must name declared workflow states other than the initial state.',
                $refusal->getMessage(),
            );
        }
    }

    /**
     * Name each invalid declaration.
     *
     * @return  iterable<string, array{string}>  Named submissions.
     *
     * @since   2.0.0
     */
    public static function invalidImmutableStates(): iterable
    {
        yield 'an undeclared state' => ['archived'];
        yield 'the initial state' => ['draft'];
        yield 'a declared state beside an undeclared one' => ['approved, closed'];
    }

    /**
     * Build a minimal editor submission with a three-state workflow and one approve transition.
     *
     * @param   array<string, string>  $extra  Additional or overriding inputs.
     *
     * @return  array<string, string>  Flattened administrator form.
     *
     * @since   2.0.0
     */
    private static function workflowForm(array $extra): array
    {
        return [
            'handle' => 'site.default.approved_document',
            'singular_label' => 'Document',
            'plural_label' => 'Documents',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'field_0_handle' => 'id',
            'field_0_label' => 'ID',
            'field_0_type' => 'core.uuid',
            'field_0_required' => '1',
            'field_0_unique' => '1',
            'field_0_indexed' => '1',
            'workflow_enabled' => '1',
            'workflow_states' => 'draft, approved, delivered',
            'workflow_initial_state' => 'draft',
            'transition_0_handle' => 'approve',
            'transition_0_from' => 'draft',
            'transition_0_to' => 'approved',
            'transition_0_capability' => 'business.record.action',
            'transition_1_handle' => 'deliver',
            'transition_1_from' => 'approved',
            'transition_1_to' => 'delivered',
            'transition_1_capability' => 'business.record.action',
        ] + $extra;
    }
}
