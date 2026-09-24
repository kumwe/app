<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Throwable;

/**
 * Console command that prints the identity and credential security timeline as `kumwe security-events`.
 *
 * It is the console face of the administrator access screen's events tab and of
 * `GET /api/v1/security-events`: all three call `AccessControlService::securityEvents()`, which authorizes
 * `users.manage` on the user collection and returns at most one hundred newest events as a closed projection
 * that never carries an event's metadata. The verified token must hold `users.manage` before the service is
 * asked at all.
 *
 * @since  2.0.0
 */
final readonly class SecurityEventsCommand implements Command
{
    /**
     * Wire the command to the access-control service and the console authorization gate.
     *
     * @param  AccessControlService  $access         Reads the security-event timeline.
     * @param  ConsoleAuthorizer     $authorization  Turns `--site` and `--token-file` into an execution
     *         context carrying `users.manage`.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AccessControlService $access,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Name the console dispatcher registers this command under.
     *
     * @return  string  Always `security-events`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'security-events';
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
        return 'core.console.security_events.description';
    }

    /**
     * Print the security-event timeline as JSON.
     *
     * The only action is `list`, which is also the default. The printed document is `{"items": [...]}`
     * with the newest event first; a refusal prints its message on stderr and exits `1`.
     *
     * @param   list<string>  $arguments  Action name first, then `--site` and `--token-file`.
     * @param   Output        $output     Sink for the JSON result, or for the failure message.
     *
     * @return  int  `0` when the timeline was printed, `1` when it was refused.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'list';
            $options = CommandInput::options($arguments);
            $context = $this->authorization->require($options, 'users.manage');
            if ($action !== 'list') {
                throw new \InvalidArgumentException('Unsupported security-events action.');
            }
            $output->line(CommandInput::render(['items' => $this->access->securityEvents($context)]));

            return 0;
        } catch (Throwable $exception) {
            // The console boundary: every refusal, including authorization, leaves as one error line.
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
