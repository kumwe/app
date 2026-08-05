<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Content\Application\ContentModelService;
use Kumwe\CMS\Content\Domain\ContentTypeDefinition;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Workflow\Domain\WorkflowDefinition;
use Throwable;

final readonly class ManageContentModelsCommand implements Command
{
    public function __construct(private ContentModelService $models, private ConsoleAuthorizer $authorization)
    {
    }

    public function name(): string
    {
        return 'content-model';
    }

    public function description(): string
    {
        return 'List, read, create, or publish versioned content types and workflows.';
    }

    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'list';
            $options = CommandInput::options($arguments);
            $context = $this->authorization->require($options, in_array($action, ['list', 'get'], true) ? 'content.read' : 'content.update');
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
