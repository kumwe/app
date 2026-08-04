<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Mcp;

use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\SessionStoreInterface;

final readonly class KumweMcpServerFactory
{
    public function __construct(
        private McpCapabilityCatalog $catalog,
        private string $serverVersion = '2.0.0',
        private ?SessionStoreInterface $sessions = null,
    ) {
    }

    public function create(KumweMcpHandlers $handlers): Server
    {
        $builder = Server::builder()
            ->setServerInfo(
                name: 'Kumwe CMS',
                version: $this->serverVersion,
                description: 'Read-only discovery and non-executable planning for Kumwe CMS.',
            )
            ->setInstructions(
                'Use Kumwe MCP only to discover, read, and prepare plans. '
                . 'No tool can apply, publish, install, administer, query raw storage, or expose secrets.',
            )
            ->setLazyLoading(false)
            ->setCapabilities(new ServerCapabilities(
                tools: true,
                toolsListChanged: false,
                resources: true,
                resourcesSubscribe: false,
                resourcesListChanged: false,
                prompts: true,
                promptsListChanged: false,
                logging: false,
                completions: false,
            ));

        if ($this->sessions !== null) {
            $builder->setSession($this->sessions);
        }

        foreach ($this->catalog->tools() as $tool) {
            $builder->addTool(
                handler: [$handlers, $tool['handler']],
                name: $tool['name'],
                title: $tool['title'],
                description: $tool['description'],
                annotations: new ToolAnnotations(
                    title: $tool['title'],
                    readOnlyHint: true,
                    destructiveHint: false,
                    idempotentHint: true,
                    openWorldHint: false,
                ),
                inputSchema: $tool['inputSchema'],
                outputSchema: $tool['outputSchema'],
            );
        }

        foreach ($this->catalog->resources() as $resource) {
            $builder->addResource(
                handler: [$handlers, $resource['handler']],
                uri: $resource['uri'],
                name: $resource['name'],
                title: $resource['title'],
                description: $resource['description'],
                mimeType: $resource['mimeType'],
            );
        }

        foreach ($this->catalog->prompts() as $prompt) {
            $builder->addPrompt(
                handler: [$handlers, $prompt['handler']],
                name: $prompt['name'],
                title: $prompt['title'],
                description: $prompt['description'],
            );
        }

        return $builder->build();
    }
}
