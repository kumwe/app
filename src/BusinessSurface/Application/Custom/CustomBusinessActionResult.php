<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application\Custom;

use InvalidArgumentException;
use Kumwe\App\Application\Automation\IdempotencyKey;

/**
 * Bounded, versioned result returned by an extension-specific business action handler.
 *
 * @since  2.0.0
 */
final readonly class CustomBusinessActionResult
{
    /**
     * Result fields validated structurally at construction and against the signed schema by the registry.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $data;

    /**
     * Admit one action result and retain its concurrency and replay evidence.
     *
     * @param   array<string, mixed>  $data           Contract-shaped result fields.
     * @param   int                   $recordVersion  Positive resulting record version.
     * @param   IdempotencyKey        $operationId    Operation identity the command executed or replayed.
     * @param   bool                  $replayed       Whether an existing completed result was returned.
     * @param   ?string               $workflowState  Resulting workflow handle, or null when not applicable.
     * @param   bool                  $deleted        Whether the resulting record is soft-deleted.
     *
     * @throws  InvalidArgumentException  When the version is not positive or result data is unbounded.
     *
     * @since   2.0.0
     */
    public function __construct(
        array $data,
        public int $recordVersion,
        public IdempotencyKey $operationId,
        public bool $replayed = false,
        public ?string $workflowState = null,
        public bool $deleted = false,
    ) {
        if ($recordVersion < 1) {
            throw new InvalidArgumentException('A custom business action result requires a positive version.');
        }
        if (
            $workflowState !== null
            && preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $workflowState) !== 1
        ) {
            throw new InvalidArgumentException('A custom business action result workflow state is invalid.');
        }
        CustomBusinessPayload::assertObject($data, 'action result');
        $this->data = $data;
    }
}
