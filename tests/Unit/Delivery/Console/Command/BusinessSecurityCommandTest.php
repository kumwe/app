<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console\Command;

use Kumwe\App\Delivery\Console\Command\BusinessSecurityCommand;
use Kumwe\App\Tests\Support\BuildsBusinessSecurityService;
use Kumwe\App\Tests\Support\CapturingMachineConsoleOutput;
use Kumwe\App\Tests\Support\ConsoleCommandFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins `kumwe business-security overview` to the Business Security screen's read model.
 *
 * The real `BusinessSecurityAdministrationService` runs over a repository stub, so the capability gate and the
 * token and step-up redaction are the service's; the command only prints the overview and turns a refusal into
 * one error line and exit status 1. It has no write action.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessSecurityCommand::class)]
final class BusinessSecurityCommandTest extends TestCase
{
    use BuildsBusinessSecurityService;

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
     * The overview is printed with the service's redactions applied.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheOverviewIsPrintedWithTheServicesRedactions(): void
    {
        $output = new CapturingMachineConsoleOutput();

        $status = $this->command(['business.security.manage'])
            ->execute(['overview', ...$this->console->options()], $output);

        self::assertSame(0, $status, implode("\n", $output->errors));
        $document = json_decode(implode("\n", $output->lines), true);
        self::assertIsArray($document);
        self::assertSame([['identifier' => 'north', 'name' => 'North']], $document['organizations']);
        self::assertSame([], $document['tokens']);
        self::assertSame([], $document['step_up_credentials']);
    }

    /**
     * Any other action and a credential without `business.security.manage` fail with one error line.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsExitOneWithOneErrorLine(): void
    {
        foreach ([[['organization-create'], ['business.security.manage']], [['overview'], ['content.read']]] as $case) {
            [$arguments, $capabilities] = $case;
            $output = new CapturingMachineConsoleOutput();

            $status = $this->command($capabilities)->execute([...$arguments, ...$this->console->options()], $output);

            self::assertSame(1, $status);
            self::assertSame([], $output->lines);
            self::assertCount(1, $output->errors);
        }
    }

    /**
     * Build the command over the real service.
     *
     * @param   list<string>  $capabilities  Capabilities the console credential holds.
     *
     * @return  BusinessSecurityCommand  Command under test.
     *
     * @since   2.0.0
     */
    private function command(array $capabilities): BusinessSecurityCommand
    {
        return new BusinessSecurityCommand($this->businessSecurityService(), $this->console->authorizer($capabilities));
    }
}
