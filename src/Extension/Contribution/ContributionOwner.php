<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;

final readonly class ContributionOwner
{
    public const CORE = 'core';

    private function __construct(private string $identifier)
    {
    }

    public static function core(): self
    {
        return new self(self::CORE);
    }

    public static function extension(string $identifier): self
    {
        return new self(ExtensionIdentifier::fromString($identifier)->value());
    }

    public static function fromString(string $identifier): self
    {
        return $identifier === self::CORE ? self::core() : self::extension($identifier);
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function namespace(): string
    {
        return $this->identifier === self::CORE
            ? self::CORE
            : str_replace('/', '.', $this->identifier);
    }

    public function assertOwns(string $identifier, string $kind): void
    {
        if ($this->identifier === self::CORE) {
            if (!str_starts_with($identifier, self::CORE . '.')) {
                throw new InvalidArgumentException(sprintf('Core %s identifiers must use the core namespace.', $kind));
            }

            return;
        }

        if (!str_starts_with($identifier, $this->namespace() . '.')) {
            throw new InvalidArgumentException(sprintf(
                'Extension %s cannot claim %s identifier %s.',
                $this->identifier,
                $kind,
                $identifier,
            ));
        }
    }
}
