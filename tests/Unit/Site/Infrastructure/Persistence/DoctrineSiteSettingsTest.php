<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Site\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\SystemIdentity;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Site\Infrastructure\Persistence\DoctrineSiteSettings;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use Kumwe\CMS\Tests\Support\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Proves settings persistence separates nullable human attribution from opaque audit actors.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineSiteSettings::class)]
final class DoctrineSiteSettingsTest extends TestCase
{
    /**
     * Stable human subject accepted by the nullable GUID settings column.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string HUMAN_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    /**
     * Managed navigation menu returned by the referential-integrity lookup.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string MENU_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb401';

    /**
     * System writes must bind SQL NULL as a GUID while retaining the system token in the audit event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSystemWriteStoresNullGuidAndRetainsSystemAuditAttribution(): void
    {
        $database = $this->database();
        $database->method('fetchOne')->willReturnCallback(
            static function (string $query): string {
                if (
                    str_contains($query, 'navigation_menus')
                    || str_contains($query, 'resource_site_ownership')
                ) {
                    return self::MENU_ID;
                }

                return 'stored-setting';
            },
        );
        $database->expects(self::never())->method('insert');
        $storedKeys = [];
        $database->expects(self::exactly(7))->method('executeStatement')->with(
            self::callback(static fn (string $query): bool => $query
                === 'UPDATE kumwe_site_settings SET setting_value = ?, updated_by = ?, updated_at = ?, '
                    . 'version = version + 1 WHERE setting_key = ?'),
            self::callback(static function (array $parameters) use (&$storedKeys): bool {
                self::assertCount(4, $parameters);
                self::assertNull($parameters[1]);
                self::assertInstanceOf(DateTimeImmutable::class, $parameters[2]);
                self::assertIsString($parameters[3]);
                $storedKeys[] = $parameters[3];

                return true;
            }),
            [Types::JSON, Types::GUID, Types::DATETIME_IMMUTABLE, Types::STRING],
        )->willReturn(1);
        $audit = $this->audit(SystemIdentity::ProfileInstaller->value);

        $this->settings($database, $audit)->updateAll(
            AuthorizationContext::system(SystemIdentity::ProfileInstaller)->context(
                SiteContext::default(),
                'profile-install-settings',
            ),
            [],
        );

        self::assertSame($this->settingKeys(), $storedKeys);
    }

    /**
     * Human writes must bind the authenticated subject as a GUID in newly inserted settings rows.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHumanWriteStoresSubjectGuidAndUsesItForAuditAttribution(): void
    {
        $database = $this->database();
        $database->method('fetchOne')->willReturnCallback(
            static function (string $query): string|false {
                if (
                    str_contains($query, 'navigation_menus')
                    || str_contains($query, 'resource_site_ownership')
                ) {
                    return self::MENU_ID;
                }

                return false;
            },
        );
        $database->expects(self::never())->method('executeStatement');
        $storedKeys = [];
        $database->expects(self::exactly(7))->method('insert')->with(
            'kumwe_site_settings',
            self::callback(static function (array $values) use (&$storedKeys): bool {
                self::assertSame(self::HUMAN_ID, $values['updated_by'] ?? null);
                self::assertSame(1, $values['version'] ?? null);
                self::assertInstanceOf(DateTimeImmutable::class, $values['updated_at'] ?? null);
                self::assertIsString($values['setting_key'] ?? null);
                $storedKeys[] = $values['setting_key'];

                return true;
            }),
            [
                'setting_value' => Types::JSON,
                'updated_by' => Types::GUID,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ],
        )->willReturn(1);
        $audit = $this->audit(self::HUMAN_ID);

        $this->settings($database, $audit)->updateAll(
            AuthorizationContext::human(['settings.manage'], self::HUMAN_ID),
            [],
        );

        self::assertSame($this->settingKeys(), $storedKeys);
    }

    /**
     * Build a connection seam that returns an empty current document and unquoted test identifiers.
     *
     * @return  Connection&MockObject  Observable database seam.
     *
     * @since   2.0.0
     */
    private function database(): Connection&MockObject
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => $identifier,
        );
        $database->method('fetchAllAssociative')->willReturn([]);

        return $database;
    }

    /**
     * Expect one successful settings audit event attributed to the supplied actor.
     *
     * @param   string  $actorId  Human subject UUID or opaque system actor token.
     *
     * @return  AuditRecorder&MockObject  Recorder with the attribution expectation installed.
     *
     * @since   2.0.0
     */
    private function audit(string $actorId): AuditRecorder&MockObject
    {
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->actorId() === $actorId
                && $event->action() === 'site.settings.update'
                && $event->outcome() === 'success',
        ));

        return $audit;
    }

    /**
     * Construct the authoritative store with deterministic infrastructure collaborators.
     *
     * @param   Connection     $database  Observable database seam.
     * @param   AuditRecorder  $audit     Recorder carrying this scenario's attribution expectation.
     *
     * @return  DoctrineSiteSettings  Settings adapter under test.
     *
     * @since   2.0.0
     */
    private function settings(Connection $database, AuditRecorder $audit): DoctrineSiteSettings
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-11T12:00:00+00:00'));
        $authorization = $this->createMock(AuthorizationGateway::class);
        $authorization->expects(self::once())->method('assertAllowed');

        return new DoctrineSiteSettings(
            $database,
            new TableNames($database, 'kumwe_'),
            new ImmediateTransactionManager(),
            $audit,
            $clock,
            $authorization,
        );
    }

    /**
     * Return the stable storage-key order in which the settings adapter rewrites its document.
     *
     * @return  list<string>  All seven storage keys.
     *
     * @since   2.0.0
     */
    private function settingKeys(): array
    {
        return [
            'site.name',
            'site.homepage_content_id',
            'site.homepage_slug',
            'site.default_locale',
            'site.timezone',
            'search.indexing_enabled',
            'site.presentation',
        ];
    }
}
