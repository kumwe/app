<?php

declare(strict_types=1);

namespace KumweContract\ManifestFour\Integration;

use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Application\Automation\JobHandler;

/**
 * Background job half of the manifest-4 compatibility package.
 *
 * @since  2.0.0
 */
final readonly class SummarizeJob implements JobHandler
{
    /**
     * Bind the executable job to the evidence sink the fixture reads back.
     *
     * @param  ObservationLedger  $ledger  Process-local evidence sink.
     *
     * @since  2.0.0
     */
    public function __construct(private ObservationLedger $ledger)
    {
    }

    /**
     * Return the declared job type this handler answers for.
     *
     * @return  string  The package-namespaced job type its manifest declares.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return 'kumwe.contract-manifest-four.summarize';
    }

    /**
     * Record that the job ran, without any external effect.
     *
     * @param   array<string, mixed>  $payload  Decoded job arguments, in the shape the type's schema declares.
     * @param   ExecutionContext      $context  Authorization context the worker built for the job's owner.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function handle(array $payload, ExecutionContext $context): void
    {
        $this->ledger->record('job');
    }
}
