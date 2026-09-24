<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use DateTimeImmutable;

/**
 * The cumulative export bytes one site may publish in a window, charged as each artifact is completed.
 *
 * A single artifact is bounded by its storage ceiling; this budget bounds the sum over a site's artifacts so
 * one site cannot consume the installation's export capacity. The charge is part of the completion
 * transaction, so a rolled-back completion is never charged and two concurrent completions for one site
 * cannot both pass on the same remaining budget.
 *
 * @since  2.0.0
 */
interface ExportSiteByteBudget
{
    /**
     * Charge a completed artifact's bytes to its site's current window, or refuse it.
     *
     * @param   string             $siteIdentifier  Site the artifact belongs to.
     * @param   int                $bytes           Artifact size in bytes, zero or more.
     * @param   DateTimeImmutable  $at              Completion instant, which selects the window.
     *
     * @return  void
     *
     * @throws  ExportSiteByteBudgetExhausted  When the charge would pass the site's budget for the window.
     *
     * @since   2.0.0
     */
    public function charge(string $siteIdentifier, int $bytes, DateTimeImmutable $at): void;
}
