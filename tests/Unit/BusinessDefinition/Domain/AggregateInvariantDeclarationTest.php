<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessDefinition\Domain;

use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\App\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\App\BusinessDefinition\Domain\BuiltInFieldTypes;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\Expression;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\App\BusinessDefinition\Domain\RecordInvariantDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins what a definition may and may not say when it aggregates its own owned lines.
 *
 * An invariant is part of a published contract, so a rule that could never be evaluated has to be refused
 * when it is declared rather than when a document is written. The two halves of that live in two places
 * for a reason: whether the collection is one this entity declares is answerable from the entity alone,
 * and whether the line entity carries the field being summed needs the rest of the catalogue.
 *
 * @since  2.0.0
 */
#[CoversClass(EntityTypeDefinition::class)]
#[CoversClass(BusinessDefinitionValidator::class)]
#[CoversClass(RecordInvariantDefinition::class)]
#[CoversClass(Expression::class)]
#[CoversClass(FieldTypeRegistry::class)]
#[CoversClass(BuiltInFieldTypes::class)]
final class AggregateInvariantDeclarationTest extends TestCase
{
    /**
     * Proves a definition may declare that its total equals the sum of a collection it owns.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADocumentMayDeclareThatItsTotalEqualsTheSumOfItsLines(): void
    {
        $document = self::header();
        $definition = EntityTypeDefinition::fromArray($document);

        self::assertSame(['lines' => ['amount']], $definition->invariantLineDependencies());
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([
            $definition,
            EntityTypeDefinition::fromArray(self::line()),
        ]);
    }

    /**
     * Proves the aggregation survives the canonical document a published definition is checksummed over.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDeclaredAggregationRoundTripsThroughTheCanonicalDocument(): void
    {
        $definition = EntityTypeDefinition::fromArray(self::header());
        $roundTrip = EntityTypeDefinition::fromArray($definition->toArray());

        self::assertSame($definition->checksum(), $roundTrip->checksum());
        self::assertSame(['lines' => ['amount']], $roundTrip->invariantLineDependencies());
    }

    /**
     * Proves an invariant cannot aggregate a collection the entity never declared.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnInvariantAggregatingAnUndeclaredCollectionIsRefused(): void
    {
        $document = self::header();
        $document['relationships'] = [];

        $this->expectException(InvalidBusinessDefinition::class);
        EntityTypeDefinition::fromArray($document);
    }

    /**
     * Proves an invariant cannot aggregate a relationship that is not an owned-line collection.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnInvariantAggregatingANonOwnedRelationshipIsRefused(): void
    {
        $document = self::header();
        $document['relationships'][0]['kind'] = 'many_to_many';
        $document['relationships'][0]['on_delete'] = 'restrict';

        $this->expectException(InvalidBusinessDefinition::class);
        EntityTypeDefinition::fromArray($document);
    }

    /**
     * Proves an invariant cannot sum a field the line entity does not carry.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnInvariantSummingAFieldTheLineEntityLacksIsRefused(): void
    {
        $document = self::header();
        $document['record_invariants'][0]['condition']['args'][1]['field'] = 'not_a_line_field';

        $this->expectException(InvalidBusinessDefinition::class);
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([
            EntityTypeDefinition::fromArray($document),
            EntityTypeDefinition::fromArray(self::line()),
        ]);
    }

    /**
     * Proves an invariant cannot sum a line field that carries no exact number.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnInvariantSummingANonNumericLineFieldIsRefused(): void
    {
        $document = self::header();
        $document['record_invariants'][0]['condition']['args'][1]['field'] = 'description';

        $this->expectException(InvalidBusinessDefinition::class);
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([
            EntityTypeDefinition::fromArray($document),
            EntityTypeDefinition::fromArray(self::line()),
        ]);
    }

    /**
     * Proves an invariant cannot fold a line value the line entity keeps restricted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnInvariantSummingARestrictedLineFieldIsRefused(): void
    {
        $line = self::line();
        $line['fields'][2]['sensitivity'] = 'restricted';

        $this->expectException(InvalidBusinessDefinition::class);
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([
            EntityTypeDefinition::fromArray(self::header()),
            EntityTypeDefinition::fromArray($line),
        ]);
    }

    /**
     * Proves a computed field cannot aggregate, because it is evaluated for one record at a time.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAComputedFieldCannotAggregateOwnedLines(): void
    {
        $document = self::header();
        $document['fields'][] = [
            'handle' => 'line_total',
            'label' => 'Line total',
            'type' => 'core.computed',
            'nullable' => true,
            'server_only' => true,
            'computed' => true,
            'read_only' => true,
            'formula' => self::sumNode(),
        ];

        $this->expectException(InvalidBusinessDefinition::class);
        EntityTypeDefinition::fromArray($document);
    }

    /**
     * Proves an action condition cannot aggregate, for the same reason a computed field cannot.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnActionConditionCannotAggregateOwnedLines(): void
    {
        $document = self::header();
        $document['actions'] = [[
            'handle' => 'approve',
            'label' => 'Approve',
            'capability' => 'business.record.action',
            'administrator' => true,
            'portal' => false,
            'public' => false,
            'condition' => [
                'op' => 'gte',
                'type' => 'boolean',
                'args' => [self::sumNode(), ['op' => 'literal', 'type' => 'decimal', 'value' => '0']],
            ],
        ]];

        $this->expectException(InvalidBusinessDefinition::class);
        EntityTypeDefinition::fromArray($document);
    }

    /**
     * Build the document header used across this class, declaring one owned-line collection and one rule.
     *
     * @return  array<string, mixed>  A canonical entity definition document.
     *
     * @since   2.0.0
     */
    private static function header(): array
    {
        return [
            'id' => '0191574f-f0b8-7bf3-a9aa-91c6b8244f01',
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => 'site.default.aggregate_header',
            'singular_label' => 'Document',
            'plural_label' => 'Documents',
            'status' => 'draft',
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [
                self::identityField(),
                [
                    'handle' => 'total',
                    'label' => 'Total',
                    'type' => 'core.decimal',
                    'required' => true,
                    'nullable' => false,
                    'precision' => 18,
                    'scale' => 2,
                ],
            ],
            'relationships' => [[
                'handle' => 'lines',
                'label' => 'Lines',
                'kind' => 'owned_line_collection',
                'target' => 'site.default.aggregate_line',
                'ordered' => true,
                'on_delete' => 'cascade',
            ]],
            'views' => [],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
            'record_invariants' => [[
                'handle' => 'total_agrees_with_lines',
                'message' => 'The document total must equal the sum of its lines.',
                'condition' => [
                    'op' => 'eq',
                    'type' => 'boolean',
                    'args' => [
                        ['op' => 'field', 'type' => 'decimal', 'field' => 'total'],
                        self::sumNode(),
                    ],
                ],
            ]],
        ];
    }

