<?php

declare(strict_types=1);

namespace Kumwe\CMS\Localization\Domain;

/**
 * Inline writing direction a locale's script is laid out in.
 *
 * The value is what a layout emits as its `dir` attribute, and it is the only thing the stylesheets
 * need in order to mirror themselves: every inline-axis rule in `assets/` is written with logical
 * properties, so the browser derives start and end from this one declaration rather than from a
 * second right-to-left stylesheet. It is a property of the locale's script, not of the site, so two
 * sites in one installation can render in opposite directions in the same process.
 *
 * @since  2.0.0
 */
enum TextDirection: string
{
    /**
     * Inline text runs from the start of the line on the left toward the right.
     *
     * @since  2.0.0
     */
    case LeftToRight = 'ltr';

    /**
     * Inline text runs from the start of the line on the right toward the left.
     *
     * @since  2.0.0
     */
    case RightToLeft = 'rtl';
}
