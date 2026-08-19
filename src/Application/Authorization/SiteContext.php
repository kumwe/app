<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use InvalidArgumentException;

/**
 * Validated identifier of the site a request, a resource, or a stored row belongs to.
 *
 * Site separation is enforced by comparing this identifier rather than by partitioning storage:
 * `DenyByDefaultAuthorizationGateway` refuses an action whose resource is owned by a site other than
 * the one on the `ExecutionContext`, and repositories bind it into their queries to scope a listing.
 * The constructor is private, so every instance has come through `fromString()` or `default()` and is
 * already trimmed, lowercased, and matched against a narrow identifier pattern — callers may compare
 * two identifiers byte for byte and bind one into a query without further checking.
 *
 * @since  2.0.0
 */
final readonly class SiteContext
{
    /**
     * Identifier of the site that always exists, whatever the installation is configured for.
     *
     * Exposed as a constant as well as through `default()` so that repositories can bind the literal
     * into a query, and compare a stored `site_identifier` against it, without building a value object.
     *
     * @var    string
     * @since  2.0.0
     */
    public const DEFAULT = 'default';

    /**
     * Hold an already validated site identifier.
     *
     * @param  string  $identifier  Normalised identifier, as produced by `fromString()` or `default()`.
     *
     * @since  2.0.0
     */
    private function __construct(private string $identifier)
    {
    }

    /**
     * Name the site a single-site installation runs under, and that installation-wide records own.
     *
     * Identity records — users, roles, grants — and installation-global automation are recorded against
     * this site, so ownership lookups resolve for them even where an installation serves several sites.
     *
     * @return  self  Context for the `default` site.
     *
     * @since   2.0.0
     */
    public static function default(): self
    {
        return new self(self::DEFAULT);
    }

    /**
     * Build a site context from an identifier read out of configuration, a request, or a stored row.
     *
     * Surrounding whitespace is stripped and the value is lowercased before validation, so identifiers
     * differing only in case or padding resolve to one site instead of silently splitting its data.
     *
     * @param   string  $identifier  Raw site identifier to normalise and validate.
     *
     * @return  self  Context carrying the normalised identifier.
     *
     * @throws  InvalidArgumentException  When the normalised value is empty, runs past 191 characters,
     *          starts with something other than a lowercase letter or digit, or holds a character
     *          outside `a-z`, `0-9`, `.`, `_`, `:` and `-`.
     *
     * @since   2.0.0
     */
    public static function fromString(string $identifier): self
    {
        $identifier = strtolower(trim($identifier));

        if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,190}$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('A site context must be a valid non-empty identifier.');
        }

        return new self($identifier);
    }

    /**
     * Expose the normalised identifier for comparison, query binding, and logging.
     *
     * @return  string  Lowercase identifier, safe to compare byte for byte against a stored value.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->identifier;
    }
}
