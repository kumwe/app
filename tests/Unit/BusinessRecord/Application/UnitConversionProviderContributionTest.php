<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessRecord\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Application\UnitConversionPipeline;
use Kumwe\CMS\BusinessRecord\Application\UnitConversionProvider;
use Kumwe\CMS\BusinessRecord\Application\UnitConversionUnavailable;
use Kumwe\CMS\BusinessRecord\Domain\ExactDecimal;
use Kumwe\CMS\BusinessRecord\Domain\ExactDecimalArithmetic;
use Kumwe\CMS\BusinessRecord\Domain\QuantityConverter;
use Kumwe\CMS\BusinessRecord\Domain\QuantityRoundingMode;
use Kumwe\CMS\BusinessRecord\Domain\QuantityValue;
use Kumwe\CMS\BusinessRecord\Domain\UnitConversionFactor;
use Kumwe\CMS\Extension\Contribution\UnitConversionProviderDefinition;
use Kumwe\CMS\BusinessRecord\Domain\UnitConversionRequest;
use Kumwe\CMS\BusinessRecord\Infrastructure\RuntimeUnitConversionProviderCatalog;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Contribution\ManifestContributionSet;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuntimeUnitConversionProviderCatalog::class)]
#[CoversClass(UnitConversionPipeline::class)]
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
        $this->contribute($registries, 'acme/units', 'acme.units.trade', '12.000000');

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
        $this->contribute($first, 'acme/units', 'acme.units.trade', '12.000000');
        $second = new ExtensionContributionRegistrySet(withCore: false);
        $this->contribute($second, 'zeta/logistics', 'zeta.logistics.packing', '12.000000');

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
        $this->contribute($registries, 'acme/units', 'acme.units.trade', '12.000000', 10);
        $this->contribute($registries, 'zeta/logistics', 'zeta.logistics.packing', '10.000000', -5);

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
        $this->contribute($undeclared, 'acme/units', 'acme.units.trade', '12.000000', 0, ['case', 'pallet']);
        try {
            $this->pipeline($undeclared)->convert($this->request());
            self::fail('A provider related a pair of units it never declared.');
        } catch (UnitConversionUnavailable $exception) {
            self::assertStringContainsString('No contributed conversion provider', $exception->getMessage());
        }

        $impersonating = new ExtensionContributionRegistrySet(withCore: false);
        $this->contribute(
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
        $owner = ContributionOwner::extension('acme/units');
        $definition = new UnitConversionProviderDefinition('acme.units.trade', ['case', 'unit']);
        $registrar = $registries->registrar($owner, new ManifestContributionSet(
            $owner,
            unitConverters: [$definition],
        ));
        try {
            $registrar->unitConversionProvider(
                $definition,
                new FixedUnitConversionProvider('acme.units.other', 'case', 'unit', '12.000000'),
            );
            self::fail('An implementation answering under another identity was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('contradicts its declaration', $exception->getMessage());
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
        $this->contribute($registries, 'acme/units', 'acme.units.trade', '12.000000');

        $registries->remove(ContributionOwner::extension('acme/units'));

        self::assertSame([], $registries->unitConversionProviders()->definitions());
        $this->expectException(UnitConversionUnavailable::class);
        $this->pipeline($registries)->convert($this->request());
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
        $owner = ContributionOwner::extension('acme/units');
        $declared = new ManifestContributionSet(
            $owner,
            spiVersion: ManifestContributionSet::CURRENT_SPI_VERSION,
            unitConverters: [new UnitConversionProviderDefinition('acme.units.trade', ['case', 'unit'], 3)],
        );

        $document = $declared->toArray();
        self::assertSame(
            [['provider_id' => 'acme.units.trade', 'units' => ['case', 'unit'], 'priority' => 3]],
            $document['integration']['unit_converters'] ?? null,
        );
        $parsed = ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/units'),
            $document,
            4,
        );
        self::assertSame($document, $parsed->toArray());
        self::assertSame('acme.units.trade', $parsed->unitConversionProviders()[0]->identifier());

        $bare = new ManifestContributionSet($owner, spiVersion: ManifestContributionSet::CURRENT_SPI_VERSION);
        self::assertArrayNotHasKey('unit_converters', $bare->toArray()['integration'] ?? []);
    }

    /**
     * Contribute one conversion package into a registry set exactly as an installed extension would.
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
    private function contribute(
        ExtensionContributionRegistrySet $registries,
        string $package,
        string $provider,
        string $factor,
        int $priority = 0,
        ?array $units = null,
        ?string $attributed = null,
    ): void {
        $owner = ContributionOwner::extension($package);
        $definition = new UnitConversionProviderDefinition($provider, $units ?? ['case', 'unit'], $priority);
        $registrar = $registries->registrar($owner, new ManifestContributionSet(
            $owner,
            unitConverters: [$definition],
        ));
        $registrar->unitConversionProvider(
            $definition,
            new FixedUnitConversionProvider($provider, 'case', 'unit', $factor, $attributed),
        );
        $registrar->complete();
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
            new RuntimeUnitConversionProviderCatalog($registries),
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
