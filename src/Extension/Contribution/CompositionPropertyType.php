<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

/**
 * The closed vocabulary of property types a composition block may declare.
 *
 * This is the published composition schema profile's type list, fixed at Gate A so the contract an
 * extension author reads and the contract the Gate B runtime enforces are the same list. Every type
 * carries its own boundedness obligation — a string carries a maximum length, a number carries its
 * range, a choice carries its closed value list — which `CompositionPropertySchema` enforces at
 * declaration time. There is deliberately no open or structured type: a block property can never
 * smuggle unbounded structure, markup or code into a stored composition document.
 *
 * @since  2.0.0
 */
enum CompositionPropertyType: string
{
    /**
     * A single line of plain text, bounded by a declared maximum length.
     *
     * @since  2.0.0
     */
    case String = 'string';

    /**
     * A multi-line run of plain text, bounded by a declared maximum length.
     *
     * @since  2.0.0
     */
    case Text = 'text';

    /**
     * A whole number bounded by a declared inclusive minimum and maximum.
     *
     * @since  2.0.0
     */
    case Integer = 'integer';

    /**
     * A decimal number bounded by a declared inclusive minimum and maximum.
     *
     * @since  2.0.0
     */
    case Number = 'number';

    /**
     * A true-or-false flag, bounded by its own two values.
     *
     * @since  2.0.0
     */
    case Boolean = 'boolean';

    /**
     * One value out of a closed declared list, the profile's enumeration type.
     *
     * @since  2.0.0
     */
    case Choice = 'choice';

    /**
     * A reference to a platform-owned artifact of a declared kind, never an inline copy of one.
     *
     * @since  2.0.0
     */
    case Reference = 'reference';
}
