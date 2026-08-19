<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Delivery\Console;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Application\Query\BusinessRecordQueryPurpose;
use Kumwe\App\BusinessReporting\Application\ExportService;
use Kumwe\App\BusinessReporting\Application\ReportExecutionRequest;
use Kumwe\App\BusinessReporting\Application\ReportService;
use Kumwe\App\BusinessReporting\Delivery\Api\ReportApiPresenter;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Command\CommandInput;
use Kumwe\App\Delivery\Console\Command\ConsoleAuthorizer;
use Kumwe\App\Delivery\Console\Output;
use Throwable;

/**
 * Authorized CLI adapter for synchronous report execution and queued export lifecycle.
 *
 * @since  2.0.0
 */
final readonly class ReportCommand implements Command
{
    /**
     * Wire CLI authentication to reporting application services.
     *
     * @param  ReportService       $reports        Synchronous report executor and discovery authority.
     * @param  ExportService       $exports        Queued export use cases.
     * @param  ConsoleAuthorizer   $authorization  Protected token-file authenticator.
     * @param  ReportApiPresenter  $presenter      Stable transport-neutral array projection.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ReportService $reports,
        private ExportService $exports,
        private ConsoleAuthorizer $authorization,
        private ReportApiPresenter $presenter,
    ) {
    }

    /**
     * Return the stable command name operators invoke.
     *
     * @return  string  Command name.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'business-report';
    }

    /**
     * Describe the permission-aware report and export lifecycle surface.
     *
     * @return  string  One-line command listing description.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.business_report.description';
    }

    /**
     * Execute `list`, `run`, `export`, `status`, or `download` with strict file-backed parameters.
     *
     * @param   list<string>  $arguments  Action and strict `--name=value` options.
     * @param   Output        $output     Separate standard and error output.
     *
     * @return  int  Zero on success and one on a redacted failure.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'run';
            $options = CommandInput::options($arguments);
            $allowed = match ($action) {
                'list' => ['site', 'token-file'],
                'run' => ['site', 'token-file', 'report', 'parameters-file'],
                'export' => ['site', 'token-file', 'report', 'parameters-file', 'retention-seconds'],
                'status' => ['site', 'token-file', 'artifact'],
                'download' => ['site', 'token-file', 'artifact', 'output-file'],
                default => throw new InvalidArgumentException('The business-report action is unsupported.'),
            };
            if (array_diff(array_keys($options), $allowed) !== []) {
                throw new InvalidArgumentException('The business-report command has an unknown option.');
            }
            $capability = in_array($action, ['list', 'run'], true)
                ? 'business.record.report'
                : 'business.record.export';
            $context = $this->authorization->require($options, $capability);
            if ($action === 'list') {
                $items = [];
                foreach ($this->reports->available($context) as $definition) {
                    $items[] = $this->presenter->summary($definition);
                }
                $result = ['items' => $items];
            } elseif ($action === 'status') {
                $result = $this->presenter->export($this->exports->status(
                    $context,
                    CommandInput::required($options, 'artifact'),
                ));
            } elseif ($action === 'download') {
                $download = $this->exports->download(
                    $context,
                    CommandInput::required($options, 'artifact'),
                );
                $path = CommandInput::required($options, 'output-file');
                $this->writeDownload($path, $download->stream, $download->size, $download->checksum);
                $result = [
                    'output_file' => $path,
                    'filename' => $download->filename,
                    'size' => $download->size,
                    'checksum' => $download->checksum,
                ];
            } else {
                $report = CommandInput::required($options, 'report');
                $parameters = isset($options['parameters-file'])
                    ? CommandInput::protectedJsonObject($options['parameters-file'])
                    : [];
                if ($action === 'run') {
                    $result = $this->presenter->report($this->reports->execute(new ReportExecutionRequest(
                        $context,
                        $report,
                        $parameters,
                        $context->organization()?->identifier(),
                        BusinessRecordQueryPurpose::Report,
                    )));
                } else {
                    $retention = isset($options['retention-seconds'])
                        ? CommandInput::positiveInteger($options, 'retention-seconds')
                        : 86_400;
                    $result = $this->presenter->export($this->exports->request(
                        $context,
                        $report,
                        $parameters,
                        $context->organization()?->identifier(),
                        $retention,
                    ));
                }
            }
            $output->line(CommandInput::render($result));
            return 0;
        } catch (Throwable) {
            $output->error(CommandInput::render([
                'error' => 'business_report_failed',
                'detail' => 'The report operation was refused or could not be completed.',
            ]));
            return 1;
        }
    }

    /**
     * Persist a verified artifact to a new operator-selected path without following links or overwriting data.
     *
     * @param   string    $path      New absolute destination path chosen by the operator.
     * @param   resource  $source    Verified artifact stream positioned at byte zero.
     * @param   int       $size      Expected exact artifact byte count.
     * @param   string    $checksum  Expected artifact SHA-256 checksum.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function writeDownload(string $path, mixed $source, int $size, string $checksum): void
    {
        if (
            (!str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) !== 1)
            || basename($path) === '' || is_link($path) || file_exists($path)
        ) {
            throw new InvalidArgumentException('The report output file must be a new absolute path.');
        }
        $directory = realpath(dirname($path));
        if ($directory === false || !is_dir($directory) || is_link(dirname($path))) {
            throw new InvalidArgumentException('The report output directory is unavailable.');
        }
        $destination = fopen($path, 'x+b');
        if (!is_resource($destination)) {
            throw new InvalidArgumentException('The report output file could not be created.');
        }
        try {
            if (chmod($path, 0600) !== true) {
                throw new InvalidArgumentException('The report output file could not be made private.');
            }
            $written = stream_copy_to_stream($source, $destination);
            if ($written !== $size || fflush($destination) !== true) {
                throw new InvalidArgumentException('The report output file was incomplete.');
            }
            rewind($destination);
            $hash = hash_init('sha256');
            hash_update_stream($hash, $destination);
            $actual = hash_final($hash);
            if (!hash_equals($checksum, $actual)) {
                throw new InvalidArgumentException('The report output file checksum did not match.');
            }
        } catch (Throwable $exception) {
            fclose($destination);
            unlink($path);
            throw $exception;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
        fclose($destination);
    }
}
