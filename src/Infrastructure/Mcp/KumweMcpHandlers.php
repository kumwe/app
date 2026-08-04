<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Mcp;

use InvalidArgumentException;
use JsonException;

final readonly class KumweMcpHandlers
{
    public function __construct(private McpCapabilityCatalog $catalog)
    {
    }

    /** @return array<string, string|list<string>> */
    public function discover(): array
    {
        return $this->catalog->publicSummary();
    }

    /** @return array<string, bool|string|list<string>> */
    public function planReview(string $operation, string $target): array
    {
        $target = trim($target);

        if (!in_array($operation, McpCapabilityCatalog::PLAN_OPERATIONS, true)) {
            throw new InvalidArgumentException('The requested review operation is not exposed by Kumwe MCP.');
        }

        if ($target === '' || strlen($target) > 255 || preg_match('/[\x00-\x1F\x7F]/', $target) === 1) {
            throw new InvalidArgumentException('A review target must contain 1 to 255 printable characters.');
        }

        return [
            'mode' => 'plan_only',
            'operation' => $operation,
            'target' => $target,
            'steps' => [
                'read_current_state',
                'evaluate_review_focus',
                'return_findings_and_proposed_changes',
            ],
            'apply_supported' => false,
        ];
    }

    /** @throws JsonException */
    public function capabilityResource(): string
    {
        return json_encode(
            $this->catalog->publicSummary(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /** @return list<array{role: string, content: string}> */
    public function siteReviewPrompt(string $focus = 'content'): array
    {
        if (!in_array($focus, ['content', 'seo', 'structure', 'extensions'], true)) {
            throw new InvalidArgumentException('The site review focus is not supported.');
        }

        return [[
            'role' => 'user',
            'content' => sprintf(
                'Review the Kumwe site with a %s focus. Read only; propose a plan and do not apply changes.',
                $focus,
            ),
        ]];
    }
}
