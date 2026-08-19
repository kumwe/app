<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Application;

/**
 * One screen of the administrator content browser: the visible records plus the paging state.
 *
 * `ContentService::browse()` filters repository batches through the authorization gateway before
 * paging them, so the page offset cannot be turned into a total row count. This value object
 * therefore reports neighbouring pages as plain booleans — derived from over-fetching one record —
 * rather than a total, which is all the list template needs to render its previous and next links.
 *
 * @since  2.0.0
 */
final readonly class ContentPage
{
    /**
     * Capture the records and paging state resolved for one browse request.
     *
     * @param  list<ContentRecord>  $items        Records the actor may read, in the order the query asked for.
     * @param  ContentBrowseQuery   $query        Filters and paging that produced this page, for building links.
     * @param  bool                 $hasPrevious  Whether a lower-numbered page exists.
     * @param  bool                 $hasNext      Whether at least one further authorized record follows.
     *
     * @since  2.0.0
     */
    public function __construct(
        public array $items,
        public ContentBrowseQuery $query,
        public bool $hasPrevious,
        public bool $hasNext,
    ) {
    }
}
