<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Mcp;

use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\SessionStoreInterface;

/**
 * Assembles the official SDK server that speaks MCP for Kumwe, one instance per transport run.
 *
 * The protocol surface is declared once in `McpCapabilityCatalog` and bound here to the methods of a
 * `KumweMcpHandlers` the caller has already rebound to a specific execution context, which is why the
 * server is built per request or per process rather than shared: the handler object carries the
 * caller's identity, so a server built for one credential can never serve another. Registration is
 * deliberately manual and eager — nothing is discovered from attributes and lazy loading is switched
 * off — and each entry's handler is proven callable as it is registered, so a catalogue that names a
 * method the handlers do not have fails the build instead of surfacing as a broken tool mid-session.
 * The advertised capabilities are fixed: tools, resources and prompts are served, while list-changed
 * notifications, resource subscriptions, logging and completions are not, because the catalogue is
 * static for a release.
 *
 * @since  2.0.0
 */
final readonly class KumweMcpServerFactory
{
    /**
     * Bind the factory to the capability catalogue and the session store its servers are built with.
     *
     * @param  McpCapabilityCatalog    $catalog        Declares the tools, resources and prompts every built
     *         server registers, together with the handler method and annotations of each.
     * @param  string                  $serverVersion  Version the built server reports as its own in the
     *         server info it advertises to clients.
     * @param  ?SessionStoreInterface  $sessions       Store the SDK keeps MCP session state in, so a client is
     *         recognised across transport calls; null leaves the builder's own default in place.
     *
     * @since  2.0.0
     */
    public function __construct(
        private McpCapabilityCatalog $catalog,
        private string $serverVersion = '2.0.0',
        private ?SessionStoreInterface $sessions = null,
    ) {
    }

    /**
     * Build a server that exposes the whole catalogue through the supplied handler object.
     *
     * Every catalogue entry is registered before the server is returned, so the returned instance is
     * complete and ready to be handed a transport. Because the handlers carry the caller's context, the
     * result is single-use in practice: build one per HTTP request or per stdio process rather than
     * caching it.
     *
     * @param   KumweMcpHandlers  $handlers  Handler object, already bound to the caller's execution context,
     *          whose methods the catalogue entries name.
     *
     * @return  Server  Server with the catalogue's tools, resources and prompts registered, ready to run.
     *
     * @throws  \LogicException  When a catalogue entry names a tool, resource or prompt handler that is not
     *          a callable method on the given handler object.
     *
     * @since   2.0.0
     */
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
