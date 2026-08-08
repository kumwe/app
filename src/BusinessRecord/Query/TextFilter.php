<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

use InvalidArgumentException;

final readonly class TextFilter implements RecordFilter
{
    public function __construct(public string $field, public TextOperator $operator, public string $text)
    {
        QueryIdentifier::assertField($field);
        if ($text === '' || mb_strlen($text) > 512) {
            throw new InvalidArgumentException('A text filter requires between 1 and 512 characters.');
        }
    }

    public function toArray(): array
    {
        return ['type' => 'text', 'field' => $this->field, 'operator' => $this->operator->value,
            'text' => $this->text];
    }

    public function operationCount(): int
    {
        return 1;
    }

    public function depth(): int
    {
        return 1;
    }

    public function relationDepth(): int
    {
        return 0;
    }
}
