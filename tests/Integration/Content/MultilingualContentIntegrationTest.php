<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Content;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DriverException;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Content\Application\ContentRecord;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Content\Application\TranslationGroupRepository;
use Kumwe\CMS\Content\Domain\ContentStatus;
use Kumwe\CMS\Content\Infrastructure\Persistence\DoctrineTranslationGroupRepository;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MultilingualContentMigration;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Localization\Domain\LocaleTag;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use Joomla\DI\Container;
use Laminas\Diactoros\ServerRequestFactory;
use Mezzio\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

#[CoversClass(MultilingualContentMigration::class)]
#[CoversClass(DoctrineTranslationGroupRepository::class)]
/**
 * Proves the content half of decision D12 against a real database and the real public site.
 *
 * Everything the acceptance criteria ask for is asserted here rather than in isolation, because every
 * one of them is a property of the whole path: a group publishes one locale while another drafts, a
 * missing translation resolves to the declared fallback, per-locale slugs do not collide across the
 * locales of one group, and `hreflang` and the shipped language selector list exactly the published
 * members and nothing else. The two uniqueness properties are proven at the database level — by
 * watching the engine refuse the write — rather than by trusting the application to have checked.
 *
 * The suite runs on MariaDB, MySQL and PostgreSQL through the same configuration every other
 * integration test uses, so the portability of the migration is exercised by running it at all.
 *
 * @since  2.0.0
 */
final class MultilingualContentIntegrationTest extends TestCase
{
    /**
     * Identifier of the logical item this test translates.
     *
     * @var    string
     * @since  2.0.0
     */
    private const GROUP = '018f22e2-7c8b-7ab0-8f3a-88e8026bc001';

    /**
     * Origin the public surface is requested on, which has to be one the kernel trusts.
     *
     * @var    string
     * @since  2.0.0
     */
    private const HOST = 'http://localhost';

