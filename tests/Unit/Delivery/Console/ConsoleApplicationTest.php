<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Console;

use Kumwe\CMS\Tests\Support\TranslatesConsoleOutput;
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
        self::assertStringContainsString('Unknown Kumwe command: unknown', $output->errors[0]);
    }

    /**
     * The command listing renders catalogue wording, not the identifier a description returns.
     *
     * description() returns a message identifier so the summary line can be translated without
     * any command carrying a translator; this pins that the dispatcher, and only the dispatcher,
     * resolves it, and that the banner and heading come from the catalogue too.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheListingResolvesEachDescriptionThroughTheCatalogue(): void
    {
        $output = new BufferedOutput();
        $application = new ConsoleApplication([new SuccessfulCommand()], $output);

        self::assertSame(0, $application->run(['kumwe', 'list']));
        self::assertSame('Kumwe CMS 2.0', $output->lines[0]);
        self::assertSame('Available commands:', $output->lines[1]);
        self::assertStringContainsString('example', $output->lines[2]);
        self::assertStringContainsString('Check whether Kumwe is ready to serve traffic.', $output->lines[2]);
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
        return 'core.console.app_health.description';
    }

    public function execute(array $arguments, Output $output): int
    {
        $output->line($arguments[0] ?? 'missing');

        return 0;
    }
}

final class BufferedOutput implements Output
{
    use TranslatesConsoleOutput;

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
