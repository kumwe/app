<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Content\Presentation;

use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Content\Application\ContentRecord;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Content\Application\SiteScopedContentRepository;
use Kumwe\CMS\Content\Application\TranslationGroupRepository;
use Kumwe\CMS\Content\Domain\ContentEntry;
use Kumwe\CMS\Content\Domain\ContentStatus;
use Kumwe\CMS\Content\Domain\PublicationWindow;
use Kumwe\CMS\Content\Domain\TranslationGroup;
use Kumwe\CMS\Content\Domain\TranslationGroupMember;
use Kumwe\CMS\Content\Presentation\TranslationGroupPresenter;
use Kumwe\CMS\Application\Persistence\TransactionManager;
use Kumwe\CMS\Localization\Application\ActiveLocale;
use Kumwe\CMS\Localization\Application\SupportedLocales;
use Kumwe\CMS\Localization\Domain\LocaleTag;
use Kumwe\CMS\Navigation\Application\NavigationRepository;
use Kumwe\CMS\Navigation\Application\PublicNavigation;
use Kumwe\CMS\Site\Application\PublicPageLocator;
use Kumwe\CMS\Site\Application\SiteSettings;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use Kumwe\CMS\Workflow\Domain\Workflow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(TranslationGroupPresenter::class)]
#[UsesClass(ContentEntry::class)]
#[UsesClass(ContentRecord::class)]
#[UsesClass(ContentService::class)]
#[UsesClass(LocaleTag::class)]
#[UsesClass(PublicNavigation::class)]
#[UsesClass(PublicPageLocator::class)]
#[UsesClass(TranslationGroup::class)]
#[UsesClass(TranslationGroupMember::class)]
/**
 * Pins the two things decision D12 requires to ship by default: automatic `hreflang` and the selector.
 *
 * @since  2.0.0
 */
final class TranslationGroupPresenterTest extends TestCase
{
    /**
     * Identifier of the logical item every case in this class renders.
     *
     * @var    string
     * @since  2.0.0
     */
    private const GROUP = '018f22e2-7c8b-7ab0-8f3a-88e8026bb940';

    /**
     * Identifier of the English entry.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ENGLISH = '018f22e2-7c8b-7ab0-8f3a-88e8026bb941';

    /**
     * Identifier of the German entry.
     *
     * @var    string
     * @since  2.0.0
     */
    private const GERMAN = '018f22e2-7c8b-7ab0-8f3a-88e8026bb942';

    /**
     * Identifier of the Afrikaans entry.
     *
     * @var    string
     * @since  2.0.0
     */
    private const AFRIKAANS = '018f22e2-7c8b-7ab0-8f3a-88e8026bb943';

