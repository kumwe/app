<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Domain;

use InvalidArgumentException;
use Kumwe\CMS\Presentation\Domain\BlockSchema;
use Kumwe\CMS\Presentation\Domain\BlockSchemaRegistry;
use Kumwe\CMS\Presentation\Domain\InvalidBlockDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlockSchemaRegistry::class)]
#[UsesClass(BlockSchema::class)]
#[UsesClass(InvalidBlockDocument::class)]
final class BlockSchemaRegistryTest extends TestCase
{
    public function testReturnsOnlyExplicitlyRegisteredSchema(): void
    {
        $schema = new BlockSchema('core.heading', []);

        self::assertSame($schema, (new BlockSchemaRegistry($schema))->schemaFor('core.heading'));
    }

    public function testRejectsDuplicateRegistration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BlockSchemaRegistry(new BlockSchema('core.heading', []), new BlockSchema('core.heading', []));
    }

    public function testRejectsUnknownBlockType(): void
    {
        $this->expectException(InvalidBlockDocument::class);

        (new BlockSchemaRegistry())->schemaFor('extension.unknown');
    }
}
