<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\BusinessSurface\Application\BusinessApprovalSurfaceService;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Throwable;

/**
 * Console command that withdraws the operator's own generated-business approval request as `kumwe business-approval`.
 *
 * The administrator's cancel control, `POST /api/v1/business/approvals/{id}/cancel` and the MCP cancel tool all
 * reach `ApprovalService::cancel()`; this command does too, through
 * `BusinessApprovalSurfaceService::businessCancel()`, which first resolves the request's visibility on the console
 * surface. Only the original requester may cancel, only on the surface and scope the request was made in, only
 * while it is pending. Cancelling withdraws a request and is never a decision: approving, rejecting and revoking
 * need a fresh browser step-up proof and have no console action. Reading the inbox stays on
 * `business-record approvals` and `business-record approval`; refusals use the same JSON failure envelope and
 * portable exits as `business-record`.
 *
 * @since  2.0.0
 */
final readonly class BusinessApprovalCommand implements Command
{
    /**
     * Wire the command to the approval surface, the console authorization gate and the business envelopes.
     *
     * @param  BusinessApprovalSurfaceService  $approvals      Surface-exposed approval cancellation.
     * @param  ConsoleAuthorizer               $authorization  Turns `--site` and `--token-file` into an
     *         execution context carrying `business.approval.request`.
     * @param  BusinessRecordConsolePresenter  $presenter      Success and failure envelopes shared with
     *         `business-record`.
     * @param  BusinessConsoleFailureMapper    $failures       Stable refusal codes and portable exits.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessApprovalSurfaceService $approvals,
        private ConsoleAuthorizer $authorization,
        private BusinessRecordConsolePresenter $presenter,
        private BusinessConsoleFailureMapper $failures,
    ) {
    }

    /**
     * Name the console dispatcher registers this command under.
     *
     * @return  string  Always `business-approval`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'business-approval';
    }

    /**
     * Summary line `bin/kumwe list` prints beside the command name.
     *
     * @return  string  Catalogue identifier of the one-sentence summary.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.business_approval.description';
    }

    /**
     * Cancel one approval request and print the business success envelope, or the failure envelope on stderr.
     *
     * @param   list<string>  $arguments  `cancel`, then `--approval-request`, `--site` and `--token-file`.
     * @param   Output        $output     Sink for the JSON envelope.
     *
     * @return  int  `0` on success, otherwise the portable exit of the mapped refusal.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments);
            $options = CommandInput::options($arguments);
            $context = $this->authorization->require($options, 'business.approval.request');
            if ($action !== 'cancel') {
                throw new InvalidArgumentException('The business-approval action is unsupported.');
            }
            $approval = CommandInput::required($options, 'approval-request');
            $this->approvals->businessCancel($context, BusinessSurface::Cli, $approval);
            $output->line(CommandInput::render($this->presenter->success('cancel', [
                'approval_request_id' => $approval,
                'status' => 'cancelled',
            ])));

            return 0;
        } catch (Throwable $exception) {
            // The console boundary: every refusal leaves as one mapped failure envelope and exit.
            $failure = $this->failures->map($exception);
            $output->error(CommandInput::render($this->presenter->failure($failure)));

            return $failure->exitCode;
        }
    }
}
