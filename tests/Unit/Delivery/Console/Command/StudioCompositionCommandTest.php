<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console\Command;

use Kumwe\App\Delivery\Console\Command\StudioCompositionCommand;
use Kumwe\App\Studio\Application\Composition\StudioContentCompositionService;
use Kumwe\App\Tests\Support\BuildsStudioCompositionService;
use Kumwe\App\Tests\Support\CapturingMachineConsoleOutput;
use Kumwe\App\Tests\Support\ConsoleCommandFixture;
use Kumwe\App\Tests\Support\RecordingAuditRecorder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins `kumwe studio-composition` to the administrator composition screen's read and provision.
 *
 * The real `StudioContentCompositionService` runs over in-memory bindings and artifacts, so the draft, its locks,
 * the theme check and the audit event are the service's; the command's job is the console grammar, both screen
 * capabilities, the REST document and one error line with exit status 1 for any refusal.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioCompositionCommand::class)]
final class StudioCompositionCommandTest extends TestCase
{
    use BuildsStudioCompositionService;

    /**
     * Console authorization fixture of the running test.
     *
     * @var    ConsoleCommandFixture
     * @since  2.0.0
     */
    private ConsoleCommandFixture $console;

    /**
     * Recorder the composition service audits to.
     *
     * @var    RecordingAuditRecorder
     * @since  2.0.0
     */
    private RecordingAuditRecorder $audit;

    /**
     * Composition service every command of the running test shares.
     *
     * @var    StudioContentCompositionService
     * @since  2.0.0
     */
    private StudioContentCompositionService $service;

    /**
     * Build a fresh recorder, composition service and console fixture.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $this->console = new ConsoleCommandFixture();
        $this->audit = new RecordingAuditRecorder();
        $this->service = $this->compositionService($this->audit);
    }

    /**
     * Remove the token file.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        $this->console->cleanup();
    }

    /**
     * Provisioning prints the draft once and every later read or provision prints the same document.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProvisionPrintsTheDraftAndReadsEchoIt(): void
    {
        $command = $this->command(['content.read', 'studio.mode.blueprint']);
        $coordinate = ['--content-type=' . self::$compositionTypeId, '--version=4'];

        $provisioned = $this->invoke($command, ['provision', ...$coordinate]);

        self::assertSame(self::$compositionTypeId, $provisioned['content_type_id']);
        self::assertSame(4, $provisioned['content_type_version']);
        self::assertSame('draft', $provisioned['blueprint']['status']);
        self::assertSame($provisioned, $this->invoke($command, ['get', ...$coordinate]));
        self::assertSame($provisioned, $this->invoke($command, ['provision', ...$coordinate]));
        self::assertSame(['studio.composition.provision'], $this->audit->actions());
    }

    /**
     * Unknown actions, missing grants, bad coordinates, unprovisioned versions and a stale theme fail one line.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsExitOneWithOneErrorLine(): void
    {
        $full = ['content.read', 'studio.mode.blueprint'];
        $command = $this->command($full);
        $coordinate = ['--content-type=' . self::$compositionTypeId, '--version=4'];
        $cases = [
            [['publish', ...$coordinate], $full, 'Unsupported studio-composition action.'],
            [['get', ...$coordinate], ['content.read'], null],
            [['provision', ...$coordinate], ['studio.mode.blueprint'], null],
            [['get', '--version=4'], $full, null],
            [['get', '--content-type=' . self::$compositionTypeId, '--version=0'], $full, null],
            [
                ['get', ...$coordinate],
                $full,
                'No Blueprint composition is provisioned for this Content type version.',
            ],
            [
                ['provision', '--content-type=018f22e2-7c8b-7ab0-8f3a-88e8026be999', '--version=4'],
                $full,
                'The requested content projection is unavailable.',
            ],
        ];
        foreach ($cases as [$arguments, $capabilities, $message]) {
            $this->assertRefused($this->command($capabilities), $arguments, $message);
        }
        self::assertSame([], $this->audit->actions());

        $this->invoke($command, ['provision', ...$coordinate]);
        $this->retheme();

        $stale = 'The Studio Blueprint public-theme lock requires an explicit migration.';
        $this->assertRefused($command, ['get', ...$coordinate], $stale);
        $this->assertRefused($command, ['provision', ...$coordinate], $stale);
    }

    /**
     * Run one refused invocation and assert its single error line.
     *
     * @param   StudioCompositionCommand  $command    Command under test.
     * @param   list<string>              $arguments  Action and options before the authorization options.
     * @param   ?string                   $message    Exact error line, or null when only its presence matters.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertRefused(StudioCompositionCommand $command, array $arguments, ?string $message): void
    {
        $output = new CapturingMachineConsoleOutput();
        $status = $command->execute([...$arguments, ...$this->console->options()], $output);

        self::assertSame(1, $status, implode(' ', $arguments));
        self::assertSame([], $output->lines);
        self::assertCount(1, $output->errors);
        if ($message !== null) {
            self::assertSame($message, $output->errors[0]);
        }
    }

    /**
     * Run one successful invocation and decode its JSON document.
     *
     * @param   StudioCompositionCommand  $command    Command under test.
     * @param   list<string>              $arguments  Action and options before the authorization options.
     *
     * @return  array<string, mixed>  Decoded result.
     *
     * @since   2.0.0
     */
    private function invoke(StudioCompositionCommand $command, array $arguments): array
    {
        $output = new CapturingMachineConsoleOutput();
        $status = $command->execute([...$arguments, ...$this->console->options()], $output);
        self::assertSame(0, $status, implode("\n", $output->errors));
        $decoded = json_decode(implode("\n", $output->lines), true, 64);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Build the command over the test's real composition service.
     *
     * @param   list<string>  $capabilities  Capabilities the console credential holds.
     *
     * @return  StudioCompositionCommand  Command under test.
     *
     * @since   2.0.0
     */
    private function command(array $capabilities): StudioCompositionCommand
    {
        return new StudioCompositionCommand($this->service, $this->console->authorizer($capabilities));
    }
}
