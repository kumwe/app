<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Domain;

use InvalidArgumentException;

/**
 * The rendering surface a theme is installed for and activated against.
 *
 * A template extension declares one of these in its manifest, and almost every theme operation branches
 * on it: which Twig loader chain is built, which template files the package validator insists on, which
 * `themes.*.manage` capability applies, and whether activation demands step-up authentication. The two
 * surfaces are governed differently — the site surface is activated per site, while the administrator
 * surface is a single global assignment that can lock operators out of the back office if it breaks —
 * so code that treats them interchangeably is a bug.
 *
 * @since  2.0.0
 */
enum ThemeSurface: string
{
    /**
     * The public front end, whose theme is activated per site and may be replaced freely.
     *
     * @since  2.0.0
     */
    case Site = 'site';

    /**
     * The back office, whose single global theme assignment is protected and recoverable by console.
     *
     * @since  2.0.0
     */
    case Administrator = 'administrator';

    /**
     * Resolves a surface supplied by an operator, treating an absent value as "no surface given".
     *
     * Callers pass request form fields, console options, and MCP arguments straight in: the value is
     * trimmed and lower-cased before matching, so casing and stray spaces are tolerated, but a non-empty
     * value that names no surface is rejected rather than silently ignored.
     *
     * @param   ?string  $value  Raw surface name from operator input, or null when the field is absent.
     *
     * @return  ?self  The named surface, or null when the caller supplied nothing to resolve.
     *
     * @throws  InvalidArgumentException  When the value is not blank but names neither surface.
     *
     * @since   2.0.0
     */
    public static function optional(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)))
            ?? throw new InvalidArgumentException('A theme surface must be site or administrator.');
    }
}
