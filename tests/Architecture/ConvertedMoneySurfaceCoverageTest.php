<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\BuiltInFieldTypes;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordView;
use Kumwe\App\BusinessRecord\Domain\ConvertedMoneyValue;
use Kumwe\App\BusinessRecord\Domain\ExactDecimal;
use Kumwe\App\BusinessRecord\Domain\ExactDecimalArithmetic;
use Kumwe\App\BusinessRecord\Domain\MoneyConversionRequest;
use Kumwe\App\BusinessRecord\Domain\MoneyConverter;
use Kumwe\App\BusinessRecord\Domain\MoneyExchangeRate;
use Kumwe\App\BusinessRecord\Domain\MoneyRoundingMode;
use Kumwe\App\BusinessRecord\Domain\MoneyValue;
use Kumwe\App\BusinessRecord\Domain\RecordValueGuard;
use Kumwe\App\BusinessReporting\Application\ReportCsvEncoder;
use Kumwe\App\BusinessReporting\Application\ReportExecutionResult;
use Kumwe\App\BusinessReporting\Domain\ReportValueType;
use Kumwe\App\BusinessSurface\Application\BusinessRecordProjector;
use Kumwe\App\BusinessSurface\Delivery\Browser\BusinessDocumentPresenter;
use Kumwe\App\BusinessSurface\Presentation\Field\ConvertedMoneySurface;
use Kumwe\App\BusinessSurface\Presentation\Field\CoreFieldPresenter;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentation;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationContext;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationRegistry;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationRequest;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresenter;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldWidget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(ConvertedMoneySurface::class)]
#[CoversClass(ConvertedMoneyValue::class)]
#[CoversClass(CoreFieldPresenter::class)]
#[CoversClass(FieldPresentation::class)]
#[CoversClass(FieldPresentationRegistry::class)]
#[CoversClass(BusinessRecordProjector::class)]
#[CoversClass(BusinessDocumentPresenter::class)]
/**
 * Proves decision D10's rule holds on every surface, driven from the one table that enumerates them.
 *
 * The rule is that a converted amount is always marked as converted and carries its rate and its as-at
 * instant, everywhere it appears. This test is what turns that from a sentence into a property. It walks
 * `ConvertedMoneySurface`, exercises each surface's carriers with one converted figure, and fails when
 * any of them renders the figure without the rate, the as-at instant or the provider behind it. It then
 * closes the other direction: a file under `src/` that reads a converted amount and is absent from the
 * table fails the build, so a renderer added later is caught here rather than in an audit.
 *
 * @since  2.0.0
 */
final class ConvertedMoneySurfaceCoverageTest extends TestCase
{
    /**
     * Rate the fixture converts at, quoted by a package that core does not ship.
     *
     * @var    string
     * @since  2.0.0
     */
    private const RATE = '0.04938240';

    /**
     * Instant the fixture rate is as at, in the exact spelling every surface must carry.
     *
     * @var    string
     * @since  2.0.0
     */
    private const AS_AT = '2026-08-14T00:00:00.000000+00:00';

    /**
     * Identity of the package that supplied the fixture rate.
     *
     * @var    string
     * @since  2.0.0
     */
    private const PROVIDER = 'acme.rates.ecb';

