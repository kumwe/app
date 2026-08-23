<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

/**
 * Additive capability for packages that contribute canonical Studio composition documents.
 *
 * Contribution SPI 4 introduces this interface beside the frozen SPI 3 registrar rather than adding
 * a method to it: `CompositionContributionRegistrar` is byte-pinned and every schema-5 provider
 * stays source compatible, while a schema-6 provider requires this capability explicitly. What a
 * provider registers here is reconciled byte-for-byte against the canonical documents its signed
 * manifest declared — the registration carries the same canonical JSON string the manifest carried,
 * so agreement is literal byte equivalence, never a normalized comparison. Host bindings are
 * declaration-only host metadata and are not registered through provider code.
 *
 * @since  2.0.0
 */
interface CanonicalCompositionRegistrar
{
    /**
     * Publish one manifest-reconciled canonical composition document.
     *
     * @param   CanonicalCompositionDocument  $document  The canonical document, byte-identical to
     *          the signed manifest's declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function canonicalCompositionDocument(CanonicalCompositionDocument $document): void;
}
