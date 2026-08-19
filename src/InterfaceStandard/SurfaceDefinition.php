<?php

declare(strict_types=1);

namespace Kumwe\App\InterfaceStandard;

use InvalidArgumentException;
use Kumwe\App\Extension\Contribution\ContributionDefinition;
use Kumwe\App\Extension\Contribution\ContributionOwner;

/**
 * Conformant owner-bound KIS surface accepted by Kumwe's existing contribution architecture.
 *
 * Only `admit()` and `fromArray()` can create this type, and both require the complete semantic
 * validator to pass. Registries therefore work with the established `ContributionDefinition`
 * contract without introducing a second container, service locator, or parallel lifecycle.
 *
 * @since  2.0.0
 */
final readonly class SurfaceDefinition implements ContributionDefinition
{
    /**
     * Hold a declaration after conformance admission has succeeded.
     *
     * @param  SurfaceDeclaration  $declaration  Validated semantic surface candidate.
     *
     * @since  2.0.0
     */
    private function __construct(public SurfaceDeclaration $declaration)
    {
    }

    /**
     * Admit a typed candidate only after every KIS semantic relationship passes.
     *
     * @param   SurfaceDeclaration            $declaration  Locally safe candidate to admit.
     * @param   ?SurfaceConformanceValidator  $validator    Alternate validator for deterministic testing,
     *          or null for the canonical KIS validator.
     *
     * @return  self  Contribution definition safe for owner-bound registration.
     *
     * @throws  SurfaceConformanceViolation  When the candidate violates one or more KIS invariants.
     *
     * @since   2.0.0
     */
    public static function admit(
        SurfaceDeclaration $declaration,
        ?SurfaceConformanceValidator $validator = null,
    ): self {
        ($validator ?? new SurfaceConformanceValidator())->assertConforms($declaration);

        return new self($declaration);
    }

    /**
     * Parse and admit strict canonical metadata through one fail-closed boundary.
     *
     * @param   ContributionOwner             $owner      Owner supplied by the current contribution phase.
     * @param   array<string, mixed>          $data       Exact canonical KIS declaration document.
     * @param   ?SurfaceConformanceValidator  $validator  Alternate deterministic validator, or null for KIS 1.0.
     *
     * @return  self  Admitted contribution definition.
     *
     * @throws  InvalidArgumentException  When declaration keys, values, or ownership are unsafe.
     * @throws  SurfaceConformanceViolation  When typed semantics do not conform to KIS.
     *
     * @since   2.0.0
     */
    public static function fromArray(
        ContributionOwner $owner,
        array $data,
        ?SurfaceConformanceValidator $validator = null,
    ): self {
        return self::admit(SurfaceDeclaration::fromArray($owner, $data), $validator);
    }

    /**
     * Return the stable owner-namespaced contribution inventory key.
     *
     * @return  string  Canonical KIS surface identifier.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->declaration->surface->value();
    }

    /**
     * Export the exact safe semantic declaration reconciled with a manifest.
     *
     * @return  array<string, mixed>  Canonical KIS declaration without executable presentation content.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return $this->declaration->toArray();
    }
}
