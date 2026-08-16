<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Http\Dashboard;

use InvalidArgumentException;
use Kumwe\CMS\Application\Presentation\Dashboard\DashboardPreferenceQuery;
use Kumwe\CMS\Delivery\Http\Dashboard\DashboardPreferenceQueryDecoder;
use Kumwe\CMS\InterfaceStandard\SurfaceArea;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies defensive GET normalization and fixed same-area dashboard continuation links.
 *
 * @since  2.0.0
 */
#[CoversClass(DashboardPreferenceQuery::class)]
#[CoversClass(DashboardPreferenceQueryDecoder::class)]
final class DashboardPreferenceQueryDecoderTest extends TestCase
{
    /**
     * Proves page and search normalize once before entering application code.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDecodesNormalizedPageAndSearch(): void
    {
        $query = (new DashboardPreferenceQueryDecoder())->decode([
            'dashboard_group_page' => '65',
            'dashboard_group_search' => "  Finance\n reviewers  ",
            'dashboard_workflow_page' => '16',
            'dashboard_workflow_search' => "  Sales\n orders  ",
        ]);

        self::assertSame(65, $query->page);
        self::assertSame('Finance reviewers', $query->search);
        self::assertSame(64, $query->previous()->page);
        self::assertSame(66, $query->next()->page);
        self::assertSame(16, $query->workflowPage);
        self::assertSame('Sales orders', $query->workflowSearch);
        self::assertSame(15, $query->workflowPrevious()->workflowPage);
        self::assertSame(17, $query->workflowNext()->workflowPage);
        self::assertSame(65, $query->workflowNext()->page);
    }

    /**
     * Proves edge Unicode whitespace normalizes away instead of refusing the whole request query.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEdgeUnicodeWhitespaceNormalizesInsteadOfRefusingTheQuery(): void
    {
        $query = (new DashboardPreferenceQueryDecoder())->decode([
            'dashboard_group_search' => "\u{00A0}Finance reviewers\u{3000}",
            'dashboard_workflow_search' => "\u{0085}Sales\u{00A0}orders\u{00A0}",
        ]);

        self::assertSame('Finance reviewers', $query->search);
        self::assertSame('Sales orders', $query->workflowSearch);
    }

    /**
     * Proves nested, ambiguous, overlong, and computationally excessive values use neutral bounded defaults.
     *
     * @param   mixed  $page    Candidate untrusted page.
     * @param   mixed  $search  Candidate untrusted search.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('malformedQueries')]
    public function testMalformedAndHugeValuesFallBackToNeutralState(mixed $page, mixed $search): void
    {
        $query = (new DashboardPreferenceQueryDecoder())->decode([
            'dashboard_group_page' => $page,
            'dashboard_group_search' => $search,
        ]);

        self::assertSame(1, $query->page);
        self::assertSame('', $query->search);
    }

    /**
     * Supply query-string shapes that must never reach storage.
     *
     * @return  iterable<string, array{mixed, mixed}>  Malformed page and search pairs.
     *
     * @since   2.0.0
     */
    public static function malformedQueries(): iterable
    {
        yield 'nested' => [['2'], ['finance']];
        yield 'leading zero' => ['02', str_repeat('a', 65)];
        yield 'huge page' => ['10001', "finance\0reviewers"];
        yield 'integer page' => [2, new \stdClass()];
    }

