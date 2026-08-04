<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Identity\Application\Authorization;

use Kumwe\CMS\Identity\Application\Authorization\AuthorizationPolicy;
use Kumwe\CMS\Identity\Application\Authorization\AuthorizationService;
use Kumwe\CMS\Identity\Domain\AuthorizationDecision;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\EmailAddress;
use Kumwe\CMS\Identity\Domain\GrantScope;
use Kumwe\CMS\Identity\Domain\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthorizationService::class)]
final class AuthorizationServiceTest extends TestCase
{
    public function testDeniesWhenNoPolicyExplicitlyAllows(): void
    {
        $decision = (new AuthorizationService([]))->decide(
            $this->activeUser(),
            Capability::fromString('content.read'),
            GrantScope::global(),
        );

        self::assertTrue($decision->isDenied());
        self::assertSame('policy.no_allowance', $decision->reason());
    }

    public function testInactiveUserIsDeniedBeforePoliciesRun(): void
    {
        $tracker = new \stdClass();
        $tracker->called = false;
        $policy = new class ($tracker) implements AuthorizationPolicy {
            public function __construct(private readonly \stdClass $tracker)
            {
            }

            public function decide(
                User $user,
                Capability $capability,
                GrantScope $scope,
                array $grants,
            ): ?AuthorizationDecision {
                $this->tracker->called = true;

                return AuthorizationDecision::allow();
            }
        };

        $decision = (new AuthorizationService([$policy]))->decide(
            User::register(
                'e3df7938-d6a8-4c01-9baa-5d1a9b2c5b67',
                EmailAddress::fromString('pending@example.com'),
                'Pending',
            ),
            Capability::fromString('content.read'),
            GrantScope::global(),
        );

        self::assertFalse($tracker->called);
        self::assertSame('user.inactive', $decision->reason());
    }

    public function testExplicitDenialOverridesAnAllowanceRegardlessOfOrder(): void
    {
        $allow = $this->fixedPolicy(AuthorizationDecision::allow('first.allowed'));
        $deny = $this->fixedPolicy(AuthorizationDecision::deny('second.denied'));

        $decision = (new AuthorizationService([$allow, $deny]))->decide(
            $this->activeUser(),
            Capability::fromString('content.read'),
            GrantScope::global(),
        );

        self::assertTrue($decision->isDenied());
        self::assertSame('second.denied', $decision->reason());
    }

    private function activeUser(): User
    {
        $user = User::register(
            '4f52fd0a-7296-4c4a-8e5d-85bc600f9718',
            EmailAddress::fromString('active@example.com'),
            'Active',
        );
        $user->activate();

        return $user;
    }

    private function fixedPolicy(AuthorizationDecision $decision): AuthorizationPolicy
    {
        return new class ($decision) implements AuthorizationPolicy {
            public function __construct(private readonly AuthorizationDecision $decision)
            {
            }

            public function decide(
                User $user,
                Capability $capability,
                GrantScope $scope,
                array $grants,
            ): ?AuthorizationDecision {
                return $this->decision;
            }
        };
    }
}
