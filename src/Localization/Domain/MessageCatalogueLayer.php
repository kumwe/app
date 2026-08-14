<?php

declare(strict_types=1);

namespace Kumwe\CMS\Localization\Domain;

/**
 * One step of the override chain a message identifier is resolved through.
 *
 * The chain exists for two reasons that look like one. The obvious one is translation: core ships
 * the base wording and everything above it corrects or completes it. The strategic one is
 * terminology adaptation — a health vertical relabelling "Client" as "Patient", an education
 * vertical as "Learner", a hospitality vertical as "Guest" — in one language or in all of them,
 * without forking core and without an extension shipping a parallel string table. That is why the
 * chain has four steps rather than two, and why resolution is per identifier rather than per file:
 * an operator changes one word without taking ownership of a catalogue.
 *
 * @since  2.0.0
 */
enum MessageCatalogueLayer: string
{
    /**
     * The base catalogue the CMS itself ships, which every other layer refines.
     *
     * @since  2.0.0
     */
    case Core = 'core';

    /**
     * Messages an installed extension ships, which may add to and override core's.
     *
     * @since  2.0.0
     */
    case Extension = 'extension';

    /**
     * Wording an operator has changed for one site, which overrides core and extensions alike.
     *
     * @since  2.0.0
     */
    case Site = 'site';

    /**
     * Wording changed for one organization within a site, which overrides every layer below it.
     *
     * @since  2.0.0
     */
    case Organization = 'organization';

    /**
     * The layers in resolution order, most specific first.
     *
     * A resolver walks this list and returns the first layer that carries the identifier, which is
     * what makes the most specific override win without any layer being merged into another.
     *
     * @return  non-empty-list<self>  Organization, then site, then extension, then core.
     *
     * @since   2.0.0
     */
    public static function mostSpecificFirst(): array
    {
        return [self::Organization, self::Site, self::Extension, self::Core];
    }
}
