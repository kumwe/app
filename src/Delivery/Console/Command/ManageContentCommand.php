<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Content\Application\ContentRecord;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Content\Domain\ContentStatus;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Throwable;
use Kumwe\CMS\Workflow\Application\ContentTransitionAuthorizer;

final readonly class ManageContentCommand implements Command
{
    public function __construct(
        private ContentService $content,
        private ConsoleAuthorizer $authorization,
        private ContentTransitionAuthorizer $transitions,
    ) {
    }

    public function name(): string
    {
        return 'content';
    }

    public function description(): string
    {
        return 'List, read, create, update, transition, trash, or restore content.';
    }

    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'list';
            $options = CommandInput::options($arguments);
            $principal = $this->authorization->require($options, match ($action) {
                'list', 'get' => 'content.read',
                'create' => 'content.create',
                'update' => 'content.update',
                'trash' => 'content.delete',
                'restore' => 'content.restore',
                'transition' => 'content.read',
                default => throw new \InvalidArgumentException('Unsupported content action.'),
            });
            $result = match ($action) {
                'list' => ['items' => array_map(
                    static fn (ContentRecord $record): array => $record->toArray(),
                    $this->content->list(includeDeleted: ($options['deleted'] ?? '0') === '1'),
                )],
                'get' => $this->content->get(CommandInput::required($options, 'id'), true)->toArray(),
                'create' => $this->content->create(
                    $principal->subject(),
                    CommandInput::required($options, 'title'),
                    CommandInput::required($options, 'slug'),
                    CommandInput::jsonObject($options, 'data'),
                )->toArray(),
                'update' => $this->content->update(
                    $principal->subject(),
                    CommandInput::required($options, 'id'),
                    CommandInput::positiveInteger($options, 'version'),
                    CommandInput::required($options, 'title'),
                    CommandInput::required($options, 'slug'),
                    CommandInput::jsonObject($options, 'data'),
                )->toArray(),
                'transition' => $this->transition($options, $principal),
                'trash' => $this->content->trash(
                    $principal->subject(),
                    CommandInput::required($options, 'id'),
                    CommandInput::positiveInteger($options, 'version'),
                )->toArray(),
                'restore' => $this->content->restore(
                    $principal->subject(),
                    CommandInput::required($options, 'id'),
                    CommandInput::positiveInteger($options, 'version'),
                )->toArray(),
            };
            $output->line(CommandInput::render($result));
            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());
            return 1;
        }
    }

    /**
     * @param array<string, string> $options
     * @return array<string, mixed>
     */
    private function transition(
        array $options,
        AuthenticatedPrincipal $principal,
    ): array {
        $id = CommandInput::required($options, 'id');
        $target = ContentStatus::from(CommandInput::required($options, 'status'));
        $this->transitions->assertAllowed($principal, $this->content->get($id)->entry->status(), $target);

        return $this->content->transition(
            $principal->subject(),
            $id,
            CommandInput::positiveInteger($options, 'version'),
            $target,
        )->toArray();
    }
}
