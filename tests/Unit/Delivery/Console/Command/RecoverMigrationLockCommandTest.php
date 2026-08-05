<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Console\Command;

use Kumwe\CMS\Application\Authorization\SystemIdentity;
use Kumwe\CMS\Application\Operations\ExpiredMigrationLockRecovery;
use Kumwe\CMS\Application\Operations\MigrationLockRecoveryService;
use Kumwe\CMS\Delivery\Console\Command\RecoverMigrationLockCommand;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecoverMigrationLockCommand::class)]
final class RecoverMigrationLockCommandTest extends TestCase
{
    public function testItRejectsUnknownArguments(): void
    {
        [$command, $recovery] = $this->command();
        $output = $this->createMock(Output::class);
        $output->expects(self::once())->method('error')->with(self::stringContains('Unknown'));

        self::assertSame(64, $command->execute(['--force'], $output));
        self::assertSame([], $recovery->owners);
    }

    public function testItRequiresTheOwnerAndExplicitQuiescenceConfirmation(): void
    {
        [$command, $recovery] = $this->command();
        $output = $this->createMock(Output::class);
        $output->expects(self::once())->method('error')->with(self::stringContains('Usage:'));

        self::assertSame(64, $command->execute(['--expected-owner=' . str_repeat('a', 64)], $output));
        self::assertSame([], $recovery->owners);
    }

    public function testItRecoversTheExactConfirmedOwner(): void
    {
        [$command, $recovery] = $this->command();
        $owner = str_repeat('c', 64);
        $output = $this->createMock(Output::class);
        $output->expects(self::once())->method('line')->with(self::stringContains('removed'));

        self::assertSame(0, $command->execute([
            '--expected-owner=' . $owner,
            '--confirm-legacy-quiesced',
        ], $output));
        self::assertSame([$owner], $recovery->owners);
    }

    /** @return array{RecoverMigrationLockCommand, CommandExpiredMigrationLockRecovery} */
    private function command(): array
    {
        $recovery = new CommandExpiredMigrationLockRecovery();

        return [
            new RecoverMigrationLockCommand(
                new MigrationLockRecoveryService($recovery, AuthorizationContext::gateway()),
                AuthorizationContext::system(SystemIdentity::Migration),
            ),
            $recovery,
        ];
    }
}

final class CommandExpiredMigrationLockRecovery implements ExpiredMigrationLockRecovery
{
    /** @var list<string> */
    public array $owners = [];

    public function recoverExpiredLegacyOwner(string $expectedOwnerToken): void
    {
        $this->owners[] = $expectedOwnerToken;
    }
}