    /**
     * Proves continuation URLs cannot carry an untrusted return destination or unvalidated fields.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBuildsOnlyFixedSameAreaEncodedContinuationUrls(): void
    {
        $decoder = new DashboardPreferenceQueryDecoder();
        $query = $decoder->decode([
            'dashboard_group_page' => '3',
            'dashboard_group_search' => 'Finance & 100%',
            'dashboard_workflow_page' => '16',
            'dashboard_workflow_search' => '2acme.sales__orders',
            'return' => 'https://attacker.example/',
        ]);

        self::assertSame(
            '/administrator/dashboard/preferences?dashboard_group_search=Finance%20%26%20100%25'
                . '&dashboard_group_page=3&dashboard_workflow_search=2acme.sales__orders'
                . '&dashboard_workflow_page=16',
            $decoder->mutationAction(SurfaceArea::Administrator, $query),
        );
        self::assertSame(
            '/portal?dashboard_group_search=Finance%20%26%20100%25&dashboard_group_page=3'
                . '&dashboard_workflow_search=2acme.sales__orders&dashboard_workflow_page=16'
                . '&dashboard-error=invalid#dashboard-customization',
            $decoder->errorHref(SurfaceArea::Portal, $query, 'invalid'),
        );
        self::assertStringNotContainsString('attacker', $decoder->successHref(SurfaceArea::Portal, $query));
    }

    /**
     * Proves typed callers cannot bypass the page and normalized-search bounds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTypedQueryRejectsPageBeyondThePracticalBound(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DashboardPreferenceQuery(DashboardPreferenceQuery::MAXIMUM_PAGE + 1);
    }

    /**
     * Proves neither independent browser can construct a continuation beyond its numeric browse window.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTypedQueryRejectsContinuationBeyondEachPracticalBound(): void
    {
        try {
            (new DashboardPreferenceQuery(DashboardPreferenceQuery::MAXIMUM_PAGE))->next();
            self::fail('The final access-group page must not produce another numeric page.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('outside the supported range', $exception->getMessage());
        }

        try {
            (new DashboardPreferenceQuery(
                workflowPage: DashboardPreferenceQuery::MAXIMUM_PAGE,
            ))->workflowNext();
            self::fail('The final workflow page must not produce another numeric page.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('outside the supported range', $exception->getMessage());
        }
    }

    /**
     * Proves application callers cannot bypass delivery's normalized-search contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTypedQueryRejectsNonNormalizedSearch(): void
    {
        try {
            new DashboardPreferenceQuery(search: ' Finance reviewers');
            self::fail('A non-normalized application search was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('search must be normalized', $exception->getMessage());
        }
    }

    /**
     * Proves clearing either browser cannot erase the independently validated other browser state.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testClearsEachBrowserIndependently(): void
    {
        $query = new DashboardPreferenceQuery(3, 'Finance', 16, 'sales.orders');

        self::assertSame([1, '', 16, 'sales.orders'], [
            $query->withoutAccessGroupBrowser()->page,
            $query->withoutAccessGroupBrowser()->search,
            $query->withoutAccessGroupBrowser()->workflowPage,
            $query->withoutAccessGroupBrowser()->workflowSearch,
        ]);
        self::assertSame([3, 'Finance', 1, ''], [
            $query->withoutWorkflowBrowser()->page,
            $query->withoutWorkflowBrowser()->search,
            $query->withoutWorkflowBrowser()->workflowPage,
            $query->withoutWorkflowBrowser()->workflowSearch,
        ]);
    }

    /**
     * Proves malformed workflow state falls back independently and its full identifier ceiling is accepted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNormalizesWorkflowStateIndependently(): void
    {
        $decoder = new DashboardPreferenceQueryDecoder();
        $malformed = $decoder->decode([
            'dashboard_group_page' => '3',
            'dashboard_group_search' => 'Finance',
            'dashboard_workflow_page' => '101',
            'dashboard_workflow_search' => str_repeat('a', 192),
        ]);

        self::assertSame(3, $malformed->page);
        self::assertSame('Finance', $malformed->search);
        self::assertSame(1, $malformed->workflowPage);
        self::assertSame('', $malformed->workflowSearch);

        $long = str_repeat('a', DashboardPreferenceQuery::MAXIMUM_WORKFLOW_SEARCH_LENGTH);
        self::assertSame($long, $decoder->decode([
            'dashboard_workflow_search' => $long,
        ])->workflowSearch);
    }
}
