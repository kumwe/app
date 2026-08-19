<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Business;

use DateTimeImmutable;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalRequestView;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalStatus;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalVoteView;
use Kumwe\App\Delivery\Http\Api\Business\BusinessApprovalApiPresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessApprovalApiPresenter::class)]
/**
 * Proves approval REST projection omits identity, role, and integrity evidence.
 *
 * @since  2.0.0
 */
final class BusinessApprovalApiPresenterTest extends TestCase
{
    /**
     * Proves approval detail exposes decisions without actors, resources, or digests.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDetailOmitsActorRoleAndIntegrityEvidence(): void
    {
        $at = new DateTimeImmutable('2026-08-10T10:00:00+00:00');
        $approval = new ApprovalRequestView(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e21',
            'invoice.approve',
            2,
            'business.approval.approve',
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e22',
            true,
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb301',
            'business.record.action.approve_invoice',
            'business_record',
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb999:018f22e2-7c8b-7ab0-8f3a-88e8026bb998',
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
            new DateTimeImmutable('2026-08-11T10:00:00+00:00'),
            2,
            false,
            false,
            true,
            [new ApprovalVoteView(
                '0191574f-f0b8-7bf3-a9aa-91c6b8244e23',
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb302',
                'approve',
                'Checked.',
                $at,
            )],
        );

        $projected = (new BusinessApprovalApiPresenter())->detail($approval);
        $encoded = json_encode($projected, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('resource_id', $projected);
        self::assertSame('approve', $projected['votes'][0]['decision']);
        self::assertStringNotContainsString('requester', $encoded);
        self::assertStringNotContainsString('approver', $encoded);
        self::assertStringNotContainsString('018f22e2-7c8b-7ab0-8f3a-88e8026bb999', $encoded);
        self::assertStringNotContainsString('digest', $encoded);
        self::assertStringNotContainsString(str_repeat('a', 64), $encoded);
    }
}
