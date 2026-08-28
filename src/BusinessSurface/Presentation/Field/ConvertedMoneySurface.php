<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Presentation\Field;

/**
 * The complete table of surfaces on which a converted amount may appear, and what carries it on each.
 *
 * Decision D10 states the rule — a converted amount is always marked as converted and carries its rate
 * and its as-at instant, everywhere it appears — and a rule of that shape is only worth stating if it is
 * universal. Universality is not a claim anybody can keep making by hand across a growing delivery
 * surface, so it is written down once here and proved from this table:
 * `ConvertedMoneySurfaceCoverageTest` walks every case, exercises the carriers it names, and refuses a
 * case whose rendering drops the rate, the as-at instant or the provider. It also refuses any file under
 * `src/` that reads `ConvertedMoneyValue` without appearing in this table or in `contractCarriers()`, so
 * a renderer added later is a build failure rather than an audit defect nobody noticed.
 *
 * The paths are repository-relative and deliberately include templates and the published REST artifact:
 * a converted figure that loses its provenance in Twig has lost it just as completely as one that loses
 * it in PHP.
 *
 * @since  2.0.0
 */
enum ConvertedMoneySurface: string
{
    /**
     * The generated administrator record surfaces — list, detail, form and confirmation screens.
     *
     * @since  2.0.0
     */
    case Administrator = 'administrator';

    /**
     * The generated portal record surfaces, which share their presenters with the administrator ones.
     *
     * @since  2.0.0
     */
    case Portal = 'portal';

    /**
     * The `document` view kind: the printed business document, its line table and its totals block.
     *
     * @since  2.0.0
     */
    case Document = 'document';

    /**
     * The generated REST schemas and the JSON they describe.
     *
     * @since  2.0.0
     */
    case Rest = 'rest';

    /**
     * The machine surface: the model-context tools and the console, over the one shared projector.
     *
     * @since  2.0.0
     */
    case Machine = 'machine';

    /**
     * Report columns, where a converted amount travels as the self-describing portable text.
     *
     * @since  2.0.0
     */
    case Report = 'report';

    /**
     * Downloaded export artifacts, which a recipient keeps and reads without the system that made them.
     *
     * @since  2.0.0
     */
    case Export = 'export';

    /**
     * Durable event payloads, which carry stored record values and therefore never carry a bare figure.
     *
     * @since  2.0.0
     */
    case Event = 'event';

    /**
     * Name the files that carry a converted amount's provenance on this surface.
     *
     * @return  non-empty-list<string>  Repository-relative paths, every one of which must exist.
     *
     * @since   2.0.0
     */
    public function carriers(): array
    {
        return match ($this) {
            self::Administrator => [
                'src/BusinessSurface/Presentation/Field/CoreFieldPresenter.php',
                'src/BusinessSurface/Presentation/Field/FieldPresentation.php',
                'src/BusinessSurface/Presentation/Field/FieldPresentationRegistry.php',
                'templates/administrator/_business-fields.twig',
            ],
            self::Portal => [
                'src/BusinessSurface/Presentation/Field/CoreFieldPresenter.php',
                'src/BusinessSurface/Presentation/Field/FieldPresentation.php',
                'src/BusinessSurface/Presentation/Field/FieldPresentationRegistry.php',
                'templates/portal/_business-fields.twig',
            ],
            self::Document => [
                'src/BusinessSurface/Application/BusinessSurfaceService.php',
                'src/BusinessSurface/Delivery/Browser/BusinessDocumentPresenter.php',
                'templates/administrator/business-document.twig',
                'templates/portal/business-document.twig',
            ],
            self::Rest => [
                'src/BusinessSurface/Application/BusinessRecordProjector.php',
                'src/OpenApi/Application/OpenApiContractCompiler.php',
                'api/openapi/kumwe-v1.json',
            ],
            self::Machine => [
                'src/BusinessSurface/Application/BusinessRecordProjector.php',
            ],
            self::Report => [
                'src/BusinessReporting/Application/ReportService.php',
                'src/BusinessReporting/Domain/ReportValueType.php',
            ],
            self::Export => [
                'src/BusinessReporting/Application/ReportCsvEncoder.php',
            ],
            self::Event => [
                'src/BusinessRecord/Application/RecordValueCodec.php',
                'src/BusinessRecord/Domain/RecordValueGuard.php',
            ],
        };
    }

    /**
     * Name the money-conversion contract files that produce or transport the value but render no surface.
     *
     * These are the other half of the completeness check. Without them the scan over `src/` would report
     * the contract's own classes as unlisted renderers; with them, every file in the tree that reads a
     * converted amount is accounted for either as a surface carrier or as part of the contract that
     * makes one.
     *
     * @return  non-empty-list<string>  Repository-relative paths, every one of which must exist.
     *
     * @since   2.0.0
     */
    public static function contractCarriers(): array
    {
        return [
            'src/BusinessRecord/Domain/MoneyRateProviderDefinition.php',
            'src/BusinessRecord/Infrastructure/RuntimeMoneyRateProviderCatalog.php',
            'src/Kernel/ContainerFactory.php',
            'vendor/kumwe/conversion/src/Contract/MoneyConverter.php',
            'vendor/kumwe/conversion/src/Decimal/ExactDecimalArithmetic.php',
            'vendor/kumwe/conversion/src/Provider/MoneyConversionPipeline.php',
            'vendor/kumwe/conversion/src/Provider/MoneyRateProvider.php',
            'vendor/kumwe/conversion/src/Provider/MoneyRateProviderCatalog.php',
            'vendor/kumwe/conversion/src/Value/ConvertedMoneyValue.php',
        ];
    }

    /**
     * List every repository path this table accounts for, surfaces and contract together.
     *
     * @return  non-empty-list<string>  Sorted, de-duplicated repository-relative paths.
     *
     * @since   2.0.0
     */
    public static function everyCarrier(): array
    {
        $paths = self::contractCarriers();
        foreach (self::cases() as $surface) {
            foreach ($surface->carriers() as $path) {
                $paths[] = $path;
            }
        }
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        /** @var non-empty-list<string> $paths */
        return $paths;
    }
}
