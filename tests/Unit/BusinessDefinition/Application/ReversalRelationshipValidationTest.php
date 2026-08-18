<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessDefinition\Application;

use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\CMS\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\CMS\Tests\Unit\BusinessDefinition\Domain\EntityTypeDefinitionTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessDefinitionValidator::class)]
/**
 * Pins what a declared reversal link may be: a restricted, same-definition pair read from both ends.
 *
 * A correction is a new record of the same definition carrying a typed link to the record it reverses,
 * so the validator has to hold the declaration to exactly that shape — its target is its own definition,
 * the reversed record can never be deleted out from under it, and its inverse is the one-to-many
 * "what corrected this" side — before any installation compiles storage for it.
 *
 * @since  2.0.0
 */
final class ReversalRelationshipValidationTest extends TestCase
{
    /**
     * Proves a reciprocal same-definition reversal pair passes graph validation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASameDefinitionReversalPairValidates(): void
    {
        $definition = EntityTypeDefinition::fromArray(self::documentWithReversal());

        $this->validator()->validateGraph([$definition]);
        self::assertNotNull($definition->runtimeRelationship('reverses'));
        self::assertNotNull($definition->runtimeRelationship('reversed_by'));
    }

    /**
     * Proves a reversal link may not point at a record of another definition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAReversalTargetingAnotherDefinitionIsRefused(): void
    {
        $other = EntityTypeDefinitionTest::document();
        $other['id'] = '018f4f24-98d8-7ad4-8f3f-38c909178b6c';
        $other['handle'] = 'site.default.other_asset';
        $document = self::documentWithReversal();
        $document['relationships'][0]['target'] = 'site.default.other_asset';
        $document['relationships'][0]['inverse'] = null;
        unset($document['relationships'][1]);
        $document['relationships'] = array_values($document['relationships']);

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('must target a record of its own definition');
        $this->validator()->validateGraph([
            EntityTypeDefinition::fromArray($document),
            EntityTypeDefinition::fromArray($other),
        ]);
    }

    /**
     * Proves a reversal link must restrict deletion of the record it reverses.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAReversalThatDoesNotRestrictDeletionIsRefused(): void
    {
        $document = self::documentWithReversal();
        $document['relationships'][0]['on_delete'] = 'set_null';

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('must restrict deletion');
        $this->validator()->validateGraph([EntityTypeDefinition::fromArray($document)]);
    }

    /**
     * Proves a reversal only pairs with a one-to-many inverse, so both directions stay declared queries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAReversalWithAWrongInverseCardinalityIsRefused(): void
    {
        $document = self::documentWithReversal();
        $document['relationships'][1]['kind'] = 'many_to_many';

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('reciprocal and cardinality-compatible');
        $this->validator()->validateGraph([EntityTypeDefinition::fromArray($document)]);
    }

    /**
     * Proves the pre-existing cardinality pairings survive the widened partner sets unchanged.
     *
     * The reversal kind widened what a one-to-many may accept back; the one-to-one and many-to-many
     * pairings must keep their exact single partner, so this pins them against a quiet widening.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheOtherCardinalityPairingsAreUnchanged(): void
    {
        $document = EntityTypeDefinitionTest::document();
        $document['relationships'] = [
            [
                'handle' => 'twin',
                'label' => 'Twin',
                'kind' => 'one_to_one',
                'target' => 'site.default.asset',
                'inverse' => 'twin_of',
                'on_delete' => 'restrict',
            ],
            [
                'handle' => 'twin_of',
                'label' => 'Twin of',
                'kind' => 'one_to_one',
                'target' => 'site.default.asset',
                'inverse' => 'twin',
                'on_delete' => 'restrict',
            ],
            [
                'handle' => 'peers',
                'label' => 'Peers',
                'kind' => 'many_to_many',
                'target' => 'site.default.asset',
                'inverse' => 'peer_of',
                'on_delete' => 'restrict',
            ],
            [
                'handle' => 'peer_of',
                'label' => 'Peer of',
                'kind' => 'many_to_many',
                'target' => 'site.default.asset',
                'inverse' => 'peers',
                'on_delete' => 'restrict',
            ],
        ];
        $this->validator()->validateGraph([EntityTypeDefinition::fromArray($document)]);

        $document['relationships'][0]['kind'] = 'reversal';
        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('reciprocal and cardinality-compatible');
        $this->validator()->validateGraph([EntityTypeDefinition::fromArray($document)]);
    }

    /**
     * Build the validator exactly as production wires it, over the built-in field types.
     *
     * @return  BusinessDefinitionValidator  A validator with the real field-type registry.
     *
     * @since   2.0.0
     */
    private function validator(): BusinessDefinitionValidator
    {
        return new BusinessDefinitionValidator(new FieldTypeRegistry());
    }

    /**
     * The neutral asset document extended with a reciprocal same-definition reversal pair.
     *
     * @return  array<string, mixed>  Canonical definition document declaring `reverses` and `reversed_by`.
     *
     * @since   2.0.0
     */
    private static function documentWithReversal(): array
    {
        $document = EntityTypeDefinitionTest::document();
        $document['relationships'] = [
            [
                'handle' => 'reverses',
                'label' => 'Reverses',
                'kind' => 'reversal',
                'target' => 'site.default.asset',
                'inverse' => 'reversed_by',
                'on_delete' => 'restrict',
            ],
            [
                'handle' => 'reversed_by',
                'label' => 'Reversed by',
                'kind' => 'one_to_many',
                'target' => 'site.default.asset',
                'inverse' => 'reverses',
                'on_delete' => 'restrict',
            ],
        ];

        return $document;
    }
}
