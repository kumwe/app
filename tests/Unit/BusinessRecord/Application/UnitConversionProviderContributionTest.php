<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\Conversion\Provider\UnitConversionPipeline;
use Kumwe\Conversion\Provider\UnitConversionProvider;
use Kumwe\Conversion\Provider\UnitConversionUnavailable;
use Kumwe\Conversion\Decimal\ExactDecimal;
use Kumwe\Conversion\Decimal\ExactDecimalArithmetic;
use Kumwe\Conversion\Contract\QuantityConverter;
use Kumwe\Conversion\Value\QuantityRoundingMode;
use Kumwe\Conversion\Value\QuantityValue;
use Kumwe\Conversion\Value\UnitConversionFactor;
use Kumwe\App\Extension\Contribution\UnitConversionProviderDefinition;
use Kumwe\Conversion\Contract\UnitConversionRequest;
use Kumwe\App\BusinessRecord\Infrastructure\RuntimeUnitConversionProviderCatalog;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Contribution\OwnedExtensionBindingRegistrar;
use Kumwe\Extension\Manifest\ExtensionIdentifier;
use Kumwe\Extension\Manifest\ManifestContributions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RuntimeUnitConversionProviderCatalog::class)]
#[CoversClass(UnitConversionProviderDefinition::class)]
/**
 * Pins the conversion table as a genuine extension point: factors arrive from packages, never from core.
 *
 * This is the named conformance check for the extension-held half of decision D13.5: a package holds the
 * table and converts through the core contract with no core edit, and two packages converting the same
 * quantity produce the same shape.
 *
 * @since  2.0.0
 */
