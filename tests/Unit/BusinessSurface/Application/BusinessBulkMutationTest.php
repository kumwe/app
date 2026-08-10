<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessSurface\Application;

use InvalidArgumentException;
use Kumwe\CMS\BusinessSurface\Application\BusinessBulkMutation;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurfaceOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessBulkMutation::class)]
/**
 * Proves bulk generated mutations are bounded, reviewed, and independently idempotent per record.
 *
 * @since  2.0.0
 */
final class BusinessBulkMutationTest extends TestCase
{
    /**
     * Proves per-record operation identities are stable regardless of selection order.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDerivesStablePerRecordOperationIdentitiesIndependentOfSelectionOrder(): void
    {
        $first = new BusinessBulkMutation(
            BusinessSurfaceOperation::Archive,
            [
                ['record_id' => 'record-a', 'expected_version' => 2],
                ['record_id' => 'record-b', 'expected_version' => 4],
            ],
            'browser:bulk-attempt-1',
        );
        $reordered = new BusinessBulkMutation(
            BusinessSurfaceOperation::Archive,
            [
                ['record_id' => 'record-b', 'expected_version' => 4],
                ['record_id' => 'record-a', 'expected_version' => 2],
            ],
            'browser:bulk-attempt-1',
        );

        self::assertMatchesRegularExpression('/^bulk:[a-f0-9]{64}$/D', $first->operationIdFor('record-a'));
        self::assertSame($first->operationIdFor('record-a'), $reordered->operationIdFor('record-a'));
        self::assertNotSame($first->operationIdFor('record-a'), $first->operationIdFor('record-b'));
    }

    /**
     * Proves a single bulk attempt cannot exceed fifty reviewed records.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsMoreThanFiftyRecords(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('1 to 50 records');

        new BusinessBulkMutation(
            BusinessSurfaceOperation::Restore,
            array_map(
                static fn (int $index): array => [
                    'record_id' => 'record-' . $index,
                    'expected_version' => 1,
                ],
                range(1, 51),
            ),
            'browser:bulk-attempt-2',
        );
    }

    /**
     * Proves every bulk member is unique and carries the version the user reviewed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsDuplicateRecordsAndMissingReviewedVersions(): void
    {
        try {
            new BusinessBulkMutation(
                BusinessSurfaceOperation::Archive,
                [
                    ['record_id' => 'record-a', 'expected_version' => 1],
                    ['record_id' => 'record-a', 'expected_version' => 2],
                ],
                'browser:bulk-attempt-3',
            );
            self::fail('A duplicate bulk record must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('duplicate record', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('malformed');
        new BusinessBulkMutation(
            BusinessSurfaceOperation::Archive,
            [['record_id' => 'record-a']],
            'browser:bulk-attempt-4',
        );
    }

    /**
     * Proves an action handle is required only for bulk action operations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAcceptsOnlyAnActionHandleForBulkActionOperations(): void
    {
        $action = new BusinessBulkMutation(
            BusinessSurfaceOperation::Action,
            [['record_id' => 'record-a', 'expected_version' => 1]],
            'browser:bulk-attempt-5',
            'approve',
        );

        self::assertSame('approve', $action->action);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('action handle');
        new BusinessBulkMutation(
            BusinessSurfaceOperation::Action,
            [['record_id' => 'record-a', 'expected_version' => 1]],
            'browser:bulk-attempt-6',
        );
    }
}
