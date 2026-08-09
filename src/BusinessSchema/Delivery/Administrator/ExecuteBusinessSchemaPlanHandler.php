<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Delivery\Administrator;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Applies an already-approved schema plan to the physical database.
 *
 * This is where a plan stops being a document and becomes physical change, and it is deliberately
 * thin: everything that makes that safe — the per-definition lock, the fencing journal, and the check
 * that the resulting schema matches the approved blueprint checksum — belongs to
 * `BusinessSchemaService` and the executor beneath it. Keeping execution on its own route, distinct
 * from the approval that authorized it, is what stops a single click from both accepting and applying
 * a change.
 *
 * It is mounted on `POST /administrator/business-schema-plans/{id}/execute` behind the administrator
 * CSRF middleware and the `business.schema.execute` capability.
 *
 * @since  2.0.0
 */
final readonly class ExecuteBusinessSchemaPlanHandler implements RequestHandlerInterface
{
    /**
     * Wire the screen action to the facade that runs the plan under the schema lock.
     *
     * @param  BusinessSchemaService  $schemas  Executes the approved plan and audits the outcome.
     *
     * @since  2.0.0
     */
    public function __construct(private BusinessSchemaService $schemas)
    {
    }

    /**
     * Execute the plan named by the route and send the operator back to it.
     *
     * The execution outcome is discarded here rather than rendered, because the service records it on
     * the plan and the plans screen reads it back from there. A first install whose foreign keys pause
     * on a peer definition is not a failure: the service drives the connected approved plans and then
     * resumes this one, so the redirect still reports completion.
     *
     * @param   ServerRequestInterface  $request  Administrator POST with `{id}` naming the approved plan.
     *
     * @return  ResponseInterface  A 303 redirect to the plans screen with this plan selected and the
     *          `executed` notice.
     *
     * @throws  \InvalidArgumentException  When the route carries no identifier, or was mounted without
     *          administrator authorization.
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When `business.schema.execute` or
     *          `business.schema.destructive` is refused.
     * @throws  \Kumwe\CMS\BusinessSchema\Application\BusinessSchemaNotFound  When no plan matches within
     *          the site.
     * @throws  \Kumwe\CMS\BusinessSchema\Application\BusinessSchemaConflict  When the plan is not approved,
     *          a connected plan is not independently approved, or an operation misses its postcondition.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $planId = BusinessSchemaAdministratorRequest::planId($request);
        $this->schemas->execute(AdministratorRequest::context($request), $planId);

        return BusinessSchemaAdministratorRequest::redirect($planId, 'executed');
    }
}
