<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Domain\ConvertedQuantityValue;
use Kumwe\CMS\BusinessRecord\Domain\QuantityConverter;
use Kumwe\CMS\BusinessRecord\Domain\UnitConversionRequest;

/**
 * The single path from a unit conversion request to an evidenced converted quantity.
 *
 * Everything that converts a quantity in a Kumwe installation comes through here, which is the point: a
 * stock extension and a sales extension agree about what a case of a product is because neither of them
 * owns the step that decides it. The pipeline asks each contributed provider in declared order whether
 * it will answer, takes the first factor offered, and applies it through the core converter.
 *
 * A provider's answer is not taken on trust. A factor attributed to a different provider, or one that
 * relates other units or postdates the instant asked about, is refused rather than converted with, so a
 * package cannot launder an unattributable factor through the platform's own contract.
 *
 * @since  2.0.0
 */
final readonly class UnitConversionPipeline
{
    /**
     * Compose the pipeline from the core conversion rule and the active provider catalog.
     *
     * @param  QuantityConverter              $converter  Core rule applying a factor and its declared rounding.
     * @param  UnitConversionProviderCatalog  $catalog    Providers currently entitled to answer, in resolution
     *         order.
     *
     * @since  2.0.0
     */
    public function __construct(
        private QuantityConverter $converter,
        private UnitConversionProviderCatalog $catalog,
    ) {
    }

    /**
     * Convert one quantity through the first contributed provider that answers.
     *
     * @param   UnitConversionRequest  $request  Quantity, target unit, as-at instant and declared rounding.
     *
     * @return  ConvertedQuantityValue  The expressed figure, marked as converted and carrying its whole
     *          provenance.
     *
     * @throws  UnitConversionUnavailable  When no contributed provider answers, or the one that did returned a
     *          factor it is not entitled to, relates other units, or dates after the instant asked about.
     *
     * @since   2.0.0
     */
    public function convert(UnitConversionRequest $request): ConvertedQuantityValue
    {
        foreach ($this->catalog->providersFor($request) as $provider) {
            if (!$provider->supports($request)) {
                continue;
            }
            $factor = $provider->factorFor($request);
            if ($factor->provider !== $provider->identifier()) {
                throw new UnitConversionUnavailable(
                    'A conversion provider supplied a factor attributed to another provider.',
                );
            }
            try {
                return $this->converter->convert($request, $factor);
            } catch (InvalidArgumentException $exception) {
                // The provider accepted the request and then answered a different question; refusing here
                // keeps an unusable factor from reaching a surface as though it had been converted with.
                throw new UnitConversionUnavailable(
                    'A conversion provider supplied a factor that does not answer the conversion requested.',
                    0,
                    $exception,
                );
            }
        }

        throw new UnitConversionUnavailable('No contributed conversion provider can relate these units.');
    }
}
