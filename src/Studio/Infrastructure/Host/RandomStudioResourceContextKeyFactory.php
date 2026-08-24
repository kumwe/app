<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Host;

use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;

/**
 * Production Studio context-key allocator backed by the operating-system CSPRNG.
 *
 * @since  2.0.0
 */
final readonly class RandomStudioResourceContextKeyFactory implements StudioResourceContextKeyFactory
{
    /**
     * Allocate 256 bits of entropy and expose them only as canonical lowercase hexadecimal.
     *
     * @return  string  Opaque canonical stable identifier under the `contexts/` namespace.
     *
     * @throws  \Random\RandomException  When the operating system cannot supply secure entropy.
     *
     * @since   2.0.0
     */
    public function create(): string
    {
        return 'contexts/' . bin2hex(random_bytes(32));
    }
}
