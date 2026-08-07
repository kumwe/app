<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

final readonly class DefinitionOwner
{
    public function __construct(public DefinitionOwnerType $type, public string $identifier)
    {
        $pattern = match ($type) {
            DefinitionOwnerType::Core => '/^core$/D',
            DefinitionOwnerType::Extension => '#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$#D',
            DefinitionOwnerType::Site => '/^[a-z0-9][a-z0-9._-]{0,190}$/D',
        };
        if (preg_match($pattern, $identifier) !== 1) {
            throw new InvalidBusinessDefinition('The business-definition owner identifier is invalid.');
        }
    }

    public static function core(): self
    {
        return new self(DefinitionOwnerType::Core, 'core');
    }

    public static function extension(string $identifier): self
    {
        return new self(DefinitionOwnerType::Extension, strtolower(trim($identifier)));
    }

    public static function site(string $identifier): self
    {
        return new self(DefinitionOwnerType::Site, strtolower(trim($identifier)));
    }

    public function namespace(): string
    {
        return match ($this->type) {
            DefinitionOwnerType::Core => 'core',
            DefinitionOwnerType::Extension => str_replace('/', '.', $this->identifier),
            DefinitionOwnerType::Site => 'site.' . $this->identifier,
        };
    }

    public function assertOwns(string $handle): void
    {
        if (!str_starts_with($handle, $this->namespace() . '.')) {
            throw new InvalidBusinessDefinition(sprintf(
                'Definition %s is outside the %s owner namespace.',
                $handle,
                $this->namespace(),
            ));
        }
    }

    /** @return array{type: string, identifier: string} */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'identifier' => $this->identifier];
    }
}
