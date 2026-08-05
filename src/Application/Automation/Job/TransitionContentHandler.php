<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation\Job;

use Kumwe\CMS\Application\Automation\JobHandler;
use Kumwe\CMS\Application\Automation\PermanentFailure;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Content\Application\ContentService;

final readonly class TransitionContentHandler implements JobHandler
{
    public function __construct(private ContentService $content)
    {
    }

    public function type(): string
    {
        return 'content.workflow.transition';
    }

    public function handle(array $payload, ExecutionContext $context): void
    {
        $id = $payload['id'] ?? null;
        $version = $payload['version'] ?? null;
        $status = $payload['status'] ?? null;

        if (!is_string($id) || !is_int($version) || !is_string($status)) {
            throw new PermanentFailure('The content transition job payload is invalid.');
        }

        $this->content->transition($context, $id, $version, $status);
    }
}
