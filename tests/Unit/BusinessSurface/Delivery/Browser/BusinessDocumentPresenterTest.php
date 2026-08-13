<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessSurface\Delivery\Browser;

use Kumwe\CMS\BusinessSurface\Delivery\Browser\BusinessDocumentPresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessDocumentPresenter::class)]
/**
 * Proves the document projection resolves human identity, stable line columns, and totals.
 *
 * @since  2.0.0
 */
final class BusinessDocumentPresenterTest extends TestCase
{
    /**
     * Proves the header title comes from the declared identity field, never a machine key.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHeaderIdentityResolvesFromTheDeclaredField(): void
    {
        $document = (new BusinessDocumentPresenter())->present(self::model());

        self::assertSame('Invoice INV-2026-101', $document['title']);
        self::assertSame('INV-2026-101', $document['identity']);
        self::assertSame('Invoice number', $document['identity_label']);
        self::assertStringNotContainsString(
            '019c7000',
            json_encode($document, JSON_THROW_ON_ERROR),
            'No machine key may leak into the document projection.',
        );
    }

    /**
     * Proves a withheld identity falls back to the definition label and record date.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTitleFallsBackToLabelAndDateWithoutAnIdentity(): void
    {
        $model = self::model();
        $view = $model['document_view'];
        self::assertIsArray($view);
        self::assertIsArray($view['document']);
        $view['document']['identity'] = null;
        $model['document_view'] = $view;

        $document = (new BusinessDocumentPresenter())->present($model);

        self::assertSame('Invoice · 2026-08-01', $document['title']);
        self::assertNull($document['identity']);
    }

    /**
     * Proves the line table keeps the declared columns and one presented cell per column.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLineTableProjectsStableColumnsAndRows(): void
    {
        $document = (new BusinessDocumentPresenter())->present(self::model());
        $lines = $document['lines'];

        self::assertIsArray($lines);
        self::assertSame('Invoice lines', $lines['label']);
        self::assertSame(
            [
                ['handle' => 'description', 'label' => 'Description'],
                ['handle' => 'line_total', 'label' => 'Line total'],
            ],
            $lines['columns'],
        );
        self::assertCount(2, $lines['rows']);
        self::assertSame(
            [
                ['handle' => 'description', 'display' => 'Automation retainer'],
                ['handle' => 'line_total', 'display' => 'N$ 1,200.00'],
            ],
            $lines['rows'][0]['cells'] ?? null,
        );
    }

    /**
     * Proves groups, parties and totals resolve against the presented policy-visible fields.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGroupsPartiesAndTotalsResolveFromPresentedFields(): void
    {
        $document = (new BusinessDocumentPresenter())->present(self::model());

        self::assertSame(
            [[
                'label' => 'Invoice dates',
                'fields' => [['handle' => 'issued_on', 'label' => 'Issued on', 'display' => '2026-08-01']],
            ]],
            $document['groups'],
        );
        self::assertCount(1, $document['parties']);
        self::assertSame('Billed to', $document['parties'][0]['label'] ?? null);
        self::assertSame(
            [
                ['handle' => 'subtotal', 'label' => 'Subtotal', 'display' => 'N$ 1,200.00'],
                ['handle' => 'total', 'label' => 'Total', 'display' => 'N$ 1,380.00'],
            ],
            $document['totals'],
        );
    }

    /**
     * Build one safe document read model shaped like `BusinessSurfaceService::document()` output.
     *
     * @return  array<string, mixed>  Model carrying identity, group, party, line and totals roles.
     *
     * @since   2.0.0
     */
    private static function model(): array
    {
        return [
            'definition' => [
                'singular_label' => 'Invoice',
                'plural_label' => 'Invoices',
                'relationships' => [
                    ['handle' => 'client', 'label' => 'Client account', 'kind' => 'many_to_one'],
                    ['handle' => 'lines', 'label' => 'Invoice lines', 'kind' => 'owned_line_collection'],
                ],
            ],
            'document_view' => [
                'handle' => 'invoice_document',
                'label' => 'Invoice document',
                'kind' => 'document',
                'custom' => false,
                'document' => [
                    'identity' => 'invoice_number',
                    'groups' => [['label' => 'Invoice dates', 'fields' => ['issued_on', 'withheld_on']]],
                    'parties' => [['label' => 'Billed to', 'relationship' => 'client']],
                    'lines' => 'lines',
                    'totals' => ['subtotal', 'total'],
                ],
                'line_columns' => [
                    ['handle' => 'description', 'label' => 'Description'],
                    ['handle' => 'line_total', 'label' => 'Line total'],
                ],
            ],
            'record' => [
                'record_id' => '019c7000-0000-7000-8000-000000002101',
                'version' => 3,
                'created_at' => '2026-08-01T08:00:00+00:00',
                'includes' => [
                    'client' => [[
                        'record_id' => '019c7000-0000-7000-8000-000000001101',
                        'label' => 'CLI-001',
                        'fields' => [
                            ['handle' => 'client_code', 'label' => 'Client code', 'display' => 'CLI-001'],
                            ['handle' => 'name', 'label' => 'Name', 'display' => 'Desert Bloom Foods'],
                        ],
                    ]],
                    'lines' => [
                        [
                            'record_id' => '019c7000-0000-7000-8000-000000003101',
                            'cells' => [
                                ['handle' => 'description', 'display' => 'Automation retainer'],
                                ['handle' => 'line_total', 'display' => 'N$ 1,200.00'],
                            ],
                        ],
                        [
                            'record_id' => '019c7000-0000-7000-8000-000000003102',
                            'cells' => [
                                ['handle' => 'description', 'display' => 'Hosting'],
                                ['handle' => 'line_total', 'display' => 'N$ 180.00'],
                            ],
                        ],
                    ],
                ],
            ],
            'fields' => [
                ['handle' => 'invoice_number', 'label' => 'Invoice number', 'display' => 'INV-2026-101'],
                ['handle' => 'issued_on', 'label' => 'Issued on', 'display' => '2026-08-01'],
                ['handle' => 'subtotal', 'label' => 'Subtotal', 'display' => 'N$ 1,200.00'],
                ['handle' => 'total', 'label' => 'Total', 'display' => 'N$ 1,380.00'],
            ],
        ];
    }
}
