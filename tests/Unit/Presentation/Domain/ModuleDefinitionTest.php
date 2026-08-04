<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Domain;

use InvalidArgumentException;
use Kumwe\CMS\Presentation\Domain\ModuleDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleDefinition::class)]
final class ModuleDefinitionTest extends TestCase
{
    public function testValidatesRequiredSettingsContract(): void
    {
        $module = new ModuleDefinition(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb211',
            'core.menu',
            ['menu_id'],
        );

        $module->validateSettings(['menu_id' => 'main']);

        self::assertSame('core.menu', $module->handle());
    }

    public function testRejectsMissingRequiredSetting(): void
    {
        $module = new ModuleDefinition(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb211',
            'core.menu',
            ['menu_id'],
        );

        $this->expectException(InvalidArgumentException::class);

        $module->validateSettings([]);
    }
}
