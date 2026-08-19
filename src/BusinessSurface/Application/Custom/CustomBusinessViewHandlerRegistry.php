<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application\Custom;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use LogicException;
use Throwable;

/**
 * Owner-aware runtime registry and validating dispatcher for custom business views.
 *
 * Registration claims both the handler and schema references once. Invocation requires the exact owner
 * and pair published by the business definition, validates query parameters before extension code runs,
 * and validates the bounded result afterwards. Removing an owner withdraws executable code and contracts
 * together while persisted business definitions remain available for lifecycle history.
 *
 * @since  2.0.0
 */
final class CustomBusinessViewHandlerRegistry
{
    /**
     * Shared claim registry coordinating view and action reference namespaces.
     *
     * @var    CustomBusinessReferenceRegistry
     * @since  2.0.0
     */
    private readonly CustomBusinessReferenceRegistry $references;

    /**
     * Registered handlers keyed by owner-scoped handler reference.
     *
     * @var    array<string, array{
     *             owner: DefinitionOwner,
     *             contract: CustomBusinessViewContract,
     *             handler: CustomBusinessViewHandler
     *         }>
     * @since  2.0.0
     */
    private array $handlers = [];

    /**
     * Handler reference holding each schema reference, used to reject schema collisions.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private array $schemas = [];

    /**
     * Bind the dispatcher to the process-wide custom reference claims.
     *
     * @param  ?CustomBusinessReferenceRegistry  $references  Shared claims; null creates an isolated registry.
     *
     * @since  2.0.0
     */
    public function __construct(?CustomBusinessReferenceRegistry $references = null)
    {
        $this->references = $references ?? new CustomBusinessReferenceRegistry();
    }

    /**
     * Register one provider-reconciled custom view handler and signed contract.
     *
     * @param   DefinitionOwner             $owner     Core or extension owner claiming both references.
     * @param   CustomBusinessViewContract  $contract  Signed query and result contract.
     * @param   CustomBusinessViewHandler   $handler   Typed application handler implementation.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When either reference escapes the owner or a handler or schema
     *          reference is already claimed.
     *
     * @since   2.0.0
     */
    public function register(
        DefinitionOwner $owner,
        CustomBusinessViewContract $contract,
        CustomBusinessViewHandler $handler,
    ): void {
        if (isset($this->handlers[$contract->handler])) {
            throw new InvalidArgumentException(sprintf(
                'Custom business view handler %s is already registered.',
                $contract->handler,
            ));
        }
        if (isset($this->schemas[$contract->schema])) {
            throw new InvalidArgumentException(sprintf(
                'Custom business view schema %s is already registered.',
                $contract->schema,
            ));
        }
        $this->references->claim($owner, $contract->handler, $contract->schema, 'view');

        $this->handlers[$contract->handler] = [
            'owner' => $owner,
            'contract' => $contract,
            'handler' => $handler,
        ];
        $this->schemas[$contract->schema] = $contract->handler;
        ksort($this->handlers, SORT_STRING);
        ksort($this->schemas, SORT_STRING);
    }

    /**
     * Execute one custom view through the exact owner and schema pair its definition published.
     *
     * @param   DefinitionOwner          $owner             Owner recorded on the resolved definition.
     * @param   string                   $handlerReference  Handler reference published by the view.
     * @param   string                   $schemaReference   Schema reference published by the view.
     * @param   CustomBusinessViewQuery  $query             Structurally validated query and context.
     *
     * @return  CustomBusinessViewResult  Handler result after result-schema validation.
     *
     * @throws  LogicException  When the exact owner, handler, and schema tuple is not active, the handler
     *          returns the wrong runtime type, or its result violates the registered contract.
     * @throws  InvalidArgumentException  When query parameters violate the registered contract.
     * @throws  CustomBusinessHandlerFailed  When extension application code raises any throwable.
     *
     * @since   2.0.0
     */
    public function execute(
        DefinitionOwner $owner,
        string $handlerReference,
        string $schemaReference,
        CustomBusinessViewQuery $query,
    ): CustomBusinessViewResult {
        CustomBusinessReference::assert($handlerReference, 'view handler');
        CustomBusinessReference::assert($schemaReference, 'view schema');
        $entry = $this->handlers[$handlerReference] ?? null;
        if (
            $entry === null
            || $entry['owner']->toArray() !== $owner->toArray()
            || $entry['contract']->schema !== $schemaReference
        ) {
            throw new LogicException('The custom business view contract is not active.');
        }

        $entry['contract']->querySchema->assertValid($query->parameters, 'view query');
        try {
            $result = $entry['handler']->handle($query);
        } catch (Throwable $exception) {
            throw new CustomBusinessHandlerFailed($exception);
        }
        $entry['contract']->resultSchema->assertValid($result->data, 'view result');

        return $result;
    }

    /**
     * Resolve the active signed contract for an exact owner and reference pair.
     *
     * @param   DefinitionOwner  $owner             Owner recorded on a resolved business definition.
     * @param   string           $handlerReference  Handler reference the definition publishes.
     * @param   string           $schemaReference   Schema reference the definition publishes.
     *
     * @return  CustomBusinessViewContract|null  Contract, or null for every absent or ownership mismatch.
     *
     * @since   2.0.0
     */
    public function contract(
        DefinitionOwner $owner,
        string $handlerReference,
        string $schemaReference,
    ): ?CustomBusinessViewContract {
        $entry = $this->handlers[$handlerReference] ?? null;
        if (
            $entry === null
            || $entry['owner']->toArray() !== $owner->toArray()
            || $entry['contract']->schema !== $schemaReference
        ) {
            return null;
        }
        return $entry['contract'];
    }

    /**
     * List the custom view contracts one owner registered.
     *
     * @param   DefinitionOwner  $owner  Contributor whose contracts are being inventoried.
     *
     * @return  list<CustomBusinessViewContract>  Contracts in handler-reference order.
     *
     * @since   2.0.0
     */
    public function ownedBy(DefinitionOwner $owner): array
    {
        return array_values(array_map(
            static fn (array $entry): CustomBusinessViewContract => $entry['contract'],
            array_filter(
                $this->handlers,
                static fn (array $entry): bool => $entry['owner']->toArray() === $owner->toArray(),
            ),
        ));
    }

    /**
     * Withdraw every custom view handler and schema belonging to one owner.
     *
     * @param   DefinitionOwner  $owner  Contributor being disabled, uninstalled, or made untrusted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function remove(DefinitionOwner $owner): void
    {
        foreach ($this->handlers as $reference => $entry) {
            if ($entry['owner']->toArray() === $owner->toArray()) {
                $this->references->release($owner, $reference, $entry['contract']->schema);
                unset($this->schemas[$entry['contract']->schema], $this->handlers[$reference]);
            }
        }
    }
}
