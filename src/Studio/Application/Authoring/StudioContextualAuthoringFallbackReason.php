<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

/**
 * Stable reason contextual Studio remains behind the structured Content editor fallback.
 *
 * These are App readiness facts, not Studio host failures. They are intentionally coarse so a
 * template or diagnostic can explain a safe fallback without disclosing deployment paths or parsing
 * exception messages.
 *
 * @since  2.0.0
 */
enum StudioContextualAuthoringFallbackReason: string
{
    /**
     * The pinned coordinated protocol does not contain the complete contextual authoring vocabulary.
     *
     * @since  2.0.0
     */
    case ProtocolUnavailable = 'protocol-unavailable';

    /**
     * The compiled contextual browser entry is absent or does not resolve to a packaged asset.
     *
     * @since  2.0.0
     */
    case BrowserRuntimeUnavailable = 'browser-runtime-unavailable';

    /**
     * App's PHP authoring port is not yet qualified for the pinned contextual contract.
     *
     * @since  2.0.0
     */
    case HostAdapterUnavailable = 'host-adapter-unavailable';
}
