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
                description: 'Capability-protected CMS administration through Kumwe application services.',
            )
            ->setInstructions(
                'Use the least-privilege token required for each operation. Mutations use the same audited '
                . 'application services and optimistic concurrency rules as the administrator and REST API.',
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
            $handler = [$handlers, $tool['handler']];
            if (!is_callable($handler)) {
                throw new \LogicException(sprintf('MCP handler %s is not callable.', $tool['handler']));
            }
            $builder->addTool(
                handler: $handler,
                name: $tool['name'],
                title: $tool['title'],
                description: $tool['description'],
                annotations: new ToolAnnotations(
                    title: $tool['title'],
                    readOnlyHint: $tool['readOnly'],
                    destructiveHint: $tool['destructive'],
                    idempotentHint: $tool['idempotent'],
                    openWorldHint: false,
                ),
                inputSchema: $tool['inputSchema'],
                outputSchema: $tool['outputSchema'],
            );
        }

        foreach ($this->catalog->resources() as $resource) {
            $handler = [$handlers, $resource['handler']];
            if (!is_callable($handler)) {
                throw new \LogicException(sprintf('MCP resource handler %s is not callable.', $resource['handler']));
            }
            $builder->addResource(
                handler: $handler,
                uri: $resource['uri'],
                name: $resource['name'],
                title: $resource['title'],
                description: $resource['description'],
                mimeType: $resource['mimeType'],
            );
        }

        foreach ($this->catalog->prompts() as $prompt) {
            $handler = [$handlers, $prompt['handler']];
            if (!is_callable($handler)) {
                throw new \LogicException(sprintf('MCP prompt handler %s is not callable.', $prompt['handler']));
            }
            $builder->addPrompt(
                handler: $handler,
                name: $prompt['name'],
                title: $prompt['title'],
                description: $prompt['description'],
            );
        }

        return $builder->build();
    }
}
