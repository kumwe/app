<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Domain;

/**
 * A normalised language tag, the direction it is written in, and the tags it falls back through.
 *
 * Every locale that reaches a catalogue lookup, an ICU formatter or a rendered `lang` attribute
 * passes through this type first, so casing and separators are settled in one place: `pt_br`,
 * `PT-BR` and `pt-BR` are the same value, and a caller can compare two locales with `equals()`
 * rather than with a normalisation of its own. The fallback list is what lets a catalogue carry
 * `pt-BR` while a message that is identical across Portuguese variants is authored once under `pt`.
 *
 * @since  2.0.0
 */
final readonly class LocaleTag
{
    /**
     * Language subtags whose scripts are written from the right.
     *
     * The list covers the scripts this platform states support for plus the neighbouring languages
     * that share them, because a site configured with one of those must not silently render as if it
     * were left-to-right. It is keyed by subtag so a lookup is an array access.
     *
     * @var    array<string, true>
     * @since  2.0.0
     */
    private const RIGHT_TO_LEFT = [
        'ar' => true,
        'arc' => true,
        'ckb' => true,
        'dv' => true,
        'fa' => true,
        'he' => true,
        'ku' => true,
        'ps' => true,
        'sd' => true,
        'ug' => true,
        'ur' => true,
        'yi' => true,
    ];

    /**
     * Hold a tag whose parts a factory has already validated and normalised.
     *
     * @param  string   $language  Two- or three-letter language subtag, lowercase.
     * @param  ?string  $script    Four-letter script subtag in title case, or null when absent.
     * @param  ?string  $region    Two-letter or three-digit region subtag, uppercase, or null when absent.
     *
     * @since  2.0.0
     */
    private function __construct(
        public string $language,
        public ?string $script,
        public ?string $region,
    ) {
    }

    /**
     * Parse and normalise a language tag offered as a string.
     *
     * Underscores are accepted as separators because a stored setting and an operating-system locale
     * both use them, and the site settings writer already normalises them the same way. Anything
     * beyond a language, an optional script and an optional region is refused rather than truncated,
     * so an extended tag never resolves to a locale the caller did not name.
     *
     * @param   string  $tag  Candidate language tag, such as `en-GB`, `pt_br` or `zh-Hans`.
     *
     * @return  self  The normalised tag.
     *
     * @throws  InvalidLocaleTag  When the value is not a language subtag with optional script and region.
     *
     * @since   2.0.0
     */
    public static function fromString(string $tag): self
    {
        $candidate = str_replace('_', '-', trim($tag));
        $matched = preg_match(
            '/^([A-Za-z]{2,3})(?:-([A-Za-z]{4}))?(?:-([A-Za-z]{2}|[0-9]{3}))?$/D',
            $candidate,
            $parts,
        );
        if ($matched !== 1) {
            throw InvalidLocaleTag::malformed($tag);
        }

        return new self(
            strtolower($parts[1]),
            ($parts[2] ?? '') === '' ? null : ucfirst(strtolower($parts[2])),
            ($parts[3] ?? '') === '' ? null : strtoupper($parts[3]),
        );
    }

    /**
     * The canonical string form, which is what a `lang` attribute and a catalogue file name carry.
     *
     * @return  string  Language, then script, then region, joined by hyphens.
     *
     * @since   2.0.0
     */
    public function toString(): string
    {
        return implode('-', array_filter([$this->language, $this->script, $this->region], is_string(...)));
    }

    /**
     * The direction this locale's script is laid out in.
     *
     * The script subtag decides when one is present, so `az-Arab` is right-to-left while `az` is not;
     * otherwise the language subtag does. This is the value the layouts emit as `dir`.
     *
     * @return  TextDirection  Right-to-left for the Arabic and Hebrew script families, otherwise left-to-right.
     *
     * @since   2.0.0
     */
    public function direction(): TextDirection
    {
        if ($this->script !== null) {
            return in_array($this->script, ['Arab', 'Hebr', 'Thaa', 'Syrc', 'Nkoo', 'Adlm'], true)
                ? TextDirection::RightToLeft
                : TextDirection::LeftToRight;
        }

        return isset(self::RIGHT_TO_LEFT[$this->language])
            ? TextDirection::RightToLeft
            : TextDirection::LeftToRight;
    }

    /**
     * The tags a lookup tries, most specific first, before it leaves this locale entirely.
     *
     * @return  non-empty-list<string>  This tag, then the same tag with the region dropped, then the
     *          bare language subtag, without repeating a form the tag already had.
     *
     * @since   2.0.0
     */
    public function fallbacks(): array
    {
        $chain = [$this->toString()];
        if ($this->region !== null && $this->script !== null) {
            $chain[] = $this->language . '-' . $this->script;
        }
        if ($this->script !== null || $this->region !== null) {
            $chain[] = $this->language;
        }

        return array_values(array_unique($chain));
    }

    /**
     * Whether two tags name the same locale after normalisation.
     *
     * @param   self  $other  Tag to compare against.
     *
     * @return  bool  True when language, script and region all match.
     *
     * @since   2.0.0
     */
    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }
}
