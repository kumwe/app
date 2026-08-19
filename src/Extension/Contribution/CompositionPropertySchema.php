<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use InvalidArgumentException;

/**
 * The bounded property schema one composition block declares, held to the published schema profile.
 *
 * A block's properties are the only structure its instances may carry in a stored composition document,
 * so this is where the platform's rule that stored composition holds bounded typed structure — never
 * markup, styles or code — is enforced for extension blocks. Every property names a type from the closed
 * `CompositionPropertyType` vocabulary and carries exactly the bound that type requires: a length for
 * text, a range for numbers, a closed value list for a choice, a platform artifact kind for a reference.
 * A property with no bound is not a looser declaration; it is a refused one, at admission and again at
 * install, before any runtime exists to consume it.
 *
 * Properties are keyed and sorted by name so two manifests declaring the same schema in a different
 * order export the same bytes and reconcile identically.
 *
 * @since  2.0.0
 */
final readonly class CompositionPropertySchema
{
    /**
     * Properties one block may declare, which bounds the manifest and every stored instance.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_PROPERTIES = 32;

    /**
     * Longest declarable single-line string, in characters.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_STRING_LENGTH = 500;

    /**
     * Longest declarable multi-line text run, in characters.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_TEXT_LENGTH = 10000;

    /**
     * Values one choice property may enumerate, which keeps the closed list a readable claim.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_CHOICE_VALUES = 32;

    /**
     * Platform artifact kinds a reference property may point at.
     *
     * The list is closed because a reference is a claim on an authoritative platform surface: content,
     * media and business records all live behind existing contracts, and a reference to anything else
     * would be a reference the platform cannot police.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const array REFERENCE_KINDS = ['business_record', 'content', 'media'];

    /**
     * Canonical property specifications, keyed and sorted by property name.
     *
     * @var    array<string, array<string, mixed>>
     * @since  2.0.0
     */
    public array $properties;

    /**
     * Validate one block's declared properties against the published schema profile.
     *
     * @param   array<string, array<string, mixed>>  $properties  Property specifications keyed by name, each
     *          naming its type and carrying exactly the bound that type requires.
     *
     * @throws  InvalidArgumentException  When there are too many properties, a name is malformed, a type is
     *          outside the closed vocabulary, or a specification is missing, exceeding, or violating the
     *          bound its type requires.
     *
     * @since   2.0.0
     */
    public function __construct(array $properties)
    {
        if (count($properties) > self::MAXIMUM_PROPERTIES) {
            throw new InvalidArgumentException(
                'A composition block property schema must declare at most 32 properties.',
            );
        }
        $canonical = [];
        foreach ($properties as $name => $specification) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/D', $name) !== 1) {
                throw new InvalidArgumentException(
                    'A composition block property name must be a bounded lowercase identifier.',
                );
            }
            if (strlen($name) > 64) {
                throw new InvalidArgumentException('A composition block property name is too long.');
            }
            $canonical[$name] = $this->specification($name, $specification);
        }
        ksort($canonical, SORT_STRING);
        $this->properties = $canonical;
    }

    /**
     * Serialize the schema for the signed manifest, the runtime publication, and inventory.
     *
     * @return  array<string, array<string, mixed>>  Canonical property specifications keyed by name.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return $this->properties;
    }

    /**
     * Reconstitute the schema from validated manifest data.
     *
     * @param   array<string, mixed>  $data  Property map exactly as `toArray()` produced it.
     *
     * @return  self  Validated bounded property schema.
     *
     * @throws  InvalidArgumentException  When a property specification is not an object, or any
     *          specification fails the profile's checks.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        $properties = [];
        foreach ($data as $name => $specification) {
            if (!is_array($specification) || array_is_list($specification)) {
                throw new InvalidArgumentException(
                    'A composition block property specification must be an object.',
                );
            }
            /** @var array<string, mixed> $specification */
            $properties[(string) $name] = $specification;
        }

        return new self($properties);
    }

    /**
     * Hold one property specification to the exact member set and bound its declared type requires.
     *
     * @param   string                $name           Property being validated, named in every failure.
     * @param   array<string, mixed>  $specification  Declared specification for that property.
     *
     * @return  array<string, mixed>  The specification with its members in canonical order.
     *
     * @throws  InvalidArgumentException  When the type is unknown, a member is missing or foreign, or a
     *          bound is absent, mistyped, or outside the profile's limits.
     *
     * @since   2.0.0
     */
    private function specification(string $name, array $specification): array
    {
        $type = CompositionPropertyType::tryFrom(is_string($specification['type'] ?? null)
            ? $specification['type']
            : '');
        if ($type === null) {
            throw new InvalidArgumentException(sprintf(
                'Composition block property %s does not name a published property type.',
                $name,
            ));
        }
        $required = $specification['required'] ?? null;
        if (!is_bool($required)) {
            throw new InvalidArgumentException(sprintf(
                'Composition block property %s must declare required as a boolean.',
                $name,
            ));
        }
        $expected = match ($type) {
            CompositionPropertyType::String,
            CompositionPropertyType::Text => ['maximum_length', 'required', 'type'],
            CompositionPropertyType::Integer,
            CompositionPropertyType::Number => ['maximum', 'minimum', 'required', 'type'],
            CompositionPropertyType::Boolean => ['required', 'type'],
            CompositionPropertyType::Choice => ['required', 'type', 'values'],
            CompositionPropertyType::Reference => ['kind', 'required', 'type'],
        };
        $declared = array_keys($specification);
        sort($declared, SORT_STRING);
        if ($declared !== $expected) {
            throw new InvalidArgumentException(sprintf(
                'Composition block property %s must carry exactly the members its type requires.',
                $name,
            ));
        }
        $canonical = ['type' => $type->value, 'required' => $required];

        return match ($type) {
            CompositionPropertyType::String => $canonical + [
                'maximum_length' => $this->length($name, $specification, self::MAXIMUM_STRING_LENGTH),
            ],
            CompositionPropertyType::Text => $canonical + [
                'maximum_length' => $this->length($name, $specification, self::MAXIMUM_TEXT_LENGTH),
            ],
            CompositionPropertyType::Integer,
            CompositionPropertyType::Number => $canonical + $this->range($name, $specification),
            CompositionPropertyType::Boolean => $canonical,
            CompositionPropertyType::Choice => $canonical + ['values' => $this->values($name, $specification)],
            CompositionPropertyType::Reference => $canonical + ['kind' => $this->kind($name, $specification)],
        };
    }

    /**
     * Read a text property's declared maximum length and hold it to the profile's ceiling.
     *
     * @param   string                $name           Property being validated, named in every failure.
     * @param   array<string, mixed>  $specification  Declared specification carrying `maximum_length`.
     * @param   int                   $ceiling        Profile ceiling for this property type.
     *
     * @return  int  Declared maximum length, between one and the ceiling.
     *
     * @throws  InvalidArgumentException  When the length is not an integer inside the profile's bound.
     *
     * @since   2.0.0
     */
    private function length(string $name, array $specification, int $ceiling): int
    {
        $length = $specification['maximum_length'] ?? null;
        if (!is_int($length) || $length < 1 || $length > $ceiling) {
            throw new InvalidArgumentException(sprintf(
                'Composition block property %s must bound its length between 1 and %d.',
                $name,
                $ceiling,
            ));
        }

        return $length;
    }

    /**
     * Read a numeric property's declared inclusive range.
     *
     * @param   string                $name           Property being validated, named in every failure.
     * @param   array<string, mixed>  $specification  Declared specification carrying `minimum` and `maximum`.
     *
     * @return  array{maximum: int, minimum: int}  Declared range in canonical member order.
     *
     * @throws  InvalidArgumentException  When either end is not an integer, or the range is inverted.
     *
     * @since   2.0.0
     */
    private function range(string $name, array $specification): array
    {
        $minimum = $specification['minimum'] ?? null;
        $maximum = $specification['maximum'] ?? null;
        if (!is_int($minimum) || !is_int($maximum) || $minimum > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'Composition block property %s must declare an ordered integer range.',
                $name,
            ));
        }

        return ['maximum' => $maximum, 'minimum' => $minimum];
    }

    /**
     * Read a choice property's closed value list, deduplicated and sorted.
     *
     * @param   string                $name           Property being validated, named in every failure.
     * @param   array<string, mixed>  $specification  Declared specification carrying `values`.
     *
     * @return  non-empty-list<string>  Distinct declared values in sorted order.
     *
     * @throws  InvalidArgumentException  When the list is empty, over its bound, repeats a value, or holds
     *          anything other than a bounded non-empty string.
     *
     * @since   2.0.0
     */
    private function values(string $name, array $specification): array
    {
        $values = $specification['values'] ?? null;
        if (
            !is_array($values)
            || !array_is_list($values)
            || $values === []
            || count($values) > self::MAXIMUM_CHOICE_VALUES
        ) {
            throw new InvalidArgumentException(sprintf(
                'Composition block property %s must enumerate between 1 and 32 choice values.',
                $name,
            ));
        }
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value) || $value === '' || strlen($value) > 120) {
                throw new InvalidArgumentException(sprintf(
                    'Composition block property %s choice values must be bounded non-empty strings.',
                    $name,
                ));
            }
            $result[] = $value;
        }
        if ($result !== array_unique($result)) {
            throw new InvalidArgumentException(sprintf(
                'Composition block property %s repeats a choice value.',
                $name,
            ));
        }
        sort($result, SORT_STRING);

        return $result;
    }

    /**
     * Read a reference property's declared platform artifact kind.
     *
     * @param   string                $name           Property being validated, named in every failure.
     * @param   array<string, mixed>  $specification  Declared specification carrying `kind`.
     *
     * @return  string  One of the closed reference kinds.
     *
     * @throws  InvalidArgumentException  When the kind is outside the closed list.
     *
     * @since   2.0.0
     */
    private function kind(string $name, array $specification): string
    {
        $kind = $specification['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, self::REFERENCE_KINDS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Composition block property %s must reference a platform artifact kind.',
                $name,
            ));
        }

        return $kind;
    }
}
