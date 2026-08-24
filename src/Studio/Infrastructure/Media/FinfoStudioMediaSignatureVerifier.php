<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Media;

use finfo;
use Kumwe\App\Studio\Application\Media\StudioMediaSignatureVerifier;

/**
 * Fileinfo-backed detector with explicit magic-byte verification for every App-supported media type.
 *
 * @since  2.0.0
 */
final readonly class FinfoStudioMediaSignatureVerifier implements StudioMediaSignatureVerifier
{
    /**
     * Detect the file and independently require its expected leading/container signature.
     *
     * @param   string  $path  Private bounded file.
     *
     * @return  string|null  Verified supported media type or null.
     *
     * @since   2.0.0
     */
    public function verify(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return null;
        }
        try {
            $prefix = fread($handle, 32);
        } finally {
            fclose($handle);
        }
        if (!is_string($prefix)) {
            return null;
        }
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!is_string($detected)) {
            return null;
        }

        return match ($detected) {
            'image/jpeg' => str_starts_with($prefix, "\xFF\xD8\xFF") ? $detected : null,
            'image/png' => str_starts_with($prefix, "\x89PNG\r\n\x1A\n") ? $detected : null,
            'image/gif' => preg_match('/^GIF8[79]a/D', $prefix) === 1 ? $detected : null,
            'image/webp' => substr($prefix, 0, 4) === 'RIFF' && substr($prefix, 8, 4) === 'WEBP'
                ? $detected
                : null,
            'image/avif' => substr($prefix, 4, 4) === 'ftyp'
                && in_array(substr($prefix, 8, 4), ['avif', 'avis'], true) ? $detected : null,
            'application/pdf' => str_starts_with($prefix, '%PDF-') ? $detected : null,
            default => null,
        };
    }
}