final class UnitConversionProviderContributionTest extends TestCase
{
    /**
     * Prove a package supplies a factor through the ordinary contribution path with no core change.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExtensionSuppliesAFactorThroughTheContributionRegistrar(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $this->activateProvider($registries, 'acme/units', 'acme.units.trade', '12.000000');

        $converted = $this->pipeline($registries)->convert($this->request());

        self::assertSame('unit', $converted->converted->unit);
        self::assertSame('24.000000', $converted->converted->amount->value());
        self::assertSame('acme.units.trade', $converted->factor->provider);
        self::assertSame('2026-08-14T00:00:00.000000+00:00', $converted->toArray()['factor']['as_at']);
        self::assertTrue($converted->toArray()['converted']);
        self::assertSame(
            [['provider_id' => 'acme.units.trade', 'units' => ['case', 'unit'], 'priority' => 0]],
            $registries->inventory(
                ContributionOwner::extension('acme/units'),
            )['integration']['unit_conversion_providers'],
        );
    }

    /**
     * Prove core itself ships nothing that relates two units, so an untouched installation refuses.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCoreShipsNoConversionTableAndRefusesRatherThanInventingAFactor(): void
    {
        $registries = new ExtensionContributionRegistrySet();

        self::assertSame([], $registries->unitConversionProviders()->definitions());
        $this->expectException(UnitConversionUnavailable::class);
        $this->expectExceptionMessage('No contributed conversion provider can relate these units.');
        $this->pipeline($registries)->convert($this->request());
    }

    /**
     * Prove two packages converting the same quantity produce the same shape and the same provenance.
     *
     * This is the disagreement the contract exists to prevent: a stock package and a sales package must
     * not be able to answer "how many units is a case" in two incompatible shapes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTwoPackagesConvertingTheSameQuantityProduceTheSameShape(): void
    {
        $first = new ExtensionContributionRegistrySet(withCore: false);
        $this->activateProvider($first, 'acme/units', 'acme.units.trade', '12.000000');
        $second = new ExtensionContributionRegistrySet(withCore: false);
        $this->activateProvider($second, 'zeta/logistics', 'zeta.logistics.packing', '12.000000');

        $left = $this->pipeline($first)->convert($this->request())->toArray();
        $right = $this->pipeline($second)->convert($this->request())->toArray();

        self::assertSame(array_keys($left), array_keys($right));
        self::assertSame($left['value'], $right['value']);
        self::assertSame('acme.units.trade', $left['factor']['provider']);
        self::assertSame('zeta.logistics.packing', $right['factor']['provider']);
        self::assertNotSame($left['factor'], $right['factor']);
    }

    /**
     * Prove resolution follows the declared priority and stops at the first package that answers.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeclaredPriorityDecidesWhichPackageAnswers(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $this->activateProvider($registries, 'acme/units', 'acme.units.trade', '12.000000', 10);
        $this->activateProvider($registries, 'zeta/logistics', 'zeta.logistics.packing', '10.000000', -5);

        $converted = $this->pipeline($registries)->convert($this->request());

        self::assertSame('zeta.logistics.packing', $converted->factor->provider);
        self::assertSame('20.000000', $converted->converted->amount->value());
    }

    /**
     * Prove a package cannot relate a unit it did not declare, or answer under another identity.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPackageCannotWidenItsReachAfterAdmission(): void
    {
        $undeclared = new ExtensionContributionRegistrySet(withCore: false);
        $this->activateProvider($undeclared, 'acme/units', 'acme.units.trade', '12.000000', 0, ['case', 'pallet']);
        try {
            $this->pipeline($undeclared)->convert($this->request());
            self::fail('A provider related a pair of units it never declared.');
        } catch (UnitConversionUnavailable $exception) {
            self::assertStringContainsString('No contributed conversion provider', $exception->getMessage());
        }

        $impersonating = new ExtensionContributionRegistrySet(withCore: false);
        $this->activateProvider(
            $impersonating,
            'acme/units',
            'acme.units.trade',
            '12.000000',
            0,
            null,
            'zeta.logistics.packing',
        );
        try {
            $this->pipeline($impersonating)->convert($this->request());
            self::fail('A provider supplied a factor attributed to another provider.');
        } catch (UnitConversionUnavailable $exception) {
            self::assertStringContainsString('attributed to another provider', $exception->getMessage());
        }

        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $definition = new UnitConversionProviderDefinition('acme.units.trade', ['case', 'unit']);
        $registrar = $this->registrar($registries, 'acme/units', $definition);
        try {
            $registrar->unitConversionProvider(
                $definition->identifier(),
                new FixedUnitConversionProvider('acme.units.other', 'case', 'unit', '12.000000'),
            );
            self::fail('An implementation answering under another identity was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('contradicts its signed declaration', $exception->getMessage());
        }
    }

    /**
     * Prove a withdrawn package stops relating units in the same sweep that removes everything else.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRemovingThePackageWithdrawsItsFactors(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $this->activateProvider($registries, 'acme/units', 'acme.units.trade', '12.000000');

        $registries->remove(ContributionOwner::extension('acme/units'));

        self::assertSame([], $registries->unitConversionProviders()->definitions());
        $this->expectException(UnitConversionUnavailable::class);
        $this->pipeline($registries)->convert($this->request());
    }

    /**
     * Prove a resident converter from an old generation is fenced before package code can be called.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStaleGenerationCannotEnterResidentUnitConversionProvider(): void
    {
        $definition = new UnitConversionProviderDefinition('acme.units.trade', ['case', 'unit']);
        $provider = $this->createMock(UnitConversionProvider::class);
        $provider->method('identifier')->willReturn('acme.units.trade');
        $provider->expects(self::never())->method('supports');
        $provider->expects(self::never())->method('factorFor');
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registrar = $this->registrar($registries, 'acme/units', $definition);
        $registrar->unitConversionProvider($definition->identifier(), $provider);
        $registrar->complete();
        $execution = $this->createMock(ExtensionExecutionGate::class);
        $execution->expects(self::once())
            ->method('assertCurrent')
            ->willThrowException(new RuntimeException('stale extension generation'));
        $pipeline = new UnitConversionPipeline(
            new QuantityConverter(),
            new RuntimeUnitConversionProviderCatalog($registries, $execution),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stale extension generation');
        $pipeline->convert($this->request());
    }

    /**
     * Prove a declared conversion provider survives the manifest round trip a publication depends on.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADeclaredConversionProviderRoundTripsThroughTheManifest(): void
    {
        $document = [
            'version' => 2,
            'integration' => [
                'unit_converters' => [
                    (new UnitConversionProviderDefinition('acme.units.trade', ['case', 'unit'], 3))->toArray(),
                ],
            ],
        ];
        $parsed = ManifestContributions::fromManifest(
            ExtensionIdentifier::fromString('acme/units'),
            $document,
            4,
        );
        self::assertSame(
            [['priority' => 3, 'provider_id' => 'acme.units.trade', 'units' => ['case', 'unit']]],
            $parsed->declarations()['integration']['unit_converters'] ?? null,
        );

        $bare = ManifestContributions::fromManifest(ExtensionIdentifier::fromString('acme/units'), [
            'version' => 2,
        ], 4);
        self::assertArrayNotHasKey('unit_converters', $bare->declarations()['integration'] ?? []);
    }

    /**
     * Activate one unit provider against its signed manifest exactly as an installed extension would.
     *
     * @param   ExtensionContributionRegistrySet  $registries  Set the package contributes into.
     * @param   string                            $package     Package identifier in `vendor/name` form.
     * @param   string                            $provider    Namespaced provider identifier it declares.
     * @param   string                            $factor      Canonical factor literal, units per case.
     * @param   int                               $priority    Declared resolution priority.
     * @param   ?list<string>                     $units       Declared units; case and unit when null.
     * @param   ?string                           $attributed  Identity the implementation attributes factors to.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function activateProvider(
        ExtensionContributionRegistrySet $registries,
        string $package,
        string $provider,
        string $factor,
        int $priority = 0,
        ?array $units = null,
        ?string $attributed = null,
    ): void {
        $definition = new UnitConversionProviderDefinition($provider, $units ?? ['case', 'unit'], $priority);
        $registrar = $this->registrar($registries, $package, $definition);
        $registrar->unitConversionProvider(
            $definition->identifier(),
            new FixedUnitConversionProvider($provider, 'case', 'unit', $factor, $attributed),
        );
        $registrar->complete();
    }

    /**
     * Open the real canonical binding sink for one signed unit-provider declaration.
     *
     * @param   ExtensionContributionRegistrySet  $registries  Host registry set receiving the binding.
     * @param   string                            $package     Canonical package identifier.
     * @param   UnitConversionProviderDefinition  $definition  Signed unit-provider policy definition.
     *
     * @return  OwnedExtensionBindingRegistrar  Manifest-scoped executable sink.
     *
     * @since   2.0.0
     */
    private function registrar(
        ExtensionContributionRegistrySet $registries,
        string $package,
        UnitConversionProviderDefinition $definition,
    ): OwnedExtensionBindingRegistrar {
        $manifest = ManifestContributions::fromManifest(
            ExtensionIdentifier::fromString($package),
            [
                'version' => 2,
                'integration' => ['unit_converters' => [$definition->toArray()]],
            ],
            4,
        );

        return new OwnedExtensionBindingRegistrar($manifest, $registries);
    }

