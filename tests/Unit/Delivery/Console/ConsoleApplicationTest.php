<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Console;

use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\ConsoleApplication;
use Kumwe\CMS\Delivery\Console\Output;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsoleApplication::class)]
final class ConsoleApplicationTest extends TestCase
{
    public function testDispatchesKnownCommand(): void
    {
        $output = new BufferedOutput();
        $application = new ConsoleApplication([new SuccessfulCommand()], $output);

        self::assertSame(0, $application->run(['kumwe', 'example', 'value']));
        self::assertSame(['value'], $output->lines);
    }

    public function testUnknownCommandHasUsageExitCode(): void
    {
        $output = new BufferedOutput();
        $application = new ConsoleApplication([], $output);

        self::assertSame(64, $application->run(['kumwe', 'unknown']));
        self::assertStringContainsString('Unknown Kumwe command', $output->errors[0]);
    }
}

final class SuccessfulCommand implements Command
{
    public function name(): string
    {
        return 'example';
    }

    public function description(): string
    {
        return 'Example command.';
    }

    public function execute(array $arguments, Output $output): int
    {
        $output->line($arguments[0] ?? 'missing');

        return 0;
    }
}

final class BufferedOutput implements Output
{
    /** @var list<string> */
    public array $lines = [];

    /** @var list<string> */
    public array $errors = [];

    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }
}
