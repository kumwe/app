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

    /**
     * Replace the stored target of one existing binding with its accepted successor.
     *
     * The key, scope, session and authority digests and the expiry are immutable; only the exact
     * Content target advances, inside the same transaction that committed the durable effect that
     * produced the successor coordinates.
     *
     * @param   ContentStudioAuthoringContextBinding  $binding  Binding carrying the successor target.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function advance(ContentStudioAuthoringContextBinding $binding): void;

    /**
     * Record the start source a session chose, once; a later different choice is not recorded.
     *
     * @param   string  $contextKey    Opaque key of an existing binding.
     * @param   string  $startSource   Canonical JSON of the Studio `startSource` the session opened with.
     * @param   string  $presentation  Initial Studio presentation, recorded atomically with the source.
     *
     * @return  array{source: string, presentation: string}|null  Recorded start, or null.
     *
     * @since   2.0.0
     */
    public function recordStart(string $contextKey, string $startSource, string $presentation): ?array;

    /**
     * Read the start source a session recorded.
     *
     * @param   string  $contextKey  Opaque key of an existing binding.
     *
     * @return  array{source: string, presentation: string}|null  Recorded start, or null.
     *
     * @since   2.0.0
     */
    public function start(string $contextKey): ?array;
}