    /**
     * Build the line entity the header's collection stores.
     *
     * @return  array<string, mixed>  A canonical entity definition document.
     *
     * @since   2.0.0
     */
    private static function line(): array
    {
        return [
            'id' => '0191574f-f0b8-7bf3-a9aa-91c6b8244f02',
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => 'site.default.aggregate_line',
            'singular_label' => 'Line',
            'plural_label' => 'Lines',
            'status' => 'draft',
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [
                self::identityField(),
                [
                    'handle' => 'description',
                    'label' => 'Description',
                    'type' => 'core.text',
                    'required' => true,
                    'nullable' => false,
                    'length' => 160,
                ],
                [
                    'handle' => 'amount',
                    'label' => 'Amount',
                    'type' => 'core.decimal',
                    'required' => true,
                    'nullable' => false,
                    'precision' => 18,
                    'scale' => 2,
                ],
            ],
            'relationships' => [],
            'views' => [],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
        ];
    }

    /**
     * Build the reduction node this class declares its rule with.
     *
     * @return  array<string, mixed>  A `line_aggregate` node summing `amount` over the `lines` collection.
     *
     * @since   2.0.0
     */
    private static function sumNode(): array
    {
        return [
            'op' => 'line_aggregate',
            'type' => 'decimal',
            'lines' => 'lines',
            'field' => 'amount',
            'aggregate' => 'sum',
        ];
    }

    /**
     * Build the UUID identity field both entities here declare.
     *
     * @return  array<string, mixed>  A canonical field document.
     *
     * @since   2.0.0
     */
    private static function identityField(): array
    {
        return [
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
        ];
    }
}
