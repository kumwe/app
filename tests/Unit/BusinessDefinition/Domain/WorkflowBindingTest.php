<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessDefinition\Domain;

use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\App\BusinessDefinition\Domain\WorkflowBinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkflowBinding::class)]
/**
 * Pins the immutable-state declaration a workflow binding may carry, and the invariants around it.
 *
 * The declaration is the anchor of the immutable-correction rule: a state named here closes the record
 * against every content mutation once entered, so what is pinned is that only declared, non-initial
 * states can be named, that the declaration round-trips through the canonical document, and that a
 * binding declaring nothing keeps exactly the canonical bytes it had before the declaration existed.
 *
 * @since  2.0.0
 */
final class WorkflowBindingTest extends TestCase
{
    /**
     * Proves declared immutable states are kept, deduplicated, and answered by `immutableIn()`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeclaredImmutableStatesAreKeptAndAnswered(): void
    {
        $binding = new WorkflowBinding(
            'draft',
            ['draft', 'approved', 'delivered'],
            [
                ['handle' => 'approve', 'from' => 'draft', 'to' => 'approved', 'capability' => 'record.approve'],
                ['handle' => 'deliver', 'from' => 'approved', 'to' => 'delivered', 'capability' => 'record.deliver'],
            ],
            ['approved', 'delivered', 'approved'],
        );

        self::assertSame(['approved', 'delivered'], $binding->immutableStates);
        self::assertTrue($binding->immutableIn('approved'));
        self::assertTrue($binding->immutableIn('delivered'));
        self::assertFalse($binding->immutableIn('draft'));
        self::assertFalse($binding->immutableIn(null), 'A record holding no state is never immutable.');
    }

    /**
     * Proves an immutable state must be one of the declared workflow states.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUndeclaredImmutableStateIsRefused(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        new WorkflowBinding(
            'draft',
            ['draft', 'approved'],
            [['handle' => 'approve', 'from' => 'draft', 'to' => 'approved', 'capability' => 'record.approve']],
            ['posted'],
        );
    }

    /**
     * Proves the initial state can never be immutable, so a record always starts life editable.
     *
     * Immutability is entered through a transition — an audited, capability-gated act — and never a
     * property a record is born with; the decision record's words are that drafts must stay mutable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheInitialStateCannotBeDeclaredImmutable(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        new WorkflowBinding(
            'draft',
            ['draft', 'approved'],
            [['handle' => 'approve', 'from' => 'draft', 'to' => 'approved', 'capability' => 'record.approve']],
            ['draft'],
        );
    }

    /**
     * Proves the declaration round-trips through the canonical document under `immutable_states`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDeclarationRoundTripsThroughTheCanonicalDocument(): void
    {
        $binding = new WorkflowBinding(
            'draft',
            ['draft', 'approved'],
            [['handle' => 'approve', 'from' => 'draft', 'to' => 'approved', 'capability' => 'record.approve']],
            ['approved'],
        );
        $document = $binding->toArray();

        self::assertSame(['approved'], $document['immutable_states'] ?? null);
        $rebuilt = WorkflowBinding::fromArray($document);
        self::assertSame(['approved'], $rebuilt->immutableStates);
        self::assertTrue($rebuilt->immutableIn('approved'));
        self::assertSame($document, $rebuilt->toArray());
    }

    /**
     * Proves a binding declaring no immutable state keeps its pre-declaration canonical bytes.
     *
     * The canonical document feeds every published definition's checksum, so the key may only appear
     * when a binding actually declares something — otherwise every existing installation's definition
     * checksum would change under it on upgrade.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testABindingWithoutTheDeclarationKeepsItsCanonicalBytes(): void
    {
        $binding = new WorkflowBinding(
            'draft',
            ['draft', 'approved'],
            [['handle' => 'approve', 'from' => 'draft', 'to' => 'approved', 'capability' => 'record.approve']],
        );
        $document = $binding->toArray();

        self::assertArrayNotHasKey('immutable_states', $document);
        self::assertSame(['initial_state', 'states', 'transitions'], array_keys($document));
        $rebuilt = WorkflowBinding::fromArray($document);
        self::assertSame([], $rebuilt->immutableStates);
        self::assertFalse($rebuilt->immutableIn('approved'));
    }

    /**
     * Proves a document smuggling a malformed immutable-state collection is refused at the boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMalformedImmutableStateCollectionIsRefused(): void
    {
        $document = [
            'initial_state' => 'draft',
            'states' => ['draft', 'approved'],
            'transitions' => [
                ['handle' => 'approve', 'from' => 'draft', 'to' => 'approved', 'capability' => 'record.approve'],
            ],
        ];

        try {
            WorkflowBinding::fromArray([...$document, 'immutable_states' => ['approved' => true]]);
            self::fail('A keyed immutable-state collection must be refused.');
        } catch (InvalidBusinessDefinition) {
            self::assertTrue(true);
        }
        try {
            WorkflowBinding::fromArray([...$document, 'immutable_states' => [1]]);
            self::fail('A non-string immutable state must be refused.');
        } catch (InvalidBusinessDefinition) {
            self::assertTrue(true);
        }
        try {
            WorkflowBinding::fromArray([...$document, 'frozen_states' => ['approved']]);
            self::fail('An unknown workflow property must still be refused.');
        } catch (InvalidBusinessDefinition) {
            self::assertTrue(true);
        }
    }
}
