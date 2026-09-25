<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console\Command;

use Kumwe\App\Delivery\Console\Command\BusinessApprovalCommand;
use Kumwe\App\Delivery\Console\Command\BusinessConsoleFailureMapper;
use Kumwe\App\Delivery\Console\Command\BusinessRecordConsolePresenter;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\BuildsBusinessApprovalSurface;
use Kumwe\App\Tests\Support\CapturingMachineConsoleOutput;
use Kumwe\App\Tests\Support\ConsoleCommandFixture;
use Kumwe\App\Tests\Support\RecordingAuditRecorder;
use Kumwe\Approval\ApprovalRepository;
use Kumwe\Approval\ApprovalStatus;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins `kumwe business-approval cancel` to the requester's cancel control and the business console envelopes.
 *
 * The real surface gate, generic query service and `ApprovalService` run over doubled stores, so the command's
 * refusals are the workflow's own: a request not visible on the console is refused with the same
 * non-enumerating `authorization.denied` as a credential without `business.approval.request`, which is refused
 * before any store is read, and a successful withdrawal is the audited `approval.cancel` transition.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessApprovalCommand::class)]
final class BusinessApprovalCommandTest extends TestCase
{
    use BuildsBusinessApprovalSurface;

    /**
     * Approval request the console withdraws.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string REQUEST = '0191574f-f0b8-7bf3-a9aa-91c6b8244e51';

    /**
     * Approval request hidden from every business surface because its action is not exposed.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string HIDDEN = '0191574f-f0b8-7bf3-a9aa-91c6b8244e52';

    /**
     * Capabilities of the requesting console credential.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array REQUESTER = ['business.approval.request', 'business.approval.approve'];

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
     * The requester's own pending request is cancelled, audited, and reported in the success envelope.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCancelWithdrawsTheRequestersOwnPendingRequest(): void
    {
        $requester = AuthorizationContext::principal(self::REQUESTER)->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'business-approval-command-test',
            surface: AuthenticatedSurface::Cli,
        );
        $workflow = $this->createMock(ApprovalRepository::class);
        $workflow->expects(self::once())->method('lock')->with(self::REQUEST)
            ->willReturn($this->pendingApprovalRow(self::REQUEST, $requester));
        $workflow->expects(self::once())->method('transition')->with(
            self::REQUEST,
            ApprovalStatus::Pending,
            ApprovalStatus::Cancelled,
            1,
        );
        $audit = new RecordingAuditRecorder();
        $output = new CapturingMachineConsoleOutput();

        $status = $this->command(self::REQUESTER, $workflow, $audit)->execute(
            ['cancel', '--approval-request=' . self::REQUEST, ...$this->console->options()],
            $output,
        );

        self::assertSame(0, $status, implode("\n", $output->errors));
        $envelope = json_decode(implode("\n", $output->lines), true);
        self::assertIsArray($envelope);
        self::assertTrue($envelope['ok']);
        self::assertSame(['approval_request_id' => self::REQUEST, 'status' => 'cancelled'], $envelope['data']);
        self::assertSame(['approval.cancel'], $audit->actions());
    }

    /**
     * An unknown action, a missing request option, a hidden request and a missing grant are each refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsLeaveAsMappedFailureEnvelopes(): void
    {
        $inert = $this->createMock(ApprovalRepository::class);
        $inert->expects(self::never())->method('lock');

        $unknown = $this->refused(self::REQUESTER, $inert, ['approve', '--approval-request=' . self::REQUEST]);
        $missing = $this->refused(self::REQUESTER, $inert, ['cancel']);
        $hidden = $this->refused(self::REQUESTER, $inert, ['cancel', '--approval-request=' . self::HIDDEN]);
        $denied = $this->refused(['business.record.read'], $inert, ['cancel', '--approval-request=' . self::REQUEST]);

        self::assertSame([64, 'invocation.invalid'], $unknown);
        self::assertSame([64, 'invocation.invalid'], $missing);
        self::assertSame([77, 'authorization.denied'], $hidden);
        self::assertSame([77, 'authorization.denied'], $denied);
    }

    /**
     * Run one refused invocation and answer its exit status and failure code.
     *
     * @param   list<string>        $capabilities  Capabilities the console credential holds.
     * @param   ApprovalRepository  $workflow      Workflow store the command may reach.
     * @param   list<string>        $arguments     Action and options before the authorization options.
     *
     * @return  array{int, mixed}  Exit status and the envelope's error code.
     *
     * @since   2.0.0
     */
    private function refused(array $capabilities, ApprovalRepository $workflow, array $arguments): array
    {
        $output = new CapturingMachineConsoleOutput();
        $status = $this->command($capabilities, $workflow, new RecordingAuditRecorder())->execute(
            [...$arguments, ...$this->console->options()],
            $output,
        );
        self::assertSame([], $output->lines);
        $envelope = json_decode(implode("\n", $output->errors), true);
        self::assertIsArray($envelope);
        self::assertFalse($envelope['ok']);

        return [$status, $envelope['error']['code'] ?? null];
    }

    /**
     * Build the command over the real surface gate with the visible and hidden requests.
     *
     * @param   list<string>            $capabilities  Capabilities the console credential holds.
     * @param   ApprovalRepository      $workflow      Workflow store cancellation reaches.
     * @param   RecordingAuditRecorder  $audit         Recorder the workflow audits to.
     *
     * @return  BusinessApprovalCommand  Command under test.
     *
     * @since   2.0.0
     */
    private function command(
        array $capabilities,
        ApprovalRepository $workflow,
        RecordingAuditRecorder $audit,
    ): BusinessApprovalCommand {
        return new BusinessApprovalCommand(
            $this->approvalSurface(
                [$this->approvalView(self::REQUEST), $this->approvalView(self::HIDDEN, 'withdraw')],
                $workflow,
                $audit,
            ),
            $this->console->authorizer($capabilities),
            new BusinessRecordConsolePresenter(),
            new BusinessConsoleFailureMapper(),
        );
    }
}
