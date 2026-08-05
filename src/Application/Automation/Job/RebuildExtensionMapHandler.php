<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation\Job;

use Kumwe\CMS\Application\Automation\JobHandler;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\CMS\Identity\Domain\Capability;

final readonly class RebuildExtensionMapHandler implements JobHandler
{
    public function __construct(
        private ExtensionRuntimeMapCompiler $compiler,
        private AuthorizationGateway $authorization,
    ) {
    }

    public function type(): string
    {
        return 'extensions.runtime.rebuild';
    }

    public function handle(array $payload, ExecutionContext $context): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('extensions.manage'),
            AuthorizationResource::collection('extension_runtime_map'),
        );
        $this->compiler->rebuild();
    }
}
