<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Site\Application;

use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Content\Application\ContentModelRepository;
use Kumwe\CMS\Content\Application\ContentRecord;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Content\Application\SiteScopedContentRepository;
use Kumwe\CMS\Content\Domain\ContentEntry;
use Kumwe\CMS\Content\Domain\ContentStatus;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Http\Handler\PublishedContentHandler;
use Kumwe\CMS\Navigation\Application\MenuItemRecord;
use Kumwe\CMS\Navigation\Application\MenuRecord;
use Kumwe\CMS\Navigation\Application\NavigationRepository;
use Kumwe\CMS\Navigation\Application\PublicNavigation;
use Kumwe\CMS\Presentation\ContentLayoutCatalog;
use Kumwe\CMS\Presentation\ContentPresenter;
use Kumwe\CMS\Presentation\RichTextFormatter;
use Kumwe\CMS\Presentation\SiteRenderer;
use Kumwe\CMS\Presentation\Twig\SiteTwigEnvironment;
use Kumwe\CMS\Site\Application\PublicPageLocator;
use Kumwe\CMS\Site\Application\SiteSettings;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use Kumwe\CMS\Workflow\Domain\Workflow;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Twig\Loader\ArrayLoader;

#[CoversClass(PublicPageLocator::class)]
#[CoversClass(PublishedContentHandler::class)]
#[CoversClass(ContentPresenter::class)]
#[UsesClass(ContentLayoutCatalog::class)]
#[UsesClass(ContentRecord::class)]
#[UsesClass(ContentService::class)]
#[UsesClass(PublicNavigation::class)]
final class PublicPageLocatorTest extends TestCase
{
    private const HOME = '018f22e2-7c8b-7ab0-8f3a-88e8026bb701';
    private const ABOUT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb702';
    private const TEAM = '018f22e2-7c8b-7ab0-8f3a-88e8026bb703';
    private const MENU = '018f22e2-7c8b-7ab0-8f3a-88e8026bb704';
    private const ABOUT_ITEM = '018f22e2-7c8b-7ab0-8f3a-88e8026bb705';
    private const TEAM_ITEM = '018f22e2-7c8b-7ab0-8f3a-88e8026bb706';

    public function testResolvesHomepageByStableContentIdentifier(): void
    {
        $home = $this->record(self::HOME, 'Home', 'home');
        $locator = $this->locator([self::HOME => $home]);

        self::assertSame($home, $locator->homepage());
        self::assertSame('/', $locator->pathFor($home));
        self::assertSame($home, $locator->byPath('/'));
    }

    public function testResolvesNestedMenuPathAndRedirectSourceToOneCanonicalPage(): void
    {
        $records = [
            self::HOME => $this->record(self::HOME, 'Home', 'home'),
            self::ABOUT => $this->record(self::ABOUT, 'About', 'about'),
            self::TEAM => $this->record(self::TEAM, 'Team', 'team'),
        ];
        $locator = $this->locator($records);

        self::assertSame($records[self::TEAM], $locator->byPath('/about/team'));
        self::assertSame($records[self::TEAM], $locator->byPath('/pages/team'));
        self::assertSame('/about/team', $locator->pathFor($records[self::TEAM]));

        $navigation = $locator->navigation();
        self::assertSame('/', $navigation[0]['href']);
        self::assertSame('/about/team', $navigation[1]['children'][0]['href']);
    }

    public function testFallsBackToRootSlugForPublishedContentWithoutAMenuItem(): void
    {
        $news = $this->record('018f22e2-7c8b-7ab0-8f3a-88e8026bb707', 'News', 'news');
        $reserved = $this->record('018f22e2-7c8b-7ab0-8f3a-88e8026bb709', 'API', 'api');
        $locator = $this->locator([
            self::HOME => $this->record(self::HOME, 'Home', 'home'),
            $reserved->entry->id() => $reserved,
        ], ['news' => $news]);

        self::assertSame($news, $locator->byPath('/news'));
        self::assertSame('/news', $locator->pathFor($news));
        self::assertNull($locator->byPath('/about/../news'));
        self::assertNull($locator->byPath('/about%2Fteam'));
        self::assertNull($locator->byPath('/api'));
        self::assertNull($locator->publicPathFor($reserved));
    }

    public function testLegacyPagesRouteRedirectsPermanentlyToNestedCanonicalPath(): void
    {
        $records = [
            self::HOME => $this->record(self::HOME, 'Home', 'home'),
            self::ABOUT => $this->record(self::ABOUT, 'About', 'about'),
            self::TEAM => $this->record(self::TEAM, 'Team', 'team'),
        ];
        $settings = $this->settings();
        $handler = new PublishedContentHandler(
            $this->locator($records, settings: $settings),
            $settings,
            new SiteRenderer(new SiteTwigEnvironment(new ArrayLoader())),
            new ContentPresenter(new RichTextFormatter()),
            $this->layouts(),
        );
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://kumwe.test/pages/team?preview=0',
        );

        $response = $handler->handle($request);

