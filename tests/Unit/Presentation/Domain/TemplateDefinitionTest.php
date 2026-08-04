<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Domain;

use InvalidArgumentException;
use Kumwe\CMS\Presentation\Domain\TemplateDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TemplateDefinition::class)]
final class TemplateDefinitionTest extends TestCase
{
    public function testExposesOnlyDeclaredSlots(): void
    {
        $template = new TemplateDefinition(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb210',
            'core.site',
            ['header', 'main', 'footer'],
        );

        self::assertSame('core.site', $template->handle());
        self::assertTrue($template->hasSlot('main'));
        self::assertFalse($template->hasSlot('unknown'));
    }

    public function testRejectsDuplicateSlots(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TemplateDefinition(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb210',
            'core.site',
            ['main', 'main'],
        );
    }
}
