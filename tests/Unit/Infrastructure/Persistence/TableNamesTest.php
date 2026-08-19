<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TableNames::class)]
final class TableNamesTest extends TestCase
{
    public function testAcceptsAPrefixedNameAtThePortableLimit(): void
    {
        $prefix = str_repeat('p', 27) . '_';
        $tables = new TableNames($this->createStub(Connection::class), $prefix);

        self::assertSame(
            $prefix . 'extension_contribution_capabilities',
            $tables->raw('extension_contribution_capabilities'),
        );
        self::assertSame(63, strlen($tables->raw('extension_contribution_capabilities')));
        self::assertLessThanOrEqual(63, strlen($tables->raw('business_schema_recovery_evidence')));
    }

    public function testRejectsAPrefixedNameBeyondThePortableLimit(): void
    {
        $tables = new TableNames($this->createStub(Connection::class), str_repeat('p', 27) . '_');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('portable 63-byte limit');

        $tables->raw(str_repeat('t', 36));
    }

    public function testRejectsAPrefixThatCannotFitTheLongestCoreTable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('table prefix is invalid');

        new TableNames($this->createStub(Connection::class), str_repeat('p', 28) . '_');
    }

    public function testRejectsNonCanonicalPrefixes(): void
    {
        foreach (['tenant', 'tenant__', 'a__b_'] as $prefix) {
            try {
                new TableNames($this->createStub(Connection::class), $prefix);
                self::fail(sprintf('Prefix "%s" should be rejected.', $prefix));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('table prefix is invalid', $exception->getMessage());
            }
        }
    }
}
