<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Delivery\Administrator;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Persists a checksummed comparison between a published definition and the storage it would need.
 *
 * This is the entry point of the schema lifecycle, and the cheapest step in it: planning changes
 * nothing physical. The planner reads immutable published metadata and the schema it observes in the
 * database, and stores the difference as an immutable plan for someone to inspect, approve, and only
 * then execute. Keeping it apart from approval is what lets the person who proposes a change be
 * someone other than the person who accepts it.
 *
 * It is mounted on `POST /administrator/business-schema-plans/plan` behind the administrator CSRF
 * middleware and the `business.schema.plan` capability.
 *
 * @since  2.0.0
 */
final readonly class CreateBusinessSchemaPlanHandler implements RequestHandlerInterface
{
    /**
     * Wire the screen action to the facade that compiles and persists the plan.
     *
     * @param  BusinessSchemaService  $schemas  Compiles the plan inside one transaction and audits it.
     *
     * @since  2.0.0
     */
    public function __construct(private BusinessSchemaService $schemas)
    {
    }

    /**
     * Plan the selected definition and send the operator to the plan that was just persisted.
     *
     * Only a published definition can be planned, which is the same filter the screen applies when it
     * builds the select, so a definition still in draft cannot be planned by posting its identifier
     * directly.
     *
     * @param   ServerRequestInterface  $request  Administrator POST carrying `definition_id`.
     *
     * @return  ResponseInterface  A 303 redirect to the plans screen with the new plan selected and the
     *          `planned` notice.
     *
     * @throws  \InvalidArgumentException  When `definition_id` is absent or blank, or the route was
     *          mounted without administrator authorization.
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When `business.schema.plan` is
     *          refused.
     * @throws  \Kumwe\CMS\BusinessSchema\Application\BusinessSchemaNotFound  When no published definition
     *          matches within the site.
     * @throws  \Kumwe\CMS\BusinessSchema\Application\BusinessSchemaConflict  When the installed schema
     *          contradicts its recorded metadata or is not older than the published definition.
     * @throws  \Kumwe\CMS\BusinessSchema\Domain\InvalidBusinessSchema  When the published definition graph
     *          cannot be compiled into a valid physical blueprint.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $form = AdministratorRequest::form($request);
        $plan = $this->schemas->createPlan(
            AdministratorRequest::context($request),
            AdministratorRequest::required($form, 'definition_id'),
        );

        return BusinessSchemaAdministratorRequest::redirect(
            $plan->id,
            'planned',
            null,
            BusinessSchemaAdministratorRequest::activeTab($form['return_tab'] ?? null),
        );
    }
}
