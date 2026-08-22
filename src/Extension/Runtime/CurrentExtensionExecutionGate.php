<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use RuntimeException;
use Throwable;

/**
 * Holds the extension generation loaded at boot against live signed runtime authority.
 *
 * A lifecycle mutation publishes a new generation in the same transaction as its registry change.
 * The comparison therefore turns false immediately after disable, uninstall, replacement or trust
 * withdrawal, before a watcher has materialized the new publication and restarted the process.
 *
 * @since  2.0.0
 */
final readonly class CurrentExtensionExecutionGate implements ExtensionExecutionGate
{
    /**
     * Bind the gate to live authority and the immutable publication this process loaded.
     *
     * @param  ExtensionRuntimeGenerationAuthority  $runtime  Live signed generation inspector.
     * @param  RuntimeMaterializationState          $loaded   Exact boot-time publication whose code is resident.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExtensionRuntimeGenerationAuthority $runtime,
        private RuntimeMaterializationState $loaded,
    ) {
    }

    /**
     * Report whether the resident extension graph remains trusted and authoritative.
     *
     * @return  bool  True only for the exact current trusted generation.
     *
     * @since   2.0.0
     */
    public function isCurrent(): bool
    {
        if (!$this->loaded->trusted) {
            return false;
        }
        try {
            return $this->runtime->isCurrent($this->loaded);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Stop an execution boundary from entering resident extension code after generation drift.
     *
     * @return  void
     *
     * @throws  RuntimeException  When no trusted generation was loaded or authority has superseded it.
     *
     * @since   2.0.0
     */
    public function assertCurrent(): void
    {
        if (!$this->isCurrent()) {
            throw new RuntimeException('This process cannot execute a stale or untrusted extension generation.');
        }
    }
}
