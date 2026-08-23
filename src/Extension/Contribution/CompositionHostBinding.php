<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use InvalidArgumentException;

/**
 * The bounded host metadata binding one canonical composition document into this application.
 *
 * A canonical document is portable Studio JSON and never carries Kumwe-specific data: renderer
 * bindings, authority and host references live here instead, exactly as kumwe/app#104 requires —
 * never as a proprietary JSON Schema keyword inside the document. A binding names the document it
 * belongs to by kind and identity, the owner-namespaced renderer the Gate B surface resolves for a
 * block, and optionally the declared capability an authoring surface must hold before offering it.
 *
 * @since  2.0.0
 */
final readonly class CompositionHostBinding implements ContributionDefinition
{
    /**
     * Bind one declared canonical document to its host-side metadata.
     *
     * @param   CanonicalCompositionKind  $kind        Kind of the document this binding belongs to.
     * @param   string                    $documentId  Identity of the document within that kind.
     * @param   string|null               $renderer    Owner-namespaced renderer binding; required for
     *          a block definition, absent for kinds the host does not render directly.
     * @param   string|null               $capability  Declared capability an authoring surface must
     *          hold before offering this contribution, or null for no additional gate.
     *
     * @throws  InvalidArgumentException  When the document identity is empty or a named member is an
     *          empty string.
     *
     * @since   2.0.0
     */
    public function __construct(
        public CanonicalCompositionKind $kind,
        public string $documentId,
        public ?string $renderer = null,
        public ?string $capability = null,
    ) {
        if ($documentId === '') {
            throw new InvalidArgumentException('A composition host binding must name its document.');
        }
        if ($renderer === '') {
            throw new InvalidArgumentException('A composition host binding renderer cannot be empty.');
        }
        if ($capability === '') {
            throw new InvalidArgumentException('A composition host binding capability cannot be empty.');
        }
    }

    /**
     * The binding's identity: one binding per document, addressed by kind and document identity.
     *
     * @return  string  The kind value and document identity joined by one space.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->kind->value . ' ' . $this->documentId;
    }

    /**
     * Export the comparable structure reconciliation and the inventory use.
     *
     * @return  array<string, mixed>  Every declared member of this binding.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'id' => $this->documentId,
            'renderer' => $this->renderer,
            'capability' => $this->capability,
        ];
    }
}
