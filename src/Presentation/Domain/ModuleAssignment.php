<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Domain;

use InvalidArgumentException;

final readonly class ModuleAssignment
{
    /** @var list<AssignmentCondition> */
    private array $conditions;

    /** @param array<mixed> $conditions */
    public function __construct(
        private string $id,
        private string $moduleInstanceId,
        private string $slot,
        private int $position,
        array $conditions = [],
        private bool $enabled = true,
    ) {
        self::assertUuid($id, 'module assignment');
        self::assertUuid($moduleInstanceId, 'module instance');

        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $slot) !== 1) {
            throw new InvalidArgumentException('A module assignment slot must be a safe lowercase identifier.');
        }

        if (!array_is_list($conditions)) {
            throw new InvalidArgumentException('Module assignment conditions must be a list.');
        }

        foreach ($conditions as $condition) {
            if (!($condition instanceof AssignmentCondition)) {
                throw new InvalidArgumentException('Module assignment conditions must implement AssignmentCondition.');
            }
        }

        /** @var list<AssignmentCondition> $conditions */
        $this->conditions = $conditions;
    }

    public function id(): string
    {
        return strtolower($this->id);
    }

    public function moduleInstanceId(): string
    {
        return strtolower($this->moduleInstanceId);
    }

    public function slot(): string
    {
        return $this->slot;
    }

    public function position(): int
    {
        return $this->position;
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

    private static function assertUuid(string $id, string $subject): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id) !== 1) {
            throw new InvalidArgumentException(sprintf('A %s ID must be a canonical UUID.', $subject));
        }
    }
}
