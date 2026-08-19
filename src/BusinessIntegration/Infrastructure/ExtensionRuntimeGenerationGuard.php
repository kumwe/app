<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Infrastructure;

use Kumwe\App\BusinessIntegration\Application\TrustedRuntimeGenerationGuard;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use RuntimeException;

/**
 * Adapts the signed extension runtime authority to integration dispatch generation checks.
 *
 * @since  2.0.0
 */
final readonly class ExtensionRuntimeGenerationGuard implements TrustedRuntimeGenerationGuard
{
    /**
     * Capture the exact materialization loaded at process boot.
     *
     * @param  ExtensionRuntimeMapCompiler  $compiler  Live runtime authority.
     * @param  RuntimeMaterializationState  $loaded    Immutable boot-time materialization.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExtensionRuntimeMapCompiler $compiler,
        private RuntimeMaterializationState $loaded,
    ) {
    }

    /**
     * Require the supplied execution context to remain current and authorized.
     *
     * @param   string  $generation  Trusted runtime generation that owns the lease.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assertCurrent(string $generation): void
    {
        if (!$this->loaded->trusted || $generation !== (string) $this->loaded->generation) {
            throw new RuntimeException(
                'The claimed integration work does not belong to the loaded runtime generation.',
            );
        }
        $this->compiler->assertLoadedGenerationCurrent($this->loaded);
    }
}
