<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use Kumwe\App\BusinessRecord\Application\RecordSecretRotation;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Throwable;

/**
 * Console command that moves stored business-record secrets onto the active encryption key.
 *
 * This is the operator's half of key rotation. Configuring new key material makes it active immediately
 * and leaves every stored envelope readable through the retired key; this is what works through those
 * envelopes so the retired key can eventually be destroyed. It is bounded on purpose — one invocation
 * re-seals at most `--batch-size` rows and then reports whether anything is left — so a rotation can be
 * driven from a shell loop, a cron entry, or the queued job beside live traffic instead of during an
 * outage.
 *
 * Interrupting it is safe and needs no cleanup: rows already re-sealed are committed, and the next
 * invocation finds exactly the rows that remain. The exit status is what a loop branches on: `0` when
 * nothing is left to do, `2` when the pass did work and more remains, `1` when it could not run.
 *
 * What it prints is counts and key names. It never prints a secret, a ciphertext, or a key, and it never
 * names the records it touched.
 *
 * @since  2.0.0
 */
final readonly class RotateRecordSecretsCommand implements Command
{
    /**
     * Wire the rotation pass and the console authorizer it runs behind.
     *
     * @param  RecordSecretRotation  $rotation       Bounded, resumable re-encryption pass.
     * @param  ConsoleAuthorizer     $authorization  Turns `--site` and `--token-file` into an authorized
     *         context, which also decides which site's installations the pass covers.
     *
     * @since  2.0.0
     */
    public function __construct(
        private RecordSecretRotation $rotation,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Name the console dispatcher registers this command under.
     *
     * @return  string  Always `business-record-rekey`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'business-record-rekey';
    }

    /**
     * Summary line `bin/kumwe list` prints beside the command name.
     *
     * @return  string  One-sentence statement of what the command does.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.business_record_rekey.description';
    }

    /**
     * Run one bounded pass and encode whether work remains in the exit status.
     *
     * @param   list<string>  $arguments  `--name=value` options; `--site` and `--token-file` are required,
     *          `--batch-size` bounds the pass and defaults to 200.
     * @param   Output        $output     Sink the JSON report, or the failure message, is written to.
     *
     * @return  int  `0` when every sealed row already carries the active key, `2` when the pass advanced
     *          but more remains, `1` when the pass could not run.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $options = CommandInput::options($arguments);
            $context = $this->authorization->require($options, 'business.record.rekey');
            $report = $this->rotation->rotate(
                $context,
                isset($options['batch-size']) ? CommandInput::positiveInteger($options, 'batch-size') : 200,
            );
            $rendered = CommandInput::render([
                'active_key_id' => $report->activeKeyId,
                'definitions_scanned' => $report->definitions,
                'rows_examined' => $report->examined,
                'rows_resealed' => $report->resealed,
                'rows_superseded' => $report->superseded,
                'skipped_installations' => $report->skipped,
                'complete' => $report->complete,
            ]);
            if (!$report->complete) {
                $output->line($rendered);

                return 2;
            }
            $output->line($rendered);

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
