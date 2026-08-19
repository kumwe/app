<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Domain;

/**
 * The stable, namespaced name a translated message is looked up by, never the message's own text.
 *
 * This is the frozen half of the translation contract. A catalogue in nine languages files every
 * translation under this value, so it has to survive an English wording change: if the identifier
 * were the source text, correcting a typographical error in English would orphan that message in
 * eight other languages and every translator would redo work for a change that altered no meaning.
 * The grammar is deliberately the one `ContributionOwner` already applies to every other contributed
 * identifier — `core.` for what the CMS ships, `vendor.name.` for what an extension ships — so an
 * extension author learns one namespacing rule rather than two.
 *
 * @since  2.0.0
 */
final readonly class MessageIdentifier
{
    /**
     * Longest identifier the catalogue will file, in bytes.
     *
     * The bound exists so that an identifier is always usable as an array key, a log field and an
     * XLIFF unit attribute without truncation anywhere in the pipeline.
     *
     * @var    int
     * @since  2.0.0
     */
    public const MAXIMUM_LENGTH = 190;

    /**
     * Fewest dotted segments an identifier may carry.
     *
     * Three is the point at which an identifier names an owner, an area and a message rather than
     * just a word, which is what keeps two unrelated surfaces from colliding on `core.save`.
     *
     * @var    int
     * @since  2.0.0
     */
    public const MINIMUM_SEGMENTS = 3;

    /**
     * Hold an identifier the factories have already validated.
     *
     * @param  string  $value  Complete dotted identifier, lowercase.
     *
     * @since  2.0.0
     */
    private function __construct(public string $value)
    {
    }

    /**
     * Validate an identifier against the grammar alone.
     *
     * Use this where the owner is not in question — reading a compiled catalogue, or checking a
     * reference found in a template. Where a contributor is claiming the identifier, use
     * `ownedBy()` so the namespace is proven too.
     *
     * @param   string  $identifier  Candidate identifier, such as `core.administrator.settings.save_action`.
     *
     * @return  self  The validated identifier.
     *
     * @throws  InvalidMessageIdentifier  When the value reads as source text, or does not match the
     *          dotted lowercase grammar, or carries fewer than three segments.
     *
     * @since   2.0.0
     */
    public static function fromString(string $identifier): self
    {
        if (self::readsAsSourceText($identifier)) {
            throw InvalidMessageIdentifier::sourceText($identifier);
        }
        if (!self::matchesGrammar($identifier)) {
            throw InvalidMessageIdentifier::malformed($identifier);
        }

        return new self($identifier);
    }

    /**
     * Validate an identifier and prove that its contributor may claim it.
     *
     * @param   string  $identifier  Candidate identifier the contributor wants to register.
     * @param   string  $namespace   Dotted namespace the contributor owns: `core`, or `vendor.name`.
     *
     * @return  self  The validated identifier, guaranteed to sit under `$namespace`.
     *
     * @throws  InvalidMessageIdentifier  When the grammar is broken, or the identifier sits outside
     *          the contributor's namespace.
     *
     * @since   2.0.0
     */
    public static function ownedBy(string $identifier, string $namespace): self
    {
        $validated = self::fromString($identifier);
        if (!str_starts_with($validated->value, $namespace . '.')) {
            throw InvalidMessageIdentifier::outsideNamespace($identifier, $namespace);
        }

        return $validated;
    }

    /**
     * Whether a candidate would be accepted, without raising when it would not.
     *
     * This is what the extraction gate and the catalogue compiler use to report every offending
     * identifier in one pass instead of stopping at the first.
     *
     * @param   string  $identifier  Candidate identifier.
     *
     * @return  bool  True when `fromString()` would accept the value.
     *
     * @since   2.0.0
     */
    public static function isValid(string $identifier): bool
    {
        return !self::readsAsSourceText($identifier) && self::matchesGrammar($identifier);
    }

    /**
     * The owner root the identifier claims, which is `core` or an extension's vendor segment.
     *
     * @return  string  The first dotted segment.
     *
     * @since   2.0.0
     */
    public function root(): string
    {
        return substr($this->value, 0, (int) strpos($this->value, '.'));
    }

    /**
     * Decide whether a candidate is prose rather than an identifier.
     *
     * The grammar below would already refuse every one of these, but doing so would report "this is
     * not three dotted lowercase segments" when the useful sentence is "you used the English string
     * as the key". Whitespace, a capital letter and terminal punctuation are what separate a
     * sentence from a name, so they are tested first and reported by their own name.
     *
     * @param   string  $identifier  Candidate identifier.
     *
     * @return  bool  True when the value reads as source text.
     *
     * @since   2.0.0
     */
    private static function readsAsSourceText(string $identifier): bool
    {
        return preg_match('/\s/', $identifier) === 1
            || preg_match('/[A-Z]/', $identifier) === 1
            || preg_match('/[.!?:,;]$/D', $identifier) === 1;
    }

    /**
     * Decide whether a candidate matches the dotted lowercase grammar and its bounds.
     *
     * @param   string  $identifier  Candidate identifier.
     *
     * @return  bool  True when the value is within bounds and matches the segment grammar.
     *
     * @since   2.0.0
     */
    private static function matchesGrammar(string $identifier): bool
    {
        if ($identifier === '' || strlen($identifier) > self::MAXIMUM_LENGTH) {
            return false;
        }
        if (substr_count($identifier, '.') < self::MINIMUM_SEGMENTS - 1) {
            return false;
        }

        return preg_match('/^[a-z0-9][a-z0-9_-]*(?:\.[a-z0-9][a-z0-9_-]*)*$/D', $identifier) === 1;
    }
}
