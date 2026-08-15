<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessRecord\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Application\UnitConversionPipeline;
use Kumwe\CMS\BusinessRecord\Application\UnitConversionProvider;
use Kumwe\CMS\BusinessRecord\Application\UnitConversionProviderCatalog;
use Kumwe\CMS\BusinessRecord\Application\UnitConversionUnavailable;
use Kumwe\CMS\BusinessRecord\Domain\ExactDecimal;
use Kumwe\CMS\BusinessRecord\Domain\ExactDecimalArithmetic;
use Kumwe\CMS\BusinessRecord\Domain\QuantityConverter;
use Kumwe\CMS\BusinessRecord\Domain\QuantityRoundingMode;
use Kumwe\CMS\BusinessRecord\Domain\QuantityValue;
use Kumwe\CMS\BusinessRecord\Domain\UnitConversionFactor;
use Kumwe\CMS\BusinessRecord\Domain\UnitConversionRequest;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the pipeline moves past a provider that declines and refuses one that answers badly.
 *
 * The catalog decides who may be asked; this is about what happens once they are. Declining is an
 * ordinary outcome, so the pipeline passes over that provider without asking it for a factor and lets
 * the next declared one answer. Answering wrongly is not an ordinary outcome: a provider that accepted
 * the request and then handed back a factor relating other units, or one dated after the instant asked
 * about, has answered a different question, and the pipeline refuses rather than letting an unusable
 * factor reach a surface as though it had been converted with. The original refusal is kept as the
 * cause, so an operator can still read which rule the provider broke.
 *
 * The catalog and the providers here are stand-ins declared in the test suite, because core ships no
 * conversion table of any kind and the pipeline's own behaviour is what is being pinned.
 *
 * @since  2.0.0
 */
#[CoversClass(UnitConversionPipeline::class)]
final class UnitConversionPipelineRefusalTest extends TestCase
{
    /**
     * Prove a declining provider is passed over unasked and the next declared one answers.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAProviderThatDeclinesIsPassedOverWithoutBeingAskedForAFactor(): void
    {
        $converted = $this->pipeline(
            new ScriptedUnitConversionProvider('acme.units.trade', false),
            new ScriptedUnitConversionProvider(
                'zeta.logistics.packing',
                true,
                self::factor('12.000000', 'zeta.logistics.packing'),
            ),
        )->convert(self::request());

        self::assertSame('zeta.logistics.packing', $converted->factor->provider);
        self::assertSame('24.000000', $converted->converted->amount->value());
        self::assertSame('unit', $converted->converted->unit);
    }

    /**
     * Prove every provider declining leaves the conversion refused rather than invented.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConversionEveryProviderDeclinesIsRefusedRatherThanInvented(): void
    {
        $pipeline = $this->pipeline(
            new ScriptedUnitConversionProvider('acme.units.trade', false),
            new ScriptedUnitConversionProvider('zeta.logistics.packing', false),
        );

        $this->expectException(UnitConversionUnavailable::class);
        $this->expectExceptionMessage('No contributed conversion provider can relate these units.');
        $pipeline->convert(self::request());
    }

    /**
     * Prove a factor dated after the instant asked about is refused instead of converted with.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFactorDatedAfterTheInstantAskedAboutIsRefusedRatherThanConvertedWith(): void
    {
        $pipeline = $this->pipeline(new ScriptedUnitConversionProvider(
            'acme.units.trade',
            true,
            self::factor('12.000000', 'acme.units.trade', '2026-08-15T00:00:00'),
        ));

        $this->refuses($pipeline);
    }

    /**
     * Prove a factor relating some other pair of units is refused instead of converted with.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFactorRelatingOtherUnitsIsRefusedRatherThanConvertedWith(): void
    {
        $pipeline = $this->pipeline(new ScriptedUnitConversionProvider(
            'acme.units.trade',
            true,
            self::factor('12.000000', 'acme.units.trade', target: 'pallet'),
        ));

        $this->refuses($pipeline);
    }

    /**
     * Require the pipeline to refuse the conversion and to keep the reason the factor was unusable.
     *
     * @param   UnitConversionPipeline  $pipeline  Pipeline whose single provider answers badly.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function refuses(UnitConversionPipeline $pipeline): void
    {
        try {
            $pipeline->convert(self::request());
            self::fail('An unusable factor reached a surface as though it had been converted with.');
        } catch (UnitConversionUnavailable $exception) {
            self::assertStringContainsString(
                'does not answer the conversion requested',
                $exception->getMessage(),
            );
            $previous = $exception->getPrevious();
            self::assertInstanceOf(InvalidArgumentException::class, $previous);
            self::assertStringContainsString(
                'must relate the requested units as at the instant asked about',
                $previous->getMessage(),
            );
        }
    }

    /**
     * Compose the pipeline over a fixed list of providers, in the order the catalog would offer them.
     *
     * @param   UnitConversionProvider  ...$providers  Providers entitled to answer, in resolution order.
     *
     * @return  UnitConversionPipeline  Pipeline reading exactly those providers.
     *
     * @since   2.0.0
     */
    private function pipeline(UnitConversionProvider ...$providers): UnitConversionPipeline
    {
        return new UnitConversionPipeline(
            new QuantityConverter(),
            new ScriptedUnitConversionProviderCatalog(array_values($providers)),
        );
    }

