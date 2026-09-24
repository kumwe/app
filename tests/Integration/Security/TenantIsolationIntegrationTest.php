<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Security;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\Access\AuthorizationDenied;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Navigation\Application\NavigationService;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Site\Infrastructure\Persistence\DoctrineSiteSettings;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Context\Value\ExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins that one site of an installation cannot reach state another site owns.
 *
 * The sites of an installation are the businesses of one group, and every resource has exactly one owner:
 * a grant scoped to one site reaches that site's resources and nothing beyond them. The site settings
 * document is stored once for the installation and rendered by its public site, so it belongs to that
 * site; an actor whose authority is scoped to a different site must not be able to rewrite the name,
 * homepage, locale, indexing or navigation every other site's public pages are served with, even after
 * arranging for every referenced resource to be one its own site owns. Isolation must also hold while every
 * site is busy at once, so concurrent writers in three sites each list only what their own site owns.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineSiteSettings::class)]
#[CoversClass(NavigationService::class)]
final class TenantIsolationIntegrationTest extends TestCase
{
    /**
     * Identifier of the second site every case works from.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string OTHER_SITE = 'security-tenant-b';

    /**
     * A settings manager scoped to another site cannot rewrite the installation's site settings.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASiteScopedManagerCannotRewriteThePublicSitesSettings(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $this->ensureSite($container, self::OTHER_SITE);
        $settings = $container->get(SiteSettings::class);
        $navigation = $container->get(NavigationService::class);
        self::assertInstanceOf(SiteSettings::class, $settings);
        self::assertInstanceOf(NavigationService::class, $navigation);
        $other = TestKernelFactory::contextFromGrantRows($container, [
            ['capability' => 'settings.manage', 'scope_type' => 'site', 'scope_identifier' => self::OTHER_SITE],
            ['capability' => 'navigation.manage', 'scope_type' => 'site', 'scope_identifier' => self::OTHER_SITE],
        ], self::OTHER_SITE);
        $menu = $navigation->createMenu($other, 'tenant_b_' . bin2hex(random_bytes(6)), 'Tenant B menu');
        $before = $settings->current();
        $presentation = $before['presentation'];
        self::assertIsArray($presentation);

        $administrator = TestKernelFactory::administratorContext($container);
        try {
            $this->attemptEveryCrossSiteAccess($settings, $other, $menu->handle, $presentation);
        } finally {
            if ($settings->current() !== $before) {
                $settings->updateAll($administrator, $before);
            }
        }
        self::assertSame($before, $settings->current(), 'The public site settings are unchanged.');

        $own = TestKernelFactory::contextFromGrantRows($container, [
            ['capability' => 'settings.manage', 'scope_type' => 'site', 'scope_identifier' => 'default'],
        ]);
        self::assertSame($before, $settings->managed($own), 'A manager of the public site still reads them.');
        $settings->update($own, 'Public site renamed', (string) $before['homepage_slug']);
        try {
            self::assertSame('Public site renamed', $settings->current()['site_name']);
        } finally {
            $settings->update($own, (string) $before['site_name'], (string) $before['homepage_slug']);
        }
    }

    /**
     * Three sites writing and listing at once never see each other's menus and never lose their own.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConcurrentWritersInThreeSitesNeverSeeEachOthersResources(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $sites = ['default', self::OTHER_SITE, 'security-tenant-c'];
        foreach ($sites as $site) {
            $this->ensureSite($container, $site);
        }
        $marker = bin2hex(random_bytes(4));
        $directory = sys_get_temp_dir() . '/kumwe-tenant-isolation-' . $marker;
        mkdir($directory, 0o700);
        $processes = [];
        try {
            foreach ($sites as $site) {
                $process = proc_open([
                    PHP_BINARY,
                    dirname(__DIR__, 3) . '/tests/Support/tenant-isolation-partner.php',
                    $site,
                    $marker,
                    '12',
                    $directory . '/' . $site . '.json',
                ], [1 => ['file', '/dev/null', 'w'], 2 => ['file', $directory . '/' . $site . '.err', 'w']], $pipes);
                self::assertIsResource($process, 'The tenant partner process could not be started.');
                $processes[$site] = $process;
            }
            $deadline = microtime(true) + 90.0;
            while (count(glob($directory . '/*.ready') ?: []) < count($sites) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            touch($directory . '/go');
            foreach ($processes as $site => $process) {
                self::assertSame(0, proc_close($process), $site . ': ' . (string) @file_get_contents(
                    $directory . '/' . $site . '.err',
                ) . (string) @file_get_contents($directory . '/' . $site . '.json'));
                unset($processes[$site]);
                $tally = json_decode(
                    (string) file_get_contents($directory . '/' . $site . '.json'),
                    true,
                    8,
                    JSON_THROW_ON_ERROR,
                );
                self::assertIsArray($tally);
                self::assertCount(12, $tally['created'] ?? [], $site . ' created every menu.');
                self::assertSame([], $tally['foreign'] ?? null, $site . ' was never shown another site\'s menu.');
                self::assertSame([], $tally['missing'] ?? null, $site . ' never lost sight of its own menu.');
            }
        } finally {
            foreach ($processes as $process) {
                proc_close($process);
            }
            array_map('unlink', glob($directory . '/*') ?: []);
            rmdir($directory);
        }
    }

    /**
     * Try every managed read and write from another site, requiring each to be refused.
     *
     * @param   SiteSettings          $settings      Production settings service.
     * @param   ExecutionContext      $other         Manager scoped to the other site.
     * @param   string                $menu          Handle of a menu the other site owns.
     * @param   array<mixed, mixed>   $presentation  Current presentation document.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function attemptEveryCrossSiteAccess(
        SiteSettings $settings,
        ExecutionContext $other,
        string $menu,
        array $presentation,
    ): void {
        foreach (
            [
                static fn () => $settings->updateAll($other, [
                    'site_name' => 'Tenant B takeover',
                    'search_indexing_enabled' => false,
                    'presentation' => ['primary_menu' => $menu] + $presentation,
                ]),
                static fn () => $settings->update($other, 'Tenant B takeover', 'tenant-b-home'),
                static fn () => $settings->managed($other),
            ] as $attempt
        ) {
            try {
                $attempt();
                self::fail('Another site rewrote or read the public site settings.');
            } catch (AuthorizationDenied) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * Register a site row once, as an operator adds a business to the installation.
     *
     * @param   Container  $container   Suite container.
     * @param   string     $identifier  Site identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function ensureSite(Container $container, string $identifier): void
    {
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $known = $database->fetchOne(
            sprintf('SELECT identifier FROM %s WHERE identifier = ?', $tables->quoted('sites')),
            [$identifier],
        );
        if ($known === false) {
            $database->insert($tables->raw('sites'), [
                'identifier' => $identifier,
                'name' => 'Security qualification tenant',
                'created_at' => new DateTimeImmutable(),
            ], ['created_at' => Types::DATETIME_IMMUTABLE]);
        }
    }
}
