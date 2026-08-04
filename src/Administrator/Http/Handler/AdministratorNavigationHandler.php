<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use InvalidArgumentException;
use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
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
    public function __construct(private NavigationService $navigation, private AdministratorRenderer $renderer)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);
        if (strtoupper($request->getMethod()) === 'POST') {
            $form = AdministratorRequest::form($request);
            $this->mutate($session->principal->subject(), $form);

            return new RedirectResponse('/administrator/navigation?saved=1', 303);
        }

        $menus = $this->navigation->menus();
        $items = [];
        foreach ($menus as $menu) {
            $items[$menu->id] = array_map(
                static fn (MenuItemRecord $item): array => $item->toArray(),
                $this->navigation->items($menu->id),
            );
        }

        return new HtmlResponse($this->renderer->render('navigation', [
            'csrf' => $session->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'menus' => array_map(static fn (MenuRecord $menu): array => $menu->toArray(), $menus),
            'items' => $items,
            'saved' => ($request->getQueryParams()['saved'] ?? null) === '1',
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    /** @param array<string, string> $form */
    private function mutate(string $actorId, array $form): void
    {
        $action = AdministratorRequest::required($form, 'action');
        match ($action) {
            'menu.create' => $this->navigation->createMenu(
                $actorId,
                AdministratorRequest::required($form, 'handle'),
                AdministratorRequest::required($form, 'title'),
            ),
            'menu.update' => $this->navigation->updateMenu(
                $actorId,
                AdministratorRequest::required($form, 'id'),
                AdministratorRequest::positiveInteger($form, 'version'),
                AdministratorRequest::required($form, 'handle'),
                AdministratorRequest::required($form, 'title'),
            ),
            'menu.delete' => $this->navigation->deleteMenu(
                $actorId,
                AdministratorRequest::required($form, 'id'),
                AdministratorRequest::positiveInteger($form, 'version'),
            ),
            'item.create' => $this->navigation->createItem(
                $actorId,
                AdministratorRequest::required($form, 'menu_id'),
                $this->nullable($form, 'parent_id'),
                AdministratorRequest::required($form, 'title'),
                AdministratorRequest::required($form, 'slug'),
                $this->nonNegativeInteger($form, 'position'),
            ),
            'item.update' => $this->navigation->updateItem(
                $actorId,
                AdministratorRequest::required($form, 'id'),
                AdministratorRequest::positiveInteger($form, 'version'),
                $this->nullable($form, 'parent_id'),
                AdministratorRequest::required($form, 'title'),
                AdministratorRequest::required($form, 'slug'),
                $this->nonNegativeInteger($form, 'position'),
            ),
            'item.delete' => $this->navigation->deleteItem(
                $actorId,
                AdministratorRequest::required($form, 'id'),
                AdministratorRequest::positiveInteger($form, 'version'),
            ),
            default => throw new InvalidArgumentException('The navigation action is not supported.'),
        };
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
