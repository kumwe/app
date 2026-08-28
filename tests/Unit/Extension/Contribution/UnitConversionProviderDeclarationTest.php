<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Contribution;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\Conversion\Decimal\ExactDecimal;
use Kumwe\Conversion\Value\QuantityRoundingMode;
use Kumwe\Conversion\Value\QuantityValue;
use Kumwe\Conversion\Contract\UnitConversionRequest;
use Kumwe\App\Extension\Contribution\UnitConversionProviderDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves a conversion declaration is a closed claim an operator can read before the package runs.
 *
 * The declaration is the whole of what an installation knows about a conversion package before any of
 * its code executes, so every part of it is refused rather than repaired: an identifier that is not
 * namespaced cannot be attributed to, a unit list that is empty or unbounded is not an inspectable
 * claim, a unit that is not a portable identifier cannot name anything the rest of the contract
 * recognises, and a priority outside its declared range would order packages by an undeclared rule.
 * The same refusals apply on the way back in from a signed manifest, because a declaration read from a
 * payload is exactly as load-bearing as one written in code.
 *
 * `relates()` is the closure itself: it decides whether a conversion is offered to a provider at all,
 * which is what stops a package widening its reach after admission by changing its runtime behaviour.
 *
 * @since  2.0.0
 */
#[CoversClass(UnitConversionProviderDefinition::class)]
final class UnitConversionProviderDeclarationTest extends TestCase
{
    /**
     * Prove a provider that cannot be attributed to by name is refused at declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAProviderIdentifierThatIsNotNamespacedIsRefused(): void
    {
        foreach (['trade', 'Acme.Units.Trade', '.units.trade', 'acme..trade', '9acme.units'] as $candidate) {
            $this->refuses(
                static fn (): UnitConversionProviderDefinition => new UnitConversionProviderDefinition(
                    $candidate,
                    ['case', 'unit'],
                ),
                'must be namespaced',
            );
        }

        self::assertSame(
            'acme.units.trade',
            (new UnitConversionProviderDefinition('acme.units.trade', ['case', 'unit']))->identifier(),
        );
    }

    /**
     * Prove the claim is bounded at both ends, so it is neither empty nor unreadably wide.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDeclaredUnitListIsBoundedAtBothEnds(): void
    {
        $this->refuses(
            static fn (): UnitConversionProviderDefinition => new UnitConversionProviderDefinition(
                'acme.units.trade',
                [],
            ),
            'between one and 64 units',
        );
        $this->refuses(
            static fn (): UnitConversionProviderDefinition => new UnitConversionProviderDefinition(
                'acme.units.trade',
                self::units(UnitConversionProviderDefinition::MAXIMUM_UNITS + 1),
            ),
            'between one and 64 units',
        );

        $widest = new UnitConversionProviderDefinition(
            'acme.units.trade',
            self::units(UnitConversionProviderDefinition::MAXIMUM_UNITS),
        );
        self::assertCount(UnitConversionProviderDefinition::MAXIMUM_UNITS, $widest->units);
    }

    /**
     * Prove a declared unit that no other part of the contract could recognise is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryDeclaredUnitMustBeABoundedPortableIdentifier(): void
    {
        foreach (['metric tonne', '', '-case', 'case!', str_repeat('u', 64)] as $candidate) {
            $this->refuses(
                static fn (): UnitConversionProviderDefinition => new UnitConversionProviderDefinition(
                    'acme.units.trade',
                    ['case', $candidate],
                ),
                'bounded portable identifier',
            );
        }

        $longest = str_repeat('u', 63);
        self::assertSame(
            ['case', $longest],
            (new UnitConversionProviderDefinition('acme.units.trade', ['case', $longest]))->units,
        );
    }

    /**
     * Prove a priority outside its declared range cannot order a package against the others.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPriorityOutsideItsDeclaredRangeIsRefused(): void
    {
        foreach ([-129, 128, 1000] as $candidate) {
            $this->refuses(
                static fn (): UnitConversionProviderDefinition => new UnitConversionProviderDefinition(
                    'acme.units.trade',
                    ['case', 'unit'],
                    $candidate,
                ),
                'outside its declared range',
            );
        }

        self::assertSame(
            [-128, 127],
            [
                (new UnitConversionProviderDefinition('acme.units.trade', ['case', 'unit'], -128))->priority(),
                (new UnitConversionProviderDefinition('acme.units.trade', ['case', 'unit'], 127))->priority(),
            ],
        );
    }

    /**
     * Prove the declared list closes the provider's reach in both directions of a conversion.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConversionOutsideTheDeclaredUnitsIsNotOfferedToTheProvider(): void
    {
        $definition = new UnitConversionProviderDefinition('acme.units.trade', ['case', 'unit']);

        self::assertTrue($definition->relates(self::request('case', 'unit')));
        self::assertTrue($definition->relates(self::request('unit', 'case')));
        self::assertFalse($definition->relates(self::request('case', 'pallet')));
        self::assertFalse($definition->relates(self::request('pallet', 'unit')));
        self::assertFalse($definition->relates(self::request('pallet', 'layer')));
    }

    /**
     * Prove two orderings of the same units declare one canonical thing, in code and in the manifest.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTwoOrderingsOfTheSameUnitsDeclareTheSameClaim(): void
    {
        $declared = new UnitConversionProviderDefinition('acme.units.trade', ['unit', 'case', 'unit'], 3);

        self::assertSame(['case', 'unit'], $declared->units);
        self::assertSame(
            ['provider_id' => 'acme.units.trade', 'units' => ['case', 'unit'], 'priority' => 3],
            $declared->toArray(),
        );
        self::assertSame(
            $declared->toArray(),
            UnitConversionProviderDefinition::fromArray($declared->toArray())->toArray(),
        );
    }

    /**
     * Prove a manifest declaration missing or padding a member is refused rather than read partially.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAManifestDeclarationMustCarryExactlyItsMembers(): void
    {
        $declared = ['provider_id' => 'acme.units.trade', 'units' => ['case', 'unit'], 'priority' => 0];

        foreach (['provider_id', 'units', 'priority'] as $member) {
            $missing = $declared;
            unset($missing[$member]);
            $this->refuses(
                static fn (): UnitConversionProviderDefinition => UnitConversionProviderDefinition::fromArray(
                    $missing,
                ),
                'exactly its members',
            );
        }

        $this->refuses(
            static fn (): UnitConversionProviderDefinition => UnitConversionProviderDefinition::fromArray(
                $declared + ['base_unit' => 'unit'],
            ),
            'exactly its members',
        );
    }

    /**
     * Prove a manifest declaration whose members are the wrong shape is refused, member by member.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAManifestDeclarationMemberOfTheWrongTypeIsRefused(): void
    {
        $wrong = [
            ['provider_id' => 12, 'units' => ['case', 'unit'], 'priority' => 0],
            ['provider_id' => 'acme.units.trade', 'units' => 'case', 'priority' => 0],
            ['provider_id' => 'acme.units.trade', 'units' => ['first' => 'case'], 'priority' => 0],
            ['provider_id' => 'acme.units.trade', 'units' => ['case', 'unit'], 'priority' => '0'],
        ];

        foreach ($wrong as $declaration) {
            $this->refuses(
                static fn (): UnitConversionProviderDefinition => UnitConversionProviderDefinition::fromArray(
                    $declaration,
                ),
                'member has the wrong type',
            );
        }
    }

    /**
     * Prove a manifest unit that is not text at all is refused before it is read as an identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAManifestUnitThatIsNotTextIsRefused(): void
    {
        $this->refuses(
            static fn (): UnitConversionProviderDefinition => UnitConversionProviderDefinition::fromArray(
                ['provider_id' => 'acme.units.trade', 'units' => ['case', 12], 'priority' => 0],
            ),
            'unit must be a string',
        );
        $this->refuses(
            static fn (): UnitConversionProviderDefinition => UnitConversionProviderDefinition::fromArray(
                ['provider_id' => 'acme.units.trade', 'units' => [['case']], 'priority' => 0],
            ),
            'unit must be a string',
        );
    }

    /**
     * Build a patterned unit list of the requested width, so bounds are exercised without real units.
     *
     * @param   int  $count  How many distinct portable identifiers to produce.
     *
     * @return  list<string>  Units named `unit-00` upwards.
     *
     * @since   2.0.0
     */
    private static function units(int $count): array
    {
        $units = [];
        for ($index = 0; $index < $count; $index++) {
            $units[] = sprintf('unit-%02d', $index);
        }

        return $units;
    }

    /**
     * Build one conversion request between the given pair of units.
     *
     * @param   string  $from  Portable identifier the quantity is held in.
     * @param   string  $to    Portable identifier it is to be expressed in.
     *
     * @return  UnitConversionRequest  A request for that pair, rounded half up to six digits.
     *
     * @since   2.0.0
     */
    private static function request(string $from, string $to): UnitConversionRequest
    {
        return new UnitConversionRequest(
            new QuantityValue(ExactDecimal::fromString('2.000000', 12, 6), $from),
            $to,
            new DateTimeImmutable('2026-08-14T00:00:00', new DateTimeZone('UTC')),
            12,
            6,
            QuantityRoundingMode::HalfUp,
        );
    }

    /**
     * Require one declaration to be refused, and its reason to name the rule it broke.
     *
     * @param   callable(): object  $declaration  Declaration expected to fail.
     * @param   string              $reason       Fragment the refusal message must contain.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function refuses(callable $declaration, string $reason): void
    {
        try {
            $declaration();
            self::fail(sprintf('A declaration violating "%s" was accepted.', $reason));
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($reason, $exception->getMessage());
        }
    }
}
