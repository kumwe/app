<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

/**
 * Compares one loaded runtime publication with live signed generation authority.
 *
 * `ExtensionRuntimeMapCompiler` supplies this narrow facet in production. Keeping the execution gate
 * coupled to the facet rather than the complete compiler makes the fail-closed policy independently
 * testable without weakening the compiler's final composition boundary.
 *
 * @since  2.0.0
 */
interface ExtensionRuntimeGenerationAuthority
{
    /**
     * Report whether one exact loaded publication remains authoritative and locally intact.
     *
     * @param   RuntimeMaterializationState  $loaded  Immutable boot-time publication to compare.
     *
     * @return  bool  True only while authority and verified local bytes still match.
     *
     * @since   2.0.0
     */
    public function isCurrent(RuntimeMaterializationState $loaded): bool;
}
