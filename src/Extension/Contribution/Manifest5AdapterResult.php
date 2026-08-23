<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use stdClass;

/**
 * The outcome of one manifest-5 compatibility translation: canonical schema or named reasons.
 *
 * The two states are mutually exclusive by construction — a translation either carried every
 * property losslessly and re-admitted under the profile, or it names each element that requires an
 * explicit schema-6 declaration. There is no partial result, because a partially translated schema
 * would silently drop the untranslated remainder.
 *
 * @since  2.0.0
 */
final readonly class Manifest5AdapterResult
{
    /**
     * Hold one outcome; use the named constructors.
     *
     * @param  stdClass|null  $schema      The complete canonical property schema, or null.
     * @param  list<string>   $unresolved  Named reasons the map stays a schema-6 authoring step.
     *
     * @since  2.0.0
     */
    private function __construct(
        public ?stdClass $schema,
        public array $unresolved,
    ) {
    }

    /**
     * A complete, admitted, lossless translation.
     *
     * @param   stdClass  $schema  The canonical closed-root property schema.
     *
     * @return  self  The translated outcome.
     *
     * @since   2.0.0
     */
    public static function translated(stdClass $schema): self
    {
        return new self($schema, []);
    }

    /**
     * A refused translation with every reason named.
     *
     * @param   list<string>  $unresolved  What requires an explicit schema-6 declaration, and why.
     *
     * @return  self  The unresolved outcome.
     *
     * @since   2.0.0
     */
    public static function unresolved(array $unresolved): self
    {
        return new self(null, array_values($unresolved));
    }
}
