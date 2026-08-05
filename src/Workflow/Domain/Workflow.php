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

    /** @var array<string, list<string>> */
    private array $transitions;

    public function __construct(?WorkflowDefinition $definition = null)
    {
        if ($definition === null) {
            $this->transitions = self::ALLOWED_TRANSITIONS;

            return;
        }

        $this->transitions = [];
        foreach ($definition->states() as $state) {
            $this->transitions[$state->key] = [];
        }
        foreach ($definition->transitions() as $transition) {
            $this->transitions[$transition->from][] = $transition->to;
        }
    }

    public function allows(ContentStatus|string $from, ContentStatus|string $to): bool
    {
        $from = $from instanceof ContentStatus ? $from->value : $from;
        $to = $to instanceof ContentStatus ? $to->value : $to;
        return in_array($to, $this->transitions[$from] ?? [], true);
    }

    public function assertCanTransition(ContentStatus|string $from, ContentStatus|string $to): void
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
            $this->transitions[$from->value] ?? [],
        );
    }
}
