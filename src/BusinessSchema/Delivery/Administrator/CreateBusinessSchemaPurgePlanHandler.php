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
 * Persists the destructive plan that would drop a definition's installed tables.
 *
 * Ordinary planning only ever compiles the difference between what is installed and what a published
 * definition needs, so taking a definition's storage away entirely has to be asked for on its own
 * route, behind two barriers before anything is even written down: the operator retypes the definition
 * identifier as a confirmation, and re-enters their current password. Even then this only persists a
 * plan — the drops still need an independent approval, which for a destructive plan additionally
 * demands tested clean-target recovery evidence, and then a separate execution.
 *
 * It is mounted on `POST /administrator/business-schema-plans/purge` behind the administrator CSRF
 * middleware and the `business.schema.destructive` capability.
 *
 * @since  2.0.0
 */
final readonly class CreateBusinessSchemaPurgePlanHandler implements RequestHandlerInterface
{
    /**
     * Wire the screen action to the planning facade and the re-authentication guard.
     *
     * @param  BusinessSchemaService      $schemas      Compiles and persists the drop-table plan.
     * @param  HighImpactCredentialGuard  $credentials  Re-checks the operator's current password before
     *         a destructive plan is written.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessSchemaService $schemas,
        private HighImpactCredentialGuard $credentials,
    ) {
    }

    /**
     * Plan the purge of the named definition and send the operator to the plan for review.
     *
     * The typed confirmation is compared before the password is asked for, so a wrong definition costs
     * nothing against the authentication rate limiter. Ordering matters the other way too: the password
     * is demanded before the plan is compiled, so an unauthenticated attempt leaves no persisted trace.
     *
     * @param   ServerRequestInterface  $request  Administrator POST carrying `definition_id`, a
     *          `confirmation` repeating it, and `current_password`.
     *
     * @return  ResponseInterface  A 303 redirect to the plans screen with the purge plan selected and the
     *          `purge-planned` notice.
     *
     * @throws  InvalidArgumentException  When a required field is missing or the confirmation does not
     *          repeat the definition identifier exactly.
     * @throws  \Kumwe\CMS\Application\Security\HighImpactAuthenticationRequired  When the re-entered
     *          password is absent, wrong, or the context has no human principal behind it.
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When `business.schema.destructive`
     *          is refused.
     * @throws  \Kumwe\CMS\BusinessSchema\Application\BusinessSchemaNotFound  When the definition is not
     *          published, or has no installed schema in this site.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $form = AdministratorRequest::form($request);
        $context = AdministratorRequest::context($request);
        $definitionId = AdministratorRequest::required($form, 'definition_id');
        if (!hash_equals($definitionId, AdministratorRequest::required($form, 'confirmation'))) {
            throw new InvalidArgumentException('Purge planning requires the exact installed definition ID.');
        }
        $this->credentials->assertCurrentPassword(
            $context,
            'business.schema.purge-plan',
            BusinessSchemaAdministratorRequest::optional($form, 'current_password'),
        );
        $plan = $this->schemas->createPurgePlan($context, $definitionId);

        return BusinessSchemaAdministratorRequest::redirect($plan->id, 'purge-planned');
    }
}