    /**
     * Compose the pipeline over one registry set, the way the composition root does.
     *
     * @param   ExtensionContributionRegistrySet  $registries  Active contribution registries.
     *
     * @return  UnitConversionPipeline  Pipeline reading providers from those registries.
     *
     * @since   2.0.0
     */
    private function pipeline(ExtensionContributionRegistrySet $registries): UnitConversionPipeline
    {
        return new UnitConversionPipeline(
            new QuantityConverter(),
            new RuntimeUnitConversionProviderCatalog(
                $registries,
                $this->createStub(ExtensionExecutionGate::class),
            ),
        );
    }

    /**
     * The one conversion every case in this class asks for.
     *
     * @return  UnitConversionRequest  Two cases expressed in units, rounded half up to six digits.
     *
     * @since   2.0.0
     */
    private function request(): UnitConversionRequest
    {
        return new UnitConversionRequest(
            new QuantityValue(ExactDecimal::fromString('2.000000', 12, 6), 'case'),
            'unit',
            new DateTimeImmutable('2026-08-14T00:00:00', new DateTimeZone('UTC')),
            12,
            6,
            QuantityRoundingMode::HalfUp,
        );
    }
}

/**
 * A conversion package's whole implementation: one contractual factor it is prepared to be held to.
 *
 * It lives in the test suite rather than under `src/` on purpose. Core ships no conversion table, so
 * proving the extension point works means standing one up entirely outside core and contributing it
 * through the same registrar every other extension surface uses.
 *
 * @since  2.0.0
 */
final readonly class FixedUnitConversionProvider implements UnitConversionProvider
{
    /**
     * Hold one fixed factor and the identity it answers under.
     *
     * @param  string   $identifier  Identity this provider is registered under.
     * @param  string   $source      Portable identifier of the unit it converts from.
     * @param  string   $target      Portable identifier of the unit it converts into.
     * @param  string   $factor      Canonical factor literal, target per one source.
     * @param  ?string  $attributed  Identity it attributes factors to; its own when null.
     *
     * @since  2.0.0
     */
    public function __construct(
        private string $identifier,
        private string $source,
        private string $target,
        private string $factor,
        private ?string $attributed = null,
    ) {
    }

    /**
     * Return the identity this provider is registered and attributed under.
     *
     * @return  string  Namespaced provider identifier.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->identifier;
    }

    /**
     * Accept only the one pair of units this contractual factor relates.
     *
     * @param   UnitConversionRequest  $request  Conversion being offered.
     *
     * @return  bool  True when the request asks for this provider's own pair.
     *
     * @since   2.0.0
     */
    public function supports(UnitConversionRequest $request): bool
    {
        return $request->quantity->unit === $this->source && $request->targetUnit === $this->target;
    }

    /**
     * Supply the fixed factor, as at the instant asked about.
     *
     * @param   UnitConversionRequest  $request  Conversion being answered.
     *
     * @return  UnitConversionFactor  The contractual factor, attributed as this provider was configured.
     *
     * @since   2.0.0
     */
    public function factorFor(UnitConversionRequest $request): UnitConversionFactor
    {
        return new UnitConversionFactor(
            $this->source,
            $this->target,
            ExactDecimalArithmetic::fromLiteral($this->factor),
            $request->asAt,
            $this->attributed ?? $this->identifier,
        );
    }
}
