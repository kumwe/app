<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Functional\Localization;

use Joomla\DI\Container;
use Kumwe\CMS\Delivery\Console\Command\DemoExportCommand;
use Kumwe\CMS\Delivery\Console\Command\MaterializeExtensionRuntimeCommand;
use Kumwe\CMS\Delivery\Console\Command\MigrateCommand;
use Kumwe\CMS\Delivery\Console\Command\MigrationStatusCommand;
use Kumwe\CMS\Delivery\Console\Command\QueueWorkCommand;
use Kumwe\CMS\Delivery\Console\Command\ScheduleRunCommand;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use Kumwe\CMS\Tests\Support\TranslatesConsoleOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the console prints resolved sentences, not identifiers, when driven for real.
 *
 * A command that names a message the catalogue does not carry still runs: the translator answers with
 * the identifier, so the operator reads `core.console.…` where a sentence belonged. Unit tests with a
 * stubbed sink cannot catch that, because they assert on what the command asked for rather than on
 * what a terminal would show. These cases resolve the container the entry point resolves, run the
 * command against a real database, and assert on the lines themselves.
 *
 * @since  2.0.0
 */
#[CoversClass(DemoExportCommand::class)]
#[CoversClass(MaterializeExtensionRuntimeCommand::class)]
#[CoversClass(MigrateCommand::class)]
#[CoversClass(MigrationStatusCommand::class)]
#[CoversClass(QueueWorkCommand::class)]
#[CoversClass(ScheduleRunCommand::class)]
final class ConsoleWordingTest extends TestCase
{
    /**
     * Container shared across this file's cases; building one installs the whole schema.
     *
     * @var    ?Container
     * @since  2.0.0
     */
    private static ?Container $kernel = null;

