<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Application;

use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Content\Domain\TranslationGroup;
use Kumwe\CMS\Localization\Domain\LocaleTag;

/**
 * Persistence contract for the translation group behind one logical item.
 *
 * Delivery asks exactly two questions of this port, and they are the two `hreflang` and the language
 * selector are built from: what group does the entry I am about to render belong to, and which locale
 * does this group publish under a given route segment. Both answers are assembled from the entries
 * themselves — a group is not a copy of its members, it is a view over them — so an implementation
 * reads the same rows the content repository does and decides publication the same way: against the
 * `public_states` of the workflow definition version each entry is pinned to.
 *
 * An entry that declares no group is not a failure. It answers null, and delivery renders the page with
 * no alternates and no selector, which is what an untranslated site looks like.
 *
 * @since  2.0.0
 */
interface TranslationGroupRepository
{
    /**
     * Load the group one content entry belongs to.
     *
     * @param   SiteContext  $site       Site the entry and every sibling must belong to.
     * @param   string       $contentId  UUID of the content entry whose group is wanted.
     *
     * @return  ?TranslationGroup  The group with every locale of the item, or null when the entry
     *          declares no group or is not reachable in that site.
     *
     * @since   2.0.0
     */
    public function forContent(SiteContext $site, string $contentId): ?TranslationGroup;

    /**
     * Declare the group a locale of an item belongs to, creating it on first use.
     *
     * Called when a translation is authored, which is the only moment a group comes into existence. The
     * declared fallback is recorded once, with the group, rather than repeated on every member — a
     * fallback that differed between two locales of the same item would not be a fallback at all.
     *
     * @param   SiteContext  $site      Site that owns the group.
     * @param   string       $groupId   UUID identifying the logical item across locales.
     * @param   LocaleTag    $fallback  Locale served when the negotiated one is missing or unpublished.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function declareGroup(SiteContext $site, string $groupId, LocaleTag $fallback): void;
}
