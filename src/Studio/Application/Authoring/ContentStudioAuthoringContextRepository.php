<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

/**
 * Persistence boundary for opaque contextual Content authoring bindings.
 *
 * @since  2.0.0
 */
interface ContentStudioAuthoringContextRepository
{
    /**
     * Persist a verified immutable binding before its opaque key is disclosed.
     *
     * @param   ContentStudioAuthoringContextBinding  $binding  Exact server-owned target and trusted scope.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function add(ContentStudioAuthoringContextBinding $binding): void;

    /**
     * Resolve an opaque key without accepting target or scope coordinates from the caller.
     *
     * @param   string  $contextKey  Opaque key supplied by a future canonical host configuration.
     *
     * @return  ContentStudioAuthoringContextBinding|null  Stored binding, or null without disclosing why.
     *
     * @since   2.0.0
     */
    public function find(string $contextKey): ?ContentStudioAuthoringContextBinding;
}
