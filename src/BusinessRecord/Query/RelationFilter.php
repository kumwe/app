<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

final readonly class RelationFilter implements RecordFilter
{
    public function __construct(
        public string $relationship,
        public RelationQuantifier $quantifier,
        public RecordFilter $target,
    ) {
        QueryIdentifier::assertField($relationship);
    }

    public function toArray(): array
    {
        return ['type' => 'relation', 'relationship' => $this->relationship,
            'quantifier' => $this->quantifier->value, 'target' => $this->target->toArray()];
    }

    public function operationCount(): int
    {
        return 1 + $this->target->operationCount();
    }

    public function depth(): int
    {
        return 1 + $this->target->depth();
    }

    public function relationDepth(): int
    {
        return 1 + $this->target->relationDepth();
    }
}
