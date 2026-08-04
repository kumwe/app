<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application;

interface ExtensionManager
{
    /** @return list<array<string, mixed>> */
    public function installed(): array;

    /** @return array<string, mixed> */
    public function install(
        string $archiveFile,
        string $actorId,
        ?string $signingKeyId = null,
        ?string $base64Signature = null,
    ): array;

    /** @return array<string, mixed> */
    public function activate(string $identifier, string $actorId): array;

    /** @return array<string, mixed> */
    public function disable(string $identifier, string $actorId): array;

    public function uninstall(string $identifier, string $actorId): void;
}
