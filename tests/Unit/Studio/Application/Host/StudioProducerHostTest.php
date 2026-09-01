<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Host;

use Kumwe\App\Studio\Application\Host\StudioProducerHost;
use Kumwe\Producer\Wire\Port\ArtifactPortInterface;
use Kumwe\Producer\Wire\Port\AuthorizationInterface;
use Kumwe\Producer\Wire\Port\LocalizationPortInterface;
use Kumwe\Producer\Wire\Port\MediaPortInterface;
use Kumwe\Producer\Wire\Port\ModelPortInterface;
use Kumwe\Producer\Wire\Port\MutationBoundaryInterface;
use Kumwe\Producer\Wire\Port\PermissionPortInterface;
use Kumwe\Producer\Wire\Port\PreviewPortInterface;
use Kumwe\Producer\Wire\Port\RecoveryPortInterface;
use Kumwe\Producer\Wire\Port\ResourcePortInterface;
use Kumwe\Producer\Wire\Port\TelemetryPortInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the request-scoped host hands Producer exactly the authorities and ports it was built with.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioProducerHost::class)]
final class StudioProducerHostTest extends TestCase
{
    /**
     * Every accessor returns the exact bound collaborator, and authoring stays unserved.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testEveryAccessorReturnsTheExactBoundCollaborator(): void
    {
        $authorization = self::createStub(AuthorizationInterface::class);
        $mutations = self::createStub(MutationBoundaryInterface::class);
        $artifact = self::createStub(ArtifactPortInterface::class);
        $localization = self::createStub(LocalizationPortInterface::class);
        $media = self::createStub(MediaPortInterface::class);
        $model = self::createStub(ModelPortInterface::class);
        $permission = self::createStub(PermissionPortInterface::class);
        $preview = self::createStub(PreviewPortInterface::class);
        $recovery = self::createStub(RecoveryPortInterface::class);
        $resource = self::createStub(ResourcePortInterface::class);
        $telemetry = self::createStub(TelemetryPortInterface::class);
        $host = new StudioProducerHost(
            $authorization,
            $mutations,
            $artifact,
            $localization,
            $media,
            $model,
            $permission,
            $preview,
            $recovery,
            $resource,
            $telemetry,
        );

        self::assertSame($authorization, $host->authorization());
        self::assertSame($mutations, $host->mutations());
        self::assertNull($host->authoring());
        self::assertSame($artifact, $host->artifact());
        self::assertSame($localization, $host->localization());
        self::assertSame($media, $host->media());
        self::assertSame($model, $host->model());
        self::assertSame($permission, $host->permission());
        self::assertSame($preview, $host->preview());
        self::assertSame($recovery, $host->recovery());
        self::assertSame($resource, $host->resource());
        self::assertSame($telemetry, $host->telemetry());
    }
}
