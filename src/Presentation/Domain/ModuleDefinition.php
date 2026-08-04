<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Domain;

use InvalidArgumentException;

final readonly class ModuleDefinition
{
    /** @param list<string> $requiredSettings */
    public function __construct(
        private string $id,
        private string $handle,
        private array $requiredSettings = [],
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id) !== 1) {
            throw new InvalidArgumentException('A module definition ID must be a canonical UUID.');
        }

        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $handle) !== 1) {
            throw new InvalidArgumentException('A module handle must be a stable lowercase identifier.');
        }

        if (!array_is_list($requiredSettings) || count($requiredSettings) !== count(array_unique($requiredSettings))) {
            throw new InvalidArgumentException('Required module settings must be unique.');
        }

        foreach ($requiredSettings as $setting) {
            if (!is_string($setting) || preg_match('/^[a-z][a-z0-9_]*$/D', $setting) !== 1) {
                throw new InvalidArgumentException('Required module settings must be safe identifiers.');
            }
        }
    }

    public function id(): string
    {
        return strtolower($this->id);
    }

    public function handle(): string
    {
        return $this->handle;
    }

    /** @param array<string, mixed> $settings */
    public function validateSettings(array $settings): void
    {
        foreach ($this->requiredSettings as $required) {
            if (!array_key_exists($required, $settings)) {
                throw new InvalidArgumentException(sprintf('Required module setting %s is missing.', $required));
            }
        }
    }
}