        self::assertSame(308, $response->getStatusCode());
        self::assertSame('/about/team?preview=0', $response->getHeaderLine('Location'));
    }

    public function testCanonicalNestedPathRendersThePublishedTarget(): void
    {
        $records = [
            self::HOME => $this->record(self::HOME, 'Home', 'home'),
            self::ABOUT => $this->record(self::ABOUT, 'About', 'about'),
            self::TEAM => $this->record(self::TEAM, 'Team', 'team'),
        ];
        $settings = $this->settings();
        $handler = new PublishedContentHandler(
            $this->locator($records, settings: $settings),
            $settings,
            new SiteRenderer(new SiteTwigEnvironment(new ArrayLoader([
                'page.twig' => '{{ current_path }}|{{ entry.title }}|{{ entry.body_html|raw }}',
            ]))),
            new ContentPresenter(new RichTextFormatter()),
            $this->layouts(),
        );
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://kumwe.test/about/team',
        );

        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/about/team|Team|<p>Team</p>', (string) $response->getBody());
    }

    public function testPresenterRendersNestedStructuredBodiesWithoutLosingTopLevelCompatibility(): void
    {
        $at = new DateTimeImmutable('2026-08-07T10:00:00+00:00');
        $record = new ContentRecord(
            ContentEntry::create(
                self::HOME,
                'Home',
                'home',
                [
                    'body' => 'Welcome **home**.',
                    'capabilities' => ['body' => 'Build **well**.'],
                    'sections' => [['body' => 'First'], ['body' => 'Second']],
                ],
                ContentStatus::Published,
            ),
            ContentService::CORE_PAGE_TYPE_ID,
            ContentService::CORE_WORKFLOW_ID,
            $at,
            $at,
        );

        $entry = (new ContentPresenter(new RichTextFormatter()))->present($record);

        self::assertSame('<p>Welcome <strong>home</strong>.</p>', $entry['body_html']);
        self::assertSame(
            '<p>Build <strong>well</strong>.</p>',
            $entry['data']['capabilities']['body_html'],
        );
        self::assertSame('<p>First</p>', $entry['data']['sections'][0]['body_html']);
        self::assertSame('<p>Second</p>', $entry['data']['sections'][1]['body_html']);
    }

    /**
     * @param array<string, ContentRecord> $byId
     * @param array<string, ContentRecord> $additionalBySlug
     */
    private function locator(
        array $byId,
        array $additionalBySlug = [],
        ?SiteSettings $settings = null,
    ): PublicPageLocator {
        $bySlug = $additionalBySlug;
        foreach ($byId as $record) {
            $bySlug[$record->entry->slug()] = $record;
        }
        $repository = $this->createStub(SiteScopedContentRepository::class);
        $repository->method('findPublishedByIdForSite')->willReturnCallback(
            static fn (SiteContext $site, string $id): ?ContentRecord => $byId[$id] ?? null,
        );
        $repository->method('findPublishedBySlugForSite')->willReturnCallback(
            static fn (SiteContext $site, string $slug): ?ContentRecord => $bySlug[$slug] ?? null,
        );
        return new PublicPageLocator(
            $this->content($repository),
            $settings ?? $this->settings(),
            $this->navigation(),
            SiteContext::default(),
        );
    }

    private function settings(): SiteSettings
    {
        $settings = $this->createStub(SiteSettings::class);
        $settings->method('current')->willReturn([
            'site_name' => 'Kumwe',
            'homepage_content_id' => self::HOME,
            'homepage_slug' => 'home',
            'search_indexing_enabled' => true,
        ]);

        return $settings;
    }

    private function content(SiteScopedContentRepository $repository): ContentService
    {
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-07T10:00:00+00:00'));

        return new ContentService(
            $repository,
            $this->createStub(AuditRecorder::class),
            $transactions,
            $clock,
            new Workflow(),
            AuthorizationContext::gateway(),
            AuthorizationContext::ownershipWriter(),
        );
    }

    private function navigation(): PublicNavigation
    {
        $at = new DateTimeImmutable('2026-08-07T10:00:00+00:00');
        $repository = $this->createStub(NavigationRepository::class);
        $repository->method('menus')->willReturn([
            new MenuRecord(self::MENU, 'main', 'Main menu', 1, $at, $at),
        ]);
        $repository->method('items')->willReturn([
            new MenuItemRecord(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb708',
                self::MENU,
                null,
                'Home',
                'home',
                '/home',
                0,
                1,
                $at,
                $at,
                contentId: self::HOME,
            ),
            new MenuItemRecord(
                self::ABOUT_ITEM,
                self::MENU,
                null,
                'About',
                'about',
                '/about',
                1,
                1,
                $at,
                $at,
                contentId: self::ABOUT,
            ),
            new MenuItemRecord(
                self::TEAM_ITEM,
                self::MENU,
                self::ABOUT_ITEM,
                'Team',
                'team',
                '/about/team',
                0,
                1,
                $at,
                $at,
                contentId: self::TEAM,
            ),
        ]);

        return new PublicNavigation($repository);
    }

    private function record(string $id, string $title, string $slug): ContentRecord
    {
        $at = new DateTimeImmutable('2026-08-07T10:00:00+00:00');

        return new ContentRecord(
            ContentEntry::create($id, $title, $slug, ['body' => $title], ContentStatus::Published),
            ContentService::CORE_PAGE_TYPE_ID,
            ContentService::CORE_WORKFLOW_ID,
            $at,
            $at,
        );
    }

    private function layouts(): ContentLayoutCatalog
    {
        return new ContentLayoutCatalog($this->createStub(ContentModelRepository::class), 'default');
    }
}
