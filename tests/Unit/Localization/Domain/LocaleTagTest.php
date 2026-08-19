<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Localization\Domain;

use Kumwe\App\Localization\Domain\InvalidLocaleTag;
use Kumwe\App\Localization\Domain\LocaleTag;
use Kumwe\App\Localization\Domain\TextDirection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocaleTag::class)]
#[CoversClass(TextDirection::class)]
#[CoversClass(InvalidLocaleTag::class)]
final class LocaleTagTest extends TestCase
{
    public function testItNormalisesCasingAndSeparatorsSoTwoSpellingsAreOneValue(): void
    {
        self::assertSame('pt-BR', LocaleTag::fromString('pt_br')->toString());
        self::assertSame('pt-BR', LocaleTag::fromString('PT-BR')->toString());
        self::assertSame('zh-Hans', LocaleTag::fromString('zh-HANS')->toString());
        self::assertSame('en-GB', LocaleTag::fromString('  en-gb ')->toString());
        self::assertTrue(LocaleTag::fromString('pt_BR')->equals(LocaleTag::fromString('pt-br')));
    }

    public function testItDerivesTheWritingDirectionFromTheScriptThenTheLanguage(): void
    {
        self::assertSame(TextDirection::RightToLeft, LocaleTag::fromString('he')->direction());
        self::assertSame(TextDirection::RightToLeft, LocaleTag::fromString('ar')->direction());
        self::assertSame(TextDirection::RightToLeft, LocaleTag::fromString('ar-EG')->direction());
        self::assertSame(TextDirection::LeftToRight, LocaleTag::fromString('en-GB')->direction());
        self::assertSame(TextDirection::LeftToRight, LocaleTag::fromString('zh-Hans')->direction());
        self::assertSame(TextDirection::RightToLeft, LocaleTag::fromString('az-Arab')->direction());
        self::assertSame(TextDirection::LeftToRight, LocaleTag::fromString('az-Latn')->direction());
        self::assertSame('rtl', TextDirection::RightToLeft->value);
        self::assertSame('ltr', TextDirection::LeftToRight->value);
    }

    public function testItFallsBackFromTheMostSpecificFormToTheBareLanguage(): void
    {
        self::assertSame(['pt-BR', 'pt'], LocaleTag::fromString('pt-BR')->fallbacks());
        self::assertSame(['zh-Hans', 'zh'], LocaleTag::fromString('zh-Hans')->fallbacks());
        self::assertSame(['zh-Hans-CN', 'zh-Hans', 'zh'], LocaleTag::fromString('zh-Hans-CN')->fallbacks());
        self::assertSame(['af'], LocaleTag::fromString('af')->fallbacks());
    }

    public function testItRefusesAValueThatIsNotALanguageTag(): void
    {
        $this->expectException(InvalidLocaleTag::class);
        $this->expectExceptionMessage('"../../etc/passwd"');

        LocaleTag::fromString('../../etc/passwd');
    }

    public function testItRefusesATagCarryingMoreThanLanguageScriptAndRegion(): void
    {
        $this->expectException(InvalidLocaleTag::class);

        LocaleTag::fromString('en-GB-oxendict-u-ca-gregory');
    }
}
