<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Plan;

/**
 * The closed set of reviews a safe plan may describe.
 *
 * `PlanPreviewHandler` resolves the request's `operation` member through `from()`, so this enum is the
 * allow-list deciding what an automation client may ask for a plan about: a name that is not a case
 * here is refused with a 400 rather than described. Every case names a read-and-report exercise, which
 * is what lets the plan endpoint state `apply_supported: false` for all of them — the plan says what a
 * reviewer would look at, never what would be written. The backing values are the strings the OpenAPI
 * `SafePlanRequest` schema publishes, so adding a case is an API change.
 *
 * @since  2.0.0
 */
enum SafePlanOperation: string
{
    /**
     * Review of one piece of editorial content: what it says and what it is filed as.
     *
     * @since  2.0.0
     */
    case ContentReview = 'content.review';
    /**
     * Review of how a page presents itself to search engines and whether it may be indexed at all.
     *
     * @since  2.0.0
     */
    case SeoReview = 'seo.review';
    /**
     * Review of how the site is arranged: its menus, the routes they name, and where they lead.
     *
     * @since  2.0.0
     */
    case SiteStructureReview = 'site.structure.review';
    /**
     * Review of whether an installed extension still fits the platform and the extensions beside it.
     *
     * @since  2.0.0
     */
    case ExtensionCompatibilityReview = 'extension.compatibility.review';
}
