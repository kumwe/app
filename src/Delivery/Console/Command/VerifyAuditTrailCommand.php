<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Audit\Application\AuditTrailVerifier;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Throwable;

/**
 * Console command that re-derives the audit trail's tamper evidence and reports the first divergence.
 *
 * This is the control an operator reaches for when the question is "has anything touched the trail" —
 * during an incident, before signing off a qualification run, or after a restore. It carries the verdict
 * in its exit status so a deployment gate can branch on it, and prints the divergence class, position
 * and event identifier when there is one, because the first divergence is where the evidence stops being
 * trustworthy. Like every management command it authenticates through a protected token file rather than
 * inheriting authority from the shell.
 *
 * @since  2.0.0
 */
final readonly class VerifyAuditTrailCommand implements Command
{
    /**
     * Wire the verifier and the console authorizer this command runs behind.
     *
     * @param  AuditTrailVerifier  $trail          Verifier that walks the chain and the anchor ledger.
     * @param  ConsoleAuthorizer   $authorization  Turns `--site` and `--token-file` into an authorized context.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AuditTrailVerifier $trail,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Name the console dispatcher registers this command under.
     *
     * @return  string  Always `audit:verify`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'audit:verify';
    }

    /**
     * Summary line `bin/kumwe list` prints beside the command name.
     *
     * @return  string  One-sentence statement of what the command decides.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'Verify the audit trail digest chain and its anchors.';
    }

    /**
     * Verify the trail and encode the verdict in the exit status.
     *
     * @param   list<string>  $arguments  `--name=value` options; `--site` and `--token-file` are required,
     *          `--batch-size` is optional.
     * @param   Output        $output     Sink the JSON verdict, or the failure message, is written to.
     *
     * @return  int  `0` when the trail verifies, `1` when it diverges or the command could not run.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $options = CommandInput::options($arguments);
            $context = $this->authorization->require($options, 'audit.manage');
            $batchSize = isset($options['batch-size'])
                ? CommandInput::positiveInteger($options, 'batch-size')
                : 1000;
            $report = $this->trail->verify($context, $batchSize);
            $divergence = $report->firstDivergence;
            $result = [
                'intact' => $report->intact(),
                'events_verified' => $report->eventsVerified,
                'anchors_verified' => $report->anchorsVerified,
                'head_position' => $report->headPosition,
            ];
            if ($divergence !== null) {
                $result['divergence'] = [
                    'code' => $divergence->code,
                    'position' => $divergence->position,
                    'event_id' => $divergence->eventId,
                    'detail' => $divergence->detail,
                ];
                $output->error(CommandInput::render($result));

                return 1;
            }
            $output->line(CommandInput::render($result));

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
