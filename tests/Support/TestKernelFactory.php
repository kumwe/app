<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Support;

use Joomla\DI\Container;
use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\SystemPrincipal;
use Kumwe\CMS\Delivery\Console\Command\CreateAdministratorCommand;
use Kumwe\CMS\Delivery\Console\Command\MigrateCommand;
use Kumwe\CMS\Delivery\Console\Command\QueueWorkCommand;
use Kumwe\CMS\Delivery\Console\Command\ScheduleRunCommand;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Kernel\ContainerFactory;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use RuntimeException;

/** Boots the real kernel and obtains a context only through production authentication. */
final class TestKernelFactory
{
    private const EMAIL = 'integration-administrator@example.test';
    private const PASSWORD = 'integration administrator password';

    public static function create(Environment $environment): Container
    {
        $container = (new ContainerFactory())->create($environment);
        $migrate = $container->get(MigrateCommand::class);
        if (!$migrate instanceof MigrateCommand || $migrate->execute([], self::output()) !== 0) {
            throw new RuntimeException('The integration database could not be migrated.');
        }

        return $container;
    }

    public static function administratorContext(Container $container): ExecutionContext
    {
        $identities = $container->get(AdministratorIdentityGateway::class);
        if (!$identities instanceof AdministratorIdentityGateway) {
            throw new RuntimeException('The administrator identity gateway is unavailable.');
        }
        $principal = $identities->authenticate(self::EMAIL, self::PASSWORD, 'integration-tests');
        if ($principal === null) {
            self::bootstrapAdministrator($container);
            $principal = $identities->authenticate(self::EMAIL, self::PASSWORD, 'integration-tests');
        }
        if ($principal === null) {
            throw new RuntimeException('The integration administrator could not be authenticated.');
        }

        return $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'integration-' . bin2hex(random_bytes(16)),
        );
    }

    public static function workerContext(Container $container): ExecutionContext
    {
        $command = $container->get(QueueWorkCommand::class);
        if (!$command instanceof QueueWorkCommand) {
            throw new RuntimeException('The worker command is unavailable.');
        }
        $property = new \ReflectionProperty($command, 'system');
        $system = $property->getValue($command);
        if (!$system instanceof SystemPrincipal) {
            throw new RuntimeException('The worker system principal is unavailable.');
        }

        return $system->context(
            SiteContext::default(),
            'integration-worker-' . bin2hex(random_bytes(16)),
        );
    }

    public static function schedulerContext(Container $container): ExecutionContext
    {
        $command = $container->get(ScheduleRunCommand::class);
        if (!$command instanceof ScheduleRunCommand) {
            throw new RuntimeException('The scheduler command is unavailable.');
        }
        $property = new \ReflectionProperty($command, 'system');
        $system = $property->getValue($command);
        if (!$system instanceof SystemPrincipal) {
            throw new RuntimeException('The scheduler system principal is unavailable.');
        }

        return $system->context(
            SiteContext::default(),
            'integration-scheduler-' . bin2hex(random_bytes(16)),
        );
    }

    /**
     * Creates a deliberately narrowed principal for integration-only denial tests.
     * Production callers cannot obtain the authority proof reflected here.
     *
     * @param list<array{capability: string, scope_type: string, scope_identifier: ?string}> $grants
     */
    public static function contextFromGrantRows(
        Container $container,
        array $grants,
        string $site = SiteContext::DEFAULT,
    ): ExecutionContext {
        $administrator = self::administratorContext($container);
        $principal = $administrator->principal();
        if ($principal === null) {
            throw new RuntimeException('The integration administrator principal is unavailable.');
        }
        $property = new \ReflectionProperty(AuthenticatedPrincipal::class, 'provenance');
        $provenance = $property->getValue($principal);
        if (!is_object($provenance)) {
            throw new RuntimeException('The integration authority proof is unavailable.');
        }

        return AuthenticatedPrincipal::issueFromGrantRows(
            $provenance,
            $principal->subject(),
            $grants,
            'integration-scoped:' . $principal->subject(),
        )->context(
            SiteContext::fromString($site),
            AuthenticationStrength::BearerToken,
            'integration-scoped-' . bin2hex(random_bytes(16)),
        );
    }

    private static function bootstrapAdministrator(Container $container): void
    {
        $passwordFile = tempnam(sys_get_temp_dir(), 'kumwe-integration-password-');
        if (!is_string($passwordFile)) {
            throw new RuntimeException('The integration password file could not be created.');
        }

        try {
            if (file_put_contents($passwordFile, self::PASSWORD) === false || !chmod($passwordFile, 0o600)) {
                throw new RuntimeException('The integration password file could not be protected.');
            }
            $command = $container->get(CreateAdministratorCommand::class);
            if (
                !$command instanceof CreateAdministratorCommand || $command->execute([
                '--email=' . self::EMAIL,
                '--name=Integration Administrator',
                '--password-file=' . $passwordFile,
                ], self::output()) !== 0
            ) {
                throw new RuntimeException('The integration administrator could not be bootstrapped.');
            }
        } finally {
            if (is_file($passwordFile)) {
                unlink($passwordFile);
            }
        }
    }

    private static function output(): Output
    {
        return new class implements Output {
            public function line(string $message): void
            {
            }

            public function error(string $message): void
            {
            }
        };
    }
}
