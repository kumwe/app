<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Application\MoneyConversionPipeline;
use Kumwe\App\BusinessRecord\Application\MoneyRateProvider;
use Kumwe\App\BusinessRecord\Application\MoneyRateUnavailable;
use Kumwe\App\BusinessRecord\Domain\ExactDecimal;
use Kumwe\App\BusinessRecord\Domain\ExactDecimalArithmetic;
use Kumwe\App\BusinessRecord\Domain\MoneyConversionRequest;
use Kumwe\App\BusinessRecord\Domain\MoneyConverter;
use Kumwe\App\BusinessRecord\Domain\MoneyExchangeRate;
use Kumwe\App\BusinessRecord\Domain\MoneyRateProviderDefinition;
use Kumwe\App\BusinessRecord\Domain\MoneyRoundingMode;
use Kumwe\App\BusinessRecord\Domain\MoneyValue;
use Kumwe\App\BusinessRecord\Infrastructure\RuntimeMoneyRateProviderCatalog;
use Kumwe\App\Extension\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\ManifestContributionSet;
use Kumwe\App\Extension\Domain\ExtensionIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoneyConversionPipeline::class)]
#[CoversClass(MoneyRateProviderDefinition::class)]
#[CoversClass(RuntimeMoneyRateProviderCatalog::class)]
/**
 * Pins the rate provider as a genuine extension point: rates arrive from packages, never from core.
 *
 * @since  2.0.0
 */
