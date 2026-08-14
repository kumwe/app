<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Domain\ConvertedMoneyValue;
use Kumwe\CMS\BusinessRecord\Domain\MoneyConversionRequest;
use Kumwe\CMS\BusinessRecord\Domain\MoneyConverter;

/**
 * The single path from a conversion request to an evidenced converted amount.
 *
 * Everything that converts money in a Kumwe installation comes through here, which is the point: two
 * extensions converting the same amount produce the same shape and the same provenance because neither
 * of them owns the step that produces it. The pipeline asks each contributed provider in declared order
 * whether it will answer, takes the first rate offered, and applies it through the core converter.
 *
 * A provider's answer is not taken on trust. A rate attributed to a different provider, or one that
 * prices another pair or postdates the instant asked about, is refused rather than converted with, so a
 * package cannot launder an unattributable rate through the platform's own contract.
 *
 * @since  2.0.0
 */
final readonly class MoneyConversionPipeline
{
    /**
     * Compose the pipeline from the core conversion rule and the active provider catalog.
     *
     * @param  MoneyConverter            $converter  Core rule applying a rate and its declared rounding.
     * @param  MoneyRateProviderCatalog  $catalog    Providers currently entitled to answer, in resolution order.
     *
     * @since  2.0.0
     */
    public function __construct(
        private MoneyConverter $converter,
        private MoneyRateProviderCatalog $catalog,
    ) {
    }

    /**
     * Convert one amount through the first contributed provider that answers.
     *
     * @param   MoneyConversionRequest  $request  Amount, target currency, as-at instant and declared rounding.
     *
     * @return  ConvertedMoneyValue  The presented figure, marked as converted and carrying its whole provenance.
     *
     * @throws  MoneyRateUnavailable  When no contributed provider answers, or the one that did returned a rate
     *          it is not entitled to, prices another pair, or dates after the instant asked about.
     *
     * @since   2.0.0
     */
    public function convert(MoneyConversionRequest $request): ConvertedMoneyValue
    {
        foreach ($this->catalog->providersFor($request) as $provider) {
            if (!$provider->supports($request)) {
                continue;
            }
            $rate = $provider->rateFor($request);
            if ($rate->provider !== $provider->identifier()) {
                throw new MoneyRateUnavailable('A rate provider supplied a rate attributed to another provider.');
            }
            try {
                return $this->converter->convert($request, $rate);
            } catch (InvalidArgumentException $exception) {
                // The provider accepted the request and then answered a different question; refusing here
                // keeps an unusable rate from reaching a surface as though it had been converted with.
                throw new MoneyRateUnavailable(
                    'A rate provider supplied a rate that does not answer the conversion requested.',
                    0,
                    $exception,
                );
            }
        }

        throw new MoneyRateUnavailable('No contributed rate provider can price this conversion.');
    }
}
