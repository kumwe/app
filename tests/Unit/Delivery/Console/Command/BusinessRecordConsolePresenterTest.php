<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Console\Command;

use DateTimeImmutable;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordRelationView;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordRevisionView;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordView;
use Kumwe\CMS\BusinessRecord\Application\RecordBrowseResult;
use Kumwe\CMS\BusinessRecord\Application\RecordHistoryResult;
use Kumwe\CMS\BusinessRecord\Application\RecordMutationResult;
use Kumwe\CMS\BusinessSurface\Application\BusinessRecordProjector;
use Kumwe\CMS\BusinessSecurity\Application\Approval\ApprovalRequestView;
use Kumwe\CMS\BusinessSecurity\Application\Approval\ApprovalStatus;
use Kumwe\CMS\BusinessSecurity\Application\Approval\ApprovalVoteView;
use Kumwe\CMS\Delivery\Console\Command\BusinessConsoleFailure;
use Kumwe\CMS\Delivery\Console\Command\BusinessRecordConsolePresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessRecordConsolePresenter::class)]
#[CoversClass(BusinessRecordProjector::class)]
/**
 * Proves CLI presentation reuses safe generated-business projections and stable envelopes.
 *
 * @since  2.0.0
 */
final class BusinessRecordConsolePresenterTest extends TestCase
{
    /**
     * Internal definition identity used to prove it is withheld.
     *
     * @var    string
     * @since  2.0.0
     */
    private const DEFINITION = '0191574f-f0b8-7bf3-a9aa-91c6b8244e10';

    /**
     * Internal storage identity used to prove it is withheld.
     *
     * @var    string
     * @since  2.0.0
     */
    private const INTERNAL_RECORD_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8244e11';

