<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\InterfaceProgramme;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

if (!defined('KUMWE_INTERFACE_PROGRAMME_LIBRARY_ONLY')) {
    define('KUMWE_INTERFACE_PROGRAMME_LIBRARY_ONLY', true);
}
require_once dirname(__DIR__, 3) . '/tools/verify-interface-programme.php';

/**
 * Exercise adversarial evidence and completion-gate programme records.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class InterfaceProgrammeEvidenceGateTest extends TestCase
{
    /**
     * Reject evidence that omits a required field or violates the declared type and revision rules.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEvidenceMustFollowTheDeclaredRulesAndRequiredFields(): void
    {
        $evidence = $this->evidence('EVID-BAD', 'unknown', ['P0-001']);
        unset($evidence['environment']);
        unset($evidence['result']);
        $evidence['status'] = 'invented';
        $evidence['source_revision'] = 'not-a-revision';

        $errors = $this->validate(
            [$evidence],
            ['P0-001' => $this->workItem('P0-001')],
        );

        self::assertContains('Evidence EVID-BAD is missing required field environment.', $errors);
        self::assertContains('Evidence EVID-BAD is missing required field result.', $errors);
        self::assertContains('Evidence EVID-BAD has an unknown type.', $errors);
        self::assertContains('Evidence EVID-BAD has unknown status invented.', $errors);
        self::assertContains('Evidence EVID-BAD requires a 40-character source revision.', $errors);
        self::assertContains('Evidence EVID-BAD requires a non-empty environment.', $errors);
        self::assertContains('Evidence EVID-BAD requires a non-empty result.', $errors);
    }

    /**
     * Retain failed and expired evidence without permitting it to satisfy completion.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHistoricalEvidenceIsValidButCannotCloseAWorkItem(): void
    {
        $failed = $this->evidence('EVID-FAILED', 'source', ['P0-001']);
        $failed['status'] = 'failed';
        $expired = $this->evidence('EVID-EXPIRED', 'source', ['P0-001']);
        $expired['status'] = 'expired';

        $errors = $this->validate(
            [$failed, $expired],
            ['P0-001' => $this->workItem('P0-001')],
        );
        self::assertSame([], $errors);

        $item = $this->workItem('P0-001', 'complete');
        $item['evidence_ids'] = ['EVID-FAILED'];
        $errors = $this->validate([$failed, $expired], ['P0-001' => $item]);
        self::assertContains(
            'Completed work item P0-001 references unaccepted evidence EVID-FAILED.',
            $errors,
        );
        self::assertContains('Completed work item P0-001 lacks accepted source evidence.', $errors);
    }

    /**
     * Require accepted evidence for every type declared by a completed work item.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCompletedWorkItemRequiresAcceptedEvidenceForEveryDeclaredType(): void
    {
        $item = $this->workItem('P0-001', 'complete');
        $item['evidence_required'] = ['source', 'test'];
        $item['evidence_ids'] = ['EVID-SOURCE'];

        $errors = $this->validate(
            [$this->evidence('EVID-SOURCE', 'source', ['P0-001'])],
            ['P0-001' => $item],
        );

        self::assertContains('Completed work item P0-001 lacks accepted test evidence.', $errors);
    }

    /**
     * Reject evidence borrowed from another target even when its type and status are accepted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCompletedEvidenceMustReciprocallySupportItsTarget(): void
    {
        $item = $this->workItem('P0-001', 'complete');
        $item['evidence_ids'] = ['EVID-SOURCE'];

        $errors = $this->validate(
            [$this->evidence('EVID-SOURCE', 'source', ['P0-002'])],
            [
                'P0-001' => $item,
                'P0-002' => $this->workItem('P0-002'),
            ],
        );

        self::assertContains(
            'Completed work item P0-001 evidence EVID-SOURCE does not declare support for P0-001.',
            $errors,
        );
        self::assertContains('Completed work item P0-001 lacks accepted source evidence.', $errors);
    }

    /**
     * Prevent a completed work item from outrunning an unfinished prerequisite.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCompletedWorkItemCannotOutrunAnIncompletePrerequisite(): void
    {
        $item = $this->workItem('P0-002', 'complete');
        $item['prerequisites'] = ['P0-001'];
        $item['evidence_ids'] = ['EVID-SOURCE'];

        $errors = $this->validate(
            [$this->evidence('EVID-SOURCE', 'source', ['P0-002'])],
            [
                'P0-001' => $this->workItem('P0-001', 'in_review'),
                'P0-002' => $item,
            ],
        );

        self::assertContains('Completed work item P0-002 has incomplete prerequisite P0-001.', $errors);
    }

    /**
     * Reject broken, unaccountable status-history transitions.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStatusHistoryMustBeDatedContinuousAndEvidenceBacked(): void
    {
        $item = $this->workItem('P0-001', 'in_review');
        $item['status_history'] = [
            [
                'date' => '2026-08-11',
                'from' => 'complete',
                'to' => 'in_progress',
                'reason' => '',
                'evidence_ids' => ['EVID-UNKNOWN'],
            ],
            [
                'at' => 'not-a-timestamp',
                'from' => 'ready',
                'to' => 'in_review',
                'reason' => 'Submitted for review.',
                'evidence_ids' => [],
            ],
        ];

        $errors = $this->validate([], ['P0-001' => $item]);

        self::assertContains(
            'Work item P0-001 history must start with null to planned or from planned.',
            $errors,
        );
        self::assertContains('Work item P0-001 history entry 1 requires a reason.', $errors);
        self::assertContains(
            'Work item P0-001 history entry 1 references unknown evidence EVID-UNKNOWN.',
            $errors,
        );
        self::assertContains(
            'Work item P0-001 history entry 2 requires exactly one valid date or UTC timestamp.',
            $errors,
        );
        self::assertContains(
            'Work item P0-001 history entry 2 does not continue the prior status.',
            $errors,
        );
    }

    /**
     * Enforce both evidence-type and prerequisite completion rules on gates.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCompletedGateRequiresEveryEvidenceTypeAndCompletePrerequisites(): void
    {
        $gate = $this->gate('GATE-B', 'complete');
        $gate['prerequisites'] = ['GATE-A'];
        $gate['required_evidence_types'] = ['source', 'review'];
        $gate['evidence_ids'] = ['EVID-SOURCE'];

        $errors = $this->validate(
            [$this->evidence('EVID-SOURCE', 'source', ['GATE-B'])],
            [],
            [
                'GATE-A' => $this->gate('GATE-A', 'in_review'),
                'GATE-B' => $gate,
            ],
        );

        self::assertContains('Completed gate GATE-B lacks accepted review evidence.', $errors);
        self::assertContains('Completed gate GATE-B has incomplete prerequisite GATE-A.', $errors);
    }

    /**
     * Prevent unresolved P0/P1 findings from being bypassed by item or gate completion.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBlockingFindingsPreventWorkItemAndGateCompletion(): void
    {
        $item = $this->workItem('P0-001', 'complete');
        $item['evidence_ids'] = ['EVID-ITEM'];
        $gate = $this->gate('GATE-A', 'complete');
        $gate['evidence_ids'] = ['EVID-GATE'];
        $finding = [
            'KIS-FINDING-P0' => [
                'phase_numbers' => [0 => true],
                'work_item_ids' => ['P0-001' => true],
            ],
        ];
        $phases = [[
            'number' => 0,
            'exit_gates' => ['GATE-A'],
            'work_items' => [$item],
        ]];

        $errors = $this->validate(
            [
                $this->evidence('EVID-ITEM', 'source', ['P0-001']),
                $this->evidence('EVID-GATE', 'source', ['GATE-A']),
            ],
            ['P0-001' => $item],
            ['GATE-A' => $gate],
            $finding,
            $phases,
        );

        self::assertContains(
            'Completed work item P0-001 is blocked by unresolved finding KIS-FINDING-P0.',
            $errors,
        );
        self::assertContains(
            'Completed gate GATE-A is blocked by unresolved finding KIS-FINDING-P0.',
            $errors,
        );
    }

    /**
     * Prevent a phase checkbox from closing before its work and exit gates are complete.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCompletePhaseRequiresSatisfiedItemsAndExitGates(): void
    {
        $item = $this->workItem('P0-001', 'in_review');
        $gate = $this->gate('GATE-A', 'in_review');
        $phases = [
            [
                'number' => 0,
                'status' => 'complete',
                'entry_gates' => [],
                'exit_gates' => ['GATE-A'],
                'work_items' => [$item],
            ],
            [
                'number' => 1,
                'status' => 'done',
                'entry_gates' => [],
                'exit_gates' => [],
                'work_items' => [],
            ],
        ];

        $errors = $this->validate(
            [],
            ['P0-001' => $item],
            ['GATE-A' => $gate],
            [],
            $phases,
        );

        self::assertContains('Complete phase 0 has incomplete work item P0-001.', $errors);
        self::assertContains('Complete phase 0 has incomplete exit gate GATE-A.', $errors);
        self::assertContains('Phase 1 has an unknown status.', $errors);
    }

    /**
     * Reject a bare waiver as a substitute for completing a prerequisite.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBareWaiverCannotSatisfyACompletedPrerequisite(): void
    {
        $target = $this->workItem('P0-002', 'complete');
        $target['prerequisites'] = ['P0-001'];
        $target['evidence_ids'] = ['EVID-SOURCE'];

        $errors = $this->validate(
            [$this->evidence('EVID-SOURCE', 'source', ['P0-002'])],
            [
                'P0-001' => $this->workItem('P0-001', 'waived', 'P2'),
                'P0-002' => $target,
            ],
        );

        self::assertContains('Waived work item P0-001 requires a waiver record.', $errors);
        self::assertContains('Completed work item P0-002 has incomplete prerequisite P0-001.', $errors);
    }

    /**
     * Permit a governed waiver reached through an explicit supersession chain.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGovernedWaiverAndNamedSupersessionCanSatisfyAPrerequisite(): void
    {
        $waived = $this->workItem('P0-001', 'waived', 'P2');
        $waived['evidence_ids'] = ['EVID-WAIVER'];
        $waived['waiver'] = [
            'finding_id' => 'FIND-P2-001',
            'owner_role' => 'programme-owner',
            'rationale' => 'The bounded limitation is accepted temporarily.',
            'compensating_control' => 'The affected route remains disabled.',
            'target_phase' => 3,
            'evidence_ids' => ['EVID-WAIVER'],
        ];
        $superseded = $this->workItem('P0-002', 'superseded');
        $superseded['superseded_by'] = 'P0-001';
        $target = $this->workItem('P0-003', 'complete');
        $target['prerequisites'] = ['P0-002'];
        $target['evidence_ids'] = ['EVID-SOURCE'];

        $errors = $this->validate(
            [
                $this->evidence('EVID-WAIVER', 'review', ['P0-001']),
                $this->evidence('EVID-SOURCE', 'source', ['P0-003']),
            ],
            [
                'P0-001' => $waived,
                'P0-002' => $superseded,
                'P0-003' => $target,
            ],
        );

        self::assertSame([], $errors);
    }

    /**
     * Prevent superseded accepted evidence from satisfying completion and reject correction cycles.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSupersededEvidenceCannotCountAndCorrectionCyclesFail(): void
    {
        $item = $this->workItem('P0-001', 'complete');
        $item['evidence_ids'] = ['EVID-OLD'];
        $old = $this->evidence('EVID-OLD', 'source', ['P0-001']);
        $replacement = $this->evidence('EVID-NEW', 'source', ['P0-001']);
        $replacement['supersedes'] = 'EVID-OLD';

        $errors = $this->validate([$old, $replacement], ['P0-001' => $item]);

        self::assertContains(
            'Completed work item P0-001 references unaccepted evidence EVID-OLD.',
            $errors,
        );
        self::assertContains('Completed work item P0-001 lacks accepted source evidence.', $errors);

        $old['supersedes'] = 'EVID-NEW';
        $errors = $this->validate([$old, $replacement], ['P0-001' => $item]);
        self::assertContains('Evidence EVID-OLD has a cyclic supersedes chain.', $errors);
        self::assertContains('Evidence EVID-NEW has a cyclic supersedes chain.', $errors);
    }

    /**
     * Validate a focused ledger fixture with the production verifier functions.
     *
     * @param   list<array<string, mixed>>             $evidence   Evidence records.
     * @param   array<string, array<string, mixed>>     $workItems  Work-item lookup.
     * @param   array<string, array<string, mixed>>     $gates      Gate lookup.
     * @param   array<string, array<string, mixed>>     $findings   Normalized blocking findings.
     * @param   list<array<string, mixed>>              $phases     Phase records for gate scope.
     *
     * @return  list<string>  Validation failures.
     *
     * @since   2.0.0
     */
    private function validate(
        array $evidence,
        array $workItems,
        array $gates = [],
        array $findings = [],
        array $phases = [],
    ): array {
        $ledger = [
            'status_vocabulary' => [
                'planned', 'ready', 'in_progress', 'blocked', 'in_review', 'complete', 'waived', 'superseded',
            ],
            'evidence_rules' => [
                'types' => ['source', 'test', 'browser', 'parity', 'security', 'decision', 'review', 'qualification'],
                'statuses' => ['accepted', 'failed', 'expired', 'superseded'],
                'required_fields' => [
                    'id', 'type', 'status', 'producer_role', 'source_revision', 'environment', 'method',
                    'result', 'artifact_paths', 'supports',
                ],
                'accepted_status' => 'accepted',
            ],
            'evidence_records' => $evidence,
            'gates' => array_values($gates),
            'phases' => $phases,
        ];
        $evidenceIds = [];
        foreach ($evidence as $record) {
            if (is_string($record['id'] ?? null)) {
                $evidenceIds[$record['id']] = true;
            }
        }
        $errors = [];
        \validateLedger(
            dirname(__DIR__, 3),
            $ledger,
            [],
            ['programme-owner' => true],
            $evidenceIds,
            array_fill_keys(array_keys($gates), true),
            $workItems,
            $errors,
            $findings,
        );
        return $errors;
    }

    /**
     * Build one accepted evidence fixture.
     *
     * @param   string        $id        Evidence identifier.
     * @param   string        $type      Evidence type.
     * @param   list<string>  $supports  Work items and gates supported by the evidence.
     *
     * @return  array<string, mixed>  Complete evidence fixture.
     *
     * @since   2.0.0
     */
    private function evidence(string $id, string $type, array $supports): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'status' => 'accepted',
            'producer_role' => 'programme-owner',
            'source_revision' => str_repeat('a', 40),
            'environment' => 'Focused PHP unit-test fixture.',
            'method' => 'Focused verifier fixture',
            'result' => 'Accepted for the focused fixture.',
            'artifact_paths' => ['tools/verify-interface-programme.php'],
            'supports' => $supports,
        ];
    }

    /**
     * Build one work-item fixture in the requested state.
     *
     * @param   string  $id        Work-item identifier.
     * @param   string  $status    Current ledger status.
     * @param   string  $severity  P0-P3 finding severity.
     *
     * @return  array<string, mixed>  Complete work-item fixture.
     *
     * @since   2.0.0
     */
    private function workItem(
        string $id,
        string $status = 'planned',
        string $severity = 'P1',
    ): array {
        $history = [[
            'date' => '2026-08-11',
            'from' => null,
            'to' => 'planned',
            'reason' => 'Fixture created.',
            'evidence_ids' => [],
        ]];
        if ($status !== 'planned') {
            $history[] = [
                'date' => '2026-08-11',
                'from' => 'planned',
                'to' => $status,
                'reason' => 'Fixture transitioned.',
                'evidence_ids' => [],
            ];
        }
        return [
            'id' => $id,
            'owner_role' => 'programme-owner',
            'status' => $status,
            'severity' => $severity,
            'prerequisites' => [],
            'surface_ids' => [],
            'evidence_required' => ['source'],
            'evidence_ids' => [],
            'status_history' => $history,
        ];
    }

    /**
     * Build one gate fixture in the requested state.
     *
     * @param   string  $id      Gate identifier.
     * @param   string  $status  Current ledger status.
     *
     * @return  array<string, mixed>  Complete gate fixture.
     *
     * @since   2.0.0
     */
    private function gate(string $id, string $status): array
    {
        $history = [[
            'date' => '2026-08-11',
            'from' => null,
            'to' => 'planned',
            'reason' => 'Fixture created.',
            'evidence_ids' => [],
        ]];
        if ($status !== 'planned') {
            $history[] = [
                'date' => '2026-08-11',
                'from' => 'planned',
                'to' => $status,
                'reason' => 'Fixture transitioned.',
                'evidence_ids' => [],
            ];
        }
        return [
            'id' => $id,
            'owner_role' => 'programme-owner',
            'status' => $status,
            'prerequisites' => [],
            'required_evidence_types' => ['source'],
            'evidence_ids' => [],
            'status_history' => $history,
        ];
    }
}
