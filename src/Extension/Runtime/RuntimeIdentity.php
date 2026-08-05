<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use InvalidArgumentException;

final readonly class RuntimeIdentity
{
    public string $leaseId;

    public function __construct(
        public string $deploymentId,
        public string $replicaId,
        public string $processId,
        public string $instanceId = 'legacy-instance',
    ) {
        foreach ([$deploymentId, $replicaId, $processId, $instanceId] as $value) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{1,126}[A-Za-z0-9]$/D', $value) !== 1) {
                throw new InvalidArgumentException('Runtime identity values must be explicit stable identifiers.');
            }
        }

        $this->leaseId = hash('sha256', implode("\0", [$deploymentId, $replicaId, $processId, $instanceId]));
    }
}
