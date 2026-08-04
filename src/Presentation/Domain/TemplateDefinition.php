<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Domain;

use InvalidArgumentException;

final readonly class TemplateDefinition
{
    /** @var list<string> */
    private array $slots;

    /** @param array<mixed> $slots */
    public function __construct(private string $id, private string $handle, array $slots)
    {
        self::assertUuid($id);

        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $handle) !== 1) {
            throw new InvalidArgumentException('A template handle must be a stable lowercase identifier.');
        }

        if (!array_is_list($slots) || $slots === []) {
            throw new InvalidArgumentException('A template requires a non-empty slot list.');
        }

        foreach ($slots as $slot) {
            if (!is_string($slot)) {
                throw new InvalidArgumentException('Template slots must be strings.');
            }

            if (preg_match('/^[a-z][a-z0-9_-]*$/D', $slot) !== 1) {
                throw new InvalidArgumentException('Template slots must be safe lowercase identifiers.');
            }
        }

        /** @var list<string> $slots */
        if (count($slots) !== count(array_unique($slots))) {
            throw new InvalidArgumentException('A template requires unique declared slots.');
        }

        $this->slots = $slots;
    }

    public function id(): string
    {
        return strtolower($this->id);
    }

    public function handle(): string
    {
        return $this->handle;
    }

    public function hasSlot(string $slot): bool
    {
        return in_array($slot, $this->slots, true);
    }

    private static function assertUuid(string $id): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id) !== 1) {
            throw new InvalidArgumentException('A template ID must be a canonical UUID.');
        }
    }
}
