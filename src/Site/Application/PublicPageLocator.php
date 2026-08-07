<?php

declare(strict_types=1);

namespace Kumwe\CMS\Site\Application;

use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Content\Application\ContentRecord;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Navigation\Application\PublicNavigation;
use Kumwe\CMS\Presentation\Application\SitePresentation;

/** Resolves public content through the site's managed navigation and homepage settings. */
final readonly class PublicPageLocator
{
    public function __construct(
        private ContentService $content,
        private SiteSettings $settings,
        private PublicNavigation $navigation,
        private SiteContext $site,
    ) {
    }

    public function homepage(): ?ContentRecord
    {
        $settings = $this->settings->current();
        $contentId = $settings['homepage_content_id'] ?? null;
        if (is_string($contentId) && trim($contentId) !== '') {
            return $this->content->publishedById(trim($contentId), $this->site);
        }

        $legacySlug = $settings['homepage_slug'] ?? null;

        return is_string($legacySlug) && $legacySlug !== ''
            ? $this->content->publishedBySlug($legacySlug, $this->site)
            : null;
    }

    public function byPath(string $path): ?ContentRecord
    {
        $path = $this->normalizePath($path);
        if ($path === null) {
            return null;
        }
        if ($path === '/') {
            return $this->homepage();
        }

        if (preg_match('#^/pages/([a-z0-9]+(?:-[a-z0-9]+)*)$#D', $path, $matches) === 1) {
            return $this->content->publishedBySlug($matches[1], $this->site);
        }
        if ($this->hasReservedPrefix($path)) {
            return null;
        }

        $contentId = $this->navigation->contentIdForPath($path, $this->primaryMenu());
        if ($contentId !== null) {
            return $this->content->publishedById($contentId, $this->site);
        }

        $item = $this->itemAtPath($this->rawNavigation(), $path);
        if ($item !== null) {
            return $this->contentForItem($item);
        }

        if (preg_match('#^/([a-z0-9]+(?:-[a-z0-9]+)*)$#D', $path, $matches) !== 1) {
            return null;
        }

        return $this->content->publishedBySlug($matches[1], $this->site);
    }

    public function pathFor(ContentRecord $record): string
    {
        $homepage = $this->homepage();
        if ($homepage !== null && $homepage->entry->id() === $record->entry->id()) {
            return '/';
        }

        $navigation = $this->rawNavigation();
        $path = $this->navigation->pathForContent($record->entry->id(), $this->primaryMenu())
            ?? $this->pathForContentId($navigation, $record->entry->id())
            ?? $this->pathForLegacySlug($navigation, $record->entry->slug());

        return $path ?? '/' . rawurlencode($record->entry->slug());
    }

    public function publicPathFor(ContentRecord $record): ?string
    {
        $published = $this->content->publishedById($record->entry->id(), $this->site);
        if ($published === null) {
            return null;
        }
        $path = $this->pathFor($published);
        $resolved = $this->byPath($path);

        return $resolved !== null && $resolved->entry->id() === $published->entry->id()
            ? $path
            : null;
    }

    /** @return list<array<string, mixed>> */
    public function navigation(): array
    {
        return $this->presentNavigation($this->rawNavigation());
    }

    /** @return list<array<string, mixed>> */
    private function rawNavigation(): array
    {
        $contentId = $this->settings->current()['homepage_content_id'] ?? null;

        return $this->navigation->items(
            is_string($contentId) && $contentId !== '' ? $contentId : null,
            $this->primaryMenu(),
        );
    }

    private function primaryMenu(): string
    {
        return SitePresentation::from(
            $this->settings->current()['presentation'] ?? SitePresentation::defaults(),
        )->primaryMenu();
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function presentNavigation(array $items): array
    {
        $presented = [];
        foreach ($items as $item) {
            $item['children'] = $this->presentNavigation($this->children($item));
            $targetType = $this->targetType($item);
            if ($targetType === 'content' || $targetType === null) {
                $record = $this->contentForItem($item);
                if ($record === null) {
                    continue;
                }
                $item['href'] = $this->pathFor($record);
            }
            $presented[] = $item;
        }

        return $presented;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>|null
     */
    private function itemAtPath(array $items, string $path): ?array
    {
        foreach ($items as $item) {
            $candidate = $item['path'] ?? null;
            if (is_string($candidate) && $this->normalizePath($candidate) === $path) {
                return $item;
            }
            $children = $this->children($item);
            if ($children !== []) {
                $match = $this->itemAtPath($children, $path);
                if ($match !== null) {
                    return $match;
                }
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function pathForContentId(array $items, string $contentId): ?string
    {
        foreach ($items as $item) {
            $path = $item['path'] ?? null;
            $normalizedPath = is_string($path) ? $this->normalizePath($path) : null;
            if ($normalizedPath !== null && $this->contentId($item) === $contentId) {
                return $normalizedPath;
            }
            $children = $this->children($item);
            if ($children !== []) {
                $childPath = $this->pathForContentId($children, $contentId);
                if ($childPath !== null) {
                    return $childPath;
                }
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $items */
    private function pathForLegacySlug(array $items, string $slug): ?string
    {
        foreach ($items as $item) {
            $path = $item['path'] ?? null;
            $normalizedPath = is_string($path) ? $this->normalizePath($path) : null;
            if (
                $normalizedPath !== null
                && $this->targetType($item) === null
                && ($item['slug'] ?? null) === $slug
            ) {
                return $normalizedPath;
            }
            $children = $this->children($item);
            if ($children !== []) {
                $childPath = $this->pathForLegacySlug($children, $slug);
                if ($childPath !== null) {
                    return $childPath;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $item */
    private function contentForItem(array $item): ?ContentRecord
    {
        $targetType = $this->targetType($item);
        if ($targetType !== null && $targetType !== 'content') {
            return null;
        }
        $contentId = $this->contentId($item);
        if ($contentId !== null) {
            return $this->content->publishedById($contentId, $this->site);
        }
        $slug = $item['slug'] ?? null;

        return is_string($slug) && $slug !== ''
            ? $this->content->publishedBySlug($slug, $this->site)
            : null;
    }

    /** @param array<string, mixed> $item */
    private function contentId(array $item): ?string
    {
        $target = $item['target'] ?? null;
        $value = $item['content_id'] ?? $item['target_id'] ?? null;
        if ($value === null && is_array($target)) {
            $value = $target['content_id'] ?? $target['id'] ?? null;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @param array<string, mixed> $item */
    private function targetType(array $item): ?string
    {
        $target = $item['target'] ?? null;
        $value = $item['target_type'] ?? null;
        if ($value === null && is_array($target)) {
            $value = $target['type'] ?? null;
        }

        return is_string($value) && $value !== '' ? strtolower($value) : null;
    }

    /**
     * @param array<string, mixed> $item
     * @return list<array<string, mixed>>
     */
    private function children(array $item): array
    {
        $children = $item['children'] ?? null;
        if (!is_array($children) || !array_is_list($children)) {
            return [];
        }
        $normalized = [];
        foreach ($children as $child) {
            if (is_array($child) && !array_is_list($child)) {
                /** @var array<string, mixed> $child */
                $normalized[] = $child;
            }
        }

        return $normalized;
    }

    private function normalizePath(string $path): ?string
    {
        $path = parse_url($path, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path[0] !== '/') {
            return null;
        }
        if (preg_match('/%(?:2f|5c)/i', $path) === 1 || str_contains($path, '//')) {
            return null;
        }
        $decoded = rawurldecode($path);
        if (
            str_contains($decoded, "\0")
            || str_contains($decoded, '\\')
            || preg_match('#(?:^|/)(?:\.{1,2})(?:/|$)#D', $decoded) === 1
        ) {
            return null;
        }
        if ($decoded !== '/' && str_ends_with($decoded, '/')) {
            $decoded = rtrim($decoded, '/');
        }

        return preg_match('#^/(?:[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*)?$#D', $decoded) === 1
            ? $decoded
            : null;
    }

    private function hasReservedPrefix(string $path): bool
    {
        $firstSegment = explode('/', ltrim($path, '/'), 2)[0] ?? '';

        return in_array($firstSegment, [
            'administrator',
            'api',
            'assets',
            'health',
            'mcp',
            'media',
            'pages',
        ], true);
    }
}
