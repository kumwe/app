<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Application;

use DateTimeImmutable;
use Kumwe\App\BusinessDefinition\Domain\CompatibilityPlan;
use Kumwe\App\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;

/**
 * One version of a business definition as it was published, beside the compatibility plan that produced it.
 *
 * Every read of definition history — the published head, the version list, the document a lifecycle change
 * answers with — arrives as this record, so its constructor is where a row coming out of storage is checked
 * against itself: the definition must carry published status, its bytes must hash to the checksum the plan
 * names as its target, its version number must be the one the plan produced, and the record's own lifecycle
 * status must have left draft. A row failing any of those is refused rather than served, which keeps a
 * corrupted or hand-edited catalog from reaching the schema compiler as though it were canonical.
 *
 * @since  2.0.0
 */
final readonly class DefinitionVersionRecord
{
    /**
     * Capture a stored definition version and assert that its parts describe the same publication.
     *
     * @param   EntityTypeDefinition  $definition     Canonical published bytes of this version.
     * @param   CompatibilityPlan     $compatibility  Plan analysed when this version replaced the previous one.
     * @param   DefinitionStatus      $status         Lifecycle state the version sits in now; never `Draft`.
     * @param   string                $publishedBy    Identifier of the actor who published the version.
     * @param   DateTimeImmutable     $publishedAt    Instant at which the publication was recorded.
     *
     * @throws  InvalidBusinessDefinition  When bytes, plan and status do not describe one published version.
     *
     * @since   2.0.0
     */
    public function __construct(
        public EntityTypeDefinition $definition,
        public CompatibilityPlan $compatibility,
        public DefinitionStatus $status,
        public string $publishedBy,
        public DateTimeImmutable $publishedAt,
    ) {
        if (!hash_equals($definition->checksum(), $compatibility->toChecksum)) {
            throw new InvalidBusinessDefinition('A stored definition version and compatibility plan disagree.');
        }
        if (
            $definition->status !== DefinitionStatus::Published
            || $definition->definitionVersion !== $compatibility->toVersion
        ) {
            throw new InvalidBusinessDefinition('A stored definition version has inconsistent canonical state.');
        }
        if ($status === DefinitionStatus::Draft) {
            throw new InvalidBusinessDefinition('A stored definition version cannot have draft status.');
        }
    }
}
