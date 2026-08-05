<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application;

use Kumwe\CMS\Application\Authorization\ExecutionContext;

interface ExtensionManager
{
    /** @return list<array<string, mixed>> */
    public function installed(ExecutionContext $context): array;

    /** @return array<string, mixed> */
    public function install(
        string $archiveFile,
        ExecutionContext $context,
        ?string $signingKeyId = null,
        ?string $base64Signature = null,
    ): array;

    /** @return array<string, mixed> */
    public function activate(string $identifier, ExecutionContext $context): array;

    /** @return array<string, mixed> */
    public function disable(string $identifier, ExecutionContext $context): array;

    public function uninstall(string $identifier, ExecutionContext $context): void;
}
