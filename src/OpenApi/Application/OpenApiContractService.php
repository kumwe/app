<?php

declare(strict_types=1);

namespace Kumwe\CMS\OpenApi\Application;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurface;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurfaceOperation;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds and caches the access-aware OpenAPI contract for one authenticated API context.
 *
 * @since  2.0.0
 */
final readonly class OpenApiContractService implements OpenApiContractProvider
{
    /**
     * Configure the compiler with the immutable core contract and shared business catalog.
     *
     * @param  array<string, mixed>     $core      Checked-in core OpenAPI contract decoded at composition.
     * @param  BusinessSurfaceCatalog   $catalog   Shared policy-filtered metadata source.
     * @param  OpenApiContractCompiler  $compiler  Deterministic assembler and validator.
     * @param  OpenApiContractCache     $cache     Disposable verified generation cache.
     * @param  LoggerInterface          $logger    Records disposable cache repair failures without contract data.
     *
     * @since  2.0.0
     */
    public function __construct(
        private array $core,
        private BusinessSurfaceCatalog $catalog,
        private OpenApiContractCompiler $compiler,
        private OpenApiContractCache $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Return the verified contract for one actor, compiling it only on a cache miss.
     *
     * @param   ExecutionContext  $context  Authenticated API actor and exact site/membership.
     *
     * @return  CompiledOpenApiContract  Canonical contract bound to current trusted metadata.
     *
     * @throws  OpenApiContractUnavailable  When current metadata cannot be safely assembled or verified.
     *
     * @since   2.0.0
     */
    public function contract(ExecutionContext $context): CompiledOpenApiContract
    {
        try {
            return $this->current($context);
        } catch (OpenApiContractUnavailable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new OpenApiContractUnavailable($exception);
        }
    }

    /**
     * Assemble or load only the exact current caller-specific generation.
     *
     * Component collisions are admitted before site publication and extension activation. Compiler
     * validation remains here as a defense against restored legacy data or an internal invariant failure;
     * it never falls back to an older generation. A corrupt or unavailable disposable cache is treated as
     * a miss, while failure to republish a verified current compile is logged and does not change its safety.
     *
     * @param   ExecutionContext  $context  Authenticated API actor and exact site/membership.
     *
     * @return  CompiledOpenApiContract  Verified exact-generation contract.
     *
     * @since   2.0.0
     */
    private function current(ExecutionContext $context): CompiledOpenApiContract
    {
        $merged = [];
        foreach (
            [
            BusinessSurfaceOperation::Browse,
            BusinessSurfaceOperation::Read,
            BusinessSurfaceOperation::Create,
            BusinessSurfaceOperation::Update,
            BusinessSurfaceOperation::Action,
            BusinessSurfaceOperation::History,
            BusinessSurfaceOperation::Relation,
            BusinessSurfaceOperation::Report,
            BusinessSurfaceOperation::Export,
            ] as $operation
        ) {
            foreach ($this->catalog->definitions($context, BusinessSurface::Api, $operation) as $definition) {
                $handle = $definition['handle'];
                $merged[$handle] = isset($merged[$handle])
                    ? $this->merge($merged[$handle], $definition)
                    : $definition;
            }
        }
        ksort($merged, SORT_STRING);
        $definitions = array_values($merged);
        $generation = hash('sha256', implode(':', [
            $this->catalog->generation($definitions),
            $context->authorizationFingerprint(),
            hash('sha256', json_encode($definitions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ]));
        try {
            $cached = $this->cache->get($generation);
        } catch (Throwable $exception) {
            $this->cacheFailure('read', $generation, $exception);
            $cached = null;
        }
        if ($cached !== null) {
            return $cached;
        }
        $compiled = $this->compiler->compile($this->core, $definitions, $generation);
        try {
            $this->cache->put($compiled);
        } catch (Throwable $exception) {
            $this->cacheFailure('write', $generation, $exception);
        }

        return $compiled;
    }

    /**
     * Record a cache repair failure without serializing contract bytes or exception messages.
     *
     * @param   string     $operation   Cache read or write operation.
     * @param   string     $generation  Exact non-secret generation digest.
     * @param   Throwable  $exception   Internal failure, represented only by class.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function cacheFailure(string $operation, string $generation, Throwable $exception): void
    {
        $this->logger->warning('The disposable OpenAPI contract cache could not be repaired.', [
            'operation' => $operation,
            'generation' => $generation,
            'exception' => $exception::class,
        ]);
    }

    /**
     * Merge metadata produced under two operation-specific policy plans.
     *
     * @param   array<string, mixed>  $left   Existing entity metadata.
     * @param   array<string, mixed>  $right  Same entity under another operation.
     *
     * @return  array<string, mixed>  Union with field-use flags ORed and named sets deduplicated.
     *
     * @since   2.0.0
     */
    private function merge(array $left, array $right): array
    {
        $fields = [];
        foreach (array_merge($left['fields'], $right['fields']) as $field) {
            $handle = $field['handle'];
            if (isset($fields[$handle])) {
                foreach ($field['uses'] as $use => $allowed) {
                    $fields[$handle]['uses'][$use] = ($fields[$handle]['uses'][$use] ?? false) || $allowed;
                }
                continue;
            }
            $fields[$handle] = $field;
        }
        ksort($fields, SORT_STRING);
        $left['fields'] = array_values($fields);
        foreach (['views', 'actions', 'relationships'] as $collection) {
            $items = [];
            foreach (array_merge($left[$collection], $right[$collection]) as $item) {
                $items[$item['handle']] = $item;
            }
            ksort($items, SORT_STRING);
            $left[$collection] = array_values($items);
        }
        $left['operation'] = 'multi';

        return $left;
    }
}
