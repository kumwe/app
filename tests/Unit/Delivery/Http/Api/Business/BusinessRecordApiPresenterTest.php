<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Business;

use DateTimeImmutable;
use Kumwe\App\BusinessRecord\Application\BusinessRecordRelationView;
use Kumwe\App\BusinessRecord\Application\BusinessRecordRevisionView;
use Kumwe\App\BusinessRecord\Application\BusinessRecordView;
use Kumwe\App\BusinessRecord\Application\RecordHistoryResult;
use Kumwe\App\BusinessRecord\Application\RecordMutationResult;
use Kumwe\App\BusinessSurface\Application\BusinessRecordProjector;
use Kumwe\App\Delivery\Http\Api\Business\BusinessRecordApiPresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessRecordApiPresenter::class)]
/**
 * Proves REST presentation exposes only safe generated-business record evidence.
 *
 * @since  2.0.0
 */
final class BusinessRecordApiPresenterTest extends TestCase
{
    /**
     * Internal definition identity used to prove it is withheld.
     *
     * @var    string
     * @since  2.0.0
     */
    private const DEFINITION = '018f22e2-7c8b-7ab0-8f3a-88e8026bb501';

    /**
     * Internal source storage identity used to prove it is withheld.
     *
     * @var    string
     * @since  2.0.0
     */
    private const INTERNAL_RECORD_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb502';

    /**
     * Internal related storage identity used to prove it is withheld.
     *
     * @var    string
     * @since  2.0.0
     */
    private const INTERNAL_RELATED_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb503';

    /**
     * Internal actor identity used to prove it is withheld.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ACTOR = '018f22e2-7c8b-7ab0-8f3a-88e8026bb504';

    /**
     * Proves record output omits internal metadata while preserving exact nested JSON recursively.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOmitsStorageActorScopeAndPreservesExactNestedJsonFromARecord(): void
    {
        $record = new BusinessRecordView(
            self::DEFINITION,
            4,
            self::INTERNAL_RECORD_ID,
            'INV-0001',
            7,
            'default',
            'acme',
            'draft',
            [
                'amount' => '1234567890.123400',
                'metadata' => ['visible' => 'yes', 'withheld' => ['redacted' => true]],
                'exact_json' => ['redacted' => true],
            ],
            self::ACTOR,
            new DateTimeImmutable('2026-08-09T08:00:00.123456+00:00'),
            self::ACTOR,
            new DateTimeImmutable('2026-08-09T09:00:00.654321+00:00'),
            null,
            null,
            null,
            null,
            ['customer' => [new BusinessRecordRelationView(
                self::DEFINITION,
                2,
                self::INTERNAL_RELATED_ID,
                'CUS-0042',
                3,
                null,
                ['name' => 'Acme', 'exact_json' => ['redacted' => true]],
            )]],
        );

        $presented = $this->presenter()->record($record);
        $encoded = json_encode($presented, JSON_THROW_ON_ERROR);

        self::assertSame('1234567890.123400', $presented['values']['amount']);
        self::assertSame(
            ['visible' => 'yes', 'withheld' => ['redacted' => true]],
            $presented['values']['metadata'],
        );
        self::assertSame(['redacted' => true], $presented['values']['exact_json']);
        self::assertSame(
            ['name' => 'Acme', 'exact_json' => ['redacted' => true]],
            $presented['includes']['customer'][0]['values'],
        );
        self::assertStringNotContainsString(self::INTERNAL_RECORD_ID, $encoded);
        self::assertStringNotContainsString(self::INTERNAL_RELATED_ID, $encoded);
        self::assertStringNotContainsString(self::ACTOR, $encoded);
        self::assertStringNotContainsString(self::DEFINITION, $encoded);
        self::assertStringNotContainsString('organization', $encoded);
        self::assertSame('2026-08-09T08:00:00+00:00', $presented['created_at']);
    }

    /**
     * Proves mutation output exposes the public identity without internal ledger keys.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOmitsInternalLedgerKeysFromMutationResults(): void
    {
        $presented = $this->presenter()->mutation(new RecordMutationResult(
            self::DEFINITION,
            4,
            self::INTERNAL_RECORD_ID,
            'INV-0001',
            8,
            'approved',
            'action',
            replayed: true,
        ));

        self::assertSame('INV-0001', $presented['record_id']);
        self::assertSame(8, $presented['version']);
        self::assertTrue($presented['replayed']);
        self::assertArrayNotHasKey('definition_id', $presented);
        self::assertArrayNotHasKey('record_key', $presented);
    }

    /**
     * Proves history output removes withheld fields and internal revision integrity evidence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHistoryOmitsWithheldHandlesAndInternalRevisionEvidence(): void
    {
        $revision = new BusinessRecordRevisionView(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb505',
            self::DEFINITION,
            3,
            self::INTERNAL_RECORD_ID,
            6,
            6,
            'update',
            ['amount' => '99.00'],
            ['amount', 'secret_margin'],
            self::ACTOR,
            new DateTimeImmutable('2026-08-08T10:00:00.000001+00:00'),
            str_repeat('a', 64),
        );

        $presented = $this->presenter()->history(new RecordHistoryResult([$revision], true));
        $encoded = json_encode($presented, JSON_THROW_ON_ERROR);

        self::assertSame(['amount' => '99.00'], $presented['items'][0]['snapshot']);
        self::assertSame(['amount'], $presented['items'][0]['changed_fields']);
        self::assertSame(6, $presented['next_before_version']);
        self::assertStringNotContainsString('secret_margin', $encoded);
        self::assertStringNotContainsString(self::INTERNAL_RECORD_ID, $encoded);
        self::assertStringNotContainsString(self::ACTOR, $encoded);
        self::assertStringNotContainsString('integrity', $encoded);
        self::assertStringNotContainsString('revision_id', $encoded);
    }

    /**
     * Proves custom contract projection does not confuse an ordinary JSON object with disclosure metadata.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCustomDocumentPreservesOrdinaryRedactedShapedJsonValues(): void
    {
        $document = [
            'result' => [
                'exact_json' => ['redacted' => true],
                'items' => [['redacted' => true], ['value' => 'visible']],
            ],
        ];

        self::assertSame([
            'result' => [
                'exact_json' => ['redacted' => true],
                'items' => [['redacted' => true], ['value' => 'visible']],
            ],
        ], $this->presenter()->document($document));
    }

    /**
     * Construct the presenter with the shared safe record projector.
     *
     * @return  BusinessRecordApiPresenter  Presenter under test.
     *
     * @since   2.0.0
     */
    private function presenter(): BusinessRecordApiPresenter
    {
        return new BusinessRecordApiPresenter(new BusinessRecordProjector());
    }
}
