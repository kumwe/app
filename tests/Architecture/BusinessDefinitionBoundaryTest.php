<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversNothing]
final class BusinessDefinitionBoundaryTest extends TestCase
{
    public function testBusinessDefinitionContextDoesNotReuseCmsContentOrExecutableSchemas(): void
    {
        $root = dirname(__DIR__, 2) . '/src/BusinessDefinition';
        $source = '';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $contents = file_get_contents($file->getPathname());
                self::assertIsString($contents);
                $source .= $contents;
            }
        }
        self::assertStringNotContainsString('Kumwe\\CMS\\Content\\', $source);
        self::assertDoesNotMatchRegularExpression('/\beval\s*\(/i', $source);
        self::assertStringNotContainsString('business_records', $source);
        self::assertStringNotContainsString('EAV', $source);
    }
}
