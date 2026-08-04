<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation\Job;

use Kumwe\CMS\Application\Automation\JobHandler;
use Kumwe\CMS\Extension\Runtime\ExtensionRuntimeMapCompiler;

final readonly class RebuildExtensionMapHandler implements JobHandler
{
    public function __construct(private ExtensionRuntimeMapCompiler $compiler)
    {
    }

    public function type(): string
    {
        return 'extensions.runtime.rebuild';
    }

    public function handle(array $payload): void
    {
        $this->compiler->rebuild();
    }
}
