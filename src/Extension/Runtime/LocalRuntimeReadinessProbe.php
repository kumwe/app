<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use Kumwe\CMS\Infrastructure\Persistence\ReadinessStatus;

final readonly class LocalRuntimeReadinessProbe implements ReadinessStatus
{
    public function __construct(
        private ExtensionRuntimeMapCompiler $runtime,
        private int $maximumAgeSeconds = 30,
    ) {
    }

    public function ready(): bool
    {
        return $this->runtime->localMarkerFresh($this->maximumAgeSeconds);
    }
}
