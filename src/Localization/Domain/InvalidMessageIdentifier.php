<?php

declare(strict_types=1);

namespace Kumwe\CMS\Localization\Domain;

use InvalidArgumentException;

/**
 * Raised when a message identifier does not satisfy the grammar the translation contract froze.
 *
 * The identifier is the one part of the translation contract that cannot be corrected later: once
 * eight languages carry a translation filed under it, renaming it discards that work. Each factory
 * below states which rule was broken, because the author of a new message needs to know whether the
 * identifier was shaped wrongly, was written as English prose, or claimed a namespace belonging to
 * someone else.
 *
 * @since  2.0.0
 */
final class InvalidMessageIdentifier extends InvalidArgumentException
{
    /**
     * State that an identifier does not match the dotted lowercase grammar.
     *
     * @param   string  $identifier  Value that was offered as a message identifier.
     *
     * @return  self  Exception naming the rejected value and restating the grammar.
     *
     * @since   2.0.0
     */
    public static function malformed(string $identifier): self
    {
        return new self(sprintf(
            'The message identifier %s must be three or more lowercase dotted segments, '
                . 'each starting with a letter or digit and continuing with letters, digits, '
                . 'underscores or hyphens.',
            self::quote($identifier),
        ));
    }

    /**
     * State that an identifier is the source text rather than a stable name for it.
     *
     * @param   string  $identifier  Value that reads as prose rather than as an identifier.
     *
     * @return  self  Exception naming the rejected value and why source text may never be a key.
     *
     * @since   2.0.0
     */
    public static function sourceText(string $identifier): self
    {
        return new self(sprintf(
            'The message identifier %s reads as source text. A message is looked up by a stable '
                . 'semantic identifier so that correcting the English wording never invalidates the '
                . 'translations filed under it.',
            self::quote($identifier),
        ));
    }

    /**
     * State that an identifier sits outside the namespace its contributor may claim.
     *
     * @param   string  $identifier  Identifier the contributor tried to register.
     * @param   string  $namespace   Dotted namespace the contributor is entitled to.
     *
     * @return  self  Exception naming both the identifier and the namespace it had to sit under.
     *
     * @since   2.0.0
     */
    public static function outsideNamespace(string $identifier, string $namespace): self
    {
        return new self(sprintf(
            'The message identifier %s is outside the %s namespace its contributor may claim.',
            self::quote($identifier),
            $namespace,
        ));
    }

    /**
     * Render a rejected identifier safely enough to appear in an operator-facing message.
     *
     * @param   string  $identifier  Raw value as it was offered.
     *
     * @return  string  The value between quotation marks, bounded and printable.
     *
     * @since   2.0.0
     */
    private static function quote(string $identifier): string
    {
        $printable = preg_replace('/[^\x20-\x7E]/', '?', substr($identifier, 0, 120));

        return '"' . ($printable ?? '') . '"';
    }
}
