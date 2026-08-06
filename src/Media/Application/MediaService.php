<?php

declare(strict_types=1);

namespace Kumwe\CMS\Media\Application;

use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

final readonly class MediaService
{
    public function __construct(
        private MediaStorage $storage,
        private AuthorizationGateway $authorization,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private int $maximumBytes,
    ) {
    }

    public function browse(
        ExecutionContext $context,
        string $query = '',
        string $kind = 'all',
        int $page = 1,
        int $perPage = 24,
    ): MediaPage {
        $this->authorize($context, 'content.read');
        $query = mb_strtolower(trim($query));
        $assets = array_values(array_filter(
            $this->storage->all($context->site()),
            static function (MediaAsset $asset) use ($query, $kind): bool {
                if ($query !== '' && !str_contains(mb_strtolower($asset->name), $query)) {
                    return false;
                }

                return match ($kind) {
                    'image' => str_starts_with($asset->mimeType, 'image/'),
                    'document' => $asset->mimeType === 'application/pdf',
                    default => true,
                };
            },
        ));
        $page = max(1, $page);
        $perPage = min(96, max(1, $perPage));

        return new MediaPage(
            array_slice($assets, ($page - 1) * $perPage, $perPage),
            count($assets),
            $page,
            $perPage,
        );
    }

    public function upload(ExecutionContext $context, string $source, string $originalName): MediaAsset
    {
        $this->authorize($context, 'content.update');
        $now = $this->clock->now();
        $asset = $this->storage->store(
            $context->site(),
            $source,
            $originalName,
            $this->maximumBytes,
            $now,
        );
        try {
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                $context->actorId(),
                'media.upload',
                'media',
                $asset->id,
                'success',
                ['mime_type' => $asset->mimeType, 'size' => $asset->size],
            ));
        } catch (\Throwable $failure) {
            $this->storage->delete($context->site(), $asset->id);
            throw $failure;
        }

        return $asset;
    }

    public function delete(ExecutionContext $context, string $id): void
    {
        $this->authorize($context, 'content.delete');
        $asset = $this->storage->find($context->site(), $id);
        if (!$asset instanceof MediaAsset) {
            return;
        }
        $now = $this->clock->now();
        $this->storage->delete($context->site(), $id);
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $now,
            $context->actorId(),
            'media.delete',
            'media',
            $asset->id,
            'success',
            ['name' => $asset->name],
        ));
    }

    private function authorize(ExecutionContext $context, string $capability): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString($capability),
            AuthorizationResource::collection('media'),
        );
    }
}
