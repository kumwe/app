<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Identity\Application\Authorization;

use Kumwe\Access\AuthorizationDecision;
use Kumwe\Access\Capability;
use Kumwe\Access\DecisionState;
use Kumwe\Access\GrantScope;
use Kumwe\App\Identity\Application\Authorization\AuthorizationPolicy;
use Kumwe\App\Identity\Application\Authorization\AuthorizationService;
use Kumwe\App\Identity\Domain\EmailAddress;
use Kumwe\App\Identity\Domain\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the deny-by-default combination rule the identity service applies to the four-state decisions.
 *
 * @since  2.0.0
 */
#[CoversClass(AuthorizationService::class)]
final class AuthorizationServiceTest extends TestCase
{
    /**
     * A request no policy speaks for is denied as `policy.no_allowance` under the service's own policy code.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeniesWhenNoPolicyExplicitlyAllows(): void
    {
        $decision = (new AuthorizationService([]))->decide(
            $this->activeUser(),
            Capability::fromString('content.read'),
            GrantScope::global(),
        );

        self::assertFalse($decision->allowed);
        self::assertSame(DecisionState::Deny, $decision->state);
        self::assertSame('core.identity-authorization.v1', $decision->policy);
        self::assertSame('policy.no_allowance', $decision->reason);
    }

    /**
     * A user who cannot authenticate is refused before any policy runs, so a permissive rule cannot rescue it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
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

                return new AuthorizationDecision(DecisionState::Allow, 'test.policy', 'policy.allowed');
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
        self::assertFalse($decision->allowed);
        self::assertSame(DecisionState::Deny, $decision->state);
        self::assertSame('user.inactive', $decision->reason);
    }

    /**
     * A denial from any policy settles the outcome, whatever an earlier policy allowed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExplicitDenialOverridesAnAllowanceRegardlessOfOrder(): void
    {
        $allow = $this->fixedPolicy(new AuthorizationDecision(DecisionState::Allow, 'test.first', 'first.allowed'));
        $deny = $this->fixedPolicy(new AuthorizationDecision(DecisionState::Deny, 'test.second', 'second.denied'));

        $decision = (new AuthorizationService([$allow, $deny]))->decide(
            $this->activeUser(),
            Capability::fromString('content.read'),
            GrantScope::global(),
        );

        self::assertFalse($decision->allowed);
        self::assertSame(DecisionState::Deny, $decision->state);
        self::assertSame('test.second', $decision->policy);
        self::assertSame('second.denied', $decision->reason);
    }

    /**
     * A step-up demand is not permission: it outranks an allowance from another policy and still denies access.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStepUpOutranksAnAllowanceAndConfersNoAuthority(): void
    {
        $allow = $this->fixedPolicy(new AuthorizationDecision(DecisionState::Allow, 'test.first', 'first.allowed'));
        $stepUp = $this->fixedPolicy(new AuthorizationDecision(DecisionState::StepUp, 'test.second', 'second.step-up'));

        $decision = (new AuthorizationService([$allow, $stepUp]))->decide(
            $this->activeUser(),
            Capability::fromString('content.read'),
            GrantScope::global(),
        );

        self::assertFalse($decision->allowed);
        self::assertSame(DecisionState::StepUp, $decision->state);
        self::assertSame('second.step-up', $decision->reason);
    }

    /**
     * A `not_applicable` decision is an abstention: alone it leaves the request denied, and beside an allowance
     * it is ignored.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNotApplicableDecisionsAbstainWithoutGrantingAuthority(): void
    {
        $abstain = $this->fixedPolicy(
            new AuthorizationDecision(DecisionState::NotApplicable, 'test.first', 'first.abstained'),
        );
        $allow = $this->fixedPolicy(new AuthorizationDecision(DecisionState::Allow, 'test.second', 'second.allowed'));
        $service = new AuthorizationService([$abstain, $allow]);
        $capability = Capability::fromString('content.read');

        $alone = (new AuthorizationService([$abstain]))->decide($this->activeUser(), $capability, GrantScope::global());
        $withAllowance = $service->decide($this->activeUser(), $capability, GrantScope::global());

        self::assertFalse($alone->allowed);
        self::assertSame('policy.no_allowance', $alone->reason);
        self::assertTrue($withAllowance->allowed);
        self::assertSame('second.allowed', $withAllowance->reason);
    }

    /**
     * Build an activated user the policies may judge.
     *
     * @return  User  A registered and activated actor.
     *
     * @since   2.0.0
     */
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

    /**
     * Build a policy that answers every request with one fixed decision.
     *
     * @param   AuthorizationDecision  $decision  Verdict the policy returns unconditionally.
     *
     * @return  AuthorizationPolicy  The fixed policy.
     *
     * @since   2.0.0
     */
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
