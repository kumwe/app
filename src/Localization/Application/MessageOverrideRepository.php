<?php

declare(strict_types=1);

namespace Kumwe\CMS\Localization\Application;

use Kumwe\CMS\Localization\Domain\LocaleTag;

/**
 * Port supplying the two administered layers of the chain: a site's wording and an organization's.
 *
 * These are the layers an operator owns. They exist so that changing one word — relabelling
 * "Client" as "Patient" for a health vertical, as "Learner" for education, as "Guest" for
 * hospitality — is an administrative act rather than a fork of a catalogue, in one language or in
 * all of them. An implementation returns the whole bounded override map for a scope in one call
 * rather than answering per identifier, because the render path resolves hundreds of messages and
 * an override chain that becomes a lookup per message is a scale defect wearing a feature's name.
 *
 * @since  2.0.0
 */
interface MessageOverrideRepository
{
    /**
     * Read every override a site has recorded for one locale.
     *
     * @param   string     $site    Site identifier the overrides belong to.
     * @param   LocaleTag  $locale  Exact locale to read.
     *
     * @return  array<string, string>  ICU patterns keyed by message identifier, empty when the site
     *          has overridden nothing.
     *
     * @since   2.0.0
     */
    public function siteOverrides(string $site, LocaleTag $locale): array;

    /**
     * Read every override an organization within a site has recorded for one locale.
     *
     * @param   string     $site          Site the organization belongs to.
     * @param   string     $organization  Organization identifier the overrides belong to.
     * @param   LocaleTag  $locale        Exact locale to read.
     *
     * @return  array<string, string>  ICU patterns keyed by message identifier, empty when the
     *          organization has overridden nothing.
     *
     * @since   2.0.0
     */
    public function organizationOverrides(string $site, string $organization, LocaleTag $locale): array;
}
