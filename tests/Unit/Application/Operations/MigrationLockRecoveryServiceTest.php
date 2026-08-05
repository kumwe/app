<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Application\Operations;

use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\SystemIdentity;
use Kumwe\CMS\Application\Operations\ExpiredMigrationLockRecovery;
use Kumwe\CMS\Application\Operations\MigrationLockRecoveryService;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(MigrationLockRecoveryService::class)]
final class MigrationLockRecoveryServiceTest extends TestCase
{
    public function testItRequiresExplicitLegacyProcessQuiescence(): void
    {
        $port = new RecordingExpiredMigrationLockRecovery();
        $service = new MigrationLockRecoveryService($port, AuthorizationContext::gateway());

        try {
            $service->recover($this->context(), str_repeat('a', 64), false);
            self::fail('Migration-lock recovery must require explicit quiescence confirmation.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('confirmed quiesced', $exception->getMessage());
        }
        self::assertSame([], $port->owners);
    }

    public function testItRejectsMalformedOwnerTokensBeforePersistence(): void
    {
        $port = new RecordingExpiredMigrationLockRecovery();
        $service = new MigrationLockRecoveryService($port, AuthorizationContext::gateway());

        try {
            $service->recover($this->context(), '../wrong-owner', true);
            self::fail('Migration-lock recovery must require the exact legacy token shape.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('owner token is invalid', $exception->getMessage());
        }
        self::assertSame([], $port->owners);
    }

    public function testItAuthorizesAndDelegatesAnExactConfirmedOwner(): void
    {
        $port = new RecordingExpiredMigrationLockRecovery();
        $service = new MigrationLockRecoveryService($port, AuthorizationContext::gateway());
        $owner = str_repeat('b', 64);

        $service->recover($this->context(), $owner, true);

        self::assertSame([$owner], $port->owners);
    }

    private function context(): \Kumwe\CMS\Application\Authorization\ExecutionContext
    {
        return AuthorizationContext::system(SystemIdentity::Migration)->context(
            SiteContext::default(),
            'migration-lock-recovery-test',
        );
    }
}

final class RecordingExpiredMigrationLockRecovery implements ExpiredMigrationLockRecovery
{
    /** @var list<string> */
    public array $owners = [];

    public function recoverExpiredLegacyOwner(string $expectedOwnerToken): void
    {
        $this->owners[] = $expectedOwnerToken;
    }
}
