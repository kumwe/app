<?php

declare(strict_types=1);

namespace Kumwe\CMS\Navigation\Application;

use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\AuthorizationResourceOwnershipUnknown;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnership;
use Kumwe\CMS\Application\Authorization\SiteContext;

final readonly class PublicNavigation
{
    public function __construct(
        private NavigationRepository $repository,
        private ?ResourceSiteOwnership $ownership = null,
        private ?SiteContext $site = null,
        private string $preferredHandle = 'main',
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        $menus = $this->repository->menus();
        $menu = null;
        $fallback = null;
        foreach ($menus as $candidate) {
            if (!$this->belongsToPublicSite('menu', $candidate->id)) {
                continue;
            }
            $fallback ??= $candidate;
            if ($candidate->handle === $this->preferredHandle) {
                $menu = $candidate;
                break;
            }
        }
        $menu ??= $fallback;
        if (!$menu instanceof MenuRecord) {
            return [];
        }

        $byParent = [];
        foreach ($this->repository->items($menu->id) as $item) {
            if (!$this->belongsToPublicSite('menu_item', $item->id)) {
                continue;
            }
            $byParent[$item->parentId ?? ''][] = $item;
        }
        foreach ($byParent as &$siblings) {
            usort($siblings, static fn (MenuItemRecord $left, MenuItemRecord $right): int => [
                $left->position,
                $left->title,
            ] <=> [
                $right->position,
                $right->title,
            ]);
        }
        unset($siblings);

        return $this->branch($byParent, null, []);
    }

    /**
     * @param array<string, list<MenuItemRecord>> $byParent
     * @param array<string, true> $ancestors
     * @return list<array<string, mixed>>
     */
    private function branch(array $byParent, ?string $parentId, array $ancestors): array
    {
        $branch = [];
        foreach ($byParent[$parentId ?? ''] ?? [] as $item) {
            if (isset($ancestors[$item->id])) {
                continue;
            }
            $nextAncestors = $ancestors;
            $nextAncestors[$item->id] = true;
            $branch[] = [
                'id' => $item->id,
                'title' => $item->title,
                'href' => '/pages/' . rawurlencode($item->slug),
                'path' => $item->path,
                'children' => $this->branch($byParent, $item->id, $nextAncestors),
            ];
        }
        return $branch;
    }

    private function belongsToPublicSite(string $type, string $id): bool
    {
        if ($this->ownership === null) {
            return true;
        }
        try {
            return $this->ownership->siteFor(AuthorizationResource::item($type, $id))->identifier()
                === ($this->site ?? SiteContext::default())->identifier();
        } catch (AuthorizationResourceOwnershipUnknown) {
            return false;
        }
    }
}
