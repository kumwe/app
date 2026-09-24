<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Studio\Application\Composition\StudioContentCompositionService;
use RuntimeException;
use Throwable;

/**
 * Console entry point for Content-model Blueprint compositions as `kumwe studio-composition`.
 *
 * `get` reads and `provision` creates the composition of one exact Content type version through
 * `StudioContentCompositionService`, the service the administrator composition screen and
 * `/api/v1/content-types/{id}/versions/{version}/composition` use. The credential needs the screen's
 * `content.read` and `studio.mode.blueprint`; the service authorizes the model again, provisions the empty
 * schema-valid draft against the App's renderer set and records `studio.composition.provision`, and answers the
 * composition already bound when there is one. Results are pretty JSON on stdout; any refusal is one line on
 * stderr and exit status 1.
 *
 * @since  2.0.0
 */
final readonly class StudioCompositionCommand implements Command
{
    /**
     * Wire the command to the composition service and the console's token authorization route.
     *
     * @param  StudioContentCompositionService  $compositions   Blueprint composition application service.
     * @param  ConsoleAuthorizer                $authorization  Resolves `--site` and `--token-file`.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioContentCompositionService $compositions,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Name the operator types to reach the composition actions.
     *
     * @return  string  Always `studio-composition`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'studio-composition';
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
        return 'core.console.studio_composition.description';
    }

    /**
     * Read or provision one composition and print it, or one error line.
     *
     * @param   list<string>  $arguments  `get` or `provision`, then `--content-type`, `--version`, `--site` and
     *          `--token-file`.
     * @param   Output        $output     Sink for the JSON result or the failure line.
     *
     * @return  int  0 when the action completed, 1 when any step failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments);
            if (!in_array($action, ['get', 'provision'], true)) {
                throw new InvalidArgumentException('Unsupported studio-composition action.');
            }
            $options = CommandInput::options($arguments);
            $this->authorization->require($options, 'studio.mode.blueprint');
            $context = $this->authorization->require($options, 'content.read');
            $contentType = CommandInput::required($options, 'content-type');
            $version = CommandInput::positiveInteger($options, 'version');
            $composition = $action === 'provision'
                ? $this->compositions->provision(
                    $context,
                    $contentType,
                    $version,
                    StudioContentCompositionService::RENDERERS,
                )
                : $this->compositions->find($context, $contentType, $version);
            if ($composition === null) {
                throw new RuntimeException('No Blueprint composition is provisioned for this Content type version.');
            }
            $output->line(CommandInput::render($composition->toArray()));

            return 0;
        } catch (Throwable $exception) {
            // The console boundary: every refusal leaves as one operator-readable line and exit status 1.
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