    /**
     * Build one factor a stand-in provider offers for the fixture conversion.
     *
     * @param   string  $factor    Canonical factor literal, target units per one case.
     * @param   string  $provider  Identity the factor is attributed to.
     * @param   string  $asAt      Naive UTC instant the factor is as at.
     * @param   string  $target    Portable identifier of the unit the factor converts into.
     *
     * @return  UnitConversionFactor  The factor, in UTC, attributed as asked.
     *
     * @since   2.0.0
     */
    private static function factor(
        string $factor,
        string $provider,
        string $asAt = '2026-08-14T00:00:00',
        string $target = 'unit',
    ): UnitConversionFactor {
        return new UnitConversionFactor(
            'case',
            $target,
            ExactDecimalArithmetic::fromLiteral($factor),
            new DateTimeImmutable($asAt, new DateTimeZone('UTC')),
            $provider,
        );
    }

    /**
     * The one conversion every case in this class asks for.
     *
     * @return  UnitConversionRequest  Two cases expressed in units, rounded half up to six digits.
     *
     * @since   2.0.0
     */
    private static function request(): UnitConversionRequest
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
 * The providers one conversion is offered to, stated outright instead of resolved from a registry.
 *
 * Which packages are entitled to answer is a composition concern the pipeline reads through this port,
 * so pinning the pipeline's own behaviour means handing it a list rather than standing up an extension
 * installation to produce one.
 *
 * @since  2.0.0
 */
final readonly class ScriptedUnitConversionProviderCatalog implements UnitConversionProviderCatalog
{
    /**
     * Hold the providers this catalog offers, already in resolution order.
     *
     * @param  list<UnitConversionProvider>  $providers  Providers entitled to answer any conversion.
     *
     * @since  2.0.0
     */
    public function __construct(private array $providers)
    {
    }

    /**
     * Offer the same providers for every conversion, in the order they were given.
     *
     * @param   UnitConversionRequest  $request  Conversion a caller is looking for a factor for.
     *
     * @return  list<UnitConversionProvider>  The providers this catalog was composed with.
     *
     * @since   2.0.0
     */
    public function providersFor(UnitConversionRequest $request): array
    {
        return $this->providers;
    }
}

/**
 * A conversion provider whose answer to both questions the pipeline asks is decided in advance.
 *
 * Whether it will answer at all, and the factor it hands back when it does, are given to it, so a
 * declining provider and one that answers a different question can both be put in front of the
 * pipeline. A provider that declines has no factor, and asking it for one is a failure of the pipeline
 * rather than of the provider — which is why it raises rather than improvising.
 *
 * @since  2.0.0
 */
final readonly class ScriptedUnitConversionProvider implements UnitConversionProvider
{
    /**
     * Hold the identity, the decision, and the factor this provider is scripted to answer with.
     *
     * @param  string                 $identifier  Identity this provider is registered under.
     * @param  bool                   $answers     Whether it accepts the conversion it is offered.
     * @param  ?UnitConversionFactor  $factor      Factor it hands back; absent when it declines.
     *
     * @since  2.0.0
     */
    public function __construct(
        private string $identifier,
        private bool $answers,
        private ?UnitConversionFactor $factor = null,
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
     * Answer the scripted decision, whatever the conversion offered.
     *
     * @param   UnitConversionRequest  $request  Conversion being offered.
     *
     * @return  bool  The decision this provider was composed with.
     *
     * @since   2.0.0
     */
    public function supports(UnitConversionRequest $request): bool
    {
        return $this->answers;
    }

    /**
     * Hand back the scripted factor, or refuse to have been asked at all.
     *
     * @param   UnitConversionRequest  $request  Conversion being answered.
     *
     * @return  UnitConversionFactor  The factor this provider was composed with.
     *
     * @throws  LogicException  When a provider that declined the conversion is asked for a factor anyway.
     *
     * @since   2.0.0
     */
    public function factorFor(UnitConversionRequest $request): UnitConversionFactor
    {
        return $this->factor
            ?? throw new LogicException('A provider that declined the conversion was asked for a factor.');
    }
}
