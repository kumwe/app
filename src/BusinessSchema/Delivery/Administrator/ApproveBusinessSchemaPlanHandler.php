<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Delivery\Administrator;

use InvalidArgumentException;
use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Application\Security\HighImpactCredentialGuard;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Turns the administrator screen's approve button into an authorized approval of one exact schema plan.
 *
 * Approval is the gate between a persisted comparison and anything physical happening, so this handler
 * does the delivery-side half of binding the operator to what they were shown: the posted
 * `expected_checksum` pins the plan revision they inspected, and for any plan above the online-safe
 * risk band a `confirmation` field must repeat the plan's current checksum and the operator must
 * re-enter their password. Low-risk approvals have their confirmation dropped rather than forwarded,
 * because `BusinessSchemaService` refuses an approval that carries high-impact state it did not ask
 * for. Nothing is executed here; the plan merely becomes executable.
 *
 * It is mounted on `POST /administrator/business-schema-plans/{id}/approve` behind the administrator
 * CSRF middleware and the `business.schema.approve` capability.
 *
 * @since  2.0.0
 */
final readonly class ApproveBusinessSchemaPlanHandler implements RequestHandlerInterface
{
    /**
     * Wire the screen action to the approval facade and the re-authentication guard.
     *
     * @param  BusinessSchemaService      $schemas      Loads the plan and records the audited approval.
     * @param  HighImpactCredentialGuard  $credentials  Re-checks the operator's current password for
     *         plans above the online-safe risk band.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessSchemaService $schemas,
        private HighImpactCredentialGuard $credentials,
    ) {
    }

    /**
     * Approve the plan named by the route and send the operator back to it with a notice.
     *
     * The plan is re-read before the risk band is consulted, so the confirmation and password demands
     * follow the plan as stored now rather than as the form was rendered. The confirmation is compared
     * with `hash_equals` and is deliberately checked here as well as in the service, so a mistyped
     * checksum is rejected before a password attempt is spent against the rate limiter.
     *
     * @param   ServerRequestInterface  $request  Administrator POST carrying `expected_checksum`, and for
     *          high-impact plans `confirmation` and `current_password`,
     *          with `{id}` naming the plan.
     *
     * @return  ResponseInterface  A 303 redirect to the plans screen with this plan selected and the
     *          `approved` notice.
     *
     * @throws  InvalidArgumentException  When a required field is missing or the confirmation does not
     *          repeat the plan's current checksum.
     * @throws  \Kumwe\CMS\Application\Security\HighImpactAuthenticationRequired  When the re-entered
     *          password is absent, wrong, or the context has no human principal behind it.
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When `business.schema.read`,
     *          `business.schema.approve`, or `business.schema.destructive` is refused.
     * @throws  \Kumwe\CMS\BusinessSchema\Application\BusinessSchemaNotFound  When no plan or referenced
     *          recovery evidence matches within the site.
     * @throws  \Kumwe\CMS\BusinessSchema\Application\BusinessSchemaConflict  When the plan changed after
     *          it was inspected, or its recovery-evidence requirement is unmet.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $form = AdministratorRequest::form($request);
        $context = AdministratorRequest::context($request);
        $planId = BusinessSchemaAdministratorRequest::planId($request);
        $plan = $this->schemas->plan($context, $planId);
        $expectedChecksum = AdministratorRequest::required($form, 'expected_checksum');
        $confirmation = BusinessSchemaAdministratorRequest::optional($form, 'confirmation');

        if ($plan->risk->requiresHighImpactAuthorization()) {
            if ($confirmation === null || !hash_equals($plan->checksum(), $confirmation)) {
                throw new InvalidArgumentException(
                    'High-impact approval requires the exact current 64-character plan checksum.',
                );
            }
            $this->credentials->assertCurrentPassword(
                $context,
                'business.schema.approve',
                BusinessSchemaAdministratorRequest::optional($form, 'current_password'),
            );
        } else {
            $confirmation = null;
        }

        $this->schemas->approve(
            $context,
            $planId,
            $expectedChecksum,
            $confirmation,
            BusinessSchemaAdministratorRequest::optional($form, 'recovery_evidence_id'),
        );

        return BusinessSchemaAdministratorRequest::redirect(
            $planId,
            'approved',
            null,
            BusinessSchemaAdministratorRequest::activeTab($form['return_tab'] ?? null, 'approval'),
        );
    }
}
