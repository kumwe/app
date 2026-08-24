<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

/**
 * Entropy boundary for opaque, non-bearer Studio resource-context identifiers.
 *
 * @since  2.0.0
 */
interface StudioResourceContextKeyFactory
{
    /**
     * Mint an identifier carrying no actor, scope or resource information.
     *
     * @return  string  Canonical stable identifier with cryptographic entropy.
     *
     * @since   2.0.0
     */
    public function create(): string;
}