    /**
     * Prove the alternates list exactly the published locales, and never a drafting one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheAlternatesListExactlyThePublishedLocales(): void
    {
        $presenter = $this->presenter($this->group(['en-GB' => true, 'de' => true, 'af' => false]));

        $view = $presenter->alternates($this->record(self::ENGLISH, 'about', 'en-GB'), '/about');

        self::assertSame(['af' => null, 'de' => '/ueber-uns', 'en-GB' => '/about'], [
            'af' => null,
            'de' => $this->hrefFor($view['alternates'], 'de'),
            'en-GB' => $this->hrefFor($view['alternates'], 'en-GB'),
        ]);
        self::assertSame(['de', 'en-GB'], array_column($view['alternates'], 'locale'));
        self::assertSame([false, true], array_column($view['alternates'], 'current'));
        self::assertSame(['ltr', 'ltr'], array_column($view['alternates'], 'direction'));
        self::assertSame('/about', $view['default_href']);
    }

    /**
     * Prove the selector names each language in that language rather than in the reader's.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEachChoiceIsNamedInItsOwnLanguage(): void
    {
        $presenter = $this->presenter($this->group(['en-GB' => true, 'de' => true]));

        $labels = array_column(
            $presenter->alternates($this->record(self::ENGLISH, 'about', 'en-GB'), '/about')['alternates'],
            'label',
            'locale',
        );

        self::assertStringContainsString('Deutsch', $labels['de']);
        self::assertStringContainsString('English', $labels['en-GB']);
    }

    /**
     * Prove an untranslated item renders no alternates and therefore no selector.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUntranslatedItemRendersNothing(): void
    {
        $presenter = $this->presenter(null);

        self::assertSame(
            ['alternates' => [], 'default_href' => null],
            $presenter->alternates($this->record(self::ENGLISH, 'about', 'en-GB'), '/about'),
        );
    }

    /**
     * Prove a language-neutral entry point serves the negotiated locale, and falls back when it drafts.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALanguageNeutralEntryPointNegotiatesAndFallsBack(): void
    {
        $group = $this->group(['en-GB' => true, 'de' => true, 'af' => false]);
        $english = $this->record(self::ENGLISH, 'about', 'en-GB');

        $german = $this->presenter($group, 'de')->negotiate($english);
        self::assertSame(self::GERMAN, $german->entry->id());

        $fallback = $this->presenter($group, 'af')->negotiate($english);
        self::assertSame(self::ENGLISH, $fallback->entry->id());
    }

    /**
     * Read one alternate's link out of the rendered view model.
     *
     * @param   list<array{locale: string, label: string, href: string, direction: string, current: bool}>  $alternates
     *          Rendered alternates.
     * @param   string  $locale  Locale whose link is wanted.
     *
     * @return  ?string  The link, or null when that locale was not rendered.
     *
     * @since   2.0.0
     */
    private function hrefFor(array $alternates, string $locale): ?string
    {
        foreach ($alternates as $alternate) {
            if ($alternate['locale'] === $locale) {
                return $alternate['href'];
            }
        }

        return null;
    }

    /**
     * Build the presenter over a group store and a negotiated locale.
     *
     * @param   ?TranslationGroup  $group   Group the store answers with, or null for an untranslated item.
     * @param   string             $locale  Locale the request negotiated.
     *
     * @return  TranslationGroupPresenter  Presenter wired the way the composition root wires it.
     *
     * @since   2.0.0
     */
    private function presenter(?TranslationGroup $group, string $locale = 'en-GB'): TranslationGroupPresenter
    {
        $records = [
            self::ENGLISH => $this->record(self::ENGLISH, 'about', 'en-GB'),
            self::GERMAN => $this->record(self::GERMAN, 'ueber-uns', 'de'),
            self::AFRIKAANS => $this->record(self::AFRIKAANS, 'oor-ons', 'af'),
        ];
        $repository = $this->createStub(SiteScopedContentRepository::class);
        $repository->method('findPublishedByIdForSite')->willReturnCallback(
            static function (SiteContext $site, string $id) use ($group, $records): ?ContentRecord {
                $member = $group?->member(
                    LocaleTag::fromString($id === self::GERMAN ? 'de' : ($id === self::AFRIKAANS ? 'af' : 'en-GB')),
                );

                return $member !== null && !$member->publicState ? null : $records[$id] ?? null;
            },
        );
        $repository->method('findPublishedBySlugForSite')->willReturn(null);
        $groups = $this->createStub(TranslationGroupRepository::class);
        $groups->method('forContent')->willReturn($group);
        $content = $this->content($repository);

        return new TranslationGroupPresenter(
            $groups,
            $content,
            new PublicPageLocator($content, $this->settings(), $this->navigation(), SiteContext::default()),
            $this->activeLocale($locale),
            $this->clock(),
            SiteContext::default(),
        );
    }

