<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\StepUp;

use Kumwe\CMS\Application\Authorization\OrganizationContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\StepUpProof;
use Kumwe\CMS\Application\Authorization\WorkspaceContext;
use Kumwe\CMS\Identity\Domain\StepUp\StepUpVerification;

/**
 * Converts provider output into the authorization layer's fresh proof value.
 *
 * @since  2.0.0
 */
final readonly class AuthorizationStepUpProofAdapter
{
    /**
     * Preserve actor, rotated session, site, organization, method, freshness, and nonce bindings.
     *
     * @param   StepUpVerification  $verification  Successful provider result.
     *
     * @return  StepUpProof  Proof suitable for a multi-factor execution context.
     *
     * @since   2.0.0
     */
    public function adapt(StepUpVerification $verification): StepUpProof
    {
        return new StepUpProof(
            $verification->intent->subjectId,
            $verification->rotatedSession->sessionId,
            SiteContext::fromString($verification->intent->siteIdentifier),
            $verification->intent->organizationIdentifier === null
                ? null
                : OrganizationContext::fromString($verification->intent->organizationIdentifier),
            $verification->method->value,
            $verification->issuedAt,
            $verification->expiresAt,
            $verification->nonce,
            $verification->intent->workspaceIdentifier === null
                ? null
                : WorkspaceContext::fromString($verification->intent->workspaceIdentifier),
            $verification->intent->purpose,
            $verification->intent->securityEpoch,
        );
    }
}
