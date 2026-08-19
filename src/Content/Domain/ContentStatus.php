<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Domain;

/**
 * Editorial states a content entry moves through under the workflow Kumwe ships with.
 *
 * An entry stores its state as a plain lowercase key so a site-defined workflow can introduce states
 * of its own; this enum names the four the default workflow uses and that the capability mapping in
 * `ContentService` and `ContentTransitionAuthorizer` reasons about. Only `Published` makes an entry
 * eligible for public delivery, and even then the entry's `PublicationWindow` decides when.
 *
 * @since  2.0.0
 */
enum ContentStatus: string
{
    /**
     * Being authored, reachable only through the administrator and the authenticated API.
     *
     * @since  2.0.0
     */
    case Draft = 'draft';

    /**
     * Submitted for approval and awaiting a reviewer's decision, still withheld from delivery.
     *
     * @since  2.0.0
     */
    case Review = 'review';

    /**
     * Approved for public delivery, subject to the entry's publication window.
     *
     * @since  2.0.0
     */
    case Published = 'published';

    /**
     * Withdrawn from delivery but retained, so an operator can restore it rather than re-author it.
     *
     * @since  2.0.0
     */
    case Archived = 'archived';

    /**
     * Report whether entries in this state are eligible for public delivery.
     *
     * Eligibility is not visibility: `ContentEntry::isVisibleAt()` also consults the publication
     * window, so a published entry outside its window stays unreachable.
     *
     * @return  bool  True only for `Published`.
     *
     * @since   2.0.0
     */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }
}
