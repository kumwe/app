<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;

final readonly class RecordQuerySpecification
{
    /** @var list<RecordSort> */
    public array $sorts;

    public RecordProjection $projection;

    /** @param list<RecordSort> $sorts */
    public function __construct(
        public ?RecordFilter $filter = null,
        public ?RecordSearch $search = null,
        array $sorts = [],
        public ?RecordCursor $after = null,
        public int $pageSize = 50,
        ?RecordProjection $projection = null,
        public bool $includeArchived = false,
        public bool $includeDeleted = false,
    ) {
        if ($pageSize < 1 || $pageSize > 200 || count($sorts) > 5) {
            throw new InvalidArgumentException('A business-record query page or sort count exceeds its bound.');
        }
        if (
            $filter !== null
            && ($filter->depth() > 8 || $filter->relationDepth() > 2 || $filter->operationCount() > 64)
        ) {
            throw new InvalidArgumentException('A business-record query exceeds its depth or operation bound.');
        }
        $seenSorts = [];
        foreach ($sorts as $sort) {
            if (isset($seenSorts[$sort->field])) {
                throw new InvalidArgumentException('A business-record sort field is duplicated.');
            }
            $seenSorts[$sort->field] = true;
        }
        $this->sorts = array_values($sorts);
        $this->projection = $projection ?? new RecordProjection();
    }

    /** @return array<string, mixed> */
    public function toArray(bool $includeCursor = true): array
    {
        return [
            'filter' => $this->filter?->toArray(),
            'search' => $this->search?->toArray(),
            'sorts' => array_map(static fn (RecordSort $sort): array => $sort->toArray(), $this->sorts),
            'after' => $includeCursor ? $this->after?->value() : null,
            'page_size' => $this->pageSize,
            'projection' => $this->projection->toArray(),
            'include_archived' => $this->includeArchived,
            'include_deleted' => $this->includeDeleted,
        ];
    }

    public function digest(): string
    {
        return CanonicalDefinitionJson::checksum($this->toArray(false));
    }
}
