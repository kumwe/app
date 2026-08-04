<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Domain;

use Kumwe\CMS\Presentation\Domain\BlockDocument;
use Kumwe\CMS\Presentation\Domain\BlockNode;
use Kumwe\CMS\Presentation\Domain\BlockPropertyType;
use Kumwe\CMS\Presentation\Domain\BlockSchema;
use Kumwe\CMS\Presentation\Domain\BlockSchemaRegistry;
use Kumwe\CMS\Presentation\Domain\InvalidBlockDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlockDocument::class)]
#[UsesClass(BlockNode::class)]
#[UsesClass(BlockPropertyType::class)]
#[UsesClass(BlockSchema::class)]
#[UsesClass(BlockSchemaRegistry::class)]
#[UsesClass(InvalidBlockDocument::class)]
final class BlockDocumentTest extends TestCase
{
    private const ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb200';

    public function testCreatesChecksummedDocumentAndRevisesOptimistically(): void
    {
        $schemas = $this->schemas();
        $firstNode = $this->heading('First');
        $document = BlockDocument::create(self::ID, 1, [$firstNode], $schemas);
        $revised = $document->revise(1, [$this->heading('Second')], $schemas);

        self::assertSame(1, $document->version());
        self::assertSame(2, $revised->version());
        self::assertSame(1, $revised->schemaVersion());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $revised->checksum());
        self::assertNotSame($document->checksum(), $revised->checksum());
    }

    public function testRejectsStaleRevision(): void
    {
        $schemas = $this->schemas();
        $document = BlockDocument::create(self::ID, 1, [$this->heading('First')], $schemas);

        $this->expectException(InvalidBlockDocument::class);

        $document->revise(2, [$this->heading('Second')], $schemas);
    }

    public function testRejectsDuplicateNodeIdentity(): void
    {
        $schemas = $this->schemas();
        $node = $this->heading('First');

        $this->expectException(InvalidBlockDocument::class);

        BlockDocument::create(self::ID, 1, [$node, $node], $schemas);
    }

    public function testChecksumIsIndependentOfAssociativePropertyOrder(): void
    {
        $schema = new BlockSchemaRegistry(new BlockSchema(
            'core.card',
            [
                'title' => BlockPropertyType::String,
                'body' => BlockPropertyType::String,
            ],
        ));
        $first = BlockDocument::create(self::ID, 1, [new BlockNode(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb203',
            'core.card',
            ['title' => 'Title', 'body' => 'Body'],
        )], $schema);
        $second = BlockDocument::create(self::ID, 1, [new BlockNode(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb203',
            'core.card',
            ['body' => 'Body', 'title' => 'Title'],
        )], $schema);

        self::assertSame($first->checksum(), $second->checksum());
    }

    private function schemas(): BlockSchemaRegistry
    {
        return new BlockSchemaRegistry(new BlockSchema(
            'core.heading',
            ['text' => BlockPropertyType::String],
            ['text'],
        ));
    }

    private function heading(string $text): BlockNode
    {
        static $sequence = 0;
        ++$sequence;

        return new BlockNode(
            sprintf('018f22e2-7c8b-7ab0-8f3a-%012d', 88_880_260_000 + $sequence),
            'core.heading',
            ['text' => $text],
        );
    }
}
