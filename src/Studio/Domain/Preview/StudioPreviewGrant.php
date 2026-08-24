<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Preview;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable complete claimable preview grant bound to trusted host-session coordinates.
 *
 * @since  2.0.0
 */
final readonly class StudioPreviewGrant
{
    /**
     * Capture one completed grant and every coordinate required to claim it safely.
     *
     * @param   string                         $resourceContextKey  Opaque host context.
     * @param   string                         $actorId             Trusted actor.
     * @param   string                         $siteIdentifier      Trusted site.
     * @param   string|null                    $organizationId      Trusted organization.
     * @param   string|null                    $workspaceId         Trusted workspace.
     * @param   string                         $sessionBinding      Authenticated browser-session digest.
     * @param   string                         $sessionGeneration   Live authority generation.
     * @param   string                         $origin              Exact same origin.
     * @param   string                         $channelId           Preview channel identity.
     * @param   string                         $sourceId            Expected source identity.
     * @param   StudioPreviewRenderRequest     $request             Exact render attempt.
     * @param   StudioPreviewRenderedDocument  $document            Rendered document and markers.
     * @param   DateTimeImmutable              $expiresAt           Absolute short-lived expiry.
     *
     * @throws  InvalidArgumentException  When a trusted coordinate is incomplete.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $resourceContextKey,
        public string $actorId,
        public string $siteIdentifier,
        public ?string $organizationId,
        public ?string $workspaceId,
        public string $sessionBinding,
        public string $sessionGeneration,
        public string $origin,
        public string $channelId,
        public string $sourceId,
        public StudioPreviewRenderRequest $request,
        public StudioPreviewRenderedDocument $document,
        public DateTimeImmutable $expiresAt,
    ) {
        if (
            $resourceContextKey === ''
            || $actorId === ''
            || $siteIdentifier === ''
            || preg_match('/^[a-f0-9]{64}$/D', $sessionBinding) !== 1
            || $sessionGeneration === ''
            || ($workspaceId !== null && $organizationId === null)
        ) {
            throw new InvalidArgumentException('The Studio preview grant binding is invalid.');
        }
    }
}
