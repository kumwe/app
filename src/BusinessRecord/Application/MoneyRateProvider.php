<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use Kumwe\CMS\BusinessRecord\Domain\MoneyConversionRequest;
use Kumwe\CMS\BusinessRecord\Domain\MoneyExchangeRate;

/**
 * The port an extension implements to be the source of an exchange rate.
 *
 * Rate sourcing is deliberately outside core. An external rate service, a manually administered table,
 * a bank feed and a contractual fixed rate are all implementations of this one contract, and none of
 * them is wired into the CMS: a provider reaches the conversion pipeline only by being contributed
 * through the extension registrar, and it is withdrawn with the rest of its package on disable,
 * uninstall or trust revocation.
 *
 * The business rule lives entirely behind this interface — which rate, from whom, applying when,
 * approved by whom. What core holds the implementation to is only that the rate it returns is complete:
 * it prices the pair that was asked about, it is not dated after the instant that was asked about, and
 * it is attributed to this provider by name.
 *
 * @since  2.0.0
 */
interface MoneyRateProvider
{
    /**
     * The identity this provider is registered under and attributes every rate to.
     *
     * @return  string  The same namespaced identifier as its contributed declaration.
     *
     * @since   2.0.0
     */
    public function identifier(): string;

    /**
     * Whether this provider is prepared to answer one conversion.
     *
     * Returning false is an ordinary outcome, not a failure: the pipeline moves on to the next declared
     * provider. Use it for the conditions the provider knows cheaply — an unpriced pair, an instant
     * outside the range it holds — and leave a genuine sourcing failure to `rateFor()`.
     *
     * @param   MoneyConversionRequest  $request  Amount, target currency and as-at instant being asked about.
     *
     * @return  bool  True when `rateFor()` may be called for this request.
     *
     * @since   2.0.0
     */
    public function supports(MoneyConversionRequest $request): bool;

    /**
     * Supply the rate this provider stands behind for one conversion.
     *
     * @param   MoneyConversionRequest  $request  Amount, target currency and as-at instant being asked about.
     *
     * @return  MoneyExchangeRate  A rate pricing the requested pair, as at that instant or earlier, attributed
     *          to this provider.
     *
     * @throws  MoneyRateUnavailable  When the provider accepted the request but cannot source a rate for it.
     *
     * @since   2.0.0
     */
    public function rateFor(MoneyConversionRequest $request): MoneyExchangeRate;
}
