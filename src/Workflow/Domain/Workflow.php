<?php

declare(strict_types=1);

namespace Kumwe\CMS\Workflow\Domain;

use Kumwe\CMS\Content\Domain\ContentStatus;

final class Workflow
{
    /**
     * This is deliberately closed: changes are product changes that require a
     * new tested workflow version, rather than mutable request configuration.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'draft' => [
            'review',
            'archived',
        ],
        'review' => [
            'draft',
            'published',
            'archived',
        ],
        'published' => [
            'draft',
            'archived',
        ],
        'archived' => [
            'draft',
        ],
    ];

    public function allows(ContentStatus $from, ContentStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED_TRANSITIONS[$from->value], true);
    }

    public function assertCanTransition(ContentStatus $from, ContentStatus $to): void
    {
        if (!$this->allows($from, $to)) {
            throw new InvalidWorkflowTransition($from, $to);
        }
    }

    /**
     * @return list<ContentStatus>
     */
    public function allowedTargets(ContentStatus $from): array
    {
        return array_map(
            static fn (string $status): ContentStatus => ContentStatus::from($status),
            self::ALLOWED_TRANSITIONS[$from->value],
        );
    }
}
