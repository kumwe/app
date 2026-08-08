<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Domain;

use InvalidArgumentException;

final class ExtensionRecord
{
    private function __construct(
        private readonly ExtensionIdentifier $identifier,
        private ExtensionType $type,
        private SemanticVersion $installedVersion,
        private ExtensionStatus $status,
        private int $registryVersion,
    ) {
        if ($registryVersion < 0) {
            throw new InvalidArgumentException('An extension registry version cannot be negative.');
        }
    }

    public static function install(ExtensionManifest $manifest): self
    {
        return new self(
            $manifest->identifier(),
            $manifest->type(),
            $manifest->version(),
            ExtensionStatus::Disabled,
            0,
        );
    }

    public static function reconstitute(
        ExtensionIdentifier $identifier,
        ExtensionType $type,
        SemanticVersion $installedVersion,
        ExtensionStatus $status,
        int $registryVersion,
    ): self {
        return new self($identifier, $type, $installedVersion, $status, $registryVersion);
    }

    public function identifier(): ExtensionIdentifier
    {
        return $this->identifier;
    }

    public function type(): ExtensionType
    {
        return $this->type;
    }

    public function installedVersion(): SemanticVersion
    {
        return $this->installedVersion;
    }

    public function status(): ExtensionStatus
    {
        return $this->status;
    }

    public function registryVersion(): int
    {
        return $this->registryVersion;
    }

    public function activate(): void
    {
        $this->changeStatus(ExtensionStatus::Active);
    }

    public function disable(): void
    {
        $this->changeStatus(ExtensionStatus::Disabled);
    }

    public function upgrade(ExtensionManifest $manifest): void
    {
        if (!$manifest->identifier()->equals($this->identifier) || $manifest->type() !== $this->type) {
            throw new InvalidArgumentException('An extension can only be upgraded by a matching manifest.');
        }

        if ($manifest->version()->compare($this->installedVersion) <= 0) {
            throw new InvalidArgumentException('An extension upgrade must increase the installed version.');
        }

        $this->installedVersion = $manifest->version();
        $this->status = ExtensionStatus::Disabled;
        ++$this->registryVersion;
    }

    private function changeStatus(ExtensionStatus $status): void
    {
        if ($this->status === $status) {
            return;
        }

        $this->status = $status;
        ++$this->registryVersion;
    }
}
