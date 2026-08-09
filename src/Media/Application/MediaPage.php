<?php

declare(strict_types=1);

namespace Kumwe\CMS\Media\Application;

/**
 * One page of media library results together with the counters a pager needs.
 *
 * `MediaService::browse()` filters the library before slicing it, so `$total` counts the assets that
 * matched the search and kind filters rather than everything the site holds — a template that renders
 * "$total items" is describing the current filter, not the library. `$page` and `$perPage` echo back
 * the values actually used after clamping, which is what makes the pager links correct when the
 * request asked for a page size the service refused.
 *
 * @since  2.0.0
 */
final readonly class MediaPage
{
    /**
     * Capture one slice of a filtered media listing.
     *
     * @param  list<MediaAsset>  $items    Assets on this page, in the library's newest-first order.
     * @param  int               $total    Assets matching the filters across every page, not just this one.
     * @param  int               $page     One-based index of the page this slice was taken from.
     * @param  int               $perPage  Page size the slice was taken with, after clamping.
     *
     * @since  2.0.0
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    /**
     * Report how many pages the filtered result set spans.
     *
     * @return  int  Never below 1, so an empty library still renders a single, valid pager position.
     *
     * @since   2.0.0
     */
    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
