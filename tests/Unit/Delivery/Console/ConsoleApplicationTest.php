<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console;

use LogicException;
use Kumwe\App\Tests\Support\TranslatesConsoleOutput;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\ConsoleApplication;
use Kumwe\App\Delivery\Console\Output;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsoleApplication::class)]
final class ConsoleApplicationTest extends TestCase
{
    public function testDispatchesKnownCommand(): void
    {
        $output = new BufferedOutput();
        $application = new ConsoleApplication([new SuccessfulCommand()], $output);

        self::assertSame(0, $application->run(['kumwe', 'extension:conformance', '/tmp/package.zip']));
        self::assertSame(['/tmp/package.zip'], $output->lines);
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
        self::assertSame('Kumwe App 2.0', $output->lines[0]);
        self::assertSame('Available commands:', $output->lines[1]);
        self::assertStringContainsString('extension:conformance', $output->lines[2]);
        self::assertStringContainsString('Check whether Kumwe is ready to serve traffic.', $output->lines[2]);
    }

    /**
     * An option not frozen for the selected action is refused before command code runs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnknownOptionIsAUsageFailureBeforeDispatch(): void
    {
        $output = new BufferedOutput();
        $application = new ConsoleApplication([new SuccessfulCommand()], $output);

        self::assertSame(64, $application->run([
            'kumwe',
            'extension:conformance',
            '/tmp/package.zip',
            '--password=must-not-reach-command',
        ]));
        self::assertSame([], $output->lines);
        self::assertStringContainsString('--password option is unknown', $output->errors[0]);
    }

    /**
     * A duplicate live registration is a composition defect rather than last-registration-wins behavior.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDuplicateCommandRegistrationIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('registered more than once');

        new ConsoleApplication([new SuccessfulCommand(), new SuccessfulCommand()], new BufferedOutput());
    }

    /**
     * An implementation cannot introduce an exit status absent from the retained contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUndeclaredExitStatusIsRejected(): void
    {
        $application = new ConsoleApplication([new UndeclaredExitCommand()], new BufferedOutput());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('undeclared exit code 70');

        $application->run(['kumwe', 'extension:conformance', '/tmp/package.zip']);
    }
}

final class SuccessfulCommand implements Command
{
    public function name(): string
    {
        return 'extension:conformance';
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

/**
 * Test command that deliberately violates its retained exit-code contract.
 *
 * @since  2.0.0
 */
final class UndeclaredExitCommand implements Command
{
    /**
     * Return a real retained name so only the exit status violates the contract.
     *
     * @return  string  Extension conformance command name.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'extension:conformance';
    }

    /**
     * Return a stable catalogue message identifier.
     *
     * @return  string  Health description identifier used by the fixture.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.app_health.description';
    }

    /**
     * Return an intentionally undeclared status.
     *
     * @param   list<string>  $arguments  Validated fixture arguments.
     * @param   Output        $output     Unused fixture output.
     *
     * @return  int  Deliberately undeclared software error code.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        return 70;
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
