<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Infrastructure;

use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessReporting\Application\ExportExecutionContextResolver;
use Kumwe\CMS\BusinessReporting\Application\ExportGenerationRejected;
use Kumwe\CMS\BusinessReporting\Domain\ExportArtifact;
use Kumwe\CMS\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\CMS\Portal\Application\PortalPrincipalLoader;

/**
 * Rehydrates current grants, security epoch and membership for the original human export actor.
 *
 * @since  2.0.0
 */
final readonly class LiveExportExecutionContextResolver implements ExportExecutionContextResolver
{
    /**
     * Wire live identity and membership stores.
     *
     * @param  PortalPrincipalLoader  $principals   Active-user and current-grant loader.
     * @param  MembershipDirectory    $memberships  Live organization/workspace resolver.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PortalPrincipalLoader $principals,
        private MembershipDirectory $memberships,
    ) {
    }

    /**
     * Rebuild a credential-independent context and prove its authority digest matches the request.
     *
     * @param   ExportArtifact    $artifact       Stored original actor and scope.
     * @param   ExecutionContext  $workerContext  Queue worker trace context.
     *
     * @return  ExecutionContext  Fresh original-human context at the original surface.
     *
     * @throws  ExportGenerationRejected  When actor, membership, grants or epoch are no longer current.
     *
     * @since   2.0.0
     */
    public function resolve(ExportArtifact $artifact, ExecutionContext $workerContext): ExecutionContext
    {
        $site = SiteContext::fromString($artifact->siteIdentifier);
        if ($workerContext->site()->identifier() !== $site->identifier()) {
            throw new ExportGenerationRejected('The export worker site does not match the artifact.');
        }
        $membership = null;
        if ($artifact->organizationIdentifier !== null) {
            $membership = $this->memberships->resolve(
                $artifact->actorId,
                $site,
                $artifact->organizationIdentifier,
                $artifact->workspaceIdentifier,
            );
            if ($membership === null) {
                throw new ExportGenerationRejected('The export membership is no longer active.');
            }
        } elseif ($artifact->workspaceIdentifier !== null) {
            throw new ExportGenerationRejected('An export workspace cannot exist without an organization.');
        }
        $identity = $this->principals->load($artifact->actorId, 'report-export-rehydrated', $membership);
        if ($identity === null) {
            throw new ExportGenerationRejected('The export actor is no longer active.');
        }
        $context = $identity->principal->context(
            $site,
            AuthenticationStrength::BearerToken,
            'report-export-' . $artifact->id,
            $workerContext->correlationId(),
            $artifact->surface,
            $membership,
        );
        if (!hash_equals($artifact->authorityFingerprint, $context->approvalFingerprint())) {
            throw new ExportGenerationRejected('The export actor authority changed.');
        }

        return $context;
    }
}
