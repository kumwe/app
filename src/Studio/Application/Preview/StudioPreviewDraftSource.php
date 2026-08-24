<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewDraft;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderRequest;

/**
 * Narrow read port between AP-6 rendering and AP-4 immutable artifact persistence.
 *
 * @since  2.0.0
 */
interface StudioPreviewDraftSource
{
    /**
     * Resolve one exact unpublished Blueprint revision inside the trusted host context.
     *
     * @param   StudioHostSessionSnapshot   $snapshot  Live trusted Studio authority.
     * @param   StudioPreviewRenderRequest  $request   Exact render identity.
     *
     * @return  StudioPreviewDraft|null  Canonical Blueprint or null without disclosing why it is unavailable.
     *
     * @since   2.0.0
     */
    public function find(
        StudioHostSessionSnapshot $snapshot,
        StudioPreviewRenderRequest $request,
    ): ?StudioPreviewDraft;
}
