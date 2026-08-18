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
     * Proves the command listing resolves every description through the message catalogue.
     *
     * The summary beside each command name is user-facing text on a translatable surface, so it is looked up
     *      * rather than written inline. Pinning it here keeps a future command from reintroducing an English
     *      * literal that no catalogue could ever translate.
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
