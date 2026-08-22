<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

use Kumwe\App\BusinessSurface\Application\BusinessSurfaceService;
use Kumwe\App\Content\Application\ContentService;
use ReflectionMethod;

/**
 * Proves catalogue policy against the PHP methods the MCP server will actually call.
 *
 * Reflection supplies the exact method source registered by `KumweMcpServerFactory`. The proof follows
 * private same-class helpers, so shared paths such as job actions and extension lifecycle locks cannot hide
 * a missing check. Literal capabilities must reach an equal `require()` call, generated-business writes
 * must bind the catalogue capability to their closed operation, and every mutation must reach the actual
 * `McpMutationGuard::run()` collaborator locally or through `BusinessMcpHandlers`. Source-unavailable or
 * ambiguous bindings fail closed instead of being accepted on the strength of catalogue metadata alone.
 *
 * @since  2.0.0
 */
final readonly class McpToolExecutionEvidence
{
    /**
     * List every live capability or mutation binding the declaration does not prove.
     *
     * @param   array{
     *              name: string, handler: string, capability: string|null,
     *              capabilityResolver: string|McpDynamicCapabilityResolver,
     *              mutationGuard: McpMutationGuardMode, readOnly: bool, ...
     *          }      $tool      Published tool declaration and its typed execution policy.
     * @param   object  $handlers  Exact handler object the server factory will register.
     *
     * @return  list<string>  Binding violations, empty when policy and live enforcement agree.
     *
     * @since   2.0.0
     */
    public function violations(array $tool, object $handlers): array
    {
        if (!method_exists($handlers, $tool['handler'])) {
            return [];
        }

        $resolver = $tool['capabilityResolver'];
        $guard = $tool['mutationGuard'];
        $violations = [];
        $publicCapability = is_string($resolver) ? $resolver : null;
        if ($tool['capability'] !== $publicCapability) {
            $violations[] = sprintf(
                'Tool "%s" publishes capability %s but its live resolver binds %s.',
                $tool['name'],
                self::displayCapability($tool['capability']),
                self::displayCapability($publicCapability),
            );
        }

        if (is_string($resolver)) {
            if (!$this->provesLiteralCapability($handlers::class, $tool['handler'], $resolver)) {
                $violations[] = sprintf(
                    'Tool "%s" declares capability %s but handler %s does not enforce that live binding.',
                    $tool['name'],
                    $resolver,
                    $tool['handler'],
                );
            }
        } elseif (!$this->provesDynamicCapability($handlers::class, $tool['handler'], $resolver)) {
            $violations[] = sprintf(
                'Tool "%s" declares dynamic capability resolver %s but handler %s does not enforce it.',
                $tool['name'],
                $resolver->value,
                $tool['handler'],
            );
        }

        if ($tool['readOnly'] && $guard !== McpMutationGuardMode::None) {
            $violations[] = sprintf('Read-only tool "%s" declares a mutation-guard route.', $tool['name']);
        }
        if (!$tool['readOnly'] && $guard === McpMutationGuardMode::None) {
            $violations[] = sprintf('Mutating tool "%s" declares no mutation-guard route.', $tool['name']);
        }
        if (
            $guard === McpMutationGuardMode::Local
            && !$this->reachesMutationGuard($handlers::class, $tool['handler'])
        ) {
            $violations[] = sprintf(
                'Mutating tool "%s" does not reach McpMutationGuard through handler %s.',
                $tool['name'],
                $tool['handler'],
            );
        }
        if (
            $guard === McpMutationGuardMode::BusinessDelegate
            && !$this->reachesBusinessDelegateGuard($handlers::class, $tool['handler'])
        ) {
            $violations[] = sprintf(
                'Mutating tool "%s" does not reach McpMutationGuard through its business delegate.',
                $tool['name'],
            );
        }

        return $violations;
    }

    /**
     * Prove an exact literal capability is required, directly or by the closed business-operation route.
     *
     * @param   class-string  $class       Handler class whose reachable source is inspected.
     * @param   string        $handler     Public method registered for this tool.
     * @param   string        $capability  Exact declared capability.
     *
     * @return  bool  True when executable source binds and enforces the capability.
     *
     * @since   2.0.0
     */
    private function provesLiteralCapability(string $class, string $handler, string $capability): bool
    {
        foreach ($this->reachableSources($class, $handler) as $source) {
            preg_match_all(
                '/\$this->require\(\s*([\'\"])([a-z][a-z0-9]*(?:[._-][a-z0-9]+)+)\1\s*\)/',
                $source,
                $matches,
            );
            if (in_array($capability, $matches[2], true)) {
                return true;
            }
        }

        $source = $this->methodSource($class, $handler);
        if ($source === null) {
            return false;
        }
        if (
            preg_match(
                '/\$this->businessMutationContext\(\s*\$operationId\s*,\s*([\'\"])([a-z_]+)\1\s*\)/',
                $source,
                $match,
            ) !== 1
        ) {
            return false;
        }

        $contextSource = $this->methodSource($class, 'businessMutationContext');

        try {
            $businessCapability = BusinessMcpHandlers::capabilityFor($match[2]);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $businessCapability === $capability
            && $contextSource !== null
            && str_contains($contextSource, 'BusinessMcpHandlers::capabilityFor($operation)')
            && str_contains($contextSource, '$this->require($capability)');
    }

    /**
     * Prove one closed dynamic resolver follows its intended executable route.
     *
     * @param   class-string                  $class     Handler class whose source is inspected.
     * @param   string                        $handler   Public method registered for this tool.
     * @param   McpDynamicCapabilityResolver  $resolver  Dynamic policy named by the catalogue.
     *
     * @return  bool  True when the handler contains the resolver's exact live enforcement route.
     *
     * @since   2.0.0
     */
    private function provesDynamicCapability(
        string $class,
        string $handler,
        McpDynamicCapabilityResolver $resolver,
    ): bool {
        $source = $this->methodSource($class, $handler);
        if ($source === null) {
            return false;
        }

        return match ($resolver) {
            McpDynamicCapabilityResolver::Authenticated => str_contains(
                $source,
                '$this->principal()',
            ) && str_contains($source, '$this->catalog->publicSummary()'),
            McpDynamicCapabilityResolver::ContentTransition => $this->provesContentTransition($source),
            McpDynamicCapabilityResolver::BusinessView => $this->provesBusinessView($source),
            McpDynamicCapabilityResolver::BusinessMutationPlan => str_contains(
                $source,
                '$this->require(BusinessMcpHandlers::capabilityFor($operation))',
            ) && $this->provesLiteralCapability($class, $handler, 'business.record.read'),
        };
    }

    /**
     * Prove transition authorization is resolved from live workflow state at both adapter and service layers.
     *
     * @param   string  $handlerSource  Registered top-level handler source.
     *
     * @return  bool  True when preauthorization and the mutation use the same live resolver.
     *
     * @since   2.0.0
     */
    private function provesContentTransition(string $handlerSource): bool
    {
        $serviceSource = $this->methodSource(ContentService::class, 'transition');

        return preg_match(
            '/\$this->preauthorize\(.*\$this->content->transitionCapability\(.*\)->value\(\)/s',
            $handlerSource,
        ) === 1
            && $serviceSource !== null
            && str_contains($serviceSource, '$this->transitionCapabilityForRecord(')
            && str_contains($serviceSource, '$this->authorize($context, $required->value(), $id)');
    }

    /**
     * Prove a custom view carries its declaration-derived operation into shared surface authorization.
     *
     * @param   string  $handlerSource  Registered top-level handler source.
     *
     * @return  bool  True when the top-level delegate and shared service both retain the dynamic operation.
     *
     * @since   2.0.0
     */
    private function provesBusinessView(string $handlerSource): bool
    {
        $delegate = $this->methodSource(BusinessMcpHandlers::class, 'view');
        $service = $this->methodSource(BusinessSurfaceService::class, 'customView');
        $metadata = $this->methodSource(BusinessSurfaceService::class, 'customViewMetadataFor');

        return str_contains($handlerSource, '$this->businessRecords->view(')
            && $delegate !== null
            && str_contains($delegate, '$this->business->customView(')
            && $service !== null
            && str_contains($service, '$this->customBusiness->viewOperation(')
            && str_contains($service, '$this->customViewMetadataFor(')
            && $metadata !== null
            && str_contains($metadata, '$this->metadata($context, $surface, $definition, $operation)');
    }

    /**
     * Decide whether a same-class handler call graph reaches the concrete mutation guard collaborator.
     *
     * @param   class-string  $class    Class whose method graph is inspected.
     * @param   string        $handler  Entry method for the graph.
     *
     * @return  bool  True when a reachable method calls `$this->mutations->run()`.
     *
     * @since   2.0.0
     */
    private function reachesMutationGuard(string $class, string $handler): bool
    {
        foreach ($this->reachableSources($class, $handler) as $source) {
            if (preg_match('/\$this->mutations\s*->\s*run\s*\(/', $source) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prove a generated-business top-level tool delegates to a method whose graph reaches the guard.
     *
     * @param   class-string  $class    Top-level MCP handler class.
     * @param   string        $handler  Registered public tool method.
     *
     * @return  bool  True when the exact delegate route reaches `McpMutationGuard::run()`.
     *
     * @since   2.0.0
     */
    private function reachesBusinessDelegateGuard(string $class, string $handler): bool
    {
        $source = $this->methodSource($class, $handler);
        if (
            $source === null || preg_match(
                '/\$this->businessRecords\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
                $source,
                $match,
            ) !== 1
        ) {
            return false;
        }

        return $this->reachesMutationGuard(BusinessMcpHandlers::class, $match[1]);
    }

    /**
     * Read a method and all same-class instance helpers it can call.
     *
     * @param   class-string  $class    Class whose graph is inspected.
     * @param   string        $handler  Entry method for the graph.
     * @param   list<string>  $seen     Cycle guard carrying method names already visited.
     *
     * @return  list<string>  Reachable method source blocks, including the entry method.
     *
     * @since   2.0.0
     */
    private function reachableSources(string $class, string $handler, array $seen = []): array
    {
        if (in_array($handler, $seen, true) || !method_exists($class, $handler)) {
            return [];
        }
        $seen[] = $handler;
        $source = $this->methodSource($class, $handler);
        if ($source === null) {
            return [];
        }

        $sources = [$source];
        preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches);
        foreach (array_unique($matches[1]) as $called) {
            if (is_string($called)) {
                $sources = [...$sources, ...$this->reachableSources($class, $called, $seen)];
            }
        }

        return $sources;
    }

    /**
     * Read exactly one reflected method body from the source installed for this release.
     *
     * @param   class-string  $class   Declaring class.
     * @param   string        $method  Method name.
     *
     * @return  ?string  Source spanning the reflected method, or null when it cannot be proved.
     *
     * @since   2.0.0
     */
    private function methodSource(string $class, string $method): ?string
    {
        if (!method_exists($class, $method)) {
            return null;
        }
        /** @var array<string, string> $sourceByMethod */
        static $sourceByMethod = [];
        $key = $class . '::' . $method;
        if (isset($sourceByMethod[$key])) {
            return $sourceByMethod[$key];
        }
        $reflection = new ReflectionMethod($class, $method);
        $file = $reflection->getFileName();
        if ($file === false) {
            return null;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();
        if ($start === false || $end === false) {
            return null;
        }

        $sourceByMethod[$key] = implode("\n", array_slice(
            $lines,
            $start - 1,
            $end - $start + 1,
        ));

        return $sourceByMethod[$key];
    }

    /**
     * Format a nullable public capability without making a null binding ambiguous in a violation.
     *
     * @param   ?string  $capability  Public capability or null for a dynamic resolver.
     *
     * @return  string  Quoted capability or the word `null`.
     *
     * @since   2.0.0
     */
    private static function displayCapability(?string $capability): string
    {
        return $capability === null ? 'null' : sprintf('"%s"', $capability);
    }
}
