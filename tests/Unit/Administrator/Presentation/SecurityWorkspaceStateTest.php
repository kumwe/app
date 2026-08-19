<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Presentation;

use Kumwe\App\Administrator\Presentation\SecurityWorkspaceState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the fail-closed navigation vocabulary for the Phase 3 security workspaces.
 *
 * @since  2.0.0
 */
#[CoversClass(SecurityWorkspaceState::class)]
final class SecurityWorkspaceStateTest extends TestCase
{
    /**
     * Invalid Business Security selectors return to the safe overview without preserving attacker input.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInvalidBusinessSelectorsFallBackToOverview(): void
    {
        $state = SecurityWorkspaceState::business([
            'section' => ['policies'],
            'mode' => 'execute',
            'kind' => 'raw-json',
            'id' => '../foreign',
            'step' => 'submit',
        ]);

        self::assertSame([
            'section' => 'overview',
            'mode' => 'browse',
            'kind' => null,
            'id' => null,
            'step' => 'scope',
        ], $state->toArray());
        self::assertSame('/administrator/business-security?section=overview', $state->url(
            '/administrator/business-security',
        ));
    }

    /**
     * Policy authoring admits only its four named steps and canonical resource kind.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPolicyAuthoringProducesCanonicalDeepLink(): void
    {
        $state = SecurityWorkspaceState::business([
            'section' => 'policies',
            'mode' => 'create',
            'kind' => 'resource',
            'step' => 'disclosure',
        ]);

        self::assertSame('/administrator/business-security?section=policies&mode=create'
            . '&kind=resource&step=disclosure&saved=1', $state->url(
                '/administrator/business-security',
                ['saved' => '1'],
            ));
    }

    /**
     * Every policy stage has one explicit canonical URL and exactly one current marker.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPolicyAuthoringBuildsOrderedCanonicalStepDestinations(): void
    {
        $state = SecurityWorkspaceState::business([
            'section' => 'policies',
            'mode' => 'create',
            'kind' => 'resource',
            'step' => 'predicate',
        ]);

        self::assertSame([
            [
                'id' => 'scope',
                'label' => 'core.administrator.business_security.policy_step_scope',
                'url' => '/administrator/business-security?section=policies&mode=create'
                    . '&kind=resource&step=scope#policy-step-scope',
                'current' => false,
            ],
            [
                'id' => 'predicate',
                'label' => 'core.administrator.business_security.policy_step_predicate',
                'url' => '/administrator/business-security?section=policies&mode=create'
                    . '&kind=resource&step=predicate#policy-step-predicate',
                'current' => true,
            ],
            [
                'id' => 'disclosure',
                'label' => 'core.administrator.business_security.policy_step_disclosure',
                'url' => '/administrator/business-security?section=policies&mode=create'
                    . '&kind=resource&step=disclosure#policy-step-disclosure',
                'current' => false,
            ],
            [
                'id' => 'review',
                'label' => 'core.administrator.business_security.policy_step_review',
                'url' => '/administrator/business-security?section=policies&mode=create'
                    . '&kind=resource&step=review#policy-step-review',
                'current' => false,
            ],
        ], $state->policySteps('/administrator/business-security'));
    }

    /**
     * Policy step navigation is absent from unrelated concerns and authoring kinds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPolicyStepsCannotLeakIntoAnotherWorkspaceState(): void
    {
        $state = SecurityWorkspaceState::business([
            'section' => 'policies',
            'mode' => 'create',
            'kind' => 'separation',
            'step' => 'review',
        ]);

        self::assertSame('scope', $state->step);
        self::assertSame([], $state->policySteps('/administrator/business-security'));
    }

    /**
     * Record selectors survive only in a task mode that can own a selected record.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBrowseModeDropsRecordSelector(): void
    {
        $state = SecurityWorkspaceState::access([
            'section' => 'users',
            'mode' => 'browse',
            'id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb302',
        ]);

        self::assertNull($state->id);
        self::assertSame('/administrator/access?section=users', $state->url('/administrator/access'));
    }

    /**
     * Users and Access refuses Business Security-only kinds and unsupported task modes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAccessVocabularyCannotCrossIntoBusinessConcerns(): void
    {
        $state = SecurityWorkspaceState::access([
            'section' => 'policies',
            'mode' => 'create',
            'kind' => 'resource',
            'id' => 'secret',
        ]);

        self::assertSame([
            'section' => 'users',
            'mode' => 'create',
            'kind' => null,
            'id' => null,
            'step' => 'scope',
        ], $state->toArray());
    }
}
