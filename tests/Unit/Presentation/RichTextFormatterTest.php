<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Presentation;

use Kumwe\App\Presentation\RichTextFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RichTextFormatter::class)]
final class RichTextFormatterTest extends TestCase
{
    public function testItFormatsTheSupportedAuthoringControls(): void
    {
        $result = (new RichTextFormatter())->format(
            "## A safe heading\n\nA **strong** [link](/pages/about).\n\n- One\n- Two",
        );

        self::assertSame(
            '<h2>A safe heading</h2>' . "\n"
            . '<p>A <strong>strong</strong> <a href="/pages/about">link</a>.</p>' . "\n"
            . '<ul><li>One</li><li>Two</li></ul>',
            $result,
        );
    }

    public function testItEscapesHtmlAndDoesNotLinkUnsafeSchemes(): void
    {
        $result = (new RichTextFormatter())->format('<script>alert(1)</script> [unsafe](javascript:evil)');

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $result);
        self::assertStringContainsString('[unsafe](javascript:evil)', $result);
        self::assertStringNotContainsString('<script>', $result);
        self::assertStringNotContainsString('href=', $result);
    }
}
