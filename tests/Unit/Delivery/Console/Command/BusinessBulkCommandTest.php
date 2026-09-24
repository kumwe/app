<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console\Command;

use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceOperation;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceUseCases;
use Kumwe\App\Delivery\Console\Command\BusinessBulkCommand;
use Kumwe\App\Delivery\Console\Command\BusinessConsoleFailureMapper;
use Kumwe\App\Delivery\Console\Command\BusinessRecordConsolePresenter;
use Kumwe\App\Tests\Support\CapturingMachineConsoleOutput;
use Kumwe\App\Tests\Support\ConsoleCommandFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins `kumwe business-bulk` to the shared bulk use case and the `business-record` envelopes.
 *
 * The command authorizes the action's record capability, hands the REST selection document, the bulk identity and
 * a bulk action's handle and protected input to `BusinessSurfaceUseCases::bulk()` on the console surface, and maps
 * every refusal, including a stale member's conflict, to the business failure envelope and portable exit.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessBulkCommand::class)]
final class BusinessBulkCommandTest extends TestCase
{
    /**
     * REST selection document every case submits.
     *
     * @var    list<array{record_id: string, expected_version: int}>
     * @since  2.0.0
     */
    private const array ITEMS = [
        ['record_id' => 'invoice-7', 'expected_version' => 4],
        ['record_id' => 'invoice-8', 'expected_version' => 2],
    ];

    /**
     * Console authorization fixture of the running test.
     *
     * @var    ConsoleCommandFixture
     * @since  2.0.0
     */
    private ConsoleCommandFixture $console;

    /**
     * Build a fresh console fixture.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $this->console = new ConsoleCommandFixture();
    }

    /**
     * Remove the token and input files.
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
     * A bulk action reaches the use case with the selection, identity, handle and protected input.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testABulkActionReachesTheSharedUseCaseOnTheConsoleSurface(): void
    {
        $input = tempnam(sys_get_temp_dir(), 'kumwe-bulk-input-');
        self::assertIsString($input);
        file_put_contents($input, '{"channel":"email"}');
        chmod($input, 0o600);
        $surfaces = $this->createMock(BusinessSurfaceUseCases::class);
        $surfaces->expects(self::once())->method('bulk')->with(
            self::anything(),
            BusinessSurface::Cli,
            'core.invoice',
            BusinessSurfaceOperation::Action,
            self::ITEMS,
            'bulk-console-0001',
            'send',
            ['channel' => 'email'],
        )->willReturn(['operation' => 'action', 'count' => 2, 'items' => []]);
        $output = new CapturingMachineConsoleOutput();

        $status = $this->command($surfaces, ['business.record.action'])->execute([
            'action',
            '--definition=core.invoice',
            '--items=' . json_encode(self::ITEMS, JSON_THROW_ON_ERROR),
            '--operation-id=bulk-console-0001',
            '--action=send',
            '--input-file=' . $input,
            ...$this->console->options(),
        ], $output);
        unlink($input);

        self::assertSame(0, $status, implode("\n", $output->errors));
        self::assertSame([
            'ok' => true,
            'data' => ['operation' => 'action', 'count' => 2, 'items' => []],
            'meta' => ['action' => 'action', 'surface' => 'cli'],
        ], json_decode(implode("\n", $output->lines), true));
    }

    /**
     * Unsupported actions, stray action options, malformed items, missing grants and conflicts are mapped.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsLeaveAsMappedFailureEnvelopes(): void
    {
        $idle = $this->createMock(BusinessSurfaceUseCases::class);
        $idle->expects(self::never())->method('bulk');
        $stale = $this->createStub(BusinessSurfaceUseCases::class);
        $stale->method('bulk')->willThrowException(new BusinessRecordVersionConflict(4, 5));
        $base = [
            '--definition=core.invoice',
            '--items=' . json_encode(self::ITEMS, JSON_THROW_ON_ERROR),
            '--operation-id=bulk-console-0002',
        ];

        self::assertSame([64, 'invocation.invalid'], $this->refused($idle, ['business.record.archive'], ['purge']));
        self::assertSame(
            [64, 'invocation.invalid'],
            $this->refused($idle, ['business.record.archive'], ['archive', ...$base, '--action=send']),
        );
        self::assertSame(
            [64, 'invocation.invalid'],
            $this->refused($idle, ['business.record.archive'], ['archive', '--definition=core.invoice', '--items={}']),
        );
        self::assertSame(
            [77, 'authorization.denied'],
            $this->refused($idle, ['business.record.read'], ['restore', ...$base]),
        );
        self::assertSame(
            [73, 'business_record.version_conflict'],
            $this->refused($stale, ['business.record.archive'], ['archive', ...$base]),
        );
    }

    /**
     * Run one refused invocation and answer its exit status and failure code.
     *
     * @param   BusinessSurfaceUseCases  $surfaces      Use cases the command may reach.
     * @param   list<string>             $capabilities  Capabilities the console credential holds.
     * @param   list<string>             $arguments     Action and options before the authorization options.
     *
     * @return  array{int, mixed}  Exit status and the envelope's error code.
     *
     * @since   2.0.0
     */
    private function refused(BusinessSurfaceUseCases $surfaces, array $capabilities, array $arguments): array
    {
        $output = new CapturingMachineConsoleOutput();
        $status = $this->command($surfaces, $capabilities)->execute(
            [...$arguments, ...$this->console->options()],
            $output,
        );
        self::assertSame([], $output->lines);
        $envelope = json_decode(implode("\n", $output->errors), true);
        self::assertIsArray($envelope);

        return [$status, $envelope['error']['code'] ?? null];
    }

    /**
     * Build the command over the given use cases.
     *
     * @param   BusinessSurfaceUseCases  $surfaces      Use cases.
     * @param   list<string>             $capabilities  Capabilities the console credential holds.
     *
     * @return  BusinessBulkCommand  Command under test.
     *
     * @since   2.0.0
     */
    private function command(BusinessSurfaceUseCases $surfaces, array $capabilities): BusinessBulkCommand
    {
        return new BusinessBulkCommand(
            $surfaces,
            $this->console->authorizer($capabilities),
            new BusinessRecordConsolePresenter(),
            new BusinessConsoleFailureMapper(),
        );
    }
}