final class MoneyRateProviderContributionTest extends TestCase
{
    /**
     * Prove a package supplies a rate through the ordinary contribution path with no core change.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExtensionSuppliesARateThroughTheContributionRegistrar(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $this->contribute($registries, 'acme/rates', 'acme.rates.ecb', '0.04938240');

        $converted = $this->pipeline($registries)->convert($this->request());

        self::assertSame('EUR', $converted->converted->currency);
        self::assertSame('1234.56', $converted->converted->amount->value());
        self::assertSame('acme.rates.ecb', $converted->rate->provider);
        self::assertSame('2026-08-14T00:00:00.000000+00:00', $converted->toArray()['rate']['as_at']);
        self::assertTrue($converted->toArray()['converted']);
        self::assertSame(
            [['provider_id' => 'acme.rates.ecb', 'currencies' => ['EUR', 'ZAR'], 'priority' => 0]],
            $registries->inventory(ContributionOwner::extension('acme/rates'))['integration']['money_rate_providers'],
        );
    }

    /**
     * Prove core itself ships nothing that prices a conversion, so an untouched installation refuses.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCoreShipsNoRateProviderAndRefusesRatherThanInventingARate(): void
    {
        $registries = new ExtensionContributionRegistrySet();

        self::assertSame([], $registries->moneyRateProviders()->definitions());
        $this->expectException(MoneyRateUnavailable::class);
        $this->expectExceptionMessage('No contributed rate provider can price this conversion.');
        $this->pipeline($registries)->convert($this->request());
    }

    /**
     * Prove two packages converting the same amount produce the same shape and the same provenance.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTwoPackagesConvertingTheSameAmountProduceTheSameShape(): void
    {
        $first = new ExtensionContributionRegistrySet(withCore: false);
        $this->contribute($first, 'acme/rates', 'acme.rates.ecb', '0.04938240');
        $second = new ExtensionContributionRegistrySet(withCore: false);
        $this->contribute($second, 'zeta/treasury', 'zeta.treasury.contracted', '0.04938240');

        $left = $this->pipeline($first)->convert($this->request())->toArray();
        $right = $this->pipeline($second)->convert($this->request())->toArray();

        self::assertSame(array_keys($left), array_keys($right));
        self::assertSame($left['value'], $right['value']);
        self::assertSame('acme.rates.ecb', $left['rate']['provider']);
        self::assertSame('zeta.treasury.contracted', $right['rate']['provider']);
        self::assertNotSame($left['rate'], $right['rate']);
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
        $this->contribute($registries, 'acme/rates', 'acme.rates.ecb', '0.04938240', 10);
        $this->contribute($registries, 'zeta/treasury', 'zeta.treasury.contracted', '0.05000000', -5);

        $converted = $this->pipeline($registries)->convert($this->request());

        self::assertSame('zeta.treasury.contracted', $converted->rate->provider);
        self::assertSame('1250.00', $converted->converted->amount->value());
    }

    /**
     * Prove a package cannot price a currency it did not declare, or answer under another identity.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPackageCannotWidenItsReachAfterAdmission(): void
    {
        $undeclared = new ExtensionContributionRegistrySet(withCore: false);
        $this->contribute($undeclared, 'acme/rates', 'acme.rates.ecb', '0.04938240', 0, ['ZAR', 'USD']);
        try {
            $this->pipeline($undeclared)->convert($this->request());
            self::fail('A provider priced a currency pair it never declared.');
        } catch (MoneyRateUnavailable $exception) {
            self::assertStringContainsString('No contributed rate provider', $exception->getMessage());
        }

        $impersonating = new ExtensionContributionRegistrySet(withCore: false);
        $this->contribute(
            $impersonating,
            'acme/rates',
            'acme.rates.ecb',
            '0.04938240',
            0,
            null,
            'zeta.treasury.contracted',
        );
        try {
            $this->pipeline($impersonating)->convert($this->request());
            self::fail('A provider supplied a rate attributed to another provider.');
        } catch (MoneyRateUnavailable $exception) {
            self::assertStringContainsString('attributed to another provider', $exception->getMessage());
        }

        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $owner = ContributionOwner::extension('acme/rates');
        $definition = new MoneyRateProviderDefinition('acme.rates.ecb', ['ZAR', 'EUR']);
        $registrar = $registries->registrar($owner, new ManifestContributionSet(
            $owner,
            moneyRateProviders: [$definition],
        ));
        try {
            $registrar->moneyRateProvider(
                $definition,
                new FixedMoneyRateProvider('acme.rates.other', 'ZAR', 'EUR', '0.04938240'),
            );
            self::fail('An implementation answering under another identity was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('contradicts its declaration', $exception->getMessage());
        }
    }

    /**
     * Prove a withdrawn package stops pricing conversions in the same sweep that removes everything else.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRemovingThePackageWithdrawsItsRates(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $this->contribute($registries, 'acme/rates', 'acme.rates.ecb', '0.04938240');

        $registries->remove(ContributionOwner::extension('acme/rates'));

        self::assertSame([], $registries->moneyRateProviders()->definitions());
        $this->expectException(MoneyRateUnavailable::class);
        $this->pipeline($registries)->convert($this->request());
    }

    /**
     * Prove a declared rate provider survives the manifest round trip a runtime publication depends on.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADeclaredRateProviderRoundTripsThroughTheManifest(): void
    {
        $owner = ContributionOwner::extension('acme/rates');
        $declared = new ManifestContributionSet(
            $owner,
            spiVersion: ManifestContributionSet::CURRENT_SPI_VERSION,
            moneyRateProviders: [new MoneyRateProviderDefinition('acme.rates.ecb', ['ZAR', 'EUR'], 3)],
        );

        $document = $declared->toArray();
        self::assertSame(
            [['provider_id' => 'acme.rates.ecb', 'currencies' => ['EUR', 'ZAR'], 'priority' => 3]],
            $document['integration']['rate_providers'] ?? null,
        );
        $parsed = ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/rates'),
            $document,
            4,
        );
        self::assertSame($document, $parsed->toArray());
        self::assertSame('acme.rates.ecb', $parsed->moneyRateProviders()[0]->identifier());

        $bare = new ManifestContributionSet($owner, spiVersion: ManifestContributionSet::CURRENT_SPI_VERSION);
        self::assertArrayNotHasKey('rate_providers', $bare->toArray()['integration'] ?? []);
    }

    /**
     * Contribute one rate package into a registry set exactly as an installed extension would.
     *
     * @param   ExtensionContributionRegistrySet  $registries  Set the package contributes into.
     * @param   string                            $package     Package identifier in `vendor/name` form.
     * @param   string                            $provider    Namespaced provider identifier it declares.
     * @param   string                            $rate        Canonical rate literal, EUR per ZAR.
     * @param   int                               $priority    Declared resolution priority.
     * @param   ?list<string>                     $currencies  Declared currencies; ZAR and EUR when null.
     * @param   ?string                           $attributed  Identity the implementation attributes rates to.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function contribute(
        ExtensionContributionRegistrySet $registries,
        string $package,
        string $provider,
        string $rate,
        int $priority = 0,
        ?array $currencies = null,
        ?string $attributed = null,
    ): void {
        $owner = ContributionOwner::extension($package);
        $definition = new MoneyRateProviderDefinition($provider, $currencies ?? ['ZAR', 'EUR'], $priority);
        $registrar = $registries->registrar($owner, new ManifestContributionSet(
            $owner,
            moneyRateProviders: [$definition],
        ));
        $registrar->moneyRateProvider(
            $definition,
            new FixedMoneyRateProvider($provider, 'ZAR', 'EUR', $rate, $attributed),
        );
        $registrar->complete();
    }

    /**
     * Compose the pipeline over one registry set, the way the composition root does.
     *
     * @param   ExtensionContributionRegistrySet  $registries  Active contribution registries.
     *
     * @return  MoneyConversionPipeline  Pipeline reading providers from those registries.
     *
     * @since   2.0.0
     */
    private function pipeline(ExtensionContributionRegistrySet $registries): MoneyConversionPipeline
    {
        return new MoneyConversionPipeline(
            new MoneyConverter(),
            new RuntimeMoneyRateProviderCatalog($registries),
        );
    }

