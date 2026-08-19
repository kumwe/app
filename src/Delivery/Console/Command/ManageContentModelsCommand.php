<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Workflow\Domain\WorkflowDefinition;
use Throwable;

/**
 * Console entry point for authoring a site's content types and workflows as `kumwe content-model`.
 *
 * One command covers both halves of the content model because they are published the same way and
 * `--kind` is all that separates them: `content-type` reaches the schema definitions, `workflow`
 * reaches the state machines they pin. Definitions are versioned rather than mutated, so `update`
 * publishes a new version against the `--version` the operator read and `ContentModelService` refuses
 * a change that would strand stored entries unless `--allow-breaking=1` says otherwise. Reads need
 * only `content.read`; every other action needs `content.update`.
 *
 * @since  2.0.0
 */
final readonly class ManageContentModelsCommand implements Command
{
    /**
     * Wire the command to the content-model use cases and to the console's token authorization route.
     *
     * @param  ContentModelService  $models         Service every action delegates its read or publication to.
     * @param  ConsoleAuthorizer    $authorization  Resolves `--site` and `--token-file` into an authorized context.
     *
     * @since  2.0.0
     */
    public function __construct(private ContentModelService $models, private ConsoleAuthorizer $authorization)
    {
    }

    /**
     * Name the operator types to reach the content-model actions.
     *
     * @return  string  Always `content-model`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'content-model';
    }

    /**
     * Describe the command for the console's command listing.
     *
     * @return  string  One-line summary naming the actions and the two kinds of definition.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.content_model.description';
    }

    /**
     * Run one content-model action against the kind `--kind` selects and print the definition as JSON.
     *
     * The first argument is the action and defaults to `list`; everything after it is a `--name=value`
     * option. `--kind` is required for every action, including `list`, because it chooses which half of
     * the model the action addresses. The capability is picked from the action before the token is
     * verified, so `list` and `get` are reachable with a read-only token while `create` and `update`
     * are not. Failures are reduced to a message and exit status 1, so a rejected schema or a version
     * conflict reads as an explanation rather than a stack trace.
     *
     * @param   list<string>  $arguments  Action name first, then `--name=value` options: `--site`,
     *          `--token-file` and `--kind` always, plus whatever the chosen action requires.
     * @param   Output        $output     Sink for the JSON definition, or for the failure message.
     *
     * @return  int  0 when the action completed, 1 when any step failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'list';
            $options = CommandInput::options($arguments);
            $capability = in_array($action, ['list', 'get'], true) ? 'content.read' : 'content.update';
            $context = $this->authorization->require($options, $capability);
            $workflow = CommandInput::required($options, 'kind') === 'workflow';
            $result = match ($action) {
                'list' => ['items' => array_map(
                    static fn (ContentTypeDefinition|WorkflowDefinition $definition): array => $definition->toArray(),
                    $workflow ? $this->models->workflows($context) : $this->models->contentTypes($context),
                )],
                'get' => ($workflow
                    ? $this->models->workflow($context, CommandInput::required($options, 'id'))
                    : $this->models->contentType($context, CommandInput::required($options, 'id')))->toArray(),
                'create' => ($workflow
                    ? $this->models->createWorkflow(
                        $context,
                        CommandInput::required($options, 'handle'),
                        CommandInput::required($options, 'name'),
                        CommandInput::jsonObjectList($options, 'states'),
                        CommandInput::jsonObjectList($options, 'transitions'),
                    )
                    : $this->models->createContentType(
                        $context,
                        CommandInput::required($options, 'handle'),
                        CommandInput::required($options, 'name'),
                        CommandInput::required($options, 'workflow'),
                        CommandInput::jsonObject($options, 'schema'),
                    ))->toArray(),
                'update' => ($workflow
                    ? $this->models->updateWorkflow(
                        $context,
                        CommandInput::required($options, 'id'),
                        CommandInput::positiveInteger($options, 'version'),
                        CommandInput::required($options, 'name'),
                        CommandInput::jsonObjectList($options, 'states'),
                        CommandInput::jsonObjectList($options, 'transitions'),
                        ($options['allow-breaking'] ?? '0') === '1',
                    )
                    : $this->models->updateContentType(
                        $context,
                        CommandInput::required($options, 'id'),
                        CommandInput::positiveInteger($options, 'version'),
                        CommandInput::required($options, 'name'),
                        CommandInput::required($options, 'workflow'),
                        CommandInput::jsonObject($options, 'schema'),
                        ($options['allow-breaking'] ?? '0') === '1',
                    ))->toArray(),
                default => throw new \InvalidArgumentException('Unsupported content-model action.'),
            };
            $output->line(CommandInput::render($result));

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
