<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Persistence\Migration;

use Kumwe\CMS\Infrastructure\Persistence\Migration\SqlStatementSplitter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SqlStatementSplitter::class)]
final class SqlStatementSplitterTest extends TestCase
{
    public function testItPreservesSemicolonsInsideStringsCommentsAndDollarQuotedBodies(): void
    {
        $sql = <<<'SQL'
CREATE TABLE {{schema}}.example (value text DEFAULT ';');
-- the next function contains internal statements;
CREATE FUNCTION {{schema}}.guard() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'blocked; always';
END;
$$ LANGUAGE plpgsql;
/* an ending comment; */
CREATE INDEX example_value_idx ON {{schema}}.example (value);
SQL;

        $statements = (new SqlStatementSplitter())->split($sql);

        self::assertCount(3, $statements);
        self::assertStringContainsString("DEFAULT ';'", $statements[0]);
        self::assertStringContainsString("RAISE EXCEPTION 'blocked; always';", $statements[1]);
        self::assertStringContainsString('CREATE INDEX', $statements[2]);
    }

    public function testItRejectsUnterminatedQuotedSections(): void
    {
        $this->expectException(RuntimeException::class);

        (new SqlStatementSplitter())->split("SELECT 'unterminated;");
    }
}
