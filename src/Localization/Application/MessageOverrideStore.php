<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Application;

use Kumwe\App\Localization\Domain\LocaleTag;
use Kumwe\App\Localization\Domain\MessageCatalogueLayer;

/**
 * Write face of the two administered override layers, kept apart from the read face the chain uses.
 *
 * `MessageOverrideRepository` answers the render path and is shaped for it: whole scope, one call, no
 * bookkeeping. This is the other half — what an administration surface needs to list, write and
 * withdraw an override. They are separate ports because they have opposite pressures: the read side
 * is on the hot path and must stay a single bounded fetch, while the write side is rare, per
 * identifier, and needs to say when and by whom.
 *
 * An implementation stores at most one pattern per layer, scope, locale and identifier, so writing
 * the same identifier twice replaces the wording rather than accumulating rows an operator would then
 * have to reconcile.
 *
 * @since  2.0.0
 */
interface MessageOverrideStore
{
    /**
     * Serialize administered wording mutations for one site inside the caller's transaction.
     *
     * The quota is scoped more narrowly than a site, but locking the site's durable identity gives even
     * an empty override scope a row all three supported engines can lock. Wording writes are rare, so the
     * deliberately coarse lock is preferable to a race-prone count or a second quota-ledger table.
     *
     * @param   string  $site  Site whose wording mutation is about to count and write.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function lockSite(string $site): void;

    /**
     * List every override stored for one scope, newest wording included.
     *
     * @param   MessageCatalogueLayer  $layer         Administered layer to list, `Site` or `Organization`.
     * @param   string                 $site          Site the scope belongs to.
     * @param   ?string                $organization  Organization within that site, or null at site level.
     * @param   ?LocaleTag             $locale        Restrict to one locale, or null for every locale.
     *
     * @return  list<MessageOverrideRecord>  Stored overrides ordered by locale and then identifier, so a
     *          screen renders them in a stable order across reads.
     *
     * @since   2.0.0
     */
    public function overrides(
        MessageCatalogueLayer $layer,
        string $site,
        ?string $organization = null,
        ?LocaleTag $locale = null,
    ): array;

    /**
     * Store or replace the wording one layer carries for one identifier at one locale.
     *
     * @param   MessageOverrideRecord  $override  The override to write, replacing any pattern already
     *          stored for the same layer, scope, locale and identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function put(MessageOverrideRecord $override): void;

    /**
     * Withdraw one override so the layer below it answers again.
     *
     * @param   MessageCatalogueLayer  $layer         Administered layer the override sits in.
     * @param   string                 $site          Site the scope belongs to.
     * @param   ?string                $organization  Organization within that site, or null at site level.
     * @param   LocaleTag              $locale        Locale the override applies to.
     * @param   string                 $identifier    Message identifier to stop overriding.
     *
     * @return  bool  True when a row was removed, false when nothing was stored for that combination.
     *
     * @since   2.0.0
     */
    public function remove(
        MessageCatalogueLayer $layer,
        string $site,
        ?string $organization,
        LocaleTag $locale,
        string $identifier,
    ): bool;
}
