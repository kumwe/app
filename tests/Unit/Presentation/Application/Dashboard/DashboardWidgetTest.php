<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Presentation\Application\Dashboard;

use InvalidArgumentException;
use Kumwe\App\Presentation\Application\Dashboard\DashboardWidget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the bounded markup-free dashboard widget contract.
 *
 * @since  2.0.0
 */
#[CoversClass(DashboardWidget::class)]
final class DashboardWidgetTest extends TestCase
{
    /**
     * Proves every core or workflow widget uses the canonical dotted surface grammar.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsNonDottedWidgetIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lowercase dotted name');
        new DashboardWidget(
            'content-summary',
            DashboardWidget::KIND_SUMMARY,
            'dashboard.content.title',
            'dashboard.content.description',
        );
    }

    /**
     * Proves translated core widget metadata and documented data labels require message identifiers.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTranslatedCoreWidgetRequiresMessageIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('message identifier');
        new DashboardWidget(
            'core.dashboard.content-summary',
            DashboardWidget::KIND_SUMMARY,
            'Visible title',
            'dashboard.content.description',
            data: [],
        );
    }

    /**
     * Proves the repository's lower-case dotted message IDs may retain semantic underscores.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTranslatedCoreWidgetAcceptsDottedMessageIdentifiersWithUnderscores(): void
    {
        $widget = new DashboardWidget(
            'core.dashboard.content-summary',
            DashboardWidget::KIND_SUMMARY,
            'core.administrator.dashboard.content_summary.title',
            'core.administrator.dashboard.content_summary.description',
            data: [
                'metrics' => [[
                    'label' => 'core.administrator.dashboard.total_content',
                    'value' => 12,
                ]],
            ],
        );

        self::assertTrue($widget->messageIds);
    }

    /**
     * Proves every accepted data-widget shape supplies every field strict Twig reads.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAcceptsCompleteStrictTemplateDataShapes(): void
    {
        $widgets = [
            new DashboardWidget(
                'core.dashboard.summary',
                DashboardWidget::KIND_SUMMARY,
                'core.dashboard.summary.title',
                'core.dashboard.summary.description',
                data: [
                    'search' => [
                        'action' => '/administrator/content',
                        'label' => 'core.dashboard.search.label',
                        'placeholder' => 'core.dashboard.search.placeholder',
                        'button' => 'core.dashboard.search.button',
                    ],
                    'metrics' => [[
                        'label' => 'core.dashboard.metric.label',
                        'value' => 12,
                        'tone' => 'success',
                        'parameters' => ['count' => 12],
                    ]],
                    'progress' => [
                        'value' => 50.5,
                        'label' => 'core.dashboard.progress.label',
                        'parameters' => ['percent' => 50.5],
                        'help' => 'core.dashboard.progress.help',
                        'help_parameters' => ['count' => 12],
                    ],
                ],
            ),
            new DashboardWidget(
                'core.dashboard.activity',
                DashboardWidget::KIND_ACTIVITY,
                'core.dashboard.activity.title',
                'core.dashboard.activity.description',
                data: [
                    'items' => [[
                        'title' => 'Quarterly report',
                        'detail' => '2026-08-15T10:00:00+00:00',
                        'detail_label' => 'core.dashboard.activity.updated_at',
                        'detail_parameters' => ['at' => 1_776_247_200],
                        'status' => 'review',
                        'status_label' => 'core.dashboard.activity.status_review',
                        'status_tone' => 'warning',
                        'href' => '/administrator/reports/quarterly',
                        'action_label' => 'core.dashboard.activity.open',
                    ]],
                    'empty_title' => 'core.dashboard.activity.empty_title',
                    'empty_message' => 'core.dashboard.activity.empty_message',
                    'action' => [
                        'href' => '/administrator/reports',
                        'label' => 'core.dashboard.activity.all',
                    ],
                ],
            ),
            new DashboardWidget(
                'core.dashboard.context',
                DashboardWidget::KIND_CONTEXT,
                'core.dashboard.context.title',
                'core.dashboard.context.description',
                data: ['items' => [[
                    'label' => 'core.dashboard.context.site',
                    'value' => 'default',
                ]]],
            ),
        ];

        self::assertSame(['summary', 'activity', 'context'], array_column(
            array_map(static fn (DashboardWidget $widget): array => $widget->toArray(), $widgets),
            'kind',
        ));
    }

    /**
     * Proves no successfully constructed widget can omit or mistype data read by strict Twig.
     *
     * @param   string                $kind  Candidate widget kind.
     * @param   array<string, mixed>  $data  Candidate semantic payload.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('invalidStrictTemplateData')]
    public function testRejectsIncompleteStrictTemplateData(string $kind, array $data): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DashboardWidget(
            'core.dashboard.contract-test',
            $kind,
            'core.dashboard.contract_test.title',
            'core.dashboard.contract_test.description',
            data: $data,
            href: $kind === DashboardWidget::KIND_WORKFLOW ? '/administrator/content' : null,
        );
    }

    /**
     * Supply malformed shapes spanning lists, numbers, statuses, actions, and required fields.
     *
     * @return  array<string, array{0: string, 1: array<string, mixed>}>  Invalid semantic widget cases.
     *
     * @since   2.0.0
     */
    public static function invalidStrictTemplateData(): array
    {
        return [
            'search missing button' => [DashboardWidget::KIND_SUMMARY, [
                'search' => [
                    'action' => '/administrator/content',
                    'label' => 'core.dashboard.search.label',
                    'placeholder' => 'core.dashboard.search.placeholder',
                ],
            ]],
            'metrics must be a list' => [DashboardWidget::KIND_SUMMARY, [
                'metrics' => ['label' => 'core.dashboard.metric.label', 'value' => 12],
            ]],
            'metric must be numeric' => [DashboardWidget::KIND_SUMMARY, [
                'metrics' => [['label' => 'core.dashboard.metric.label', 'value' => 'twelve']],
            ]],
            'progress must be a percentage' => [DashboardWidget::KIND_SUMMARY, [
                'progress' => ['value' => 101, 'label' => 'core.dashboard.progress.label'],
            ]],
            'progress cannot inject inline style' => [DashboardWidget::KIND_SUMMARY, [
                'progress' => [
                    'value' => '50;--unsafe:1',
                    'label' => 'core.dashboard.progress.label',
                ],
            ]],
            'tone is closed' => [DashboardWidget::KIND_SUMMARY, [
                'metrics' => [[
                    'label' => 'core.dashboard.metric.label',
                    'value' => 12,
                    'tone' => 'flashing',
                ]],
            ]],
            'activity needs empty state' => [DashboardWidget::KIND_ACTIVITY, ['items' => []]],
            'activity item needs title' => [DashboardWidget::KIND_ACTIVITY, [
                'items' => [['status' => 'review']],
                'empty_title' => 'core.dashboard.activity.empty_title',
                'empty_message' => 'core.dashboard.activity.empty_message',
            ]],
            'activity href needs label' => [DashboardWidget::KIND_ACTIVITY, [
                'items' => [['title' => 'Report', 'href' => '/administrator/reports/quarterly']],
                'empty_title' => 'core.dashboard.activity.empty_title',
                'empty_message' => 'core.dashboard.activity.empty_message',
            ]],
            'activity status is bounded' => [DashboardWidget::KIND_ACTIVITY, [
                'items' => [[
                    'title' => 'Report',
                    'status' => 'Review now',
                    'status_label' => 'core.dashboard.activity.status_review',
                ]],
                'empty_title' => 'core.dashboard.activity.empty_title',
                'empty_message' => 'core.dashboard.activity.empty_message',
            ]],
            'activity status needs display label' => [DashboardWidget::KIND_ACTIVITY, [
                'items' => [['title' => 'Report', 'status' => 'review']],
                'empty_title' => 'core.dashboard.activity.empty_title',
                'empty_message' => 'core.dashboard.activity.empty_message',
            ]],
            'activity detail parameters need label' => [DashboardWidget::KIND_ACTIVITY, [
                'items' => [[
                    'title' => 'Report',
                    'detail' => '2026-08-15T10:00:00+00:00',
                    'detail_parameters' => ['at' => '2026-08-15 10:00'],
                ]],
                'empty_title' => 'core.dashboard.activity.empty_title',
                'empty_message' => 'core.dashboard.activity.empty_message',
            ]],
            'activity action needs href' => [DashboardWidget::KIND_ACTIVITY, [
                'items' => [],
                'empty_title' => 'core.dashboard.activity.empty_title',
                'empty_message' => 'core.dashboard.activity.empty_message',
                'action' => ['label' => 'core.dashboard.activity.all'],
            ]],
            'context needs items' => [DashboardWidget::KIND_CONTEXT, []],
            'context item needs value' => [DashboardWidget::KIND_CONTEXT, [
                'items' => [['label' => 'core.dashboard.context.site']],
            ]],
            'workflow data stays empty' => [DashboardWidget::KIND_WORKFLOW, ['items' => []]],
            'unknown summary field' => [DashboardWidget::KIND_SUMMARY, ['html' => '<p>Unsafe</p>']],
        ];
    }

