<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Business;

use Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationService;
use Kumwe\App\Delivery\Http\Api\ApiExecutionContext;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the Business Security read model the administrator renders but no machine contract published.
 *
 * `GET /api/v1/business-security` answers `BusinessSecurityAdministrationService::overview()` — the
 * organizations, workspaces, memberships with their explained effective access, resource policies,
 * separation-of-duty rules and approvals the Business Security screen shows. The service authorizes
 * `business.security.manage` again and scopes every row to the credential's site and membership; this
 * adapter adds nothing but JSON. The screen's writes stay browser-only because the service consumes a fresh
 * human step-up proof for each of them, which a bearer caller cannot hold.
 *
 * @since  2.0.0
 */
final readonly class BusinessSecurityApiHandler implements RequestHandlerInterface
{
    /**
     * Bind the route to the business security read model.
     *
     * @param  BusinessSecurityAdministrationService  $security  Business security administration service.
     *
     * @since  2.0.0
     */
    public function __construct(private BusinessSecurityAdministrationService $security)
    {
    }

    /**
     * Answer the business security overview for the credential's site and scope.
     *
     * @param   ServerRequestInterface  $request  Authenticated API request past the capability pre-flight.
     *
     * @return  ResponseInterface  No-store JSON document.
     *
     * @throws  \Kumwe\Access\AuthorizationDenied  When the service refuses `business.security.manage`.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse(
            $this->security->overview(ApiExecutionContext::fromRequest($request)),
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
