<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation\Job;

use Kumwe\CMS\Application\Automation\JobHandler;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSessionStore;

final readonly class PurgeAdministratorSessionsHandler implements JobHandler
{
    public function __construct(private AdministratorSessionStore $sessions)
    {
    }

    public function type(): string
    {
        return 'system.sessions.purge';
    }

    public function handle(array $payload, ExecutionContext $context): void
    {
        $this->sessions->purgeExpired($context);
    }
}
