<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use Kumwe\App\BusinessRecord\Domain\UnitConversionFactor;
use Kumwe\App\BusinessRecord\Domain\UnitConversionRequest;

/**
 * The port an extension implements to be the source of a unit conversion factor.
 *
 * The conversion table is deliberately outside core. A metric standards table, a trade-unit table
 * administered by hand, a supplier feed and a contractual case size are all implementations of this one
 * contract, and none of them is wired into the CMS: a provider reaches the conversion pipeline only by
 * being contributed through the extension registrar, and it is withdrawn with the rest of its package on
 * disable, uninstall or trust revocation.
 *
 * The business rule lives entirely behind this interface — which factor, from whom, applying when,
 * approved by whom. What core holds the implementation to is only that the factor it returns is
 * complete: it relates the units that were asked about, it is not dated after the instant that was asked
 * about, and it is attributed to this provider by name.
 *
 * @since  2.0.0
 */
interface UnitConversionProvider
{
    /**
     * The identity this provider is registered under and attributes every factor to.
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
     * provider. Use it for the conditions the provider knows cheaply — an unrelated pair of units, an
     * instant outside the range it holds — and leave a genuine sourcing failure to `factorFor()`.
     *
     * @param   UnitConversionRequest  $request  Quantity, target unit and as-at instant being asked about.
     *
     * @return  bool  True when `factorFor()` may be called for this request.
     *
     * @since   2.0.0
     */
    public function supports(UnitConversionRequest $request): bool;

    /**
     * Supply the factor this provider stands behind for one conversion.
     *
     * @param   UnitConversionRequest  $request  Quantity, target unit and as-at instant being asked about.
     *
     * @return  UnitConversionFactor  A factor relating the requested units, as at that instant or earlier,
     *          attributed to this provider.
     *
     * @throws  UnitConversionUnavailable  When the provider accepted the request but cannot source a factor
     *          for it.
     *
     * @since   2.0.0
     */
    public function factorFor(UnitConversionRequest $request): UnitConversionFactor;
}
