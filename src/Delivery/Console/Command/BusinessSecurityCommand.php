<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationService;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Throwable;

/**
 * Console entry point for the Business Security read model as `kumwe business-security overview`.
 *
 * The overview is `BusinessSecurityAdministrationService::overview()`, the same document the Business Security
 * screen renders and `GET /api/v1/business-security` answers: organizations, workspaces, memberships with their
 * explained effective access, resource policies, separation-of-duty rules and approvals, scoped to the
 * credential's site and membership under `business.security.manage`. The screen's writes have no console action:
 * the service consumes a fresh human step-up proof for each of them, which a console credential cannot hold.
 *
 * @since  2.0.0
 */
final readonly class BusinessSecurityCommand implements Command
{
    /**
     * Wire the command to the business security read model and the console's token authorization route.
     *
     * @param  BusinessSecurityAdministrationService  $security       Business security administration service.
     * @param  ConsoleAuthorizer                      $authorization  Resolves `--site` and `--token-file`.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessSecurityAdministrationService $security,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Name the operator types to reach the overview.
     *
     * @return  string  Always `business-security`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'business-security';
    }

    /**
     * Describe the command for the console's command listing.
     *
     * @return  string  Catalogue identifier of the one-sentence summary.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.business_security.description';
    }

    /**
     * Print the overview as one JSON document, or one error line.
     *
     * @param   list<string>  $arguments  `overview`, then `--site` and `--token-file`.
     * @param   Output        $output     Sink for the JSON result or the failure line.
     *
     * @return  int  0 when the overview was printed, 1 when any step failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            if ((array_shift($arguments) ?? 'overview') !== 'overview') {
                throw new InvalidArgumentException('Unsupported business-security action.');
            }
            $context = $this->authorization->require(
                CommandInput::options($arguments),
                'business.security.manage',
            );
            $output->line(CommandInput::render($this->security->overview($context)));

            return 0;
        } catch (Throwable $exception) {
            // The console boundary: every refusal leaves as one operator-readable line and exit status 1.
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
