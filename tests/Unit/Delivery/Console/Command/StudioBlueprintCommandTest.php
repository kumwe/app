<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console\Command;

use Kumwe\App\Delivery\Console\Command\StudioBlueprintCommand;
use Kumwe\App\Studio\Application\Authoring\StudioMachineCompositionGateway;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioProducerHostFactory;
use Kumwe\App\Tests\Support\BuildsStudioCompositionService;
use Kumwe\App\Tests\Support\CapturingMachineConsoleOutput;
use Kumwe\App\Tests\Support\ConsoleCommandFixture;
use Kumwe\App\Tests\Support\RecordingAuditRecorder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins `kumwe studio-blueprint` refusals to the Studio envelope and portable exits `studio-authoring` uses.
 *
 * The real gateway runs over the real composition service and in-memory stores, with a session authority and host
 * factory these invocations must never reach, so the command's grammar, its credential floor and the gateway's
 * own refusals each leave as one `{"ok":false}` envelope on stderr with the category's exit status.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioBlueprintCommand::class)]
final class StudioBlueprintCommandTest extends TestCase
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
     * Remove the token and argument files.
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
     * Every refused invocation prints one closed envelope and the portable status of its category.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsPrintOneEnvelopeWithThePortableStatus(): void
    {
        $argument = tempnam(sys_get_temp_dir(), 'kumwe-blueprint-argument-');
        self::assertIsString($argument);
        file_put_contents($argument, '{}');
        chmod($argument, 0o600);
        $session = ['--session=contexts/key', '--session-generation=session-one', '--argument-file=' . $argument];
        $full = ['content.read', 'studio.mode.blueprint'];
        $cases = [
            'unknown action' => [['retire', ...$session], $full, 65, 'studio_authoring.invalid_request'],
            'no credential floor' => [['open'], ['studio.mode.blueprint'], 77, 'studio_authoring.forbidden'],
            'unknown mode' => [
                ['open', '--content-type=' . self::$compositionTypeId, '--content-type-version=4', '--mode=content'],
                $full,
                65,
                'studio_authoring.invalid_request',
            ],
            'missing coordinate' => [
                ['open', '--content-type-version=4'],
                $full,
                65,
                'studio_authoring.invalid_request',
            ],
            'not provisioned' => [
                ['open', '--content-type=' . self::$compositionTypeId, '--content-type-version=4'],
                $full,
                66,
                'studio_authoring.not_found',
            ],
            'no replay key' => [
                ['save', ...$session, '--expected-revision=initial-a'],
                $full,
                65,
                'studio_authoring.invalid_request',
            ],
        ];
        try {
            foreach ($cases as $label => [$arguments, $capabilities, $status, $code]) {
                $output = new CapturingMachineConsoleOutput();
                $exit = $this->command($capabilities)->execute([...$arguments, ...$this->console->options()], $output);
                $envelope = json_decode(implode("\n", $output->errors), true);

                self::assertSame($status, $exit, $label);
                self::assertSame([], $output->lines, $label);
                self::assertIsArray($envelope, $label);
                self::assertFalse($envelope['ok'], $label);
                self::assertSame($code, $envelope['error']['code'], $label);
            }
        } finally {
            unlink($argument);
        }
    }

    /**
     * Build the command over a gateway whose session authority and host must never be reached.
     *
     * @param   list<string>  $capabilities  Capabilities the console credential holds.
     *
     * @return  StudioBlueprintCommand  Command under test.
     *
     * @since   2.0.0
     */
    private function command(array $capabilities): StudioBlueprintCommand
    {
        return new StudioBlueprintCommand(
            new StudioMachineCompositionGateway(
                $this->compositionService(new RecordingAuditRecorder()),
                (new ReflectionClass(StudioHostSessionAuthority::class))->newInstanceWithoutConstructor(),
                (new ReflectionClass(StudioProducerHostFactory::class))->newInstanceWithoutConstructor(),
            ),
            $this->console->authorizer($capabilities),
        );
    }
}
