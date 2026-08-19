<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessDefinition\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\BusinessDefinition\Infrastructure\Persistence\DoctrineBusinessDefinitionRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineBusinessDefinitionRepository::class)]
/**
 * Proves derived contract-name admission uses a portable site-wide transaction lock.
 *
 * @since  2.0.0
 */
final class DoctrineBusinessDefinitionRepositoryTest extends TestCase
{
    /**
     * Lock the stable site row so an empty or disjoint definition catalog cannot escape serialization.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContractNamespaceLockUsesSiteAuthorityRowForUpdate(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('isTransactionActive')->willReturn(true);
        $database->expects(self::once())->method('quoteSingleIdentifier')
            ->with('kumwe_sites')
            ->willReturn('`kumwe_sites`');
        $database->expects(self::once())->method('fetchOne')->with(
            self::callback(static fn (string $sql): bool => $sql
                === 'SELECT identifier FROM `kumwe_sites` WHERE identifier = ? FOR UPDATE'),
            [SiteContext::DEFAULT],
            [Types::STRING],
        )->willReturn(SiteContext::DEFAULT);
        $repository = new DoctrineBusinessDefinitionRepository(
            $database,
            new TableNames($database, 'kumwe_'),
        );

        $repository->lockContractNamespace(SiteContext::default());
    }

    /**
     * Refuse namespace locking outside the transaction that will perform admission and publication.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContractNamespaceLockRequiresActiveTransaction(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('isTransactionActive')->willReturn(false);
        $database->expects(self::never())->method('fetchOne');
        $repository = new DoctrineBusinessDefinitionRepository(
            $database,
            new TableNames($database, 'kumwe_'),
        );

        $this->expectException(LogicException::class);
        $repository->lockContractNamespace(SiteContext::default());
    }
}
