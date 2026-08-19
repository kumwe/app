<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Business;

use Kumwe\App\BusinessRecord\Application\BusinessRecordView;
use Kumwe\App\BusinessRecord\Application\RecordBrowseResult;
use Kumwe\App\BusinessRecord\Application\RecordHistoryResult;
use Kumwe\App\BusinessRecord\Application\RecordMutationResult;
use Kumwe\App\BusinessSurface\Application\BusinessRecordProjector;

/**
 * Adapts the shared generated-business projection to REST response methods.
 *
 * REST deliberately owns no second serialization policy. The shared projector removes internal keys,
 * omits denied field handles recursively, preserves exact values, and supplies the same stable shape to
 * browser, CLI, MCP, and REST adapters.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordApiPresenter
{
    /**
     * Bind REST presentation to the one delivery-neutral record projector.
     *
     * @param  BusinessRecordProjector  $projector  Shared safe generated-business projector.
     *
     * @since  2.0.0
     */
    public function __construct(private BusinessRecordProjector $projector)
    {
    }

    /**
     * Present one policy-filtered record.
     *
     * @param   BusinessRecordView  $record  Application record view.
     *
     * @return  array<string, mixed>  Public exact record representation.
     *
     * @since   2.0.0
     */
    public function record(BusinessRecordView $record): array
    {
        return $this->projector->record($record);
    }

    /**
     * Present one bounded browse page.
     *
     * @param   RecordBrowseResult  $result  Bounded application browse result.
     *
     * @return  array<string, mixed>  Public exact browse representation.
     *
     * @since   2.0.0
     */
    public function browse(RecordBrowseResult $result): array
    {
        return $this->projector->browse($result);
    }

    /**
     * Present one idempotent mutation result.
     *
     * @param   RecordMutationResult  $result  Application mutation result or replay.
     *
     * @return  array<string, int|string|bool|null>  Public mutation representation.
     *
     * @since   2.0.0
     */
    public function mutation(RecordMutationResult $result): array
    {
        /** @var array<string, int|string|bool|null> $projected */
        $projected = $this->projector->mutation($result);

        return $projected;
    }

    /**
     * Present one bounded history page.
     *
     * @param   RecordHistoryResult  $result  Disclosure-filtered history result.
     *
     * @return  array<string, mixed>  Public history representation.
     *
     * @since   2.0.0
     */
    public function history(RecordHistoryResult $result): array
    {
        return $this->projector->history($result);
    }

    /**
     * Present a contract-validated custom document exactly.
     *
     * Signed custom schemas reject reserved persistence and audit property names before activation. The
     * result has also passed that schema after handler execution, so applying a REST-only recursive filter
     * here would violate the contract and diverge from browser, CLI and MCP output.
     *
     * @param   array<string, mixed>  $document  Contract-validated custom result document.
     *
     * @return  array<string, mixed>  Recursively omission-safe public document.
     *
     * @since   2.0.0
     */
    public function document(array $document): array
    {
        return $document;
    }
}
