<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Delivery\Administrator;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Restarts an interrupted schema execution from the recovery control of the plans screen.
 *
 * A schema execution that stops part-way leaves the plan and its step journal describing exactly how far it
 * got, and recovery is the only way forward: the operator does not re-approve or re-plan, they ask the same
 * approved plan to resume from the journal under a fresh fence. The route is mounted on
 * `POST /administrator/business-schema-plans/{id}/recover` behind the CSRF middleware and demands
 * `business.schema.recover`, a grant held separately from the one that starts an execution. This handler
 * carries no policy of its own: whether the plan is interrupted at all, and whether it is still safe to
 * resume, is re-decided inside the service once it holds the schema lock.
 *
 * @since  2.0.0
 */
final readonly class RecoverBusinessSchemaPlanHandler implements RequestHandlerInterface
{
    /**
     * Wire the recovery control to the service that owns the execution rules.
     *
     * @param  BusinessSchemaService  $schemas  Authorizes the recovery and drives the executor.
     *
     * @since  2.0.0
     */
    public function __construct(private BusinessSchemaService $schemas)
    {
    }

    /**
     * Resume the named plan's execution and return the operator to the plans screen.
     *
     * The redirect reports only that recovery was accepted; what it actually did — how many steps it
     * skipped as already applied and how many it ran — is read back from the plan and its steps on the
     * screen that loads next.
     *
     * @param   ServerRequestInterface  $request  Administrator POST carrying the plan in its `{id}` segment.
     *
     * @return  ResponseInterface  A 303 redirect to the plans screen with a `recovered` notice.
     *
     * @throws  \InvalidArgumentException  When the route carries no plan identifier, or the route was mounted
     *          without the authorization middleware that attaches the execution context.
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not recover schemas,
     *          or may not run a plan that destroys data.
     * @throws  \Kumwe\CMS\BusinessSchema\Application\BusinessSchemaNotFound  When no plan with that identifier
     *          belongs to this site.
     * @throws  \Kumwe\CMS\BusinessSchema\Application\BusinessSchemaConflict  When the plan was never interrupted,
     *          is no longer recoverable once the lock is held, or its recovery evidence has gone stale.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $planId = BusinessSchemaAdministratorRequest::planId($request);
        $this->schemas->recover(AdministratorRequest::context($request), $planId);

        return BusinessSchemaAdministratorRequest::redirect($planId, 'recovered');
    }
}