    /**
     * Every surface named in the table is covered by an assertion in this class, and none is missing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySurfaceInTheTableIsCoveredByAnAssertion(): void
    {
        $covered = [
            ConvertedMoneySurface::Administrator->value,
            ConvertedMoneySurface::Portal->value,
            ConvertedMoneySurface::Document->value,
            ConvertedMoneySurface::Rest->value,
            ConvertedMoneySurface::Machine->value,
            ConvertedMoneySurface::Report->value,
            ConvertedMoneySurface::Export->value,
            ConvertedMoneySurface::Event->value,
        ];
        sort($covered, SORT_STRING);
        $declared = array_map(
            static fn (ConvertedMoneySurface $surface): string => $surface->value,
            ConvertedMoneySurface::cases(),
        );
        sort($declared, SORT_STRING);

        self::assertSame(
            $declared,
            $covered,
            'A surface was added to the table without an assertion proving it renders provenance.',
        );
    }

    /**
     * Every carrier the table names exists, so the table cannot rot into a list of moved files.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryCarrierNamedByTheTableExists(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (ConvertedMoneySurface::everyCarrier() as $path) {
            self::assertFileExists($root . '/' . $path, $path . ' is named by the surface table but is absent.');
        }
    }

    /**
     * No file under `src/` reads a converted amount without being accounted for by the table.
     *
     * The table's own file is skipped, because naming the type in prose is what a table does; every
     * other file in the tree that mentions `ConvertedMoneyValue` either renders a surface or belongs to
     * the money-conversion contract, and has to say which.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNoUnlistedSourceFileReadsAConvertedAmount(): void
    {
        $table = 'src/BusinessSurface/Presentation/Field/ConvertedMoneySurface.php';
        $root = dirname(__DIR__, 2);
        $reading = [];
        $source = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src', RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($source as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);
            if (!str_contains($contents, 'ConvertedMoneyValue')) {
                continue;
            }
            $relative = str_replace($root . '/', '', $file->getPathname());
            if ($relative !== $table) {
                $reading[] = $relative;
            }
        }
        sort($reading, SORT_STRING);
        $accounted = array_values(array_filter(
            ConvertedMoneySurface::everyCarrier(),
            static fn (string $path): bool => str_starts_with($path, 'src/'),
        ));

        self::assertSame(
            [],
            array_values(array_diff($reading, $accounted)),
            'A source file reads a converted amount without appearing in the converted-money surface table.',
        );
    }

    /**
     * The administrator and portal record surfaces render the figure and its whole provenance.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGeneratedAdministratorAndPortalSurfacesRenderProvenance(): void
    {
        $converted = self::converted();
        foreach (
            [
                FieldPresentationContext::Detail,
                FieldPresentationContext::List,
                FieldPresentationContext::Relation,
                FieldPresentationContext::Update,
            ] as $context
        ) {
            $presentation = self::registry()->present(new FieldPresentationRequest(
                self::field(),
                self::moneyType(),
                $context,
                $converted,
                editable: true,
            ));

            self::assertSame($converted->toArray(), $presentation->provenance);
            self::assertSame($converted->toPortableString(), $presentation->display);
            self::assertFalse($presentation->editable, 'A converted amount was offered as an editor.');
            self::assertSame(FieldWidget::Output, $presentation->widget);
            self::assertNull($presentation->inputValue);
            self::assertSame($converted->toArray(), $presentation->toArray()['provenance']);
            self::assertProvenance($presentation->display);
        }

        foreach (
            ['templates/administrator/_business-fields.twig', 'templates/portal/_business-fields.twig'] as $template
        ) {
            $markup = self::source($template);
            self::assertStringContainsString('field.provenance.rate.rate', $markup, $template);
            self::assertStringContainsString('field.provenance.rate.as_at', $markup, $template);
            self::assertStringContainsString('field.provenance.rate.provider', $markup, $template);
        }
    }

    /**
     * No presenter, core or contributed, can hand back a converted amount stripped of its evidence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPresenterCannotReduceAConvertedAmountToABareFigure(): void
    {
        $registry = new FieldPresentationRegistry();
        $registry->register(
            DefinitionOwner::core(),
            'core.money',
            [FieldPresentationContext::Detail],
            new class implements FieldPresenter {
                /**
                 * Present the figure alone, as a renderer written without the rule in mind would.
                 *
                 * @param   FieldPresentationRequest  $request  Server-authorized presentation request.
                 *
                 * @return  FieldPresentation  A bare figure carrying none of its provenance.
                 *
                 * @since   2.0.0
                 */
                public function present(FieldPresentationRequest $request): FieldPresentation
                {
                    return new FieldPresentation(
                        $request->field->handle,
                        $request->field->label,
                        $request->context,
                        FieldWidget::Output,
                        '1234.56 EUR',
                        null,
                        false,
                        $request->field->required,
                        $request->errors,
                    );
                }
            },
        );

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('must be presented with its conversion provenance');
        $registry->present(new FieldPresentationRequest(
            self::field(),
            self::moneyType(),
            FieldPresentationContext::Detail,
            self::converted(),
        ));
    }

    /**
     * The document view kind carries provenance into its meta blocks, its line cells and its totals.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDocumentViewKindRendersProvenance(): void
    {
        $converted = self::converted();
        $document = (new BusinessDocumentPresenter())->present([
            'definition' => ['singular_label' => 'Invoice', 'plural_label' => 'Invoices', 'relationships' => []],
            'document_view' => [
                'kind' => 'document',
                'document' => ['identity' => 'number', 'groups' => [], 'totals' => ['total']],
                'line_columns' => [],
            ],
            'record' => ['created_at' => '2026-08-14T00:00:00+00:00', 'includes' => []],
            'fields' => [
                ['handle' => 'number', 'label' => 'Number', 'display' => 'INV-0001', 'provenance' => null],
                [
                    'handle' => 'total',
                    'label' => 'Total',
                    'display' => $converted->toPortableString(),
                    'provenance' => $converted->toArray(),
                ],
            ],
        ]);

        $totals = $document['totals'];
        self::assertIsArray($totals);
        self::assertArrayHasKey(0, $totals);
        $total = $totals[0];
        self::assertIsArray($total);
        self::assertSame($converted->toArray(), $total['provenance']);
        self::assertIsString($total['display']);
        self::assertProvenance($total['display']);

        foreach (
            ['templates/administrator/business-document.twig', 'templates/portal/business-document.twig'] as $template
        ) {
            $markup = self::source($template);
            self::assertStringContainsString('total.provenance', $markup, $template);
            self::assertStringContainsString('cell.provenance', $markup, $template);
            self::assertStringContainsString('field.provenance', $markup, $template);
        }
    }

    /**
     * The generated REST schemas describe a converted amount, and admit one on reads only.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGeneratedRestSchemasDescribeAConvertedAmount(): void
    {
        $contract = json_decode(self::source('api/openapi/kumwe-v1.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($contract);
        $components = $contract['components'] ?? null;
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        self::assertIsArray($schemas);

        $converted = $schemas['GeneratedBusinessConvertedMoney'] ?? null;
        self::assertIsArray($converted);
        self::assertSame(false, $converted['additionalProperties'] ?? null);
        self::assertSame(
            ['converted', 'value', 'source', 'rate', 'rounding'],
            $converted['required'] ?? null,
        );
        $properties = $converted['properties'] ?? null;
        self::assertIsArray($properties);
        $rate = $properties['rate'] ?? null;
        self::assertIsArray($rate);
        self::assertSame(
            ['base_currency', 'quote_currency', 'rate', 'as_at', 'provider'],
            $rate['required'] ?? null,
        );

        $column = $schemas['GeneratedBusinessReportColumn'] ?? null;
        self::assertIsArray($column);
        $columnProperties = $column['properties'] ?? null;
        self::assertIsArray($columnProperties);
        $type = $columnProperties['type'] ?? null;
        self::assertIsArray($type);
        self::assertContains(
            ReportValueType::ConvertedMoney->value,
            $type['enum'] ?? [],
            'The published report column vocabulary omits the converted-money type the runtime emits.',
        );
    }

    /**
     * The machine surface exports a converted amount in the declared shape, never as a bare figure.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheMachineAndRestProjectionExportsProvenance(): void
    {
        $converted = self::converted();
        $projected = (new BusinessRecordProjector())->record(self::view($converted));
        $values = $projected['values'];

        self::assertIsArray($values);
        self::assertSame($converted->toArray(), $values['presented_total'] ?? null);
        self::assertSame(
            $converted->source->toArray(),
            $values['total'] ?? null,
            'The stored amount must keep its own shape beside the converted presentation of it.',
        );
        self::assertNotSame($values['total'] ?? null, $values['presented_total'] ?? null);
        self::assertSame(
            $converted->toArray(),
            ConvertedMoneyValue::fromArray($values['presented_total'])->toArray(),
        );
    }

    /**
     * A report column and a downloaded export both refuse a converted figure without its provenance.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheReportAndExportSurfacesRefuseAFigureWithoutProvenance(): void
    {
        $converted = self::converted();
        self::assertTrue(ReportValueType::ConvertedMoney->accepts($converted->toPortableString()));
        self::assertFalse(ReportValueType::ConvertedMoney->accepts('1234.56'));

        $result = new ReportExecutionResult(
            'acme.price_list',
            str_repeat('a', 64),
            str_repeat('b', 64),
            ['presented' => 'Presented price'],
            ['presented' => ReportValueType::ConvertedMoney],
            [['presented' => $converted->toPortableString()]],
        );
        $csv = implode('', iterator_to_array((new ReportCsvEncoder())->encode($result), false));
        $rows = explode("\r\n", $csv);
        self::assertArrayHasKey(1, $rows);
        self::assertProvenance($rows[1]);
    }

    /**
     * A durable event payload cannot carry a converted amount, because a stored value can never be one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEventPayloadCannotCarryAConvertedAmountAtAll(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported runtime type');
        RecordValueGuard::assertValue(self::converted());
    }

    /**
     * Assert one rendered string carries the rate, the as-at instant and the provider identity.
     *
     * @param   string  $rendered  Text a surface would show or write.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertProvenance(string $rendered): void
    {
        self::assertStringContainsString('converted from', $rendered);
        self::assertStringContainsString(self::RATE, $rendered);
        self::assertStringContainsString(self::AS_AT, $rendered);
        self::assertStringContainsString(self::PROVIDER, $rendered);
    }

    /**
     * Build the core presenter registry the generated browser surfaces use.
     *
     * @return  FieldPresentationRegistry  Registry with the core presenter bound to every money context.
     *
     * @since   2.0.0
     */
    private static function registry(): FieldPresentationRegistry
    {
        $registry = new FieldPresentationRegistry();
        $registry->register(
            DefinitionOwner::core(),
            'core.money',
            [
                FieldPresentationContext::Detail,
                FieldPresentationContext::List,
                FieldPresentationContext::Relation,
                FieldPresentationContext::Update,
            ],
            new CoreFieldPresenter(),
        );

        return $registry;
    }

    /**
     * Read the core money field type from the built-in catalogue rather than restating it.
     *
     * @return  FieldTypeDefinition  The shipped `core.money` type.
     *
     * @since   2.0.0
     */
    private static function moneyType(): FieldTypeDefinition
    {
        foreach (BuiltInFieldTypes::all() as $type) {
            if ($type->id === 'core.money') {
                return $type;
            }
        }

        self::fail('The core money field type is unavailable.');
    }

    /**
     * Declare one money field the surfaces present.
     *
     * @return  FieldDefinition  A nullable money field with the precision the fixture converts at.
     *
     * @since   2.0.0
     */
    private static function field(): FieldDefinition
    {
        return new FieldDefinition('total', 'Total', 'core.money', precision: 12, scale: 2);
    }

    /**
     * Build one disclosed record view holding a stored amount and a converted presentation of it.
     *
     * @param   ConvertedMoneyValue  $converted  Converted presentation attached beside the stored value.
     *
     * @return  BusinessRecordView  A view the shared projector serializes for REST and the machine surface.
     *
     * @since   2.0.0
     */
    private static function view(ConvertedMoneyValue $converted): BusinessRecordView
    {
        return new BusinessRecordView(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e10',
            1,
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e11',
            'INV-0001',
            3,
            'default',
            null,
            null,
            ['total' => $converted->source, 'presented_total' => $converted],
            'operator',
            new DateTimeImmutable('2026-08-14T00:00:00+00:00'),
            'operator',
            new DateTimeImmutable('2026-08-14T00:00:00+00:00'),
            null,
            null,
            null,
            null,
        );
    }

    /**
     * Build the one converted figure every surface in this test is asked to render.
     *
     * @return  ConvertedMoneyValue  25000.00 ZAR presented as EUR from a package-supplied rate.
     *
     * @since   2.0.0
     */
    private static function converted(): ConvertedMoneyValue
    {
        $asAt = new DateTimeImmutable('2026-08-14T00:00:00', new DateTimeZone('UTC'));

        return (new MoneyConverter())->convert(
            new MoneyConversionRequest(
                new MoneyValue(ExactDecimal::fromString('25000.00', 12, 2), 'ZAR'),
                'EUR',
                $asAt,
                12,
                2,
                MoneyRoundingMode::HalfUp,
            ),
            new MoneyExchangeRate(
                'ZAR',
                'EUR',
                ExactDecimalArithmetic::fromLiteral(self::RATE),
                $asAt,
                self::PROVIDER,
            ),
        );
    }

    /**
     * Read one repository file the surface assertions inspect directly.
     *
     * @param   string  $path  Repository-relative path.
     *
     * @return  string  Complete file bytes.
     *
     * @since   2.0.0
     */
    private static function source(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($contents, 'Could not read ' . $path . '.');

        return $contents;
    }
}
