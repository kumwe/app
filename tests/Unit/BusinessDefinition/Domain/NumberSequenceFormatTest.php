<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessDefinition\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Domain\NumberSequenceFormat;
use Kumwe\CMS\BusinessDefinition\Domain\NumberSequenceReset;
use Kumwe\CMS\BusinessDefinition\Domain\NumberSequenceScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NumberSequenceFormat::class)]
#[CoversClass(NumberSequenceReset::class)]
#[CoversClass(NumberSequenceScope::class)]
final class NumberSequenceFormatTest extends TestCase
{
    public function testAnEmptyDeclarationIsASiteWideLifetimeCounterInUtc(): void
    {
        $format = NumberSequenceFormat::fromConfiguration([]);

        self::assertSame(NumberSequenceScope::Site, $format->scope);
        self::assertSame(NumberSequenceReset::Never, $format->reset);
        self::assertSame('', $format->prefix);
        self::assertSame(6, $format->padding);
        self::assertSame('UTC', $format->timezone->getName());
        self::assertSame(
            ['scope' => '-', 'period' => ''],
            $format->counter('acme', new DateTimeImmutable('2026-12-31T23:30:00+00:00')),
        );
        self::assertSame('000042', $format->render(42, ''));
    }

    public function testTheResetPeriodIsJudgedInTheDeclaredZoneRatherThanUtc(): void
    {
        $format = NumberSequenceFormat::fromConfiguration([
            'reset' => 'yearly',
            'prefix' => 'INV-',
            'padding' => 5,
            'timezone' => 'Africa/Windhoek',
        ]);
        $justBeforeMidnightLocal = new DateTimeImmutable('2026-12-31T21:30:00+00:00');
        $justAfterMidnightLocal = new DateTimeImmutable('2026-12-31T22:30:00+00:00');

        self::assertSame('2026', $format->counter(null, $justBeforeMidnightLocal)['period']);
        self::assertSame(
            '2027',
            $format->counter(null, $justAfterMidnightLocal)['period'],
            'A local new year starts a new run even while UTC is still in the old one.',
        );
        self::assertSame('INV-2027-00001', $format->render(1, '2027'));
    }

    public function testAMonthlyRunKeysAndRendersItsCalendarMonth(): void
    {
        $format = NumberSequenceFormat::fromConfiguration(['reset' => 'monthly', 'prefix' => 'DN/', 'padding' => 3]);

        self::assertSame(
            '2026-02',
            $format->counter(null, new DateTimeImmutable('2026-02-14T00:00:00+00:00'))['period'],
        );
        self::assertSame('DN/2026-02-007', $format->render(7, '2026-02'));
    }

    public function testAPerOrganizationRunKeysOnTheRecordsOwnOrganization(): void
    {
        $format = NumberSequenceFormat::fromConfiguration(['scope' => 'organization']);

        self::assertSame('north-branch', $format->counter('north-branch', new DateTimeImmutable())['scope']);

        $this->expectException(InvalidArgumentException::class);
        $format->counter(null, new DateTimeImmutable());
    }

    public function testTheWidestFormatStillFitsTheColumnTheCompilerEmits(): void
    {
        $format = NumberSequenceFormat::fromConfiguration([
            'reset' => 'monthly',
            'prefix' => str_repeat('X', NumberSequenceFormat::MAXIMUM_PREFIX),
            'padding' => NumberSequenceFormat::MAXIMUM_PADDING,
        ]);
        $widest = $format->render(999_999_999_999, '2026-12');

        self::assertSame(NumberSequenceFormat::MAXIMUM_LENGTH, strlen($widest));
    }

    public function testANumberThatOutgrewItsPaddingIsRefusedRatherThanTruncated(): void
    {
        $format = NumberSequenceFormat::fromConfiguration([
            'reset' => 'monthly',
            'prefix' => str_repeat('X', NumberSequenceFormat::MAXIMUM_PREFIX),
            'padding' => NumberSequenceFormat::MAXIMUM_PADDING,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $format->render(1_000_000_000_000, '2026-12');
    }

    /**
     * @return  list<array{array<string, scalar|list<scalar|null>|null>}>  Declarations that must be refused.
     */
    public static function unusableDeclarations(): array
    {
        return [
            'unknown scope' => [['scope' => 'installation']],
            'unknown reset' => [['reset' => 'quarterly']],
            'unknown timezone' => [['timezone' => 'Mars/Olympus']],
            'lower-case prefix' => [['prefix' => 'inv-']],
            'oversized prefix' => [['prefix' => str_repeat('X', NumberSequenceFormat::MAXIMUM_PREFIX + 1)]],
            'padding below one' => [['padding' => 0]],
            'padding above the ceiling' => [['padding' => NumberSequenceFormat::MAXIMUM_PADDING + 1]],
            'padding as text' => [['padding' => '6']],
            'scope as a number' => [['scope' => 1]],
        ];
    }

    /**
     * @param   array<string, scalar|list<scalar|null>|null>  $configuration  Declaration under test.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableDeclarations')]
    public function testAnUnusableDeclarationIsRefusedRatherThanDefaultedOver(array $configuration): void
    {
        $this->expectException(InvalidArgumentException::class);
        NumberSequenceFormat::fromConfiguration($configuration);
    }

    public function testAZeroOrNegativeCounterValueIsNeverRendered(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NumberSequenceFormat::fromConfiguration([])->render(0, '');
    }

    public function testTheKeyOfALifetimeRunIsEmptyWhateverTheZone(): void
    {
        self::assertSame(
            '',
            NumberSequenceReset::Never->key(new DateTimeImmutable(), new DateTimeZone('Africa/Windhoek')),
        );
    }
}
