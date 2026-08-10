<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Domain;

use Kumwe\CMS\Extension\Contribution\ContributionDefinition;

/**
 * Data-only declaration that may be compiled into a trusted runtime generation.
 *
 * @since  2.0.0
 */
interface IntegrationContract extends ContributionDefinition
{
    /** @return string Stable identifier used for ownership and collision checks. @since 2.0.0 */
    public function identifier(): string;

    /** @return array<string, mixed> Canonical publication representation. @since 2.0.0 */
    public function toArray(): array;
}
