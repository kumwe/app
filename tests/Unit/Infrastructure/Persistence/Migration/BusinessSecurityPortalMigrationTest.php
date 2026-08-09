<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\Migration\BusinessSecurityPortalMigration;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(BusinessSecurityPortalMigration::class)]
final class BusinessSecurityPortalMigrationTest extends TestCase
{
    public function testNewSiteColumnsCopyTheCanonicalCharacterDefinition(): void
    {
        $database = $this->createStub(Connection::class);
        $migration = new BusinessSecurityPortalMigration(new TableNames($database, 'kumwe_'));
        $sites = new Table('kumwe_sites');
        $siteIdentifier = $sites->addColumn('identifier', Types::STRING, [
            'length' => 191,
            'fixed' => false,
            'platformOptions' => [
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
        ]);
        /** @var array<string, mixed> $siteIdentifierOptions */
        $siteIdentifierOptions = (new ReflectionMethod($migration, 'siteIdentifierOptions'))
            ->invoke($migration, $siteIdentifier);

        /** @var list<Table> $definitions */
        $definitions = (new ReflectionMethod($migration, 'tables'))->invoke($migration, $siteIdentifierOptions);
        $byName = [];
        foreach ($definitions as $definition) {
            $byName[$definition->getObjectName()->getUnqualifiedName()->getValue()] = $definition;
        }

        foreach (
            [
            'kumwe_organizations',
            'kumwe_separation_duty_rules',
            'kumwe_approval_requests',
            'kumwe_step_up_proofs',
            'kumwe_portal_sessions',
            ] as $tableName
        ) {
            $definition = $byName[$tableName] ?? null;
            self::assertInstanceOf(Table::class, $definition);
            $siteIdentifier = $definition->getColumn('site_identifier');
            self::assertSame(191, $siteIdentifier->getLength());
            self::assertFalse($siteIdentifier->getFixed());
            self::assertSame('utf8mb4', $siteIdentifier->getCharset());
            self::assertSame('utf8mb4_unicode_ci', $siteIdentifier->getCollation());
        }
    }
}
