<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Operations;

use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Identity\Domain\Capability;
use RuntimeException;

final readonly class MigrationLockRecoveryService
{
    public function __construct(
        private ExpiredMigrationLockRecovery $recovery,
        private AuthorizationGateway $authorization,
    ) {
    }

    public function recover(
        ExecutionContext $context,
        string $expectedOwnerToken,
        bool $legacyProcessesAreQuiesced,
    ): void {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('system.migrate'),
            AuthorizationResource::collection('database_schema'),
        );
        if (!$legacyProcessesAreQuiesced) {
            throw new RuntimeException('Migration-lock recovery requires a confirmed quiesced legacy deployment.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedOwnerToken) !== 1) {
            throw new RuntimeException('The expected legacy migration owner token is invalid.');
        }

        $this->recovery->recoverExpiredLegacyOwner($expectedOwnerToken);
    }
}
