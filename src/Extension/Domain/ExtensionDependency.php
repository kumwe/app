<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Domain;

final readonly class ExtensionDependency
{
    public function __construct(
        private ExtensionIdentifier $extension,
        private VersionConstraint $constraint,
        private bool $optional = false,
    ) {
    }

    public function extension(): ExtensionIdentifier
    {
        return $this->extension;
    }

    public function constraint(): VersionConstraint
    {
        return $this->constraint;
    }

    public function isOptional(): bool
    {
        return $this->optional;
    }

    public function isSatisfiedBy(SemanticVersion $version): bool
    {
        return $this->constraint->accepts($version);
    }
}
