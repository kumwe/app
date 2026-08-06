<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Application;

use InvalidArgumentException;

final readonly class ContentBrowseQuery
{
    private const SCOPES = ['active', 'trashed', 'all'];
    private const SORTS = ['updated_desc', 'updated_asc', 'title_asc', 'title_desc'];

    public function __construct(
        public string $search = '',
        public string $status = '',
        public string $contentType = '',
        public string $scope = 'active',
        public string $sort = 'updated_desc',
        public int $page = 1,
        public int $perPage = 25,
    ) {
        if (mb_strlen($search) > 160) {
            throw new InvalidArgumentException('The content search may not exceed 160 characters.');
        }
        if ($status !== '' && preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $status) !== 1) {
            throw new InvalidArgumentException('The content status filter is invalid.');
        }
        if (
            $contentType !== ''
            && preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',
                $contentType,
            ) !== 1
        ) {
            throw new InvalidArgumentException('The content type filter is invalid.');
        }
        if (!in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('The content scope filter is invalid.');
        }
        if (!in_array($sort, self::SORTS, true)) {
            throw new InvalidArgumentException('The content sort is invalid.');
        }
        if ($page < 1 || $page > 100_000) {
            throw new InvalidArgumentException('The content page is invalid.');
        }
        if (!in_array($perPage, [10, 25, 50], true)) {
            throw new InvalidArgumentException('The content page size is invalid.');
        }
    }

    public function withPage(int $page): self
    {
        return new self(
            $this->search,
            $this->status,
            $this->contentType,
            $this->scope,
            $this->sort,
            $page,
            $this->perPage,
        );
    }

    /** @return array<string, int|string> */
    public function toQueryParameters(): array
    {
        return array_filter([
            'q' => $this->search,
            'status' => $this->status,
            'type' => $this->contentType,
            'scope' => $this->scope,
            'sort' => $this->sort,
            'page' => $this->page,
            'per_page' => $this->perPage,
        ], static fn (int|string $value, string $key): bool => match ($key) {
            'q', 'status', 'type' => $value !== '',
            'scope' => $value !== 'active',
            'sort' => $value !== 'updated_desc',
            'page' => $value !== 1,
            'per_page' => $value !== 25,
            default => true,
        }, ARRAY_FILTER_USE_BOTH);
    }
}
