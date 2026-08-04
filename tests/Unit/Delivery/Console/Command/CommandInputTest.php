<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\CMS\Delivery\Console\Command\CommandInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommandInput::class)]
final class CommandInputTest extends TestCase
{
    public function testJsonObjectAcceptsAnEmptyObject(): void
    {
        self::assertSame([], CommandInput::jsonObject(['payload' => '{}'], 'payload'));
    }

    public function testJsonObjectPreservesNestedObjectData(): void
    {
        self::assertSame(
            ['context' => ['site' => 'main'], 'enabled' => true],
            CommandInput::jsonObject(
                ['payload' => '{"context":{"site":"main"},"enabled":true}'],
                'payload',
            ),
        );
    }

    public function testJsonObjectRejectsAList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The --payload option must be a JSON object.');

        CommandInput::jsonObject(['payload' => '[]'], 'payload');
    }
}
