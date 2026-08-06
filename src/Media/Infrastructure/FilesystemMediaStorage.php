<?php

declare(strict_types=1);

namespace Kumwe\CMS\Media\Infrastructure;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Media\Application\MediaAsset;
use Kumwe\CMS\Media\Application\MediaStorage;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class FilesystemMediaStorage implements MediaStorage
{
    /** @var array<string, string> */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'application/pdf' => 'pdf',
    ];

    public function __construct(private string $root)
    {
    }

    public function all(SiteContext $site): array
    {
        $directory = $this->siteDirectory($site);
        if (!is_dir($directory)) {
            return [];
        }
        $files = glob($directory . '/*.json');
        if (!is_array($files)) {
            return [];
        }
        $assets = [];
        foreach ($files as $metadata) {
            $id = pathinfo($metadata, PATHINFO_FILENAME);
            $asset = $this->find($site, $id);
            if ($asset instanceof MediaAsset) {
                $assets[] = $asset;
            }
        }
        usort($assets, static fn (MediaAsset $left, MediaAsset $right): int => [
            $right->createdAt->getTimestamp(),
            $right->id,
        ] <=> [
            $left->createdAt->getTimestamp(),
            $left->id,
        ]);

        return $assets;
    }

    public function find(SiteContext $site, string $id): ?MediaAsset
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $directory = $this->siteDirectory($site);
        $metadataPath = $directory . '/' . strtolower($id) . '.json';
        if (!is_file($metadataPath) || is_link($metadataPath)) {
            return null;
        }
        try {
            $metadata = json_decode((string) file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($metadata)) {
            return null;
        }
        $name = $metadata['name'] ?? null;
        $mime = $metadata['mime_type'] ?? null;
        $size = $metadata['size'] ?? null;
        $created = $metadata['created_at'] ?? null;
        $extension = $metadata['extension'] ?? null;
        if (
            !is_string($name) || !is_string($mime) || !is_int($size)
            || !is_string($created) || !is_string($extension)
            || (self::MIME_EXTENSIONS[$mime] ?? null) !== $extension
        ) {
            return null;
        }
        $path = $directory . '/' . strtolower($id) . '.' . $extension;
        $root = realpath($directory);
        $resolved = realpath($path);
        if (
            !is_string($root) || !is_string($resolved) || !str_starts_with($resolved, $root . '/')
            || !is_file($resolved) || is_link($path)
        ) {
            return null;
        }
        $actualSize = filesize($resolved);
        if (!is_int($actualSize) || $actualSize !== $size) {
            return null;
        }

        try {
            $createdAt = new DateTimeImmutable($created);
        } catch (\Exception) {
            return null;
        }

        return new MediaAsset(strtolower($id), $name, $mime, $size, $createdAt, $resolved);
    }

    public function store(
        SiteContext $site,
        string $source,
        string $originalName,
        int $maximumBytes,
        DateTimeImmutable $createdAt,
    ): MediaAsset {
        if (!is_file($source) || is_link($source)) {
            throw new InvalidArgumentException('The uploaded media file is unavailable.');
        }
        $size = filesize($source);
        if (!is_int($size) || $size < 1 || $size > $maximumBytes) {
            throw new InvalidArgumentException('The media file is empty or exceeds the configured upload limit.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        if (!is_string($mime) || !isset(self::MIME_EXTENSIONS[$mime])) {
            throw new InvalidArgumentException('Only JPEG, PNG, GIF, WebP, AVIF and PDF files are supported.');
        }
        $extension = self::MIME_EXTENSIONS[$mime];
        $name = $this->displayName($originalName, $extension);
        $id = Uuid::uuid7()->toString();
        $directory = $this->siteDirectory($site);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('The media directory could not be created.');
        }
        $temporary = $directory . '/.upload-' . bin2hex(random_bytes(16));
        $path = $directory . '/' . $id . '.' . $extension;
        if (!copy($source, $temporary) || !chmod($temporary, 0640) || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('The media file could not be stored.');
        }
        $metadataPath = $directory . '/' . $id . '.json';
        try {
            $metadata = json_encode([
                'name' => $name,
                'mime_type' => $mime,
                'extension' => $extension,
                'size' => $size,
                'created_at' => $createdAt->format(DATE_ATOM),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (file_put_contents($metadataPath, $metadata, LOCK_EX) === false || !chmod($metadataPath, 0640)) {
                throw new RuntimeException('The media metadata could not be stored.');
            }
        } catch (\Throwable $failure) {
            @unlink($path);
            @unlink($metadataPath);
            throw $failure;
        }

        return new MediaAsset($id, $name, $mime, $size, $createdAt, $path);
    }

    public function delete(SiteContext $site, string $id): void
    {
        $asset = $this->find($site, $id);
        if (!$asset instanceof MediaAsset) {
            return;
        }
        if (!unlink($asset->path)) {
            throw new RuntimeException('The media file could not be deleted.');
        }
        $metadata = $this->siteDirectory($site) . '/' . strtolower($id) . '.json';
        if (is_file($metadata) && !unlink($metadata)) {
            throw new RuntimeException('The media metadata could not be deleted.');
        }
    }

    private function siteDirectory(SiteContext $site): string
    {
        return rtrim($this->root, '/') . '/' . $site->identifier();
    }

    private function displayName(string $originalName, string $extension): string
    {
        $name = basename(str_replace('\\', '/', trim($originalName)));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        if ($name === '' || $name === '.' || $name === '..') {
            return 'upload.' . $extension;
        }

        return mb_substr($name, 0, 180);
    }
}
