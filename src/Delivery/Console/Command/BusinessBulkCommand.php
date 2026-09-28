<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceOperation;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceUseCases;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Throwable;

/**
 * Console entry point for the generated administrator and portal bulk forms as `kumwe business-bulk`.
 *
 * `archive`, `restore` and `action` apply one atomic bulk mutation to at most fifty reviewed records through
 * `BusinessSurfaceService::bulk()`, the use case the browser bulk form and `POST .../records/bulk` call. Every
 * member is re-resolved through the canonical record policy at its reviewed version and carries the child
 * operation identity the other surfaces derive from `--operation-id`, so a retried bulk replays member by member
 * and any stale version or refusal rolls the whole selection back. `--items` is the REST selection document, a
 * JSON list of `{"record_id": ..., "expected_version": ...}`; a bulk action's shared input is a protected file,
 * as `business-record action` reads it. Results and refusals use the `business-record` envelopes and exits.
 *
 * @since  2.0.0
 */
final readonly class BusinessBulkCommand implements Command
{
    /**
     * Capability each bulk action requires.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array CAPABILITIES = [
        'archive' => 'business.record.archive',
        'restore' => 'business.record.restore',
        'action' => 'business.record.action',
    ];

    /**
     * Wire the command to the shared bulk use case, the console authorization gate and the business envelopes.
     *
     * @param  BusinessSurfaceUseCases         $surfaces       Shared generated-business use cases.
     * @param  ConsoleAuthorizer               $authorization  Turns `--site` and `--token-file` into a context.
     * @param  BusinessRecordConsolePresenter  $presenter      Success and failure envelopes of `business-record`.
     * @param  BusinessConsoleFailureMapper    $failures       Stable refusal codes and portable exits.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessSurfaceUseCases $surfaces,
        private ConsoleAuthorizer $authorization,
        private BusinessRecordConsolePresenter $presenter,
        private BusinessConsoleFailureMapper $failures,
    ) {
    }

    /**
     * Name the console dispatcher registers this command under.
     *
     * @return  string  Always `business-bulk`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'business-bulk';
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
        return 'core.console.business_bulk.description';
    }

    /**
     * Apply one bulk mutation and print the business success envelope, or the failure envelope on stderr.
     *
     * @param   list<string>  $arguments  `archive`, `restore` or `action`, then `--definition`, `--items`,
     *          `--operation-id`, for `action` also `--action` and an optional `--input-file`, `--site` and
     *          `--token-file`.
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
            if (!is_string($action) || !isset(self::CAPABILITIES[$action])) {
                throw new InvalidArgumentException('The business-bulk action is unsupported.');
            }
            $options = CommandInput::options($arguments);
            $context = $this->authorization->require($options, self::CAPABILITIES[$action]);
            if ($action !== 'action' && (isset($options['action']) || isset($options['input-file']))) {
                throw new InvalidArgumentException('Only a bulk action accepts --action and --input-file.');
            }
            $result = $this->surfaces->bulk(
                $context,
                BusinessSurface::Cli,
                CommandInput::required($options, 'definition'),
                match ($action) {
                    'archive' => BusinessSurfaceOperation::Archive,
                    'restore' => BusinessSurfaceOperation::Restore,
                    default => BusinessSurfaceOperation::Action,
                },
                CommandInput::jsonObjectList($options, 'items'),
                CommandInput::required($options, 'operation-id'),
                $action === 'action' ? CommandInput::required($options, 'action') : null,
                isset($options['input-file'])
                    ? CommandInput::protectedJsonObject(CommandInput::required($options, 'input-file'))
                    : [],
            );
            $output->line(CommandInput::render($this->presenter->success($action, $result)));

            return 0;
        } catch (Throwable $exception) {
            // The console boundary: every refusal leaves as one mapped failure envelope and exit.
            $failure = $this->failures->map($exception);
            $output->error(CommandInput::render($this->presenter->failure($failure)));

            return $failure->exitCode;
        }
    }
}
