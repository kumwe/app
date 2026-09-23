<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSecurity\Application;

use DateTimeImmutable;
use Kumwe\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessRecord\Application\BusinessRecordView;
use Kumwe\App\BusinessRecord\Domain\BusinessRecord;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\BusinessPolicy\Application\FieldDisclosurePlan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the host projects a stored record through an empty package disclosure plan as no fields at all.
 *
 * The portable plan, digest and bound assertions belong to kumwe/business-policy; this suite keeps only the
 * host contract that an empty field set never means every field when a record view is built from it.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessRecordView::class)]
final class BusinessRecordAccessPlanTest extends TestCase
{
    /**
     * Proves a record view built over an empty disclosure plan exposes no stored values.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEmptyFieldSetsStayEmptyAndNeverMeanAllFields(): void
    {
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
            disclosure: new FieldDisclosurePlan(),
        )->values);
    }
}
