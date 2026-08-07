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
    public function items(?string $homepageContentId = null, ?string $preferredHandle = null): array
    {
        $menu = $this->publicMenu($preferredHandle);
        if (!$menu instanceof MenuRecord) {
            return [];
        }

        $byParent = [];
        $pathByContent = [];
        foreach ($this->repository->items($menu->id) as $item) {
            if (!$this->belongsToPublicSite('menu_item', $item->id)) {
                continue;
            }
            $byParent[$item->parentId ?? ''][] = $item;
            if ($item->targetType === 'content' && $item->contentId !== null) {
                $pathByContent[$item->contentId] ??= $item->path;
            }
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

        return $this->branch($byParent, null, [], $pathByContent, $homepageContentId);
    }

    public function contentIdForPath(string $path, ?string $preferredHandle = null): ?string
    {
        $menu = $this->publicMenu($preferredHandle);
        if (!$menu instanceof MenuRecord) {
            return null;
        }
        $path = '/' . trim($path, '/');
        foreach ($this->repository->items($menu->id) as $item) {
            if (
                $item->path === $path
                && $item->targetType === 'content'
                && $item->contentId !== null
                && $this->belongsToPublicSite('menu_item', $item->id)
            ) {
                return $item->contentId;
            }
        }

        return null;
    }

    public function pathForContent(string $contentId, ?string $preferredHandle = null): ?string
    {
        $menu = $this->publicMenu($preferredHandle);
        if (!$menu instanceof MenuRecord) {
            return null;
        }
        foreach ($this->repository->items($menu->id) as $item) {
            if (
                $item->targetType === 'content'
                && $item->contentId === $contentId
                && $this->belongsToPublicSite('menu_item', $item->id)
            ) {
                return $item->path;
            }
        }

        return null;
    }

    /**
     * @param array<string, list<MenuItemRecord>> $byParent
     * @param array<string, true> $ancestors
     * @param array<string, string> $pathByContent
     * @return list<array<string, mixed>>
     */
    private function branch(
        array $byParent,
        ?string $parentId,
        array $ancestors,
        array $pathByContent,
        ?string $homepageContentId,
    ): array {
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
                'target_type' => $item->targetType,
                'content_id' => $item->contentId,
                'target_url' => $item->targetUrl,
                'href' => $this->href($item, $pathByContent, $homepageContentId),
                'path' => $item->path,
                'children' => $this->branch(
                    $byParent,
                    $item->id,
                    $nextAncestors,
                    $pathByContent,
                    $homepageContentId,
                ),
            ];
        }
        return $branch;
    }

    /** @param array<string, string> $pathByContent */
    private function href(
        MenuItemRecord $item,
        array $pathByContent,
        ?string $homepageContentId,
    ): string {
        if ($item->targetType === 'url') {
            return $item->targetUrl ?? $item->path;
        }
        if ($item->targetType === 'anchor') {
            $fragment = $item->targetUrl ?? '';
            if ($item->contentId === null) {
                return $fragment === '' ? $item->path : $fragment;
            }
            $path = $item->contentId === $homepageContentId
                ? '/'
                : ($pathByContent[$item->contentId] ?? '');
            if ($path === '') {
                return $fragment;
            }

            return $path === '/' ? '/' . $fragment : rtrim($path, '/') . $fragment;
        }

        return $item->contentId !== null && $item->contentId === $homepageContentId
            ? '/'
            : ($item->contentId === null ? $item->path : ($pathByContent[$item->contentId] ?? $item->path));
    }

    private function publicMenu(?string $preferredHandle = null): ?MenuRecord
    {
        $preferredHandle ??= $this->preferredHandle;
        $fallback = null;
        foreach ($this->repository->menus() as $candidate) {
            if (!$this->belongsToPublicSite('menu', $candidate->id)) {
                continue;
            }
            $fallback ??= $candidate;
            if ($candidate->handle === $preferredHandle) {
                return $candidate;
            }
        }

        return $fallback;
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