    /**
     * The migration status command reports a current schema as a sentence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheMigrationStatusCommandReportsInResolvedWording(): void
    {
        $container = $this->kernel();
        $command = $container->get(MigrationStatusCommand::class);
        self::assertInstanceOf(MigrationStatusCommand::class, $command);
        $output = new RecordingConsoleOutput();

        $status = $command->execute([], $output);

        self::assertContains($status, [0, 2]);
        self::assertNotSame([], $output->lines);
        foreach ($output->lines as $line) {
            self::assertStringNotContainsString('core.console.', $line);
        }
        // Both branches are pinned. Guarding the only real assertion on a status the shared kernel
        // decides is how a case stops proving anything the day the installation changes.
        if ($status === 0) {
            self::assertSame(['Database schema is current.'], $output->lines);

            return;
        }
        foreach ($output->lines as $line) {
            self::assertMatchesRegularExpression('/^Pending [0-9a-z_]+$/', $line);
        }
    }

    /**
     * Exporting a demo profile narrates every step in resolved wording and writes a real package.
     *
     * The export is the one console workflow that prints a whole report rather than a line, so it is
     * where an unresolved identifier would be most visible and least excusable. Driving it end to end
     * also proves the package it describes is the package it wrote. The business half of the report
     * depends on what the shared kernel has installed, so the assertion holds the content half and
     * the absence of identifiers on either stream rather than the exit status.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExportingADemoProfileNarratesItselfInResolvedWording(): void
    {
        $container = $this->kernel();
        $command = $container->get(DemoExportCommand::class);
        self::assertInstanceOf(DemoExportCommand::class, $command);
        $directory = sys_get_temp_dir() . '/kumwe-demo-export-' . bin2hex(random_bytes(8));
        $passwordFile = tempnam(sys_get_temp_dir(), 'kumwe-demo-export-password-');
        self::assertIsString($passwordFile);
        file_put_contents($passwordFile, TestKernelFactory::ADMINISTRATOR_PASSWORD);
        chmod($passwordFile, 0o600);
        $output = new RecordingConsoleOutput();

        try {
            $status = $command->execute([
                '--profile=wordingcheck',
                '--output=' . $directory,
                '--admin-email=' . TestKernelFactory::ADMINISTRATOR_EMAIL,
                '--admin-password-file=' . $passwordFile,
            ], $output);
        } finally {
            unlink($passwordFile);
        }

        $narration = implode("\n", [...$output->lines, ...$output->errors]);
        self::assertNotSame('', $narration);
        self::assertStringNotContainsString('core.console.', $narration);
        if ($status === 0) {
            self::assertMatchesRegularExpression(
                '/^Exported \d+ content entries and \d+ menus as profile wordingcheck\.$/m',
                $narration,
            );
            self::assertMatchesRegularExpression(
                '/^Catalog re-validation checksum [0-9a-f]+\.$/m',
                $narration,
            );
            self::assertStringContainsString($directory, $narration);
            self::assertFileExists($directory . '/resources/demo/content/wordingcheck.json');
        } else {
            // The shared installation publishes far more business definitions than a demo profile
            // carries, and the refusal is as much a narration contract as the success is: it must be a
            // finished English sentence, and nothing may have been written.
            self::assertMatchesRegularExpression(
                '/^The site publishes \d+ business definitions, which exceeds the demo-profile '
                    . 'envelope of \d+; nothing was written\.$/m',
                $narration,
            );
            self::assertDirectoryDoesNotExist($directory);
        }
        $this->removeTree($directory);
    }


    /**
     * Migrating an already-current schema narrates the no-op and the runtime generation as sentences.
     *
     * `database:migrate` is the command an operator runs most often and the one a deployment script
     * reads, so both of its ordinary lines have to resolve.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMigratingACurrentSchemaNarratesItselfInResolvedWording(): void
    {
        $container = $this->kernel();
        $command = $container->get(MigrateCommand::class);
        self::assertInstanceOf(MigrateCommand::class, $command);
        $output = new RecordingConsoleOutput();

        self::assertSame(0, $command->execute([], $output));

        $narration = implode("\n", $output->lines);
        self::assertStringNotContainsString('core.console.', $narration);
        self::assertMatchesRegularExpression('/^Materialized extension runtime generation \d+$/m', $narration);
    }

    /**
     * Materializing the runtime names the generation it published, in resolved wording.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMaterializingTheRuntimeNarratesItselfInResolvedWording(): void
    {
        $container = $this->kernel();
        $command = $container->get(MaterializeExtensionRuntimeCommand::class);
        self::assertInstanceOf(MaterializeExtensionRuntimeCommand::class, $command);
        $output = new RecordingConsoleOutput();

        self::assertSame(0, $command->execute([], $output));

        $narration = implode("\n", $output->lines);
        self::assertStringNotContainsString('core.console.', $narration);
        self::assertMatchesRegularExpression(
            '/^Materialized trusted extension runtime generation \d+\.$/m',
            $narration,
        );
    }

    /**
     * The scheduler and the queue worker both report an idle pass in resolved wording.
     *
     * A worker started by a supervisor prints these lines on every cycle, so an unresolved identifier
     * here would fill an operator's logs rather than one screen.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheWorkersReportAnIdlePassInResolvedWording(): void
    {
        $container = $this->kernel();
        foreach ([ScheduleRunCommand::class, QueueWorkCommand::class] as $class) {
            $command = $container->get($class);
            self::assertInstanceOf($class, $command);
            $output = new RecordingConsoleOutput();

            // --once, not --max-jobs=1: the job budget only stops the loop after a job has been
            // handled, so on an idle queue the worker sleeps and loops forever and this case passes
            // only while some other test happens to leave work behind.
            $status = $command->execute($class === QueueWorkCommand::class ? ['--once'] : [], $output);

            self::assertSame(0, $status, implode("\n", $output->errors));
            foreach ([...$output->lines, ...$output->errors] as $line) {
                self::assertStringNotContainsString('core.console.', $line, $class);
            }
        }
    }

    /**
     * Build, once, the kernel these cases resolve their commands from.
     *
     * @return  Container  The functional test kernel.
     *
     * @since   2.0.0
     */
    private function kernel(): Container
    {
        return self::$kernel ??= TestKernelFactory::create(Environment::fromGlobals());
    }

    /**
     * Remove the exported package tree the case wrote.
     *
     * @param   string  $path  Directory to remove.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}

/** Captures what a terminal would have shown, so the lines themselves can be asserted on. */
final class RecordingConsoleOutput implements Output
{
    use TranslatesConsoleOutput;

    /**
     * Result lines, in the order the command wrote them.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $lines = [];

    /**
     * Failure lines, in the order the command wrote them.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $errors = [];

    /**
     * Record one result line.
     *
     * @param   string  $message  Line the command emitted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    /**
     * Record one failure line.
     *
     * @param   string  $message  Line the command emitted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function error(string $message): void
    {
        $this->errors[] = $message;
    }
}
