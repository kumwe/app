<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Domain;

use InvalidArgumentException;

final readonly class AssignmentCondition
{
    public function __construct(private ConditionType $type, private string $value)
    {
        if ($value === '' || strlen($value) > 190 || preg_match('/[\x00-\x1F\x7F]/D', $value) === 1) {
            throw new InvalidArgumentException('An assignment condition requires a safe non-empty value.');
        }
    }

    public function matches(PresentationContext $context): bool
    {
        return match ($this->type) {
            ConditionType::Route => $context->route() === $this->value,
            ConditionType::Menu => $context->menuId() === $this->value,
            ConditionType::Locale => $context->locale() === $this->value,
            ConditionType::Role => $context->hasRole($this->value),
        };
    }

    /** @return array{type: string, value: string} */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'value' => $this->value];
    }
}