    /**
     * The one conversion every case in this class asks for.
     *
     * @return  MoneyConversionRequest  25000.00 ZAR presented as EUR, rounded half up to two digits.
     *
     * @since   2.0.0
     */
    private function request(): MoneyConversionRequest
    {
        return new MoneyConversionRequest(
            new MoneyValue(ExactDecimal::fromString('25000.00', 12, 2), 'ZAR'),
            'EUR',
            new DateTimeImmutable('2026-08-14T00:00:00', new DateTimeZone('UTC')),
            12,
            2,
            MoneyRoundingMode::HalfUp,
        );
    }
}

/**
 * A rate package's whole implementation: one contractual rate it is prepared to be held to.
 *
 * It lives in the test suite rather than under `src/` on purpose. Core ships no provider, so proving
 * the extension point works means standing one up entirely outside core and contributing it through
 * the same registrar every other extension surface uses.
 *
 * @since  2.0.0
 */
final readonly class FixedMoneyRateProvider implements MoneyRateProvider
{
    /**
     * Hold one fixed rate and the identity it answers under.
     *
     * @param  string   $identifier  Identity this provider is registered under.
     * @param  string   $base        Uppercase ISO 4217 code it converts from.
     * @param  string   $quote       Uppercase ISO 4217 code it converts into.
     * @param  string   $rate        Canonical rate literal, quote per one base.
     * @param  ?string  $attributed  Identity it attributes rates to; its own when null.
     *
     * @since  2.0.0
     */
    public function __construct(
        private string $identifier,
        private string $base,
        private string $quote,
        private string $rate,
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
     * Accept only the one pair this contractual rate prices.
     *
     * @param   MoneyConversionRequest  $request  Conversion being offered.
     *
     * @return  bool  True when the request asks for this provider's own pair.
     *
     * @since   2.0.0
     */
    public function supports(MoneyConversionRequest $request): bool
    {
        return $request->amount->currency === $this->base && $request->targetCurrency === $this->quote;
    }

    /**
     * Supply the fixed rate, as at the instant asked about.
     *
     * @param   MoneyConversionRequest  $request  Conversion being answered.
     *
     * @return  MoneyExchangeRate  The contractual rate, attributed as this provider was configured.
     *
     * @since   2.0.0
     */
    public function rateFor(MoneyConversionRequest $request): MoneyExchangeRate
    {
        return new MoneyExchangeRate(
            $this->base,
            $this->quote,
            ExactDecimalArithmetic::fromLiteral($this->rate),
            $request->asAt,
            $this->attributed ?? $this->identifier,
        );
    }
}
