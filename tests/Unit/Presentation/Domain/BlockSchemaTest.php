<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Domain;

use Kumwe\CMS\Presentation\Domain\BlockNode;
use Kumwe\CMS\Presentation\Domain\BlockPropertyType;
use Kumwe\CMS\Presentation\Domain\BlockSchema;
use Kumwe\CMS\Presentation\Domain\InvalidBlockDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlockSchema::class)]
#[UsesClass(BlockNode::class)]
#[UsesClass(BlockPropertyType::class)]
#[UsesClass(InvalidBlockDocument::class)]
final class BlockSchemaTest extends TestCase
{
    public function testValidatesPropertiesRequiredFieldsAndChildren(): void
    {
        $schema = new BlockSchema(
            'core.container',
            ['width' => BlockPropertyType::String],
            ['width'],
            ['core.heading'],
            true,
        );
        $node = new BlockNode(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb201',
            'core.container',
            ['width' => 'wide'],
            [new BlockNode('018f22e2-7c8b-7ab0-8f3a-88e8026bb202', 'core.heading')],
        );

        $schema->validate($node);

        self::assertSame('core.container', $schema->type());
    }

    public function testRejectsUndeclaredProperty(): void
    {
        $schema = new BlockSchema('core.heading', ['text' => BlockPropertyType::String]);

        $this->expectException(InvalidBlockDocument::class);

        $schema->validate(new BlockNode(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb201',
            'core.heading',
            ['html' => '<script>'],
        ));
    }
}
