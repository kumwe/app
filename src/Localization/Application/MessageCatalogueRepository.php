<?php

declare(strict_types=1);

namespace Kumwe\CMS\Localization\Application;

use Kumwe\CMS\Localization\Domain\LocaleTag;
use Kumwe\CMS\Localization\Domain\MessageCatalogue;
use Kumwe\CMS\Localization\Domain\MessageCatalogueLayer;

/**
 * Port supplying the two file-shipped layers of the chain: what core ships and what extensions ship.
 *
 * These layers are build output rather than administered state, so an implementation reads them
 * from compiled PHP and never parses XLIFF: the authored format is for translators and translation
 * platforms, and the request path sees only an array the opcode cache already holds. A caller asks
 * for one layer at one locale and receives an empty catalogue rather than null when nothing is
 * shipped, so the resolver walks a uniform chain.
 *
 * @since  2.0.0
 */
interface MessageCatalogueRepository
{
    /**
     * Read one file-shipped layer for one locale.
     *
     * @param   MessageCatalogueLayer  $layer   Either the core layer or the extension layer.
     * @param   LocaleTag              $locale  Exact locale to read; fallback is the resolver's job.
     *
     * @return  MessageCatalogue  The layer's messages, empty when it ships none at this locale.
     *
     * @throws  \InvalidArgumentException  When asked for a layer this port does not serve.
     *
     * @since   2.0.0
     */
    public function catalogue(MessageCatalogueLayer $layer, LocaleTag $locale): MessageCatalogue;
}