    /**
     * Internal actor identity used to prove it is withheld.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ACTOR = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    /**
     * Proves success, failure, and approval-request envelopes are stable and machine readable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSuccessFailureAndApprovalEnvelopesAreStable(): void
    {
        $presenter = new BusinessRecordConsolePresenter();

        self::assertSame([
            'ok' => true,
            'data' => ['amount' => '99999999999999999999.001200'],
            'meta' => ['action' => 'get', 'surface' => 'cli'],
        ], $presenter->success('get', ['amount' => '99999999999999999999.001200']));
        self::assertSame([
            'ok' => false,
            'error' => ['code' => 'record.conflict', 'message' => 'The record changed.'],
        ], $presenter->failure(new BusinessConsoleFailure(73, 'record.conflict', 'The record changed.')));
        self::assertSame([
            'approval_required' => true,
            'approval_request_id' => '0191574f-f0b8-7bf3-a9aa-91c6b8244e99',
        ], $presenter->approvalRequest('0191574f-f0b8-7bf3-a9aa-91c6b8244e99'));
        self::assertSame([
            'approval_required' => false,
            'approval_request_id' => null,
        ], $presenter->approvalRequest(null));
    }

    /**
     * Proves record projection withholds internal keys and preserves exact ordinary JSON values.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSharedProjectionOmitsInternalKeysAndPreservesExactJson(): void
    {
        $at = new DateTimeImmutable('2026-08-09T10:11:12+00:00');
        $relation = new BusinessRecordRelationView(
            self::DEFINITION,
            1,
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e12',
            'line-1',
            2,
            0,
            ['amount' => '10000000000000000000.001200', 'exact_json' => ['redacted' => true]],
        );
        $record = new BusinessRecordView(
            self::DEFINITION,
            1,
            self::INTERNAL_RECORD_ID,
            'invoice-7',
            7,
            'default',
            'acme',
            'approved',
            ['amount' => '99999999999999999999.001200', 'exact_json' => ['redacted' => true]],
            'actor-1',
            $at,
            'actor-2',
            $at,
            null,
            null,
            null,
            null,
            ['lines' => [$relation]],
        );

        $presented = (new BusinessRecordProjector())->record($record);
        $encoded = json_encode($presented, JSON_THROW_ON_ERROR);

        self::assertSame('99999999999999999999.001200', $presented['values']['amount']);
        self::assertSame(['redacted' => true], $presented['values']['exact_json']);
        self::assertSame(['redacted' => true], $presented['includes']['lines'][0]['values']['exact_json']);
        self::assertStringNotContainsString('record_key', $encoded);
        self::assertStringNotContainsString('definition_id', $encoded);
        self::assertStringNotContainsString(self::INTERNAL_RECORD_ID, $encoded);
    }

    /**
     * Proves browse, mutation, and history projections retain exact values without leaking evidence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSharedBrowseMutationAndHistoryProjectionPreservesExactValuesSafely(): void
    {
        $projector = new BusinessRecordProjector();
        $record = $this->record(['quantity' => ['amount' => '0.000000000000000001', 'unit' => 'kg']]);
        $browse = $projector->browse(new RecordBrowseResult(
            [$record],
            null,
            ['exact_total' => '12345678901234567890.000000000000000001'],
        ));
        $mutation = $projector->mutation(new RecordMutationResult(
            self::DEFINITION,
            1,
            self::INTERNAL_RECORD_ID,
            'invoice-1',
            8,
            'approved',
            'update',
            false,
            true,
        ));
        $revision = new BusinessRecordRevisionView(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e13',
            self::DEFINITION,
            1,
            self::INTERNAL_RECORD_ID,
            4,
            4,
            'update',
            ['name' => 'Invoice', 'exact_json' => ['redacted' => true]],
            ['name', 'exact_json'],
            'actor-1',
            new DateTimeImmutable('2026-08-09T10:11:12+00:00'),
            str_repeat('a', 64),
        );
        $history = $projector->history(new RecordHistoryResult([$revision], false));
        $encoded = json_encode([$browse, $mutation, $history], JSON_THROW_ON_ERROR);

        self::assertSame('0.000000000000000001', $browse['items'][0]['values']['quantity']['amount']);
        self::assertSame(
            '12345678901234567890.000000000000000001',
            $browse['aggregates']['exact_total'],
        );
        self::assertArrayNotHasKey('record_key', $mutation);
        self::assertTrue($mutation['replayed']);
        self::assertSame(
            ['name' => 'Invoice', 'exact_json' => ['redacted' => true]],
            $history['items'][0]['snapshot'],
        );
        self::assertSame(['name', 'exact_json'], $history['items'][0]['changed_fields']);
        self::assertNull($history['next_before_version']);
        self::assertStringNotContainsString(self::INTERNAL_RECORD_ID, $encoded);
    }

    /**
     * Proves approval lists stay summarized while detail output uses the safe shared projection.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testApprovalInboxIsBoundedToSummariesAndDetailUsesTheSafeProjection(): void
    {
        $at = new DateTimeImmutable('2026-08-09T10:11:12+00:00');
        $vote = new ApprovalVoteView(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e20',
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb302',
            'approve',
            'Checked against the invoice.',
            $at,
        );
        $approval = new ApprovalRequestView(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e21',
            'invoice.approve',
            2,
            'business.approval.approve',
            null,
            true,
            self::ACTOR,
            'business.record.action.approve_invoice',
            'business_record',
            'invoice-1',
            7,
            'default',
            'acme',
            null,
            str_repeat('a', 64),
            str_repeat('b', 64),
            1,
            1,
            ApprovalStatus::Approved,
            $at,
            new DateTimeImmutable('2026-08-09T10:26:12+00:00'),
            2,
            false,
            true,
            false,
            [$vote],
        );
        $presenter = new BusinessRecordConsolePresenter();
        $inbox = $presenter->approvalInbox([$approval]);
        $detail = $presenter->approvalDetail($approval);

        self::assertArrayNotHasKey('resource_id', $inbox['items'][0]);
        self::assertArrayNotHasKey('votes', $inbox['items'][0]);
        self::assertArrayNotHasKey('payload_digest', $inbox['items'][0]);
        self::assertSame('approved', $detail['status']);
        self::assertSame('approve', $detail['votes'][0]['decision']);
        self::assertArrayNotHasKey('approver_id', $detail['votes'][0]);
        self::assertArrayNotHasKey('binding_digest', $detail);
        $encoded = json_encode([$inbox, $detail], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('redacted', $encoded);
        self::assertStringNotContainsString(self::INTERNAL_RECORD_ID, $encoded);
        self::assertStringNotContainsString(self::ACTOR, $encoded);
    }

    /**
     * Build one record view with caller-selected visible values.
     *
     * @param   array<string, mixed>  $values  Values the projector may expose.
     *
     * @return  BusinessRecordView  Record fixture carrying internal evidence.
     *
     * @since   2.0.0
     */
    private function record(array $values): BusinessRecordView
    {
        $at = new DateTimeImmutable('2026-08-09T10:11:12+00:00');

        return new BusinessRecordView(
            self::DEFINITION,
            1,
            self::INTERNAL_RECORD_ID,
            'invoice-1',
            7,
            'default',
            'acme',
            null,
            $values,
            'actor-1',
            $at,
            'actor-1',
            $at,
            null,
            null,
            null,
            null,
        );
    }
}
