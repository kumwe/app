<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Extension\Spi\Contribution\ContributionDefinition;
use Kumwe\App\Extension\Runtime\RuntimeCanonicalJson;

/**
 * Produces owner-bound digests for persisted contribution declarations.
 *
 * A definition's array form is already the signed-manifest reconciliation contract. Binding that
 * exact form to its validated owner gives installation and migration code one checksum algorithm,
 * and prevents an otherwise identical declaration from being reassigned without changing its digest.
 *
 * @since  2.0.0
 */
final class ContributionDefinitionChecksum
{
    /**
     * Hash one canonical declaration together with the owner entitled to publish it.
     *
     * @param   ContributionOwner       $owner       Core or extension owner of the declaration.
     * @param   ContributionDefinition  $definition  Typed contribution whose complete export is hashed.
     *
     * @return  string  Lowercase SHA-256 digest of the canonical owner-bound declaration.
     *
     * @throws  \InvalidArgumentException  When the owner is not entitled to the definition identifier.
     *
     * @since   2.0.0
     */
    public static function calculate(
        ContributionOwner $owner,
        ContributionDefinition $definition,
    ): string {
        $kind = match (true) {
            $definition instanceof CapabilityDefinition => 'capability',
            $definition instanceof ResourcePolicyDefinition => 'resource policy',
            default => 'contribution',
        };
        $owner->assertOwns($definition->identifier(), $kind);

        return hash('sha256', RuntimeCanonicalJson::encode([
            'owner' => $owner->identifier(),
            'definition' => $definition->toArray(),
        ]));
    }
}
