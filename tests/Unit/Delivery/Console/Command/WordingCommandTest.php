<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console\Command;

use Kumwe\App\Delivery\Console\Command\WordingCommand;
use Kumwe\App\Tests\Support\CapturingMachineConsoleOutput;
use Kumwe\App\Tests\Support\ConsoleCommandFixture;
use Kumwe\App\Tests\Support\InMemoryWording;
use Kumwe\App\Tests\Support\RecordingAuditRecorder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins `kumwe wording` to the administrator Wording screen's list, search, save and withdraw.
 *
 * The real `MessageOverrideService` runs over an in-memory store, so `localization.overrides.manage`, the
 * administered-layer and carried-locale checks, identifier grammar, ICU validation and the audit events are the
 * service's; the command's job is the console grammar, the JSON shapes REST also answers, and one error line
 * with exit status 1 for any refusal.
 *
 * @since  2.0.0
 */
#[CoversClass(WordingCommand::class)]
final class WordingCommandTest extends TestCase
{
    /**
     * Console authorization fixture of the running test.
     *
     * @var    ConsoleCommandFixture
     * @since  2.0.0
     */
    private ConsoleCommandFixture $console;

    /**
     * In-memory wording store.
     *
     * @var    InMemoryWording
     * @since  2.0.0
     */
    private InMemoryWording $wording;

    /**
     * Recorder the wording service audits to.
     *
     * @var    RecordingAuditRecorder
     * @since  2.0.0
     */
    private RecordingAuditRecorder $audit;

    /**
     * Build a fresh store, recorder and console fixture.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $this->console = new ConsoleCommandFixture();
        $this->wording = new InMemoryWording();
        $this->audit = new RecordingAuditRecorder();
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
     * Save, list, search and withdraw answer the REST shapes through the service.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSaveListSearchAndWithdrawMatchTheWordingScreen(): void
    {
        $saved = $this->invoke([
            'save',
            '--locale=en-GB',
            '--identifier=' . InMemoryWording::IDENTIFIER,
            '--pattern=Customer',
        ]);
        self::assertSame('Customer', $saved['pattern']);

        $listed = $this->invoke(['overrides', '--layer=site', '--locale=en-GB']);
        self::assertSame('site', $listed['layer']);
        self::assertSame('en-GB', $listed['locale']);
        self::assertSame([InMemoryWording::IDENTIFIER], array_column($listed['items'], 'identifier'));
        self::assertNull($this->invoke(['overrides'])['locale']);

        $found = $this->invoke(['catalogue', '--locale=en-GB', '--query=client', '--limit=10']);
        self::assertSame('en-GB', $found['locale']);
        self::assertSame([InMemoryWording::IDENTIFIER], array_column($found['items'], 'identifier'));
        self::assertSame($found, $this->invoke(['catalogue', '--locale=en-GB', '--query=client']));

        $withdraw = ['withdraw', '--locale=en-GB', '--identifier=' . InMemoryWording::IDENTIFIER];
        self::assertSame(['withdrawn' => true], $this->invoke($withdraw));
        self::assertSame(['withdrawn' => false], $this->invoke($withdraw));
        self::assertSame([], $this->invoke(['overrides'])['items']);
        self::assertCount(2, $this->audit->events);
    }

    /**
     * Unknown actions, unadministered layers, bad bounds, broken patterns and missing grants fail one line.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsExitOneWithOneErrorLine(): void
    {
        $save = ['save', '--locale=en-GB', '--identifier=' . InMemoryWording::IDENTIFIER];
        foreach (
            [
                [['purge'], null, 'Unsupported wording action.'],
                [['overrides', '--layer=core'], null, 'An administered wording layer is required.'],
                [
                    ['catalogue', '--locale=en-GB', '--limit=201'],
                    null,
                    'The wording search limit must be between 1 and 200.',
                ],
                [['catalogue'], null, null],
                [[...$save, '--pattern=Broken {'], null, null],
                [['save', '--locale=en-GB', '--identifier=Not An Identifier', '--pattern=A'], null, null],
                [['overrides'], ['content.read'], null],
            ] as [$arguments, $capabilities, $message]
        ) {
            $output = new CapturingMachineConsoleOutput();
            $status = $this->command($capabilities ?? ['localization.overrides.manage'])
                ->execute([...$arguments, ...$this->console->options()], $output);

            self::assertSame(1, $status, implode(' ', $arguments));
            self::assertSame([], $output->lines);
            self::assertCount(1, $output->errors);
            if ($message !== null) {
                self::assertSame($message, $output->errors[0]);
            }
        }
        self::assertSame([], $this->audit->events);
    }

    /**
     * Run one successful invocation and decode its JSON document.
     *
     * @param   list<string>  $arguments  Action and options before the authorization options.
     *
     * @return  array<string, mixed>  Decoded result.
     *
     * @since   2.0.0
     */
    private function invoke(array $arguments): array
    {
        $output = new CapturingMachineConsoleOutput();
        $status = $this->command(['localization.overrides.manage'])
            ->execute([...$arguments, ...$this->console->options()], $output);
        self::assertSame(0, $status, implode("\n", $output->errors));
        $decoded = json_decode(implode("\n", $output->lines), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Build the command over the real wording service.
     *
     * @param   list<string>  $capabilities  Capabilities the console credential holds.
     *
     * @return  WordingCommand  Command under test.
     *
     * @since   2.0.0
     */
    private function command(array $capabilities): WordingCommand
    {
        return new WordingCommand($this->wording->service($this->audit), $this->console->authorizer($capabilities));
    }
}