    /**
     * Build the group under test with a stated publication state per locale.
     *
     * @param   array<string, bool>  $locales  Publication state keyed by language tag.
     *
     * @return  TranslationGroup  The item across those locales, falling back to `en-GB`.
     *
     * @since   2.0.0
     */
    private function group(array $locales): TranslationGroup
    {
        $slugs = ['en-GB' => 'about', 'de' => 'ueber-uns', 'af' => 'oor-ons'];
        $identifiers = ['en-GB' => self::ENGLISH, 'de' => self::GERMAN, 'af' => self::AFRIKAANS];
        $members = [];
        foreach ($locales as $locale => $published) {
            $members[] = new TranslationGroupMember(
                LocaleTag::fromString($locale),
                $identifiers[$locale],
                $slugs[$locale],
                $published ? ContentStatus::Published->value : ContentStatus::Draft->value,
                $published,
                PublicationWindow::unbounded(),
            );
        }

        return new TranslationGroup(self::GROUP, LocaleTag::fromString('en-GB'), $members);
    }

    /**
     * Build one locale's stored record.
     *
     * @param   string  $id      UUID of the entry.
     * @param   string  $slug    Route segment the locale is published under.
     * @param   string  $locale  Language tag the entry is written in.
     *
     * @return  ContentRecord  The stored record, published and unscheduled.
     *
     * @since   2.0.0
     */
    private function record(string $id, string $slug, string $locale): ContentRecord
    {
        $at = new DateTimeImmutable('2026-08-17T09:00:00+00:00');

        return new ContentRecord(
            ContentEntry::create(
                $id,
                ucfirst($slug),
                $slug,
                ['body' => 'Hello'],
                ContentStatus::Published,
                null,
                $locale,
                self::GROUP,
            ),
            ContentService::CORE_PAGE_TYPE_ID,
            ContentService::CORE_WORKFLOW_ID,
            $at,
            $at,
        );
    }

    /**
     * Build the content service over a stubbed repository.
     *
     * @param   SiteScopedContentRepository  $repository  Store the service reads through.
     *
     * @return  ContentService  Service wired with a fixed clock and the built-in workflow.
     *
     * @since   2.0.0
     */
    private function content(SiteScopedContentRepository $repository): ContentService
    {
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );

        return new ContentService(
            $repository,
            $this->createStub(AuditRecorder::class),
            $transactions,
            $this->clock(),
            new Workflow(),
            AuthorizationContext::gateway(),
            AuthorizationContext::ownershipWriter(),
        );
    }

    /**
     * Open a request-scoped locale holder on one negotiated locale.
     *
     * @param   string  $locale  Language tag the request negotiated.
     *
     * @return  ActiveLocale  The holder, already opened.
     *
     * @since   2.0.0
     */
    private function activeLocale(string $locale): ActiveLocale
    {
        $active = new ActiveLocale(new SupportedLocales());
        $active->begin(LocaleTag::fromString($locale));

        return $active;
    }

    /**
     * The settings a site with no nominated homepage and no menu answers with.
     *
     * @return  SiteSettings  Stubbed settings document.
     *
     * @since   2.0.0
     */
    private function settings(): SiteSettings
    {
        $settings = $this->createStub(SiteSettings::class);
        $settings->method('current')->willReturn([
            'site_name' => 'Kumwe',
            'homepage_content_id' => null,
            'homepage_slug' => null,
            'search_indexing_enabled' => true,
        ]);

        return $settings;
    }

    /**
     * An empty public navigation, so every link falls back to the record's own slug.
     *
     * @return  PublicNavigation  Navigation over a store with no menu.
     *
     * @since   2.0.0
     */
    private function navigation(): PublicNavigation
    {
        $repository = $this->createStub(NavigationRepository::class);
        $repository->method('menus')->willReturn([]);
        $repository->method('items')->willReturn([]);

        return new PublicNavigation($repository);
    }

    /**
     * The one instant every publication question in this class is asked about.
     *
     * @return  ClockInterface  Clock fixed to a moment inside every open window.
     *
     * @since   2.0.0
     */
    private function clock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-17T10:00:00+00:00'));

        return $clock;
    }
}
