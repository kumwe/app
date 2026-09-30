<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use Kumwe\App\Audit\Application\AuditCheckpoint;
use Kumwe\App\Audit\Application\AuditContinuityVerifier;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
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
 * The verdict has three states rather than two, because the trail can be intact under two materially
 * different postures. Exit `0` means the chain verified *and* the database is refusing rewrites. Exit
 * `2` means the chain verified but the append-only triggers are not installed on this server, so the
 * trail is append-only by application discipline alone — the evidence is sound and nothing is known to
 * have been tampered with, but prevention is absent and a qualification sign-off has to say so. Exit `1`
 * is reserved for an actual divergence or a command that could not run. The degraded verdict is written
 * to the error stream for the same reason it does not exit zero: an operator skimming a deployment log
 * must not have to notice the absence of a field to learn that a control is missing. The same exit `2`
 * reports a runtime database principal that itself holds audit retention authority.
 *
 * Hashes stored beside the trail cannot reveal that the trail and its ledger were erased or rolled back
 * together. A guarded verdict therefore prints the `checkpoint` it reached, which the operator keeps
 * off-host; `--checkpoint-file` supplies such a retained checkpoint back, and a trail that no longer
 * reaches it, like one that no longer reaches the privately retained checkpoint, exits `1`.
 *
 * @since  2.0.0
 */
final readonly class VerifyAuditTrailCommand implements Command
{
    /**
     * Wire the verifier and the console authorizer this command runs behind.
     *
     * @param  AuditContinuityVerifier  $trail          Verifier that walks the chain, ledger and checkpoints.
     * @param  ConsoleAuthorizer        $authorization  Turns `--site` and `--token-file` into an authorized context.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AuditContinuityVerifier $trail,
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
        return 'core.console.audit_verify.description';
    }

    /**
     * Verify the trail and encode the verdict in the exit status.
     *
     * @param   list<string>  $arguments  `--name=value` options; `--site` and `--token-file` are required,
     *          `--batch-size` and `--checkpoint-file` are optional.
     * @param   Output        $output     Sink the JSON verdict, or the failure message, is written to.
     *
     * @return  int  `0` when the trail verifies and append-only enforcement is installed, `2` when it
     *          verifies but enforcement is absent or the runtime principal holds retention authority,
     *          `1` when the trail diverges, no longer reaches a retained checkpoint, or the command
     *          could not run.
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
            $external = isset($options['checkpoint-file'])
                ? AuditCheckpoint::fromArray(CommandInput::protectedJsonObject(
                    CommandInput::required($options, 'checkpoint-file'),
                ))
                : null;
            $continuity = $this->trail->verifyContinuity($context, $batchSize, $external);
            $report = $continuity->report;
            $divergence = $report->firstDivergence;
            $result = [
                'intact' => $report->intact(),
                'append_only_enforcement' => $report->enforcement->value,
                'events_verified' => $report->eventsVerified,
                'anchors_verified' => $report->anchorsVerified,
                'head_position' => $report->headPosition,
                'enforcement_detail' => $report->enforcement->summary(),
                'retention_authority' => $continuity->retentionAuthority->value,
                'retention_authority_detail' => $continuity->retentionAuthority->summary(),
                'retained_checkpoint' => $continuity->retained?->toArray(),
                'checkpoint' => $continuity->checkpoint?->toArray(),
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
            if (!$report->enforcement->installed() || $continuity->retentionAuthority->degradesPrevention()) {
                $output->error(CommandInput::render($result));

                return 2;
            }
            $output->line(CommandInput::render($result));

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
