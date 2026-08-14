<?php

declare(strict_types=1);

namespace Kumwe\CMS\Localization\Domain;

use InvalidArgumentException;

/**
 * Raised when a value offered as a locale is not a language tag this platform will resolve.
 *
 * Locale tags arrive from three untrusted-ish places — a stored site setting, an `Accept-Language`
 * header and an explicit request parameter — and every one of them reaches a catalogue lookup and a
 * rendered `lang` attribute. Refusing a malformed tag by name here keeps a crafted value from
 * becoming a file path, a formatter argument or markup, and gives the operator a message that says
 * which tag was rejected rather than a generic argument error.
 *
 * @since  2.0.0
 */
final class InvalidLocaleTag extends InvalidArgumentException
{
    /**
     * State that a candidate does not match the language-tag grammar.
     *
     * @param   string  $candidate  Value that was offered as a locale tag.
     *
     * @return  self  Exception naming the rejected candidate.
     *
     * @since   2.0.0
     */
    public static function malformed(string $candidate): self
    {
        return new self(sprintf(
            'The locale tag %s is not a language subtag with optional script and region subtags.',
            self::quote($candidate),
        ));
    }

    /**
     * State that a well-formed tag names a locale this installation does not carry.
     *
     * @param   string        $candidate  Well-formed tag that matched no supported locale.
     * @param   list<string>  $supported  Locales this installation does carry, in declaration order.
     *
     * @return  self  Exception naming the rejected tag and what is available instead.
     *
     * @since   2.0.0
     */
    public static function unsupported(string $candidate, array $supported): self
    {
        return new self(sprintf(
            'The locale tag %s is not one this installation carries. Supported locales are %s.',
            self::quote($candidate),
            implode(', ', $supported),
        ));
    }

    /**
     * Render a rejected candidate safely enough to appear in an operator-facing message.
     *
     * The candidate may have come from a request header, so it is truncated and stripped of anything
     * outside printable ASCII before it is quoted into a sentence a log or a console will show.
     *
     * @param   string  $candidate  Raw value as it was offered.
     *
     * @return  string  The value between quotation marks, bounded and printable.
     *
     * @since   2.0.0
     */
    private static function quote(string $candidate): string
    {
        $printable = preg_replace('/[^\x20-\x7E]/', '?', substr($candidate, 0, 64));

        return '"' . ($printable ?? '') . '"';
    }
}
