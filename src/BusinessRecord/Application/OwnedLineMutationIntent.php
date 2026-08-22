<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use Kumwe\App\BusinessDefinition\Domain\RelationshipDefinition;

/**
 * Complete owned-line mutation the aggregate document command has decided but not yet written.
 *
 * This value is the boundary between validation and persistence. It carries the exact pinned line type,
 * the final dense collection, the storage keys to remove and whether surviving rows need two-pass
 * renumbering. The facade can therefore write the header and this intent under its one transaction
 * without rediscovering relationship policy or line identity.
 *
 * @since  2.0.0
 */
final readonly class OwnedLineMutationIntent
{
    /**
     * Capture one whole replacement of an owned-line collection.
     *
     * @param  RelationshipDefinition      $relationship  Owned collection declared by the header definition.
     * @param  ResolvedBusinessDefinition  $line          Pinned line definition matching the installed table.
     * @param  list<OwnedLineWrite>         $writes        Final collection in dense position order.
     * @param  list<string>                 $removed       Storage keys no longer owned by the document.
     * @param  bool                         $renumber      Whether surviving positions require two-pass rewrite.
     *
     * @since  2.0.0
     */
    public function __construct(
        public RelationshipDefinition $relationship,
        public ResolvedBusinessDefinition $line,
        public array $writes,
        public array $removed,
        public bool $renumber,
    ) {
    }

    /**
     * Flatten the final line values into the scalar vocabulary aggregate invariants consume.
     *
     * @return  list<array<string, bool|int|string|null>>  Final collection values in stored position order.
     *
     * @since   2.0.0
     */
    public function invariantValues(): array
    {
        return array_map(
            static fn (OwnedLineWrite $line): array => RecordExpressionValues::from($line->values),
            $this->writes,
        );
    }
}
