<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Application;

use DateTimeImmutable;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\DefinitionStatus;

/**
 * Where one business-definition handle stands in a site's catalog, without any of its definition bytes.
 *
 * `BusinessDefinitionRepository::catalog()` and `entry()` answer with these heads, and every operation on a
 * definition starts from one: the service turns a caller-supplied UUID or handle into an entry, authorizes
 * against its `$id`, and only then loads the draft or a published version. Separating the head from the bytes
 * is what makes that first step cheap, and the head is also what a caller reads to decide its next write —
 * `$draftRevision` is the token an optimistic `saveDraft()` or `publish()` has to quote, and
 * `$publishedVersion` says whether the handle is serving anything yet.
 *
 * @since  2.0.0
 */
final readonly class DefinitionCatalogEntry
{
    /**
     * Capture where one handle stands, as the catalog head records it.
     *
     * @param  string             $id                Definition UUID, which is also the identity that
     *         authorization resources and audit entries are keyed on.
     * @param  string             $siteIdentifier    Site whose catalog holds the handle.
     * @param  string             $handle            Namespaced entity handle this head stands for.
     * @param  DefinitionOwner    $owner             Who introduced the definition: core, an extension, or the
     *         site itself. Settled when the entry was created and never moved afterwards.
     * @param  bool               $ownerActive       Whether an extension-owned definition is currently usable;
     *         core- and site-owned entries are always active.
     * @param  int                $draftRevision     Revision of the stored draft, which the next write has to
     *         quote; zero once a publication consumed the draft.
     * @param  ?int               $publishedVersion  Version the head currently serves, or null when the handle
     *         has never been published.
     * @param  DefinitionStatus   $status            Publication state of the head, as its last write left it.
     * @param  DateTimeImmutable  $updatedAt         Instant the head last moved.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $id,
        public string $siteIdentifier,
        public string $handle,
        public DefinitionOwner $owner,
        public bool $ownerActive,
        public int $draftRevision,
        public ?int $publishedVersion,
        public DefinitionStatus $status,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
