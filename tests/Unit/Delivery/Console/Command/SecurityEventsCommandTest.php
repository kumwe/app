<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console\Command;

use DateTimeImmutable;
use Kumwe\App\Application\Security\HighImpactCredentialGuard;
use Kumwe\App\Delivery\Console\Command\SecurityEventsCommand;
use Kumwe\App\Identity\Application\Administration\AccessControlRepository;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Identity\Application\Security\PasswordHasher;
use Kumwe\App\Identity\Application\StepUp\StepUpCredentialStore;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\CapturingMachineConsoleOutput;
use Kumwe\App\Tests\Support\ConsoleCommandFixture;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;
use Kumwe\App\Tests\Support\MovableAuditClock;
use Kumwe\App\Tests\Support\RecordingAuditRecorder;
use Kumwe\Transaction\Testing\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins `kumwe security-events` to the access screen's timeline and its console refusals.
 *
 * The real `AccessControlService` runs over a repository stub, so the installation-wide `users.manage` gate is
 * the service's; the command's job is authenticating the console credential, printing `{"items": [...]}` and
 * turning any refusal into an error line and exit status one.
 *
 * @since  2.0.0
 */
#[CoversClass(SecurityEventsCommand::class)]
final class SecurityEventsCommandTest extends TestCase
{
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
     * The default `list` action prints the service's closed projection as one JSON document.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testListPrintsTheServicesTimeline(): void
    {
        $events = [['id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb304', 'action' => 'user.create']];
        $output = new CapturingMachineConsoleOutput();

        $status = $this->command(['users.manage'], $events)
            ->execute(['list', ...$this->console->options()], $output);

        self::assertSame(0, $status, implode("\n", $output->errors));
        self::assertSame(['items' => $events], json_decode($output->lines[0], true));
    }

    /**
     * An unknown action and a credential without `users.manage` both exit one with an error line.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsExitOneWithAnErrorLine(): void
    {
        $unknown = new CapturingMachineConsoleOutput();
        $denied = new CapturingMachineConsoleOutput();

        $unknownStatus = $this->command(['users.manage'], [])
            ->execute(['purge', ...$this->console->options()], $unknown);
        $deniedStatus = $this->command(['content.read'], [])
            ->execute(['list', ...$this->console->options()], $denied);

        self::assertSame(1, $unknownStatus);
        self::assertSame(['Unsupported security-events action.'], $unknown->errors);
        self::assertSame(1, $deniedStatus);
        self::assertSame([], $denied->lines);
        self::assertCount(1, $denied->errors);
    }

    /**
     * Build the command over the real service and a repository answering the given events.
     *
     * @param   list<string>                $capabilities  Capabilities the console principal holds.
     * @param   list<array<string, mixed>>  $events        Events the repository answers.
     *
     * @return  SecurityEventsCommand  Command under test.
     *
     * @since   2.0.0
     */
    private function command(array $capabilities, array $events): SecurityEventsCommand
    {
        $repository = $this->createStub(AccessControlRepository::class);
        $repository->method('securityEvents')->willReturn($events);

        return new SecurityEventsCommand(
            new AccessControlService(
                $repository,
                $this->createStub(PasswordHasher::class),
                new ImmediateTransactionManager(),
                new RecordingAuditRecorder(),
                new MovableAuditClock(new DateTimeImmutable('2026-09-24T10:00:00+00:00')),
                AuthorizationContext::gateway(),
                AuthorizationContext::ownershipWriter(),
                $this->createStub(HighImpactCredentialGuard::class),
                $this->createStub(StepUpCredentialStore::class),
                $this->createStub(AdministratorSessionStore::class),
                new DeterministicCanonicalEncoder(),
            ),
            $this->console->authorizer($capabilities),
        );
    }
}
