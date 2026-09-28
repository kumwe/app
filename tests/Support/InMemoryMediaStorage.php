<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Media\Application\MediaAsset;
use Kumwe\App\Media\Application\MediaStorage;
use Kumwe\Context\Value\SiteContext;

/**
 * Media library held in memory, refusing what the filesystem store refuses so adapters meet real outcomes.
 *
 * Machine-surface unit tests drive the real `MediaService` over this store: it copies the staged bytes the
 * adapter hands over, refuses an empty, unreadable or oversized source and any type other than PNG or PDF,
 * and keeps assets per site, newest first, so authorization, the size ceiling and audit stay the service's.
 *
 * @since  2.0.0
 */
final class InMemoryMediaStorage implements MediaStorage
{
    /**
     * Stored assets keyed by site identifier, then by asset identifier.
     *
     * @var    array<string, array<string, MediaAsset>>
     * @since  2.0.0
     */
    private array $assets = [];

    /**
     * Stored bytes keyed by asset identifier, so a test can prove the adapter passed the exact body.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    public array $bytes = [];

    /**
     * List the site's assets, newest first.
     *
     * @param   SiteContext  $site  Site whose library is listed.
     *
     * @return  list<MediaAsset>  Assets newest first.
     *
     * @since   2.0.0
     */
    public function all(SiteContext $site): array
    {
        return array_reverse(array_values($this->assets[$site->identifier()] ?? []));
    }

    /**
     * Find one asset of the site.
     *
     * @param   SiteContext  $site  Site the lookup is scoped to.
     * @param   string       $id    Asset identifier.
     *
     * @return  ?MediaAsset  The asset, or null when this site holds none of that identifier.
     *
     * @since   2.0.0
     */
    public function find(SiteContext $site, string $id): ?MediaAsset
    {
        return $this->assets[$site->identifier()][$id] ?? null;
    }

    /**
     * Copy a staged file into the site's library after the filesystem store's refusals.
     *
     * @param   SiteContext        $site          Site the asset is filed under.
     * @param   string             $source        Staged file path.
     * @param   string             $originalName  Client file name.
     * @param   int                $maximumBytes  Accepted size bound.
     * @param   DateTimeImmutable  $createdAt     Instant of the write.
     *
     * @return  MediaAsset  The stored asset.
     *
     * @throws  InvalidArgumentException  When the source is unreadable, empty, oversized or not PNG or PDF.
     *
     * @since   2.0.0
     */
    public function store(
        SiteContext $site,
        string $source,
        string $originalName,
        int $maximumBytes,
        DateTimeImmutable $createdAt,
    ): MediaAsset {
        $bytes = is_file($source) ? file_get_contents($source) : false;
        if ($bytes === false || $bytes === '') {
            throw new InvalidArgumentException('The uploaded media file is empty or unreadable.');
        }
        if (strlen($bytes) > $maximumBytes) {
            throw new InvalidArgumentException('The uploaded media file is larger than the configured limit.');
        }
        $type = match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, '%PDF-') => 'application/pdf',
            default => throw new InvalidArgumentException('The uploaded media type is not supported.'),
        };
        $id = sprintf('%032x', count($this->bytes) + 1);
        $asset = new MediaAsset($id, basename($originalName), $type, strlen($bytes), $createdAt, 'memory/' . $id);
        $this->assets[$site->identifier()][$id] = $asset;
        $this->bytes[$id] = $bytes;

        return $asset;
    }

    /**
     * Remove one asset of the site; an unknown identifier is ignored.
     *
     * @param   SiteContext  $site  Site the removal is scoped to.
     * @param   string       $id    Asset identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function delete(SiteContext $site, string $id): void
    {
        unset($this->assets[$site->identifier()][$id], $this->bytes[$id]);
    }
}
