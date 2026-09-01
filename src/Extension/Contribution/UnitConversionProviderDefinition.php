<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use Kumwe\Extension\Spi\Contribution\ContributionDefinition;
use InvalidArgumentException;
use Kumwe\Conversion\Value\UnitConversionFactor;
use Kumwe\Conversion\Contract\UnitConversionRequest;

/**
 * What a package declares before any of its code is allowed to convert between units of measure.
 *
 * Core ships no conversion table, no unit standard and no packaging policy, so every factor in a Kumwe
 * installation arrives from a package that said in its signed manifest that it would supply one. The
 * declaration is what makes that inspectable before install: an operator can read which units a package
 * claims to relate and where it sits in the resolution order, without running it. A metric standards
 * table, a trade-unit table administered by hand and a supplier's contractual case size all declare
 * through this same shape.
 *
 * The unit list is a closed claim, not a hint. A conversion whose source or target unit is outside it is
 * not offered to that provider at all, so a package cannot quietly widen its reach after admission by
 * changing its runtime behaviour.
 *
 * @since  2.0.0
 */
final readonly class UnitConversionProviderDefinition implements ContributionDefinition
{
    /**
     * Units one provider may claim, which bounds both the manifest and the resolution scan.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_UNITS = 64;

    /**
     * Declared unit identifiers, deduplicated and sorted so two orderings declare the same thing.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $units;

    /**
     * Declare one conversion provider, the units it relates, and where it sits in resolution order.
     *
     * @param   string        $providerId  Namespaced identifier inside the declaring package's namespace.
     * @param   list<string>  $units       Portable unit identifiers this provider is prepared to convert between.
     * @param   int           $priority    Resolution order, lowest first, between -128 and 127.
     *
     * @throws  InvalidArgumentException  When the identifier is not namespaced, the unit list is empty, over
     *          its bound or holds something other than a portable unit identifier, or the priority is
     *          outside its range.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $providerId,
        array $units,
        private int $priority = 0,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+){1,15}$/D', $providerId) !== 1) {
            throw new InvalidArgumentException('A unit conversion provider identifier must be namespaced.');
        }
        if ($units === [] || count($units) > self::MAXIMUM_UNITS) {
            throw new InvalidArgumentException('A unit conversion provider must declare between one and 64 units.');
        }
        foreach ($units as $unit) {
            if (preg_match(UnitConversionFactor::UNIT_PATTERN, $unit) !== 1) {
                throw new InvalidArgumentException(
                    'A unit conversion provider unit must be a bounded portable identifier.',
                );
            }
        }
        if ($priority < -128 || $priority > 127) {
            throw new InvalidArgumentException('A unit conversion provider priority is outside its declared range.');
        }
        $units = array_values(array_unique($units));
        sort($units, SORT_STRING);
        $this->units = $units;
    }

    /**
     * The identifier this provider is registered, resolved, and attributed under.
     *
     * @return  string  Namespaced provider identity, matching the `provider` on every factor it supplies.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->providerId;
    }

    /**
     * Where this provider sits when more than one package can relate the same pair of units.
     *
     * @return  int  Lowest first; equal priorities resolve in identifier order.
     *
     * @since   2.0.0
     */
    public function priority(): int
    {
        return $this->priority;
    }

    /**
     * Whether this declaration admits a conversion at all, before the provider itself is consulted.
     *
     * @param   UnitConversionRequest  $request  Conversion a caller is looking for a factor for.
     *
     * @return  bool  True only when both the stored and the target unit are declared.
     *
     * @since   2.0.0
     */
    public function relates(UnitConversionRequest $request): bool
    {
        return in_array($request->quantity->unit, $this->units, true)
            && in_array($request->targetUnit, $this->units, true);
    }

    /**
     * Serialize the declaration for the signed manifest, the runtime publication, and inventory.
     *
     * @return  array{provider_id: string, units: list<string>, priority: int}  Canonical declaration.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'provider_id' => $this->providerId,
            'units' => $this->units,
            'priority' => $this->priority,
        ];
    }

    /**
     * Reconstitute the declaration from validated manifest data.
     *
     * @param   array<string, mixed>  $data  Declaration as `toArray()` produced it.
     *
     * @return  self  Validated unit-conversion declaration.
     *
     * @throws  InvalidArgumentException  When a member is missing, extra, or mistyped.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        $expected = ['provider_id', 'units', 'priority'];
        if (array_diff($expected, array_keys($data)) !== [] || array_diff(array_keys($data), $expected) !== []) {
            throw new InvalidArgumentException(
                'A unit conversion provider declaration must carry exactly its members.',
            );
        }
        $providerId = $data['provider_id'];
        $units = $data['units'];
        $priority = $data['priority'];
        if (!is_string($providerId) || !is_array($units) || !array_is_list($units) || !is_int($priority)) {
            throw new InvalidArgumentException('A unit conversion provider declaration member has the wrong type.');
        }
        $identifiers = [];
        foreach ($units as $unit) {
            if (!is_string($unit)) {
                throw new InvalidArgumentException('A unit conversion provider unit must be a string.');
            }
            $identifiers[] = $unit;
        }

        return new self($providerId, $identifiers, $priority);
    }
}
