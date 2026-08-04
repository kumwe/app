<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Domain;

use InvalidArgumentException;

final readonly class TemplateAssignment
{
    /** @var list<AssignmentCondition> */
    private array $conditions;

    /** @param array<mixed> $conditions */
    public function __construct(
        private string $id,
        private TemplateDefinition $template,
        private int $priority,
        array $conditions = [],
        private bool $enabled = true,
    ) {
        self::assertUuid($id);

        if (!array_is_list($conditions)) {
            throw new InvalidArgumentException('Template assignment conditions must be a list.');
        }

        foreach ($conditions as $condition) {
            if (!($condition instanceof AssignmentCondition)) {
                throw new InvalidArgumentException(
                    'Template assignment conditions must implement AssignmentCondition.',
                );
            }
        }

        /** @var list<AssignmentCondition> $conditions */
        $this->conditions = $conditions;
    }

    public function id(): string
    {
        return strtolower($this->id);
    }

    public function template(): TemplateDefinition
    {
        return $this->template;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function matches(PresentationContext $context): bool
    {
        if (!$this->enabled) {
            return false;
        }

        foreach ($this->conditions as $condition) {
            if (!$condition->matches($context)) {
                return false;
            }
        }

        return true;
    }

    private static function assertUuid(string $id): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id) !== 1) {
            throw new InvalidArgumentException('A template assignment ID must be a canonical UUID.');
        }
    }
}
