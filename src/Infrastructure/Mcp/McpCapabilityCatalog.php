<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Mcp;

final class McpCapabilityCatalog
{
    /** @var list<string> */
    public const array PLAN_OPERATIONS = [
        'content.review',
        'seo.review',
        'site.structure.review',
        'extension.compatibility.review',
    ];

    /**
     * @return list<array{
     *     name: string,
     *     title: string,
     *     description: string,
     *     handler: 'discover'|'planReview',
     *     inputSchema: array<string, mixed>,
     *     outputSchema: array<string, mixed>
     * }>
     */
    public function tools(): array
    {
        return [
            [
                'name' => 'kumwe_discover',
                'title' => 'Discover Kumwe capabilities',
                'description' => 'Lists the intentionally exposed read-only Kumwe MCP surface.',
                'handler' => 'discover',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'additionalProperties' => false,
                ],
                'outputSchema' => [
                    'type' => 'object',
                    'required' => ['product', 'mode', 'tools', 'resources', 'prompts'],
                    'properties' => [
                        'product' => ['type' => 'string'],
                        'mode' => ['const' => 'read_and_plan_only'],
                        'tools' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'resources' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'prompts' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'kumwe_plan_review',
                'title' => 'Plan a Kumwe review',
                'description' => 'Builds a non-executable review plan. It never applies or publishes changes.',
                'handler' => 'planReview',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['operation', 'target'],
                    'properties' => [
                        'operation' => ['type' => 'string', 'enum' => self::PLAN_OPERATIONS],
                        'target' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    ],
                    'additionalProperties' => false,
                ],
                'outputSchema' => [
                    'type' => 'object',
                    'required' => ['mode', 'operation', 'target', 'steps', 'apply_supported'],
                    'properties' => [
                        'mode' => ['const' => 'plan_only'],
                        'operation' => ['type' => 'string'],
                        'target' => ['type' => 'string'],
                        'steps' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'apply_supported' => ['const' => false],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @return list<array{
     *     uri: string,
     *     name: string,
     *     title: string,
     *     description: string,
     *     mimeType: string,
     *     handler: 'capabilityResource'
     * }>
     */
    public function resources(): array
    {
        return [[
            'uri' => 'kumwe://capabilities',
            'name' => 'kumwe_capabilities',
            'title' => 'Kumwe capability catalog',
            'description' => 'A stable JSON description of the safe MCP surface.',
            'mimeType' => 'application/json',
            'handler' => 'capabilityResource',
        ]];
    }

    /**
     * @return list<array{
     *     name: string,
     *     title: string,
     *     description: string,
     *     handler: 'siteReviewPrompt'
     * }>
     */
    public function prompts(): array
    {
        return [[
            'name' => 'kumwe_site_review',
            'title' => 'Review a Kumwe site',
            'description' => 'Creates instructions for a read-only site review.',
            'handler' => 'siteReviewPrompt',
        ]];
    }

    /** @return array<string, string|list<string>> */
    public function publicSummary(): array
    {
        $tools = [];
        $resources = [];
        $prompts = [];

        foreach ($this->tools() as $tool) {
            $tools[] = $tool['name'];
        }

        foreach ($this->resources() as $resource) {
            $resources[] = $resource['uri'];
        }

        foreach ($this->prompts() as $prompt) {
            $prompts[] = $prompt['name'];
        }

        return [
            'product' => 'Kumwe CMS',
            'mode' => 'read_and_plan_only',
            'tools' => $tools,
            'resources' => $resources,
            'prompts' => $prompts,
        ];
    }
}
