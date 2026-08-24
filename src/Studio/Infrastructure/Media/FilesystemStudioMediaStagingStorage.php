<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Media;

use Kumwe\App\Studio\Application\Media\StudioMediaPortRejected;
use Kumwe\App\Studio\Application\Media\StudioMediaStagingStorage;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Private filesystem staging adapter with atomic bounded replacement and opaque filenames.
 *
 * @since  2.0.0
 */
final readonly class FilesystemStudioMediaStagingStorage implements StudioMediaStagingStorage
{
    /**
     * Bind staging to a private directory created mode 0700 on demand.
     *
     * @param  string  $root  Absolute host-private staging root.
     *
     * @since  2.0.0
     */
    public function __construct(private string $root)
    {
    }

    /**
     * Allocate one empty mode-0600 staging file under an opaque filename.
     *
     * @param   string  $uploadId  Opaque upload identity.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function create(string $uploadId): void
    {
        $this->ensureRoot();
        $path = $this->path($uploadId);
        $handle = @fopen($path, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('The Studio upload staging object could not be allocated.');
        }
        fclose($handle);
        @chmod($path, 0600);
    }

    /**
     * Stream a replacement body through the grant quota and atomically publish it to staging.
     *
     * @param   string           $uploadId      Opaque upload identity.
     * @param   StreamInterface  $source        Request body stream.
     * @param   int              $maximumBytes  Inclusive byte quota.
     *
     * @return  int  Exact received bytes.
     *
     * @since   2.0.0
     */
    public function write(string $uploadId, StreamInterface $source, int $maximumBytes): int
    {
        $this->ensureRoot();
        $target = $this->path($uploadId);
        if (!is_file($target)) {
            throw new StudioMediaPortRejected('not-found', 'studio.media/upload-not-found');
        }
        $temporary = $target . '.part-' . bin2hex(random_bytes(12));
        $handle = @fopen($temporary, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('The Studio upload staging body could not be opened.');
        }
        @chmod($temporary, 0600);
        $bytes = 0;
        $ready = false;
        try {
            if ($source->isSeekable()) {
                $source->rewind();
            }
            while (!$source->eof()) {
                $chunk = $source->read(min(8192, $maximumBytes - $bytes + 1));
                if ($chunk === '') {
                    throw new RuntimeException('The Studio upload request body stalled.');
                }
                $bytes += strlen($chunk);
                if ($bytes > $maximumBytes) {
                    throw new StudioMediaPortRejected('limit-exceeded', 'studio.media/upload-too-large');
                }
                if (fwrite($handle, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('The Studio upload staging body could not be written.');
                }
            }
            if (!fflush($handle)) {
                throw new RuntimeException('The Studio upload staging body could not be flushed.');
            }
            $ready = true;
        } finally {
            fclose($handle);
            if (!$ready && is_file($temporary)) {
                @unlink($temporary);
            }
        }
        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('The Studio upload staging body could not be committed.');
        }
        @chmod($target, 0600);

        return $bytes;
    }

    /**
     * Resolve the private path for one structurally valid opaque identity.
     *
     * @param   string  $uploadId  Opaque upload identity.
     *
     * @return  string  Absolute private path.
     *
     * @since   2.0.0
     */
    public function path(string $uploadId): string
    {
        if (preg_match('/^uploads\/[a-f0-9]{32}$/D', $uploadId) !== 1) {
            throw new StudioMediaPortRejected('not-found', 'studio.media/upload-not-found');
        }

        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . substr($uploadId, 8) . '.upload';
    }

    /**
     * Remove one existing private staged body.
     *
     * @param   string  $uploadId  Opaque upload identity.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function delete(string $uploadId): void
    {
        $path = $this->path($uploadId);
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('The Studio upload staging object could not be removed.');
        }
    }

    /**
     * Create and validate the private staging root without following a non-directory path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function ensureRoot(): void
    {
        if (!is_dir($this->root) && !@mkdir($this->root, 0700, true) && !is_dir($this->root)) {
            throw new RuntimeException('The Studio upload staging root could not be created.');
        }
        if (is_link($this->root) || !is_writable($this->root)) {
            throw new RuntimeException('The Studio upload staging root is unsafe.');
        }
        @chmod($this->root, 0700);
    }
}
