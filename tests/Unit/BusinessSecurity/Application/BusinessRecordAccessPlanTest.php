<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSecurity\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessRecord\Application\BusinessRecordView;
use Kumwe\App\BusinessRecord\Domain\BusinessRecord;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessPlan;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldDisclosurePlan;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyConstant;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySchema;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessRecordAccessPlan::class)]
#[CoversClass(FieldDisclosurePlan::class)]
final class BusinessRecordAccessPlanTest extends TestCase
{
    public function testEmptyFieldSetsStayEmptyAndNeverMeanAllFields(): void
    {
        $fields = new FieldDisclosurePlan();

        self::assertSame([], $fields->fields(FieldAccessUsage::Detail));
        self::assertSame([], $fields->fields(FieldAccessUsage::List));
        self::assertFalse($fields->allows(FieldAccessUsage::Detail, 'name'));

        $record = new BusinessRecord(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e10',
            1,
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e11',
            'record-one',
            RecordScope::reconstitute(ScopeMode::Site, 'default', null),
            1,
            null,
            ['name' => 'Hidden'],
            'user:one',
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            'user:one',
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
        self::assertSame([], BusinessRecordView::fromRecord(
            $record,
            disclosure: $fields,
        )->values);
    }

    public function testDigestBindsDisclosureRelationAndAuthorizationFingerprint(): void
    {
        $records = new RecordPolicySet(new RecordPolicySchema([]), [new RecordPolicyConstant(true)]);
        $base = new BusinessRecordAccessPlan(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e10',
            'business.record.browse',
            $records,
            new FieldDisclosurePlan(['list' => ['name']]),
            str_repeat('a', 64),
        );
        $changedFields = new BusinessRecordAccessPlan(
            $base->resourceIdentifier,
            $base->operation,
            $records,
            new FieldDisclosurePlan(['list' => []]),
            str_repeat('a', 64),
        );
        $changedAuthorization = new BusinessRecordAccessPlan(
            $base->resourceIdentifier,
            $base->operation,
            $records,
            new FieldDisclosurePlan(['list' => ['name']]),
            str_repeat('b', 64),
        );

        self::assertNotSame($base->digest(), $changedFields->digest());
        self::assertNotSame($base->digest(), $changedAuthorization->digest());
    }

    public function testDurableDigestSeparatesCredentialIdentityAndRecursesThroughRelatedPlans(): void
    {
        $records = new RecordPolicySet(new RecordPolicySchema([]), [new RecordPolicyConstant(true)]);
        $firstChild = new BusinessRecordAccessPlan(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e11',
            'business.record.export',
            $records,
            new FieldDisclosurePlan(['export' => ['name']]),
            str_repeat('a', 64),
            durableAuthorizationFingerprint: str_repeat('d', 64),
        );
        $rehydratedChild = new BusinessRecordAccessPlan(
            $firstChild->resourceIdentifier,
            $firstChild->operation,
            $records,
            new FieldDisclosurePlan(['export' => ['name']]),
            str_repeat('b', 64),
            durableAuthorizationFingerprint: str_repeat('d', 64),
        );
        $changedAuthorityChild = new BusinessRecordAccessPlan(
            $firstChild->resourceIdentifier,
            $firstChild->operation,
            $records,
            new FieldDisclosurePlan(['export' => ['name']]),
            str_repeat('b', 64),
            durableAuthorizationFingerprint: str_repeat('e', 64),
        );
        $first = new BusinessRecordAccessPlan(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e10',
            'business.record.export',
            $records,
            new FieldDisclosurePlan(['export' => ['name']]),
            str_repeat('a', 64),
            ['customer' => $firstChild],
            durableAuthorizationFingerprint: str_repeat('d', 64),
        );
        $rehydrated = new BusinessRecordAccessPlan(
            $first->resourceIdentifier,
            $first->operation,
            $records,
            new FieldDisclosurePlan(['export' => ['name']]),
            str_repeat('b', 64),
            ['customer' => $rehydratedChild],
            durableAuthorizationFingerprint: str_repeat('d', 64),
        );
        $changedAuthority = new BusinessRecordAccessPlan(
            $first->resourceIdentifier,
            $first->operation,
            $records,
            new FieldDisclosurePlan(['export' => ['name']]),
            str_repeat('b', 64),
            ['customer' => $changedAuthorityChild],
            durableAuthorizationFingerprint: str_repeat('d', 64),
        );

        self::assertNotSame($first->digest(), $rehydrated->digest());
        self::assertSame($first->durableDigest(), $rehydrated->durableDigest());
        self::assertNotSame($first->durableDigest(), $changedAuthority->durableDigest());
    }

    public function testDurableDigestDefaultsToTheStrictDigest(): void
    {
        $plan = new BusinessRecordAccessPlan(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e10',
            'business.record.export',
            new RecordPolicySet(new RecordPolicySchema([]), [new RecordPolicyConstant(true)]),
            new FieldDisclosurePlan(['export' => ['name']]),
            str_repeat('a', 64),
        );

        self::assertSame($plan->digest(), $plan->durableDigest());
        self::assertSame(str_repeat('a', 64), $plan->toArray()['authorization']);
    }

    public function testDurableDigestRejectsAnInvalidAuthorizationFingerprint(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BusinessRecordAccessPlan(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e10',
            'business.record.export',
            new RecordPolicySet(new RecordPolicySchema([]), [new RecordPolicyConstant(true)]),
            new FieldDisclosurePlan(),
            str_repeat('a', 64),
            durableAuthorizationFingerprint: 'invalid',
        );
    }

    public function testDisclosureBoundSupportsEveryFieldAtEveryUsage(): void
    {
        $handles = [];
        for ($index = 0; $index < 256; ++$index) {
            $handles[] = 'field_' . $index;
        }
        $allowed = [];
        foreach (FieldAccessUsage::cases() as $usage) {
            $allowed[$usage->value] = $handles;
        }

        $fields = new FieldDisclosurePlan($allowed);

        foreach (FieldAccessUsage::cases() as $usage) {
            self::assertCount(256, $fields->fields($usage));
        }
    }

    public function testRelatedPlanBoundMatchesTheMaximumDefinitionTargetCount(): void
    {
        $records = new RecordPolicySet(new RecordPolicySchema([]), [new RecordPolicyConstant(true)]);
        $leaf = new BusinessRecordAccessPlan(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e11',
            'business.record.browse',
            $records,
            new FieldDisclosurePlan(),
            str_repeat('a', 64),
        );
        $related = [];
        for ($index = 0; $index < 384; ++$index) {
            $related['related_' . $index] = $leaf;
        }

        $plan = new BusinessRecordAccessPlan(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e10',
            'business.record.browse',
            $records,
            new FieldDisclosurePlan(),
            str_repeat('a', 64),
            $related,
        );
        self::assertSame($leaf, $plan->related('related_383'));

        $related['related_384'] = $leaf;
        $this->expectException(InvalidArgumentException::class);
        new BusinessRecordAccessPlan(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e10',
            'business.record.browse',
            $records,
            new FieldDisclosurePlan(),
            str_repeat('a', 64),
            $related,
        );
    }
}
