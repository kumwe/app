<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

final readonly class SystemPrincipal
{
    private function __construct(private object $provenance, private SystemIdentity $identity)
    {
    }

    public static function issue(object $provenance, SystemIdentity $identity): self
    {
        return new self($provenance, $identity);
    }

    public function identity(): SystemIdentity
    {
        return $this->identity;
    }

    public function context(
        SiteContext $site,
        string $requestId,
        ?string $correlationId = null,
    ): ExecutionContext {
        return ExecutionContext::issueSystem(
            $this->provenance,
            $this->identity,
            $site,
            $requestId,
            $correlationId,
        );
    }
}
