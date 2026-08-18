<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Console\Command;

use Kumwe\CMS\Application\Authorization\SystemIdentity;
use Kumwe\CMS\Application\Operations\ExpiredMigrationLockRecovery;
use Kumwe\CMS\Application\Operations\MigrationLockRecoveryService;
use Kumwe\CMS\Delivery\Console\Command\RecoverMigrationLockCommand;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use Kumwe\CMS\Tests\Support\TranslatesConsoleOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecoverMigrationLockCommand::class)]
final class RecoverMigrationLockCommandTest extends TestCase
{
    public function testItRejectsUnknownArguments(): void
    {
        [$command, $recovery] = $this->command();
        $output = new RecoverLockOutput();

        self::assertSame(64, $command->execute(['--force'], $output));
        self::assertSame([], $recovery->owners);
        self::assertCount(1, $output->errors);
        self::assertStringContainsString('Unknown', $output->errors[0]);
    }

    public function testItRequiresTheOwnerAndExplicitQuiescenceConfirmation(): void
    {
        [$command, $recovery] = $this->command();
        $output = new RecoverLockOutput();

        self::assertSame(64, $command->execute(['--expected-owner=' . str_repeat('a', 64)], $output));
        self::assertSame([], $recovery->owners);
        self::assertCount(1, $output->errors);
        self::assertStringContainsString('Usage:', $output->errors[0]);
    }

    public function testItRecoversTheExactConfirmedOwner(): void
    {
        [$command, $recovery] = $this->command();
        $owner = str_repeat('c', 64);
        $output = new RecoverLockOutput();

        self::assertSame(0, $command->execute([
            '--expected-owner=' . $owner,
            '--confirm-legacy-quiesced',
        ], $output));
        self::assertSame([$owner], $recovery->owners);
        self::assertCount(1, $output->lines);
        self::assertStringContainsString('removed', $output->lines[0]);
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

/** Recording sink for the recovery command's lines, resolving wording as production does. */
final class RecoverLockOutput implements Output
{
    use TranslatesConsoleOutput;

    /** @var list<string> */
    public array $lines = [];

    /** @var list<string> */
    public array $errors = [];

    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }
}
