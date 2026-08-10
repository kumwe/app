<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\CMS\BusinessIntegration\Application\IntegrationOperationsService;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Throwable;

/**
 * Authorized CLI for integration ledgers, projection rebuilds, replay, retention and process cancellation.
 *
 * @since  2.0.0
 */
final readonly class ManageIntegrationsCommand implements Command
{
    /**
     * Bind the CLI adapter to the centralized operator service and file-backed token authorizer.
     *
     * @param  IntegrationOperationsService  $operations     Authorized integration control surface.
     * @param  ConsoleAuthorizer             $authorization  Protected token-file authenticator.
     *
     * @since  2.0.0
     */
    public function __construct(
        private IntegrationOperationsService $operations,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Return the stable console dispatcher name.
     *
     * @return  string  Stable command name.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'integration:manage';
    }

    /**
     * Return the one-line command description shown in console discovery.
     *
     * @return  string  Stable command description.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'Inspect durable integrations and authorize projection rebuild, replay, retention, or cancellation.';
    }

    /**
     * Execute one strict operator action with a protected file-backed management token.
     *
     * @param   list<string>  $arguments  Action followed by strict `--name=value` options.
     * @param   Output        $output     Separate standard and error output sink.
     *
     * @return  int  Zero on success and one on a sanitized refusal or failure.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'outbox';
            $options = CommandInput::options($arguments);
            $this->assertKnownOptions($action, $options);
            $context = $this->authorization->require($options, 'automation.manage');
            $limit = isset($options['limit']) ? CommandInput::positiveInteger($options, 'limit') : 100;

            $result = match ($action) {
                'outbox' => ['items' => $this->operations->outbox($context, $limit)],
                'inbox' => ['items' => $this->operations->inbox(
                    $context,
                    CommandInput::required($options, 'consumer'),
                    $limit,
                )],
                'processes' => ['items' => $this->operations->processes($context, $limit)],
                'process-work' => ['items' => $this->operations->processWork(
                    $context,
                    CommandInput::required($options, 'process'),
                    $limit,
                )],
                'projections' => ['items' => $this->operations->projections($context)],
                'projection-rebuild' => $this->operations->rebuildProjection(
                    $context,
                    CommandInput::required($options, 'projection'),
                ),
                'replay' => $this->operations->replay(
                    $context,
                    CommandInput::required($options, 'event'),
                ),
                'purge' => $this->operations->purge($context, $limit),
                'cancel' => $this->operations->cancel(
                    $context,
                    CommandInput::required($options, 'process'),
                    CommandInput::positiveInteger($options, 'version'),
                    CommandInput::required($options, 'note'),
                ),
                default => throw new InvalidArgumentException('The integration management action is unsupported.'),
            };
            $output->line(CommandInput::render($result));

            return 0;
        } catch (Throwable) {
            $output->error(CommandInput::render([
                'error' => 'integration_operation_failed',
                'detail' => 'The integration operation was refused or could not be completed.',
            ]));

            return 1;
        }
    }

    /**
     * Reject unknown or action-inappropriate options before authentication or storage access.
     *
     * @param   string                 $action   Requested operator action.
     * @param   array<string, string>  $options  Parsed strict option map.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the action or any supplied option is unsupported.
     *
     * @since   2.0.0
     */
    private function assertKnownOptions(string $action, array $options): void
    {
        $allowed = match ($action) {
            'outbox', 'processes', 'purge' => ['site', 'token-file', 'limit'],
            'projections' => ['site', 'token-file'],
            'projection-rebuild' => ['site', 'token-file', 'projection'],
            'inbox' => ['site', 'token-file', 'consumer', 'limit'],
            'process-work' => ['site', 'token-file', 'process', 'limit'],
            'replay' => ['site', 'token-file', 'event'],
            'cancel' => ['site', 'token-file', 'process', 'version', 'note'],
            default => throw new InvalidArgumentException('The integration management action is unsupported.'),
        };
        if (array_diff(array_keys($options), $allowed) !== []) {
            throw new InvalidArgumentException('The integration management command has an unknown option.');
        }
    }
}
