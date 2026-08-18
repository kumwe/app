<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessRecord\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Domain\PostingPeriod;
use Kumwe\CMS\BusinessRecord\Domain\PostingPeriodStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the posting-period value object: half-open containment, state moves, and construction rules.
 *
 * @since  2.0.0
 */
#[CoversClass(PostingPeriod::class)]
#[CoversClass(PostingPeriodStatus::class)]
final class PostingPeriodTest extends TestCase
{
    /**
     * Containment is half-open: the range start is inside, the range end is already outside.
     *
     * The half-open convention is what keeps adjacent period declarations gap-free, so both
     * boundaries are pinned exactly rather than approximately.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContainmentIsHalfOpenAndInstantBased(): void
    {
        $period = $this->period();

        self::assertTrue($period->contains(new DateTimeImmutable('2026-08-01T00:00:00Z')));
        self::assertTrue($period->contains(new DateTimeImmutable('2026-08-31T23:59:59Z')));
        self::assertFalse($period->contains(new DateTimeImmutable('2026-09-01T00:00:00Z')));
        self::assertFalse($period->contains(new DateTimeImmutable('2026-07-31T23:59:59Z')));
        // The same instant spelled in another zone classifies identically.
        self::assertTrue($period->contains(
            new DateTimeImmutable('2026-08-01T02:00:00', new DateTimeZone('Africa/Windhoek')),
        ));
    }

    /**
     * Boundaries authored in a non-UTC zone are normalised, so equal instants compare equal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBoundariesAreNormalisedToUtc(): void
    {
        $period = new PostingPeriod(
            'default',
            null,
            '2026-08',
            new DateTimeImmutable('2026-08-01T02:00:00', new DateTimeZone('Africa/Windhoek')),
            new DateTimeImmutable('2026-09-01T02:00:00', new DateTimeZone('Africa/Windhoek')),
            PostingPeriodStatus::Closed,
            'actor-1',
            new DateTimeImmutable('2026-09-05T08:00:00Z'),
        );

        self::assertSame('2026-08-01T00:00:00Z', $period->toArray()['starts_at']);
        self::assertSame('2026-09-01T00:00:00Z', $period->toArray()['ends_at']);
    }

    /**
     * Re-opening keeps the close bookkeeping; closing again replaces it and clears the re-open.
     *
     * The row holds current state only — the audit trail is the history — so each transition's
     * bookkeeping rules are part of the contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStateMovesKeepTheBookkeepingTheContractPromises(): void
    {
        $period = $this->period();
        self::assertTrue($period->isClosed());

        $reopened = $period->reopened('actor-2', new DateTimeImmutable('2026-09-10T09:00:00Z'));
        self::assertFalse($reopened->isClosed());
        self::assertSame(PostingPeriodStatus::Open, $reopened->status);
        self::assertSame('actor-1', $reopened->closedBy);
        self::assertSame('actor-2', $reopened->reopenedBy);
        self::assertSame('2026-09-10T09:00:00Z', $reopened->toArray()['reopened_at']);

        $closedAgain = $reopened->closed('actor-3', new DateTimeImmutable('2026-09-11T09:00:00Z'));
        self::assertTrue($closedAgain->isClosed());
        self::assertSame('actor-3', $closedAgain->closedBy);
        self::assertNull($closedAgain->reopenedBy);
        self::assertNull($closedAgain->reopenedAt);
    }

    /**
     * The rendered document carries the whole declaration under stable snake_case keys.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDocumentCarriesTheWholeDeclaration(): void
    {
        $document = $this->period('acme')->toArray();

        self::assertSame([
            'site' => 'default',
            'organization' => 'acme',
            'key' => '2026-08',
            'starts_at' => '2026-08-01T00:00:00Z',
            'ends_at' => '2026-09-01T00:00:00Z',
            'status' => 'closed',
            'closed_by' => 'actor-1',
            'closed_at' => '2026-09-05T08:00:00Z',
            'reopened_by' => null,
            'reopened_at' => null,
        ], $document);
    }

    /**
     * Construction refuses a declaration the lock could not evaluate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConstructionRefusesABrokenDeclaration(): void
    {
        $starts = new DateTimeImmutable('2026-08-01T00:00:00Z');
        $ends = new DateTimeImmutable('2026-09-01T00:00:00Z');
        $at = new DateTimeImmutable('2026-09-05T08:00:00Z');
        $cases = [
            'empty range' => static fn (): PostingPeriod => new PostingPeriod(
                'default',
                null,
                '2026-08',
                $starts,
                $starts,
                PostingPeriodStatus::Closed,
                'actor-1',
                $at,
            ),
            'inverted range' => static fn (): PostingPeriod => new PostingPeriod(
                'default',
                null,
                '2026-08',
                $ends,
                $starts,
                PostingPeriodStatus::Closed,
                'actor-1',
                $at,
            ),
            'malformed key' => static fn (): PostingPeriod => new PostingPeriod(
                'default',
                null,
                'no spaces allowed',
                $starts,
                $ends,
                PostingPeriodStatus::Closed,
                'actor-1',
                $at,
            ),
            'malformed site' => static fn (): PostingPeriod => new PostingPeriod(
                'Bad Site',
                null,
                '2026-08',
                $starts,
                $ends,
                PostingPeriodStatus::Closed,
                'actor-1',
                $at,
            ),
            'empty organization' => static fn (): PostingPeriod => new PostingPeriod(
                'default',
                '',
                '2026-08',
                $starts,
                $ends,
                PostingPeriodStatus::Closed,
                'actor-1',
                $at,
            ),
            'blank actor' => static fn (): PostingPeriod => new PostingPeriod(
                'default',
                null,
                '2026-08',
                $starts,
                $ends,
                PostingPeriodStatus::Closed,
                '',
                $at,
            ),
            'half re-open bookkeeping' => static fn (): PostingPeriod => new PostingPeriod(
                'default',
                null,
                '2026-08',
                $starts,
                $ends,
                PostingPeriodStatus::Open,
                'actor-1',
                $at,
                'actor-2',
                null,
            ),
        ];

        foreach ($cases as $name => $construct) {
            try {
                $construct();
                self::fail(sprintf('A declaration with %s must be refused.', $name));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    /**
     * Build the closed August fixture most cases start from.
     *
     * @param   ?string  $organization  Organization scope, or null for a site-wide declaration.
     *
     * @return  PostingPeriod  Closed period over August 2026.
     *
     * @since   2.0.0
     */
    private function period(?string $organization = null): PostingPeriod
    {
        return new PostingPeriod(
            'default',
            $organization,
            '2026-08',
            new DateTimeImmutable('2026-08-01T00:00:00Z'),
            new DateTimeImmutable('2026-09-01T00:00:00Z'),
            PostingPeriodStatus::Closed,
            'actor-1',
            new DateTimeImmutable('2026-09-05T08:00:00Z'),
        );
    }
}
