<?php

declare(strict_types=1);

namespace Kumwe\CMS\Localization\Domain;

/**
 * The messages one layer carries for one locale, as an immutable identifier-to-pattern map.
 *
 * This is the runtime shape, not the authored one: patterns arrive already extracted from XLIFF by
 * the build, so a lookup is an array access against a structure the opcode cache already holds. It
 * carries no formatting behaviour and no fallback behaviour of its own — a catalogue answers only
 * "do I have this identifier, and what is its pattern" — because the order layers are consulted in
 * belongs to the resolver and the substitution belongs to the formatter.
 *
 * @since  2.0.0
 */
final readonly class MessageCatalogue
{
    /**
     * Hold one layer's messages for one locale.
     *
     * @param  LocaleTag              $locale    Locale these patterns are written in.
     * @param  MessageCatalogueLayer  $layer     Chain step this catalogue occupies.
     * @param  array<string, string>  $messages  ICU MessageFormat patterns, keyed by message identifier.
     *
     * @since  2.0.0
     */
    public function __construct(
        public LocaleTag $locale,
        public MessageCatalogueLayer $layer,
        public array $messages,
    ) {
    }

    /**
     * An empty catalogue for a layer that carries nothing at this locale.
     *
     * Absence is expressed as an empty catalogue rather than as null so that a resolver walks a
     * uniform chain instead of branching on which layers happen to exist for a given site.
     *
     * @param   LocaleTag              $locale  Locale the empty catalogue stands in for.
     * @param   MessageCatalogueLayer  $layer   Chain step the empty catalogue occupies.
     *
     * @return  self  A catalogue carrying no messages.
     *
     * @since   2.0.0
     */
    public static function empty(LocaleTag $locale, MessageCatalogueLayer $layer): self
    {
        return new self($locale, $layer, []);
    }

    /**
     * Whether this catalogue carries a pattern for an identifier.
     *
     * @param   string  $identifier  Message identifier to test.
     *
     * @return  bool  True when the identifier is present in this layer.
     *
     * @since   2.0.0
     */
    public function has(string $identifier): bool
    {
        return isset($this->messages[$identifier]);
    }

    /**
     * Read the pattern filed under an identifier.
     *
     * @param   string  $identifier  Message identifier to read.
     *
     * @return  ?string  The ICU pattern, or null when this layer does not carry the identifier.
     *
     * @since   2.0.0
     */
    public function pattern(string $identifier): ?string
    {
        return $this->messages[$identifier] ?? null;
    }

    /**
     * Every identifier this catalogue carries.
     *
     * @return  list<string>  Identifiers in the order the catalogue stores them, which compilation
     *          fixed as ascending byte order.
     *
     * @since   2.0.0
     */
    public function identifiers(): array
    {
        return array_keys($this->messages);
    }

    /**
     * How many messages this catalogue carries.
     *
     * @return  int<0, max>  Count of filed identifiers.
     *
     * @since   2.0.0
     */
    public function count(): int
    {
        return count($this->messages);
    }
}
