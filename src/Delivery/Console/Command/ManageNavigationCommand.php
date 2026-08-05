<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Navigation\Application\MenuItemRecord;
use Kumwe\CMS\Navigation\Application\MenuRecord;
use Kumwe\CMS\Navigation\Application\NavigationService;
use Throwable;

final readonly class ManageNavigationCommand implements Command
{
    public function __construct(private NavigationService $navigation, private ConsoleAuthorizer $authorization)
    {
    }

    public function name(): string
    {
        return 'navigation';
    }

    public function description(): string
    {
        return 'List and manage menus and menu items.';
    }

    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'list';
            $options = CommandInput::options($arguments);
            $context = $this->authorization->require($options, 'navigation.manage');
            $result = match ($action) {
                'list' => ['items' => array_map(
                    static fn (MenuRecord $menu): array => $menu->toArray(),
                    $this->navigation->menus($context),
                )],
                'items' => ['items' => array_map(
                    static fn (MenuItemRecord $item): array => $item->toArray(),
                    $this->navigation->items($context, CommandInput::required($options, 'menu')),
                )],
                'create-menu' => $this->navigation->createMenu(
                    $context,
                    CommandInput::required($options, 'handle'),
                    CommandInput::required($options, 'title'),
                )->toArray(),
                'update-menu' => $this->navigation->updateMenu(
                    $context,
                    CommandInput::required($options, 'id'),
                    CommandInput::positiveInteger($options, 'version'),
                    CommandInput::required($options, 'handle'),
                    CommandInput::required($options, 'title'),
                )->toArray(),
                'delete-menu' => $this->deleteMenu($options, $context),
                'create-item' => $this->navigation->createItem(
                    $context,
                    CommandInput::required($options, 'menu'),
                    $this->optional($options, 'parent'),
                    CommandInput::required($options, 'title'),
                    CommandInput::required($options, 'slug'),
                    (int) ($options['position'] ?? 0),
                )->toArray(),
                'update-item' => $this->navigation->updateItem(
                    $context,
                    CommandInput::required($options, 'id'),
                    CommandInput::positiveInteger($options, 'version'),
                    $this->optional($options, 'parent'),
                    CommandInput::required($options, 'title'),
                    CommandInput::required($options, 'slug'),
                    (int) ($options['position'] ?? 0),
                )->toArray(),
                'delete-item' => $this->deleteItem($options, $context),
                default => throw new \InvalidArgumentException('Unsupported navigation action.'),
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
     * @return array{deleted: bool}
     */
    private function deleteMenu(array $options, ExecutionContext $context): array
    {
        $this->navigation->deleteMenu(
            $context,
            CommandInput::required($options, 'id'),
            CommandInput::positiveInteger($options, 'version'),
        );
        return ['deleted' => true];
    }

    /**
     * @param array<string, string> $options
     * @return array{deleted: bool}
     */
    private function deleteItem(array $options, ExecutionContext $context): array
    {
        $this->navigation->deleteItem(
            $context,
            CommandInput::required($options, 'id'),
            CommandInput::positiveInteger($options, 'version'),
        );
        return ['deleted' => true];
    }

    /** @param array<string, string> $options */
    private function optional(array $options, string $name): ?string
    {
        $value = trim($options[$name] ?? '');
        return $value === '' ? null : $value;
    }
}
