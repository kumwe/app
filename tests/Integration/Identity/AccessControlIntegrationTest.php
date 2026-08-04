<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Identity;

use Kumwe\CMS\Identity\Application\Administration\AccessControlService;
use Kumwe\CMS\Identity\Infrastructure\Administration\DoctrineAccessControlRepository;
use Kumwe\CMS\Kernel\ContainerFactory;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(AccessControlService::class)]
#[CoversClass(DoctrineAccessControlRepository::class)]
final class AccessControlIntegrationTest extends TestCase
{
    public function testCreatesIdentityWithPortableDoctrineDateTimeBindings(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $access = $container->get(AccessControlService::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        $marker = Uuid::uuid7()->toString();

        $id = $access->createUser(
            'system:test',
            sprintf('integration-%s@example.test', $marker),
            'Database Matrix Editor',
            'correct horse battery staple',
        );

        $created = array_values(array_filter(
            $access->users(),
            static fn (array $user): bool => ($user['id'] ?? null) === $id,
        ));
        self::assertCount(1, $created);
        self::assertSame('Database Matrix Editor', $created[0]['display_name']);
        self::assertSame('active', $created[0]['status']);
    }
}
