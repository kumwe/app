<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use InvalidArgumentException;
use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Content\Application\ContentRecord;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Navigation\Application\MenuItemRecord;
use Kumwe\CMS\Navigation\Application\MenuRecord;
use Kumwe\CMS\Navigation\Application\NavigationService;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdministratorNavigationHandler implements RequestHandlerInterface
{
    public function __construct(
        private NavigationService $navigation,
        private AdministratorRenderer $renderer,
        private ?ContentService $content = null,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);
        if (strtoupper($request->getMethod()) === 'POST') {
            $form = AdministratorRequest::form($request);
            $this->mutate(AdministratorRequest::context($request), $form);

            return new RedirectResponse('/administrator/navigation?saved=1', 303);
        }

        $context = AdministratorRequest::context($request);
        $menus = $this->navigation->menus($context);
        $items = [];
        foreach ($menus as $menu) {
            $items[$menu->id] = array_map(
                static fn (MenuItemRecord $item): array => $item->toArray(),
                $this->navigation->items($context, $menu->id),
            );
        }

        return new HtmlResponse($this->renderer->render('navigation', [
            'csrf' => $session->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'menus' => array_map(static fn (MenuRecord $menu): array => $menu->toArray(), $menus),
            'items' => $items,
            'content_targets' => $this->content === null ? [] : array_map(
                static fn (ContentRecord $record): array => $record->toArray(),
                array_values(array_filter(
                    $this->content->list($context, 500),
                    static fn (ContentRecord $record): bool =>
                        $record->contentTypeId === ContentService::CORE_PAGE_TYPE_ID,
                )),
            ),
            'saved' => ($request->getQueryParams()['saved'] ?? null) === '1',
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    /** @param array<string, string> $form */
    private function mutate(ExecutionContext $context, array $form): void
    {
        $action = AdministratorRequest::required($form, 'action');
        if ($action === 'item.reorder') {
            $this->reorder(
                $context,
                AdministratorRequest::required($form, 'menu_id'),
                AdministratorRequest::required($form, 'order'),
            );

            return;
        }
        match ($action) {
            'menu.create' => $this->navigation->createMenu(
                $context,
                AdministratorRequest::required($form, 'handle'),
                AdministratorRequest::required($form, 'title'),
            ),
            'menu.update' => $this->navigation->updateMenu(
                $context,
                AdministratorRequest::required($form, 'id'),
                AdministratorRequest::positiveInteger($form, 'version'),
                AdministratorRequest::required($form, 'handle'),
                AdministratorRequest::required($form, 'title'),
            ),
            'menu.delete' => $this->navigation->deleteMenu(
                $context,
                AdministratorRequest::required($form, 'id'),
                AdministratorRequest::positiveInteger($form, 'version'),
            ),
            'item.create' => $this->navigation->createItem(
                $context,
                AdministratorRequest::required($form, 'menu_id'),
                $this->nullable($form, 'parent_id'),
                AdministratorRequest::required($form, 'title'),
                AdministratorRequest::required($form, 'slug'),
                $this->nonNegativeInteger($form, 'position'),
                $form['target_type'] ?? 'content',
                $this->nullable($form, 'content_id'),
                $this->nullable($form, 'target_url'),
            ),
            'item.update' => $this->navigation->updateItem(
                $context,
                AdministratorRequest::required($form, 'id'),
                AdministratorRequest::positiveInteger($form, 'version'),
                $this->nullable($form, 'parent_id'),
                AdministratorRequest::required($form, 'title'),
                AdministratorRequest::required($form, 'slug'),
                $this->nonNegativeInteger($form, 'position'),
                $form['target_type'] ?? 'content',
                $this->nullable($form, 'content_id'),
                $this->nullable($form, 'target_url'),
            ),
            'item.delete' => $this->navigation->deleteItem(
                $context,
                AdministratorRequest::required($form, 'id'),
                AdministratorRequest::positiveInteger($form, 'version'),
            ),
            default => throw new InvalidArgumentException('The navigation action is not supported.'),
        };
    }

    private function reorder(ExecutionContext $context, string $menuId, string $order): void
    {
        $identifiers = array_values(array_filter(
            array_map('trim', explode(',', $order)),
            static fn (string $identifier): bool => $identifier !== '',
        ));
        if (count($identifiers) !== count(array_unique($identifiers))) {
            throw new InvalidArgumentException('The menu item order contains duplicate items.');
        }
        $current = $this->navigation->items($context, $menuId);
        $byId = [];
        foreach ($current as $item) {
            $byId[$item->id] = $item;
        }
        if (count($identifiers) !== count($byId)) {
            throw new InvalidArgumentException('The menu item order is incomplete.');
        }
        foreach ($identifiers as $position => $identifier) {
            $item = $byId[$identifier] ?? null;
            if (!$item instanceof MenuItemRecord) {
                throw new InvalidArgumentException('The menu item order contains an unknown item.');
            }
            if ($item->position === $position) {
                continue;
            }
            $this->navigation->updateItem(
                $context,
                $item->id,
                $item->version,
                $item->parentId,
                $item->title,
                $item->slug,
                $position,
            );
        }
    }

    /** @param array<string, string> $form */
    private function nullable(array $form, string $field): ?string
    {
        $value = trim($form[$field] ?? '');

        return $value === '' ? null : $value;
    }

    /** @param array<string, string> $form */
    private function nonNegativeInteger(array $form, string $field): int
    {
        $value = $form[$field] ?? '0';
        if (preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The %s field must be a non-negative integer.', $field));
        }

        return (int) $value;
    }
}
