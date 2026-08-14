<?php

declare(strict_types=1);

namespace Kumwe\CMS\Localization\Domain;

/**
 * The four layers of one locale, ordered so that the first layer carrying an identifier wins.
 *
 * Resolution is per identifier, never per file, and that distinction is the whole point of the
 * chain: an operator who wants to change one word does not have to take ownership of a catalogue,
 * and an extension that overrides three of core's messages still inherits the other several
 * hundred. The layers are assembled once for a locale and a scope and then queried many times,
 * because a page resolves hundreds of messages and a chain rebuilt per message would turn a
 * feature into a scale defect.
 *
 * @since  2.0.0
 */
final readonly class MessageCatalogueChain
{
    /**
     * Hold the layers of one locale in resolution order.
     *
     * @param  LocaleTag                         $locale  Locale every layer in this chain is written in.
     * @param  non-empty-list<MessageCatalogue>  $layers  Catalogues, most specific first.
     *
     * @since  2.0.0
     */
    public function __construct(public LocaleTag $locale, public array $layers)
    {
    }

    /**
     * Read the pattern the most specific layer carrying this identifier holds.
     *
     * @param   string  $identifier  Message identifier to resolve.
     *
     * @return  ?string  The winning ICU pattern, or null when no layer of this locale carries it.
     *
     * @since   2.0.0
     */
    public function resolve(string $identifier): ?string
    {
        foreach ($this->layers as $catalogue) {
            $pattern = $catalogue->pattern($identifier);
            if ($pattern !== null) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * Which layer would answer for an identifier.
     *
     * This exists for the tests and the administration surfaces that have to show an operator where
     * a word is coming from; resolution itself does not need it.
     *
     * @param   string  $identifier  Message identifier to attribute.
     *
     * @return  ?MessageCatalogueLayer  The winning layer, or null when no layer carries the identifier.
     *
     * @since   2.0.0
     */
    public function winningLayer(string $identifier): ?MessageCatalogueLayer
    {
        foreach ($this->layers as $catalogue) {
            if ($catalogue->has($identifier)) {
                return $catalogue->layer;
            }
        }

        return null;
    }
}
