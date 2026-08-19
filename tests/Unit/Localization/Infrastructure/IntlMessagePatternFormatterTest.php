<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Localization\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Kumwe\App\Localization\Application\MessageFormattingFailed;
use Kumwe\App\Localization\Domain\LocaleTag;
use Kumwe\App\Localization\Infrastructure\IntlExtensionMissing;
use Kumwe\App\Localization\Infrastructure\IntlMessagePatternFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IntlMessagePatternFormatter::class)]
#[CoversClass(MessageFormattingFailed::class)]
#[CoversClass(IntlExtensionMissing::class)]
final class IntlMessagePatternFormatterTest extends TestCase
{
    private const PLURAL = '{count, plural, =0 {zero} zero {zero-form} one {one-form} two {two-form} '
        . 'few {few-form} many {many-form} other {other-form}}';

    public function testEveryVersionTwoLanguageSelectsItsOwnPluralCategory(): void
    {
        $formatter = new IntlMessagePatternFormatter();
        $expectations = [
            // One category: Simplified Chinese uses `other` for every count.
            ['zh-Hans', 1, 'other-form'],
            ['zh-Hans', 2, 'other-form'],
            ['zh-Hans', 11, 'other-form'],
            // Two categories: the European set separates one from everything else.
            ['en-GB', 1, 'one-form'],
            ['en-GB', 2, 'other-form'],
            ['en-US', 1, 'one-form'],
            ['af', 1, 'one-form'],
            ['af', 5, 'other-form'],
            ['de', 1, 'one-form'],
            ['de', 5, 'other-form'],
            ['es', 1, 'one-form'],
            ['es', 5, 'other-form'],
            ['pt-BR', 1, 'one-form'],
            ['pt-BR', 5, 'other-form'],
            // Three categories: Hebrew distinguishes a dual.
            ['he', 1, 'one-form'],
            ['he', 2, 'two-form'],
            ['he', 7, 'other-form'],
        ];

        foreach ($expectations as [$locale, $count, $expected]) {
            self::assertSame(
                $expected,
                $formatter->format(self::PLURAL, ['count' => $count], LocaleTag::fromString($locale)),
                sprintf('%s with %d', $locale, $count),
            );
        }
    }

    public function testArabicSelectsAllSixOfItsPluralCategories(): void
    {
        $formatter = new IntlMessagePatternFormatter();
        $arabic = LocaleTag::fromString('ar');
        $pattern = '{count, plural, zero {zero-form} one {one-form} two {two-form} few {few-form} '
            . 'many {many-form} other {other-form}}';

        $selected = [];
        foreach ([0, 1, 2, 3, 11, 100] as $count) {
            $selected[] = $formatter->format($pattern, ['count' => $count], $arabic);
        }

        self::assertSame(
            ['zero-form', 'one-form', 'two-form', 'few-form', 'many-form', 'other-form'],
            $selected,
        );
    }

    public function testItSelectsOnGenderAndOnABooleanFlag(): void
    {
        $formatter = new IntlMessagePatternFormatter();
        $locale = LocaleTag::fromString('en-GB');
        $pattern = '{gender, select, female {She approved it} male {He approved it} other {They approved it}}';

        self::assertSame('She approved it', $formatter->format($pattern, ['gender' => 'female'], $locale));
        self::assertSame('They approved it', $formatter->format($pattern, ['gender' => 'unknown'], $locale));
        self::assertSame(
            'yes',
            $formatter->format('{flag, select, true {yes} other {no}}', ['flag' => true], $locale),
        );
        self::assertSame(
            'no',
            $formatter->format('{flag, select, true {yes} other {no}}', ['flag' => false], $locale),
        );
    }

    public function testItFormatsOrdinalsNumbersAndCurrencyPerLocale(): void
    {
        $formatter = new IntlMessagePatternFormatter();
        $ordinal = '{position, selectordinal, one {#st} two {#nd} few {#rd} other {#th}}';

        self::assertSame('1st', $formatter->format($ordinal, ['position' => 1], LocaleTag::fromString('en-GB')));
        self::assertSame('3rd', $formatter->format($ordinal, ['position' => 3], LocaleTag::fromString('en-GB')));
        self::assertSame('11th', $formatter->format($ordinal, ['position' => 11], LocaleTag::fromString('en-GB')));

        $british = $formatter->format('{total, number}', ['total' => 1234567.5], LocaleTag::fromString('en-GB'));
        $german = $formatter->format('{total, number}', ['total' => 1234567.5], LocaleTag::fromString('de'));
        self::assertNotSame($british, $german);
        self::assertStringContainsString('1,234,567', $british);

        $price = $formatter->format('{amount, number, currency}', ['amount' => 42.5], LocaleTag::fromString('en-GB'));
        self::assertStringContainsString('42', $price);
    }

    public function testItFormatsADateInTheLocaleRatherThanInTheProcessLocale(): void
    {
        $formatter = new IntlMessagePatternFormatter();
        $instant = new DateTimeImmutable('2026-08-14 09:30:00', new DateTimeZone('UTC'));

        $british = $formatter->format('{at, date, long}', ['at' => $instant], LocaleTag::fromString('en-GB'));
        $german = $formatter->format('{at, date, long}', ['at' => $instant], LocaleTag::fromString('de'));

        self::assertStringContainsString('2026', $british);
        self::assertStringContainsString('2026', $german);
        self::assertNotSame($british, $german);
    }

    public function testAPatternThatDoesNotCompileIsReportedRatherThanRendered(): void
    {
        $formatter = new IntlMessagePatternFormatter();

        $this->expectException(MessageFormattingFailed::class);
        $this->expectExceptionMessage('cannot be formatted for locale en-GB');

        $formatter->format('{count, plural, one {unterminated', ['count' => 1], LocaleTag::fromString('en-GB'));
    }

    public function testTheMissingExtensionFailureNamesTheExtensionAndTheConsequence(): void
    {
        $failure = IntlExtensionMissing::forMessageFormatting();

        self::assertStringContainsString('intl extension is required', $failure->getMessage());
        self::assertStringContainsString('not degraded to a substituting formatter', $failure->getMessage());
    }
}