    /**
     * Proves underscores cannot replace a semantic segment's required leading character.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTranslatedCoreWidgetRejectsMalformedUnderscoreSegment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('message identifier');
        new DashboardWidget(
            'core.dashboard.content-summary',
            DashboardWidget::KIND_SUMMARY,
            'dashboard._content.title',
            'dashboard.content.description',
        );
    }

    /**
     * Proves a widget cannot opt into an unknown render kind.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsUnknownKind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('kind is unsupported');
        new DashboardWidget(
            'core.dashboard.content-summary',
            'html',
            'dashboard.content.title',
            'dashboard.content.description',
        );
    }

    /**
     * Proves a widget cannot introduce a layout size outside the closed responsive vocabulary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsUnknownSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('size is unsupported');
        new DashboardWidget(
            'core.dashboard.content-summary',
            DashboardWidget::KIND_SUMMARY,
            'dashboard.content.title',
            'dashboard.content.description',
            size: 'viewport',
        );
    }

    /**
     * Proves object payloads cannot become an executable widget data channel.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsObjectData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported or unbounded node');
        new DashboardWidget(
            'core.dashboard.content-summary',
            DashboardWidget::KIND_SUMMARY,
            'dashboard.content.title',
            'dashboard.content.description',
            data: ['unsafe' => new \stdClass()],
        );
    }

    /**
     * Proves the top-level data contract is a semantic object rather than an unlabelled list.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsTopLevelListData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('semantic object');
        new DashboardWidget(
            'core.dashboard.content-summary',
            DashboardWidget::KIND_SUMMARY,
            'dashboard.content.title',
            'dashboard.content.description',
            data: ['unlabelled'],
        );
    }

    /**
     * Proves only a root-relative filtered navigation destination may enter a workflow model.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNavigationProjectionRejectsExternalHref(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('root-relative');
        DashboardWidget::fromNavigation([
            'id' => 'core.content',
            'label' => 'Content',
            'description' => 'Open content.',
            'href' => 'https://example.test/content',
            'icon' => 'content',
            'group' => 'Workspace',
        ]);
    }

    /**
     * Proves an encoded dot segment cannot escape a filtered navigation area's root.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNavigationProjectionRejectsEncodedDotSegments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('root-relative');
        DashboardWidget::fromNavigation([
            'id' => 'core.content',
            'label' => 'Content',
            'description' => 'Open content.',
            'href' => '/administrator/%2e%2e/portal',
            'icon' => 'content',
            'group' => 'Workspace',
        ]);
    }

    /**
     * Proves the exported contract distinguishes message-ID widgets from current navigation display text.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNavigationProjectionExportsDisplayTextMode(): void
    {
        $widget = DashboardWidget::fromNavigation([
            'id' => '2acme.sales__orders',
            'label' => 'Sales orders',
            'description' => 'Open sales orders.',
            'href' => '/administrator/extensions/acme/sales/orders',
            'icon' => 'content',
            'group' => 'Workspace',
        ])->toArray();

        self::assertSame('2acme.sales__orders', $widget['id']);
        self::assertSame('workflow', $widget['kind']);
        self::assertSame('/administrator/extensions/acme/sales/orders', $widget['href']);
        self::assertFalse($widget['message_ids']);
        self::assertSame([], $widget['data']);
    }
}
