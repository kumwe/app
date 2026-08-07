<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Identity;

use Doctrine\DBAL\Connection;
use Joomla\DI\Container;
use Kumwe\CMS\Delivery\Console\Command\CreateAdministratorCommand;
use Kumwe\CMS\Delivery\Console\Command\MigrateCommand;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Infrastructure\Administration\DoctrineAdministratorIdentityGateway;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Kernel\ContainerFactory;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CreateAdministratorCommand::class)]
#[CoversClass(DoctrineAdministratorIdentityGateway::class)]
final class AdministratorProvisioningIntegrationTest extends TestCase
{
    public function testCreatesMultipleAdministratorsAndReusesTheCanonicalRole(): void
    {
        $container = $this->recoveryContainer();
        $command = $container->get(CreateAdministratorCommand::class);
        $identities = $container->get(AdministratorIdentityGateway::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(CreateAdministratorCommand::class, $command);
        self::assertInstanceOf(AdministratorIdentityGateway::class, $identities);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);

        $marker = bin2hex(random_bytes(12));
        $firstEmail = sprintf('first-%s@administrator.test', $marker);
        $secondEmail = sprintf('second-%s@administrator.test', $marker);
        $firstPassword = 'First administrator password ' . $marker;
        $secondPassword = 'Second administrator password ' . $marker;

        [$firstStatus, $firstOutput] = $this->provision(
            $command,
            $firstEmail,
            'First Administrator',
            $firstPassword,
        );
        [$secondStatus, $secondOutput] = $this->provision(
            $command,
            $secondEmail,
            'Second Administrator',
            $secondPassword,
        );

        self::assertSame(0, $firstStatus, implode("\n", $firstOutput->errors));
        self::assertSame(0, $secondStatus, implode("\n", $secondOutput->errors));
        self::assertCount(1, $firstOutput->lines);
        self::assertCount(1, $secondOutput->lines);
        self::assertStringStartsWith('Created administrator ', $firstOutput->lines[0]);
        self::assertStringStartsWith('Created administrator ', $secondOutput->lines[0]);

        $firstPrincipal = $identities->authenticate($firstEmail, $firstPassword, 'integration-first');
        $secondPrincipal = $identities->authenticate($secondEmail, $secondPassword, 'integration-second');
        self::assertNotNull($firstPrincipal);
        self::assertNotNull($secondPrincipal);
        self::assertTrue($firstPrincipal->hasCapability(Capability::fromString('administrator.access')));
        self::assertTrue($firstPrincipal->hasCapability(Capability::fromString('users.manage')));
        self::assertTrue($secondPrincipal->hasCapability(Capability::fromString('administrator.access')));
        self::assertTrue($secondPrincipal->hasCapability(Capability::fromString('users.manage')));

        $administratorRoleCount = $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE code = ?',
            $tables->quoted('roles'),
        ), ['administrator']);
        self::assertSame(1, (int) $administratorRoleCount);
        self::assertSame(
            $this->administratorRoleIdFor($database, $tables, $firstEmail),
            $this->administratorRoleIdFor($database, $tables, $secondEmail),
        );
    }

    public function testDuplicateEmailFailsWithoutResettingTheExistingPassword(): void
    {
        $container = $this->recoveryContainer();
        $command = $container->get(CreateAdministratorCommand::class);
        $identities = $container->get(AdministratorIdentityGateway::class);
        self::assertInstanceOf(CreateAdministratorCommand::class, $command);
        self::assertInstanceOf(AdministratorIdentityGateway::class, $identities);

        $marker = bin2hex(random_bytes(12));
        $email = sprintf('duplicate-%s@administrator.test', $marker);
        $originalPassword = 'Original administrator password ' . $marker;
        $replacementPassword = 'Replacement administrator password ' . $marker;

        [$initialStatus, $initialOutput] = $this->provision(
            $command,
            $email,
            'Original Administrator',
            $originalPassword,
        );
        [$duplicateStatus, $duplicateOutput] = $this->provision(
            $command,
            $email,
            'Replacement Administrator',
            $replacementPassword,
        );

        self::assertSame(0, $initialStatus, implode("\n", $initialOutput->errors));
        self::assertSame(1, $duplicateStatus);
        self::assertSame([], $duplicateOutput->lines);
        self::assertCount(1, $duplicateOutput->errors);
        self::assertStringContainsString('already exists', $duplicateOutput->errors[0]);
        self::assertNotNull($identities->authenticate($email, $originalPassword, 'integration-original'));
        self::assertNull($identities->authenticate($email, $replacementPassword, 'integration-replacement'));
    }

    private function recoveryContainer(): Container
    {
        $container = (new ContainerFactory())->createRecovery(Environment::fromGlobals());
        $migrate = $container->get(MigrateCommand::class);
        self::assertInstanceOf(MigrateCommand::class, $migrate);
        $output = new AdministratorProvisioningOutput();
        self::assertSame(0, $migrate->execute([], $output), implode("\n", $output->errors));

        return $container;
    }

    /** @return array{int, AdministratorProvisioningOutput} */
    private function provision(
        CreateAdministratorCommand $command,
        string $email,
        string $name,
        string $password,
    ): array {
        $passwordFile = tempnam(sys_get_temp_dir(), 'kumwe-administrator-password-');
        if (!is_string($passwordFile)) {
            throw new RuntimeException('The test password file could not be created.');
        }

        try {
            if (file_put_contents($passwordFile, $password) === false || !chmod($passwordFile, 0o600)) {
                throw new RuntimeException('The test password file could not be protected.');
            }
            $output = new AdministratorProvisioningOutput();
            $status = $command->execute([
                '--email=' . $email,
                '--name=' . $name,
                '--password-file=' . $passwordFile,
            ], $output);

            return [$status, $output];
        } finally {
            if (is_file($passwordFile)) {
                unlink($passwordFile);
            }
        }
    }

    private function administratorRoleIdFor(Connection $database, TableNames $tables, string $email): string
    {
        $roleId = $database->fetchOne(sprintf(
            'SELECT r.id FROM %s r INNER JOIN %s ur ON ur.role_id = r.id '
            . 'INNER JOIN %s u ON u.id = ur.user_id WHERE r.code = ? AND u.email_normalized = ?',
            $tables->quoted('roles'),
            $tables->quoted('user_roles'),
            $tables->quoted('users'),
        ), ['administrator', $email]);
        self::assertIsString($roleId);
        self::assertNotSame('', $roleId);

        return $roleId;
    }
}

final class AdministratorProvisioningOutput implements Output
{
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
