<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessSecurity\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Kumwe\CMS\Application\Authorization\AuthenticatedSurface;
use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\MembershipContext;
use Kumwe\CMS\Application\Authorization\OrganizationContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessDefinition\Domain\ScopeMode;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\CMS\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\CMS\BusinessRecord\Domain\RecordScope;
use Kumwe\CMS\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\CMS\BusinessSecurity\Infrastructure\Persistence\DoctrineBusinessRecordAccessController;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use RuntimeException;
use stdClass;

#[CoversClass(DoctrineBusinessRecordAccessController::class)]
final class DoctrineBusinessRecordAccessControllerTest extends TestCase
{
    public function testPolicySnapshotUsesSiteGenerationSharedLockInsideActiveTransaction(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('isTransactionActive')->willReturn(true);
        $database->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $database->expects(self::once())->method('fetchOne')->with(
            self::callback(static fn (string $sql): bool => str_contains($sql, 'SELECT policy_generation')
                && str_contains($sql, 'WHERE identifier = ?')
                && str_ends_with($sql, ' LOCK IN SHARE MODE')),
            [SiteContext::DEFAULT],
        )->willReturn('4');
        $provenance = new stdClass();
        $principal = AuthenticatedPrincipal::issueFromStrings(
            $provenance,
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e10',
            ['business.record.read'],
        );
        $context = ExecutionContext::issueHuman(
            $provenance,
            $principal,
            SiteContext::default(),
            AuthenticationStrength::Password,
            'policy-lock-test',
            surface: AuthenticatedSurface::Administrator,
        );
        $controller = new DoctrineBusinessRecordAccessController(
            $database,
            new TableNames($database, 'kumwe_'),
            $this->createStub(BusinessRecordDefinitionResolver::class),
            $this->createStub(MembershipDirectory::class),
            $this->createStub(ClockInterface::class),
        );
        $method = (new ReflectionClass($controller))->getMethod('lockPolicySnapshot');

        $method->invoke($controller, $context);
    }

    public function testMutationPlanFailsClosedBeforePolicyLookupWhenMembershipIsStale(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('fetchAllAssociative');
        $resolver = $this->createStub(BusinessRecordDefinitionResolver::class);
        $memberships = $this->createMock(MembershipDirectory::class);
        $clock = $this->createStub(ClockInterface::class);
        $membership = new MembershipContext(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e12',
            OrganizationContext::fromString('organization.one'),
            null,
            3,
            7,
        );
        $provenance = new stdClass();
        $principal = AuthenticatedPrincipal::issueFromStrings(
            $provenance,
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e10',
            ['business.record.update'],
        );
        $context = ExecutionContext::issueHuman(
            $provenance,
            $principal,
            SiteContext::default(),
            AuthenticationStrength::Password,
            'stale-membership-test',
            surface: AuthenticatedSurface::Administrator,
            membership: $membership,
        );
        $memberships->expects(self::once())->method('current')->with(
            $context->actorId(),
            $context->site(),
            $membership,
            true,
        )->willReturn(false);
        $controller = new DoctrineBusinessRecordAccessController(
            $database,
            new TableNames($database, 'kumwe_'),
            $resolver,
            $memberships,
            $clock,
        );
        $resolved = (new ReflectionClass(ResolvedBusinessDefinition::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(ResolvedBusinessDefinition::class, $resolved);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The business-record authorization context is stale.');
        $controller->plan(
            $context,
            'business.record.update',
            $resolved,
            RecordScope::reconstitute(ScopeMode::Site, SiteContext::DEFAULT, null),
        );
    }
}
