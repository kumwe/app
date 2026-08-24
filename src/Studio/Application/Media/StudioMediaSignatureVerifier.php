<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Media;

/**
 * Detects a staged media type from bytes and verifies its canonical file signature.
 *
 * @since  2.0.0
 */
interface StudioMediaSignatureVerifier
{
    /**
     * Return a supported lower-case media type only when both detection and magic bytes agree.
     *
     * @param   string  $path  Private bounded file.
     *
     * @return  string|null  Verified media type or null.
     *
     * @since   2.0.0
     */
    public function verify(string $path): ?string;
}
