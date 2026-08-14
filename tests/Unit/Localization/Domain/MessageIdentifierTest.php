<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Localization\Domain;

use Kumwe\CMS\Localization\Domain\InvalidMessageIdentifier;
use Kumwe\CMS\Localization\Domain\MessageIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageIdentifier::class)]
#[CoversClass(InvalidMessageIdentifier::class)]
final class MessageIdentifierTest extends TestCase
{
    public function testItAcceptsAStableNamespacedDottedIdentifier(): void
    {
        self::assertSame(
            'core.administrator.settings.save_action',
            MessageIdentifier::fromString('core.administrator.settings.save_action')->value,
        );
        self::assertSame('core', MessageIdentifier::fromString('core.site.home.heading')->root());
        self::assertSame(
            'acme.tools.dashboard.title',
            MessageIdentifier::ownedBy('acme.tools.dashboard.title', 'acme.tools')->value,
        );
    }

    public function testItRefusesTheSourceTextAsTheLookupKey(): void
    {
        $this->expectException(InvalidMessageIdentifier::class);
        $this->expectExceptionMessage('reads as source text');

        MessageIdentifier::fromString('Save settings and design');
    }

    public function testItRefusesAnIdentifierEndingInSentencePunctuation(): void
    {
        $this->expectException(InvalidMessageIdentifier::class);
        $this->expectExceptionMessage('reads as source text');

        MessageIdentifier::fromString('core.site.home.heading.');
    }

    public function testItRefusesAnUnnamespacedIdentifierFromAnExtension(): void
    {
        $this->expectException(InvalidMessageIdentifier::class);
        $this->expectExceptionMessage('outside the acme.tools namespace');

        MessageIdentifier::ownedBy('core.dashboard.title.text', 'acme.tools');
    }

    public function testItRefusesAnIdentifierThatDiffersFromAnotherOnlyByCase(): void
    {
        self::assertTrue(MessageIdentifier::isValid('core.site.home.heading'));
        self::assertFalse(MessageIdentifier::isValid('Core.Site.Home.Heading'));

        $this->expectException(InvalidMessageIdentifier::class);

        MessageIdentifier::fromString('core.site.home.Heading');
    }

    public function testItRefusesAnIdentifierWithFewerThanThreeSegments(): void
    {
        self::assertFalse(MessageIdentifier::isValid('core.save'));
        self::assertFalse(MessageIdentifier::isValid('save'));
        self::assertTrue(MessageIdentifier::isValid('core.site.save'));
    }

    public function testItRefusesMalformedSegmentsAndOverLongIdentifiers(): void
    {
        self::assertFalse(MessageIdentifier::isValid('core..site.heading'));
        self::assertFalse(MessageIdentifier::isValid('core.site.heading.'));
        self::assertFalse(MessageIdentifier::isValid('_core.site.heading'));
        self::assertFalse(MessageIdentifier::isValid('core.site.' . str_repeat('a', 200)));
        self::assertFalse(MessageIdentifier::isValid(''));
    }

    public function testItKeepsARejectedValuePrintableInsideTheMessage(): void
    {
        $this->expectException(InvalidMessageIdentifier::class);
        $this->expectExceptionMessage('"core.site.?heading"');

        MessageIdentifier::fromString("core.site.\u{0007}heading");
    }
}
