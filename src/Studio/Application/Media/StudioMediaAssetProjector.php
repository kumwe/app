<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Media;

use Kumwe\App\Media\Application\MediaAsset;
use Kumwe\App\Studio\Domain\Media\StudioMediaAcceptedAsset;
use RuntimeException;
use stdClass;

/**
 * Projects validated App media assets into detached canonical Studio media documents.
 *
 * @since  2.0.0
 */
final readonly class StudioMediaAssetProjector
{
    /**
     * Build a portable immutable media asset snapshot from already verified App storage metadata.
     *
     * @param   MediaAsset  $asset  Validated site-owned App media asset.
     *
     * @return  stdClass  Detached `media-asset` document.
     *
     * @since   2.0.0
     */
    public function document(MediaAsset $asset): stdClass
    {
        $revision = self::revision($asset);
        $metadata = new stdClass();
        if (str_starts_with($asset->mimeType, 'image/')) {
            $dimensions = @getimagesize($asset->path);
            if (is_array($dimensions)) {
                $metadata->width = $dimensions[0];
                $metadata->height = $dimensions[1];
            }
        }

        return (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'media-asset',
            'id' => $asset->id,
            'revision' => $revision,
            'state' => 'ready',
            'mediaKind' => self::kind($asset->mimeType),
            'mediaType' => $asset->mimeType,
            'byteSize' => $asset->size,
            'filename' => $asset->name,
            'metadata' => $metadata,
        ];
    }

    /**
     * Project the small identity returned by complete, import and status operations.
     *
     * @param   MediaAsset  $asset  Validated site-owned App media asset.
     *
     * @return  StudioMediaAcceptedAsset  Stable ready identity.
     *
     * @since   2.0.0
     */
    public function accepted(MediaAsset $asset): StudioMediaAcceptedAsset
    {
        return new StudioMediaAcceptedAsset($asset->id, self::revision($asset), 'ready');
    }

    /**
     * Derive an immutable revision from verified bytes and immutable stored metadata.
     *
     * @param   MediaAsset  $asset  Validated App asset.
     *
     * @return  string  Stable bounded revision.
     *
     * @since   2.0.0
     */
    private static function revision(MediaAsset $asset): string
    {
        $digest = hash_file('sha256', $asset->path);
        if (!is_string($digest)) {
            throw new RuntimeException('The Studio media revision could not be calculated.');
        }

        return 'sha256-' . $digest;
    }

    /**
     * Map the App media type to Studio's closed media-kind vocabulary.
     *
     * @param   string  $mediaType  Verified media type.
     *
     * @return  string  Canonical media kind.
     *
     * @since   2.0.0
     */
    private static function kind(string $mediaType): string
    {
        return match (true) {
            str_starts_with($mediaType, 'image/') => 'image',
            str_starts_with($mediaType, 'video/') => 'video',
            str_starts_with($mediaType, 'audio/') => 'audio',
            $mediaType === 'application/pdf' => 'document',
            in_array($mediaType, ['application/zip', 'application/gzip'], true) => 'archive',
            default => 'other',
        };
    }
}
