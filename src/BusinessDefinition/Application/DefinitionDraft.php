<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Application;

use DateTimeImmutable;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;

/**
 * A business definition's work in progress, checked against itself on the way out of storage.
 *
 * Between publications a handle carries at most one draft, and this is the shape every reader of it gets:
 * `BusinessDefinitionRepository::draft()` and `saveDraft()` both answer with one, and the administrator, REST
 * and console surfaces render it. Two things travel beside the bytes. The revision is the optimistic token the
 * next `saveDraft()` or `publish()` has to quote, so a caller can prove it composed its change against the
 * state it read. The checksum is the digest stored alongside those bytes, and the constructor re-derives it
 * from the definition rather than trusting it, so a draft row that was hand-edited or corrupted in the
 * database is refused here instead of reaching the compatibility analyzer as though it were canonical.
 *
 * @since  2.0.0
 */
final readonly class DefinitionDraft
{
    /**
     * Capture a stored draft and assert that its bytes, revision and checksum describe the same save.
     *
     * @param   EntityTypeDefinition  $definition  Draft definition as it was last saved.
     * @param   int                   $revision    Draft revision, counting from one, that the next write to
     *          this handle has to quote.
     * @param   string                $checksum    Digest stored beside the bytes, which has to equal the
     *          definition's own canonical checksum.
     * @param   string                $updatedBy   Actor recorded as having last saved the draft.
     * @param   DateTimeImmutable     $updatedAt   Instant of that save.
     *
     * @throws  InvalidBusinessDefinition  When the revision is below one, or the stored checksum does not
     *          match the definition's canonical bytes.
     *
     * @since   2.0.0
     */
    public function __construct(
        public EntityTypeDefinition $definition,
        public int $revision,
        public string $checksum,
        public string $updatedBy,
        public DateTimeImmutable $updatedAt,
    ) {
        if ($revision < 1 || !hash_equals($definition->checksum(), $checksum)) {
            throw new InvalidBusinessDefinition('A stored business-definition draft is inconsistent.');
        }
    }
}
