<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Domain;

use InvalidArgumentException;
use Kumwe\CMS\Presentation\Domain\BlockNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlockNode::class)]
final class BlockNodeTest extends TestCase
{
    private const ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb201';

    public function testExportsClosedStructuredTree(): void
    {
        $child = new BlockNode(self::ID, 'core.heading', ['text' => 'Welcome']);
        $root = new BlockNode(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb202',
            'core.container',
            ['width' => 'wide'],
            [$child],
        );

        self::assertSame('core.container', $root->type());
        self::assertSame([$child], $root->children());
        self::assertSame('core.heading', $root->toArray()['children'][0]['type']);
    }

    public function testRejectsObjectProperties(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BlockNode(self::ID, 'core.heading', ['unsafe' => new \stdClass()]);
    }
}
