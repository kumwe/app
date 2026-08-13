<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessDefinition\Domain;

use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\CMS\BusinessDefinition\Domain\DocumentViewDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\ViewDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DocumentViewDefinition::class)]
#[CoversClass(ViewDefinition::class)]
/**
 * Pins the document view kind's vocabulary, canonical bytes, and entity-level role validation.
 *
 * @since  2.0.0
 */
final class DocumentViewDefinitionTest extends TestCase
{
    /**
     * Proves a declared document view round-trips through canonical bytes with a stable checksum.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentViewRoundTripsThroughCanonicalBytes(): void
    {
        $definition = EntityTypeDefinition::fromArray(self::documentedEntity());
        $roundTrip = EntityTypeDefinition::fromArray($definition->toArray());

        self::assertSame($definition->checksum(), $roundTrip->checksum());
        $view = $roundTrip->views()[1];
        self::assertSame('document', $view->kind);
        self::assertNotNull($view->document);
        self::assertSame('reference', $view->document->identity);
        self::assertSame('lines', $view->document->lines);
        self::assertSame([['label' => 'Dates', 'fields' => ['name']]], $view->document->groups);
        self::assertSame([['label' => 'Billed to', 'relationship' => 'client']], $view->document->parties);
        self::assertSame(['amount'], $view->document->totals);
    }

    /**
     * Proves views without a document block keep their historical canonical bytes and checksum.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testViewsWithoutDocumentBlockPreserveLegacyCanonicalBytes(): void
    {
        $legacy = EntityTypeDefinition::fromArray(EntityTypeDefinitionTest::document());

        self::assertArrayNotHasKey('document', $legacy->views()[0]->toArray());
        self::assertSame(
            CanonicalDefinitionJson::checksum(EntityTypeDefinition::fromArray(
                EntityTypeDefinitionTest::document(),
            )->toArray()),
            CanonicalDefinitionJson::checksum($legacy->toArray()),
        );
    }

    /**
     * Proves a document block cannot ride on any other view kind.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentBlockRequiresTheDocumentKind(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('document view kind');

        new ViewDefinition(
            'invoice_list',
            'Invoices',
            'list',
            ['name'],
            document: new DocumentViewDefinition('reference'),
        );
    }

    /**
     * Proves a document view keeps the generated rendering path rather than a custom handler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentViewCannotBindACustomHandler(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('custom handler');

        new ViewDefinition(
            'invoice_document',
            'Invoice document',
            'document',
            ['name'],
            handler: 'acme.views.invoice',
            schema: 'acme.schemas.invoice',
        );
    }

    /**
     * Proves an unknown property inside the document block fails at the import boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnknownDocumentPropertyIsRejected(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('unknown property');

        DocumentViewDefinition::fromArray(['identity' => 'reference', 'watermark' => 'PAID']);
    }

    /**
     * Proves a declared group must project at least one field.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGroupWithoutFieldsIsRejected(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);

        DocumentViewDefinition::fromArray(['groups' => [['label' => 'Dates', 'fields' => []]]]);
    }

    /**
     * Proves a document role must name a field the entity declares.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentRoleMustNameADeclaredField(): void
    {
        $entity = self::documentedEntity();
        $entity['views'][1]['document']['identity'] = 'missing_number';
        $entity['views'][1]['fields'][] = 'missing_number';

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('missing field missing_number');

        EntityTypeDefinition::fromArray($entity);
    }

    /**
     * Proves no documentary role can surface a UUID field as a human value.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentRoleRefusesAUuidField(): void
    {
        $entity = self::documentedEntity();
        $entity['views'][1]['fields'][] = 'id';
        $entity['views'][1]['document']['identity'] = 'id';

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('UUID field');

        EntityTypeDefinition::fromArray($entity);
    }

    /**
     * Proves a documentary field role cannot escape the view's own projection.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentRoleMustStayInsideTheViewProjection(): void
    {
        $entity = self::documentedEntity();
        $entity['views'][1]['fields'] = ['reference', 'name'];

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('view projection');

        EntityTypeDefinition::fromArray($entity);
    }

    /**
     * Proves the lines role must name a declared owned-line collection.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLinesRoleMustNameAnOwnedLineCollection(): void
    {
        $entity = self::documentedEntity();
        $entity['views'][1]['document']['lines'] = 'client';

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('owned-line collection');

        EntityTypeDefinition::fromArray($entity);
    }

    /**
     * Proves every party role must name a declared many-to-one relationship.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPartyRoleMustNameAManyToOneRelationship(): void
    {
        $entity = self::documentedEntity();
        $entity['views'][1]['document']['parties'] = [['label' => 'Billed to', 'relationship' => 'lines']];

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('many-to-one');

        EntityTypeDefinition::fromArray($entity);
    }

    /**
     * Build one valid entity declaring a list view and a complete document view.
     *
     * @return  array<string, mixed>  Decoded entity document ready for `EntityTypeDefinition::fromArray()`.
     *
     * @since   2.0.0
     */
    private static function documentedEntity(): array
    {
        $entity = EntityTypeDefinitionTest::document();
        $entity['fields'][] = [
            'handle' => 'reference',
            'label' => 'Reference',
            'type' => 'core.text',
            'required' => true,
            'nullable' => false,
            'length' => 40,
        ];
        $entity['fields'][] = [
            'handle' => 'amount',
            'label' => 'Amount',
            'type' => 'core.money',
            'required' => true,
            'nullable' => false,
            'precision' => 16,
            'scale' => 2,
            'configuration' => ['currency' => 'NAD'],
        ];
        $entity['relationships'] = [
            [
                'handle' => 'client',
                'label' => 'Client',
                'kind' => 'many_to_one',
                'target' => 'site.default.client',
                'on_delete' => 'set_null',
            ],
            [
                'handle' => 'lines',
                'label' => 'Lines',
                'kind' => 'owned_line_collection',
                'target' => 'site.default.asset_line',
                'ordered' => true,
                'on_delete' => 'cascade',
            ],
        ];
        $entity['views'][] = [
            'handle' => 'asset_document',
            'label' => 'Asset document',
            'kind' => 'document',
            'fields' => ['reference', 'name', 'amount'],
            'filters' => [],
            'sorts' => [],
            'administrator' => true,
            'portal' => false,
            'public' => false,
            'document' => [
                'identity' => 'reference',
                'groups' => [['label' => 'Dates', 'fields' => ['name']]],
                'parties' => [['label' => 'Billed to', 'relationship' => 'client']],
                'lines' => 'lines',
                'totals' => ['amount'],
            ],
        ];

        return $entity;
    }
}
