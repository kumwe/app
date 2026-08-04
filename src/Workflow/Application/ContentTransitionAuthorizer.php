<?php

declare(strict_types=1);

namespace Kumwe\CMS\Workflow\Application;

use Kumwe\CMS\Content\Domain\ContentStatus;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\CMS\Identity\Domain\Capability;

final readonly class ContentTransitionAuthorizer
{
    public function requiredCapability(ContentStatus $from, ContentStatus $to): Capability
    {
        $capability = match (true) {
            $from === ContentStatus::Draft && $to === ContentStatus::Review => 'content.submit',
            $from === ContentStatus::Review && $to === ContentStatus::Draft => 'content.review',
            $to === ContentStatus::Published => 'content.publish',
            $from === ContentStatus::Published && $to === ContentStatus::Draft => 'content.unpublish',
            $to === ContentStatus::Archived => 'content.archive',
            $from === ContentStatus::Archived && $to === ContentStatus::Draft => 'content.restore',
            default => 'content.update',
        };

        return Capability::fromString($capability);
    }

    public function assertAllowed(
        AuthenticatedPrincipal $principal,
        ContentStatus $from,
        ContentStatus $to,
    ): void {
        $required = $this->requiredCapability($from, $to);

        if (!$principal->hasCapability($required)) {
            throw new InsufficientCapability($required->value());
        }
    }
}