    /**
     * Prove the migration lands the language dimension and its constraints on every supported engine.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheMigrationAddsTheLanguageDimensionAndItsConstraints(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $tables = $this->service($container, TableNames::class);
        $manager = $this->service($container, Connection::class)->createSchemaManager();

        $entries = $manager->introspectTableByUnquotedName($tables->raw('content_entries'));
        self::assertTrue($entries->hasColumn('locale'));
        self::assertTrue($entries->hasColumn('translation_group_id'));
        self::assertTrue($entries->hasIndex('uniq_content_translation_locale'));
        self::assertTrue($entries->getIndex('uniq_content_translation_locale')->isUnique());
        self::assertTrue($entries->hasIndex('uniq_content_site_slug'));
        self::assertTrue($entries->getIndex('uniq_content_site_slug')->isUnique());
        self::assertTrue($manager->tablesExist([$tables->raw('content_translation_groups')]));
    }

    /**
     * Prove one locale publishes while another drafts, and a missing translation takes the fallback.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOneLocalePublishesWhileAnotherDraftsAndAMissingOneFallsBack(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $content = $this->service($container, ContentService::class);
        $groups = $this->service($container, TranslationGroupRepository::class);
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);

        $english = $this->page($content, $context, 'About ' . $suffix, 'about-' . $suffix, true);
        $german = $this->page($content, $context, 'Ueber uns ' . $suffix, 'ueber-uns-' . $suffix, false);
        $groupId = Uuid::uuid7()->toString();
        $content->translate(
            $context,
            $english->entry->id(),
            $english->entry->version(),
            LocaleTag::fromString('en-GB'),
            $groupId,
        );
        $content->translate(
            $context,
            $german->entry->id(),
            $german->entry->version(),
            LocaleTag::fromString('de'),
            $groupId,
        );

        $group = $groups->forContent($context->site(), $english->entry->id());
        self::assertNotNull($group);
        self::assertCount(2, $group->members());
        self::assertSame(['de', 'en-GB'], array_map(
            static fn (object $member): string => $member->locale->toString(),
            $group->members(),
        ));
        self::assertSame(['en-GB'], array_map(
            static fn (object $member): string => $member->locale->toString(),
            $group->publishedMembers(new \DateTimeImmutable('now')),
        ));
        self::assertSame(
            $english->entry->id(),
            $group->resolve(LocaleTag::fromString('de'), new \DateTimeImmutable('now'))?->contentId,
        );
        self::assertSame(
            $english->entry->id(),
            $group->resolve(LocaleTag::fromString('af'), new \DateTimeImmutable('now'))?->contentId,
        );
    }

    /**
     * Prove the database itself refuses a second entry for one locale of one item.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDatabaseRefusesTwoEntriesForOneLocaleOfOneItem(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $content = $this->service($container, ContentService::class);
        $database = $this->service($container, Connection::class);
        $tables = $this->service($container, TableNames::class);
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);

        $first = $this->page($content, $context, 'First ' . $suffix, 'first-' . $suffix, true);
        $second = $this->page($content, $context, 'Second ' . $suffix, 'second-' . $suffix, true);
        $groupId = Uuid::uuid7()->toString();
        $content->translate(
            $context,
            $first->entry->id(),
            $first->entry->version(),
            LocaleTag::fromString('en-GB'),
            $groupId,
        );

        $this->expectException(DriverException::class);
        $database->executeStatement(sprintf(
            'UPDATE %s SET locale = ?, translation_group_id = ? WHERE id = ?',
            $tables->quoted('content_entries'),
        ), ['en-GB', $groupId, $second->entry->id()], [Types::STRING, Types::STRING, Types::GUID]);
    }

    /**
     * Prove the database refuses two locales of one item claiming the same route segment.
     *
     * The site-wide slug index is what carries this property: a slug names one page in a site, so two
     * locales of one item can never collide on one, and a visitor arriving on a segment is never
     * ambiguous about which language they asked for.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDatabaseRefusesTwoLocalesClaimingOneRouteSegment(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $content = $this->service($container, ContentService::class);
        $database = $this->service($container, Connection::class);
        $tables = $this->service($container, TableNames::class);
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);

        $english = $this->page($content, $context, 'Shared ' . $suffix, 'shared-' . $suffix, true);
        $german = $this->page($content, $context, 'Geteilt ' . $suffix, 'geteilt-' . $suffix, true);
        $groupId = Uuid::uuid7()->toString();
        $content->translate(
            $context,
            $english->entry->id(),
            $english->entry->version(),
            LocaleTag::fromString('en-GB'),
            $groupId,
        );
        $content->translate(
            $context,
            $german->entry->id(),
            $german->entry->version(),
            LocaleTag::fromString('de'),
            $groupId,
        );

        $this->expectException(DriverException::class);
        $database->executeStatement(sprintf(
            'UPDATE %s SET slug = ? WHERE id = ?',
            $tables->quoted('content_entries'),
        ), ['shared-' . $suffix, $german->entry->id()], [Types::STRING, Types::GUID]);
    }

    /**
     * Prove the public page advertises exactly the published locales and offers exactly those choices.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testThePublicPageAdvertisesAndOffersExactlyThePublishedLocales(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $content = $this->service($container, ContentService::class);
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);

        $english = $this->page($content, $context, 'Guide ' . $suffix, 'guide-' . $suffix, true);
        $german = $this->page($content, $context, 'Anleitung ' . $suffix, 'anleitung-' . $suffix, true);
        $afrikaans = $this->page($content, $context, 'Gids ' . $suffix, 'gids-' . $suffix, false);
        $groupId = Uuid::uuid7()->toString();
        foreach ([[$english, 'en-GB'], [$german, 'de'], [$afrikaans, 'af']] as [$record, $locale]) {
            $content->translate(
                $context,
                $record->entry->id(),
                $record->entry->version(),
                LocaleTag::fromString($locale),
                $groupId,
                LocaleTag::fromString('en-GB'),
            );
        }

        $body = $this->publicPage('/pages/guide-' . $suffix);

        self::assertStringContainsString(
            sprintf('<link rel="alternate" hreflang="en-GB" href="/guide-%s">', $suffix),
            $body,
        );
        self::assertStringContainsString(
            sprintf('<link rel="alternate" hreflang="de" href="/anleitung-%s">', $suffix),
            $body,
        );
        self::assertStringContainsString(
            sprintf('<link rel="alternate" hreflang="x-default" href="/guide-%s">', $suffix),
            $body,
        );
        self::assertStringNotContainsString('hreflang="af"', $body);
        self::assertStringNotContainsString('gids-' . $suffix, $body);
        self::assertStringContainsString('site-language-selector', $body);
        self::assertStringContainsString(
            sprintf('href="/anleitung-%s" hreflang="de" lang="de" dir="ltr"', $suffix),
            $body,
        );
        self::assertStringContainsString('aria-current="true"', $body);
    }

    /**
     * Create one page in one language, published or left drafting.
     *
     * @param   ContentService    $content    Service every write goes through.
     * @param   ExecutionContext  $context    Actor and site the page is created for.
     * @param   string            $title      Title of the page.
     * @param   string            $slug       Route segment the page is published under.
     * @param   bool              $published  Whether the page is moved into its public state.
     *
     * @return  ContentRecord  The stored record, at the version the caller must hand back.
     *
     * @since   2.0.0
     */
    private function page(
        ContentService $content,
        ExecutionContext $context,
        string $title,
        string $slug,
        bool $published,
    ): ContentRecord {
        $record = $content->create($context, $title, $slug, ['body' => 'Body of ' . $title]);
        if (!$published) {
            return $record;
        }
        // The built-in workflow reaches its public state through review, so a page that has to be live
        // for this test travels the same two edges an editor travels.
        $record = $content->transition(
            $context,
            $record->entry->id(),
            $record->entry->version(),
            ContentStatus::Review,
        );

        return $content->transition(
            $context,
            $record->entry->id(),
            $record->entry->version(),
            ContentStatus::Published,
        );
    }

    /**
     * Fetch one public page through the real application, following its canonical redirect.
     *
     * @param   string  $path  Permalink path to request.
     *
     * @return  string  The rendered HTML of the canonical page.
     *
     * @since   2.0.0
     */
    private function publicPage(string $path): string
    {
        $application = $this->service(
            (new \Kumwe\CMS\Kernel\ContainerFactory())->create(Environment::fromGlobals()),
            Application::class,
        );
        $response = $application->handle(
            (new ServerRequestFactory())
                ->createServerRequest('GET', self::HOST . $path)
                ->withHeader('Host', 'localhost'),
        );
        if ($response->getStatusCode() === 308) {
            $response = $application->handle(
                (new ServerRequestFactory())
                    ->createServerRequest('GET', self::HOST . $response->getHeaderLine('Location'))
                    ->withHeader('Host', 'localhost'),
            );
        }
        self::assertSame(200, $response->getStatusCode(), $path);

        return (string) $response->getBody();
    }

    /**
     * Resolve one service out of the container, refusing anything of the wrong type.
     *
     * @template T of object
     *
     * @param   Container         $container  Booted kernel container.
     * @param   class-string<T>   $service    Service to resolve.
     *
     * @return  T  The resolved service.
     *
     * @throws  RuntimeException  When the container answers with something else.
     *
     * @since   2.0.0
     */
    private function service(Container $container, string $service): object
    {
        $resolved = $container->get($service);
        if (!$resolved instanceof $service) {
            throw new RuntimeException(sprintf('The container did not supply %s.', $service));
        }

        return $resolved;
    }
}
