<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSurface\Application\Custom;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Automation\IdempotencyKey;
use Kumwe\CMS\BusinessRecord\Application\RecordRequestGuard;
use Ramsey\Uuid\Uuid;

/**
 * Validated, delivery-neutral command passed to an extension-specific record action handler.
 *
 * Expected version, idempotency identity, organization scope, approval identity, and authenticated
 * execution context are mandatory parts of the typed boundary rather than conventions an HTTP, CLI, or
 * MCP adapter may forget. The registry additionally validates `$input` against the signed action schema.
 *
 * @since  2.0.0
 */
final readonly class CustomBusinessActionCommand
{
    /**
     * Contract-specific action input after structural admission.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $input;

    /**
     * Assemble one concurrency- and replay-aware custom action command.
     *
     * @param   ExecutionContext      $context                 Actor, site, membership, and provenance.
     * @param   string                $definitionIdentifier    UUID or handle of the published entity type.
     * @param   string                $recordId                Public identity of the target record.
     * @param   int                   $expectedVersion         Positive version the caller read.
     * @param   string                $action                  Action handle declared inside the definition.
     * @param   IdempotencyKey        $idempotencyKey          Identity under which retries replay the outcome.
     * @param   array<string, mixed>  $input                   Contract-specific command fields.
     * @param   ?string               $organizationIdentifier  Expected organization scope, or null.
     * @param   ?string               $approvalRequestId       Approved maker-checker request when required.
     *
     * @throws  \InvalidArgumentException  When an identifier, version, approval, or input payload is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public string $recordId,
        public int $expectedVersion,
        public string $action,
        public IdempotencyKey $idempotencyKey,
        array $input = [],
        public ?string $organizationIdentifier = null,
        public ?string $approvalRequestId = null,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::record($recordId);
        RecordRequestGuard::expectedVersion($expectedVersion);
        RecordRequestGuard::handle($action, 'action');
        RecordRequestGuard::organization($organizationIdentifier);
        if ($approvalRequestId !== null && !Uuid::isValid($approvalRequestId)) {
            throw new \InvalidArgumentException('A custom action approval identity must be a canonical UUID.');
        }
        CustomBusinessPayload::assertObject($input, 'action command');
        $this->input = $input;
    }
}
