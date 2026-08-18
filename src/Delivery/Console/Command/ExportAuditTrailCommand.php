<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Audit\Application\AuditTrailExporter;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Throwable;

/**
 * Console command that preserves a range of the audit trail as a protected, checksummed archive.
 *
 * Incident response asks operators to preserve audit evidence; this is the tool that does it without
 * handing anyone raw database access. The archive is written into private storage rather than to
 * standard output, so the bytes never pass through a terminal, a shell history, or a log; what the
 * command prints is the manifest — key, checksum, byte size, the position range and the anchor the range
 * was sealed under — which is exactly what an operator needs to reference the file and to prove later
 * that it is the one the trail names. The export is capability-gated and recorded in the trail itself.
 *
 * @since  2.0.0
 */
final readonly class ExportAuditTrailCommand implements Command
{
    /**
     * Wire the exporter and the console authorizer this command runs behind.
     *
     * @param  AuditTrailExporter  $trail          Exporter that writes the redacted archive.
     * @param  ConsoleAuthorizer   $authorization  Turns `--site` and `--token-file` into an authorized context.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AuditTrailExporter $trail,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Name the console dispatcher registers this command under.
     *
     * @return  string  Always `audit:export`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'audit:export';
    }

    /**
     * Summary line `bin/kumwe list` prints beside the command name.
     *
     * @return  string  One-sentence statement of what the command produces.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.audit_export.description';
    }

    /**
     * Write the archive and print its manifest.
     *
     * @param   list<string>  $arguments  `--name=value` options; `--site` and `--token-file` are required,
     *          `--from` and `--to` bound the exported position range.
     * @param   Output        $output     Sink the JSON manifest, or the failure message, is written to.
     *
     * @return  int  `0` when the archive was written, `1` with its message on stderr when it was not.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $options = CommandInput::options($arguments);
            $context = $this->authorization->require($options, 'audit.export');
            $export = $this->trail->export(
                $context,
                isset($options['from']) ? CommandInput::positiveInteger($options, 'from') : null,
                isset($options['to']) ? CommandInput::positiveInteger($options, 'to') : null,
            );
            $output->line(CommandInput::render([
                'archive_key' => $export->archive->key,
                'archive_sha256' => $export->archive->checksum,
                'archive_bytes' => $export->archive->size,
                'from_position' => $export->fromPosition,
                'to_position' => $export->toPosition,
                'event_count' => $export->eventCount,
                'redacted_values' => $export->redactedCount,
                'anchor_sequence' => $export->anchorSequence,
            ]));

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
