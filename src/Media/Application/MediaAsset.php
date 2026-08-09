<?php

declare(strict_types=1);

namespace Kumwe\CMS\Media\Application;

use DateTimeImmutable;

/**
 * One stored media file as the media library hands it to the rest of the application.
 *
 * A `MediaStorage` implementation only produces an instance once the bytes on disk have been checked
 * against the store's own record of them, so holding a `MediaAsset` means the payload exists, matches
 * the recorded size, and carries a media type the CMS is willing to serve. Callers may therefore
 * stream `$path` and echo `$mimeType` without revalidating anything.
 *
 * `$deletable` separates uploads under the writable media root from the read-only assets shipped with
 * the distribution, which the administrator interface lists but must never offer to remove.
 *
 * @since  2.0.0
 */
final readonly class MediaAsset
{
    /**
     * Capture a media file that the library has already validated.
     *
     * @param  string             $id         UUID of the asset, which also names both of its files on disk.
     * @param  string             $name       Display and download filename, sanitised from the upload.
     * @param  string             $mimeType   Media type detected from the stored bytes, not the claimed one.
     * @param  int                $size       Size of the stored payload in bytes, verified against the file.
     * @param  DateTimeImmutable  $createdAt  Moment the asset entered the library; listings order on it.
     * @param  string             $path       Absolute path of the payload, ready to stream to a client.
     * @param  bool               $deletable  False for bundled assets that ship with the distribution.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $mimeType,
        public int $size,
        public DateTimeImmutable $createdAt,
        public string $path,
        public bool $deletable = true,
    ) {
    }

    /**
     * Export the asset in the flat shape the administrator templates and media pickers render.
     *
     * The `url` is built from the identifier and the display name so that the public media route can
     * check the pair before streaming; both segments are percent-encoded here rather than in Twig.
     *
     * @return  array<string, bool|int|string>  Presentation fields, including `url`, a human-readable
     *          `size_label`, and an `is_image` flag for the grid.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mime_type' => $this->mimeType,
            'size' => $this->size,
            'size_label' => self::sizeLabel($this->size),
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'url' => '/media/' . rawurlencode($this->id) . '/' . rawurlencode($this->name),
            'is_image' => str_starts_with($this->mimeType, 'image/'),
            'deletable' => $this->deletable,
        ];
    }

    /**
     * Render a byte count as the short label the media grid shows beside a thumbnail.
     *
     * @param   int  $bytes  Size of a stored payload in bytes.
     *
     * @return  string  Whole bytes below 1 KB, otherwise one decimal place in KB or MB.
     *
     * @since   2.0.0
     */
    private static function sizeLabel(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1_048_576) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return number_format($bytes / 1_048_576, 1) . ' MB';
    }
}
