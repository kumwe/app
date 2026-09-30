<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Closure;
use Doctrine\DBAL\Cache\ArrayResult;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\API\MySQL\ExceptionConverter as MySQLExceptionConverter;
use Doctrine\DBAL\Driver\API\PostgreSQL\ExceptionConverter as PostgreSQLExceptionConverter;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;
use LogicException;
use SensitiveParameter;

/**
 * A real DBAL connection over a scripted driver, for exercising diagnostics adapters on every platform.
 *
 * The diagnostics adapters branch on the database platform and parse what each engine's views return.
 * The integration suite proves the statements against real MariaDB, MySQL and PostgreSQL servers; this
 * support lets one engine's CI leg also execute the other engines' parsing, classification and refusal
 * branches. The connection, parameter expansion, platform quoting and exception conversion are DBAL's
 * own; only the rows each statement returns, or the driver error it raises, are scripted. Every
 * statement the adapter issues is recorded so a test can assert the exact timeout syntax it used.
 *
 * @since  2.0.0
 */
final class ScriptedDiagnosticDatabase
{
    /**
     * Statements the adapter issued, with their bound parameters, in order.
     *
     * @var    list<array{sql: string, parameters: array<int|string, mixed>}>
     * @since  2.0.0
     */
    public array $statements = [];

    /**
     * Build the scripted database for one platform.
     *
     * @param  AbstractPlatform  $platform   Platform the connection reports.
     * @param  Closure(string, array<int|string, mixed>): (list<array<string, mixed>>|AbstractException)  $responder
     *         Answers each statement with rows, or with the driver error to raise.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly AbstractPlatform $platform, private readonly Closure $responder)
    {
    }

    /**
     * Open a DBAL connection whose driver answers through the responder.
     *
     * @return  Connection  A real DBAL connection.
     *
     * @since   2.0.0
     */
    public function connection(): Connection
    {
        return new Connection(['dbname' => 'scripted'], new ScriptedDiagnosticDriver($this));
    }

    /**
     * The platform the connection reports.
     *
     * @return  AbstractPlatform  As constructed.
     *
     * @since   2.0.0
     */
    public function platform(): AbstractPlatform
    {
        return $this->platform;
    }

    /**
     * Record and answer one statement.
     *
     * @param   string                    $sql         Statement text after DBAL expansion.
     * @param   array<int|string, mixed>  $parameters  Bound parameters.
     *
     * @return  Result  The scripted rows.
     *
     * @throws  AbstractException  When the responder scripts a driver error.
     *
     * @since   2.0.0
     */
    public function answer(string $sql, array $parameters): Result
    {
        $this->statements[] = ['sql' => $sql, 'parameters' => $parameters];
        $answer = ($this->responder)($sql, $parameters);
        if ($answer instanceof AbstractException) {
            throw $answer;
        }
        $columns = $answer === [] ? [] : array_keys($answer[0]);
        $rows = [];
        foreach ($answer as $row) {
            $rows[] = array_values($row);
        }

        return new ArrayResult($columns, $rows);
    }

    /**
     * Build a driver error carrying an SQLSTATE and a native error code.
     *
     * @param   string|null  $sqlState  SQLSTATE the engine reports.
     * @param   int          $code      Native engine error number.
     *
     * @return  AbstractException  The driver error.
     *
     * @since   2.0.0
     */
    public static function failure(?string $sqlState, int $code = 0): AbstractException
    {
        return new ScriptedDiagnosticDriverException('Scripted engine refusal.', $sqlState, $code);
    }
}

/**
 * Driver handing out scripted connections for one platform.
 *
 * @since  2.0.0
 */
final class ScriptedDiagnosticDriver implements Driver
{
    /**
     * Bind the driver to its scripted database.
     *
     * @param  ScriptedDiagnosticDatabase  $database  Answers every statement.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly ScriptedDiagnosticDatabase $database)
    {
    }

    /**
     * Open a scripted driver connection.
     *
     * @param   array<string, mixed>  $params  Ignored.
     *
     * @return  DriverConnection  The scripted connection.
     *
     * @since   2.0.0
     */
    public function connect(#[SensitiveParameter] array $params): DriverConnection
    {
        return new ScriptedDiagnosticDriverConnection($this->database);
    }

    /**
     * Report the scripted platform.
     *
     * @param   ServerVersionProvider  $versionProvider  Ignored.
     *
     * @return  AbstractPlatform  The scripted platform.
     *
     * @since   2.0.0
     */
    public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
    {
        return $this->database->platform();
    }

    /**
     * Convert driver errors exactly as the real driver family does.
     *
     * @return  ExceptionConverter  MySQL-family or PostgreSQL converter.
     *
     * @since   2.0.0
     */
    public function getExceptionConverter(): ExceptionConverter
    {
        return $this->database->platform() instanceof AbstractMySQLPlatform
            ? new MySQLExceptionConverter()
            : new PostgreSQLExceptionConverter();
    }
}

/**
 * Driver connection that answers every statement through the scripted database.
 *
 * @since  2.0.0
 */
final class ScriptedDiagnosticDriverConnection implements DriverConnection
{
    /**
     * Bind the connection to its scripted database.
     *
     * @param  ScriptedDiagnosticDatabase  $database  Answers every statement.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly ScriptedDiagnosticDatabase $database)
    {
    }

    /**
     * Prepare a statement that answers on execution.
     *
     * @param   string  $sql  Statement text.
     *
     * @return  Statement  The scripted statement.
     *
     * @since   2.0.0
     */
    public function prepare(string $sql): Statement
    {
        return new ScriptedDiagnosticStatement($this->database, $sql);
    }

    /**
     * Answer an unprepared statement.
     *
     * @param   string  $sql  Statement text.
     *
     * @return  Result  The scripted rows.
     *
     * @since   2.0.0
     */
    public function query(string $sql): Result
    {
        return $this->database->answer($sql, []);
    }

    /**
     * Quote a literal the way the tests expect.
     *
     * @param   string  $value  Literal.
     *
     * @return  string  Single-quoted literal.
     *
     * @since   2.0.0
     */
    public function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Answer a statement that returns no rows.
     *
     * @param   string  $sql  Statement text.
     *
     * @return  int  Always zero affected rows.
     *
     * @since   2.0.0
     */
    public function exec(string $sql): int
    {
        $this->database->answer($sql, []);

        return 0;
    }

    /**
     * Not supported by a read-only diagnostics double.
     *
     * @return  int  Never returns.
     *
     * @throws  LogicException  Always.
     *
     * @since   2.0.0
     */
    public function lastInsertId(): int
    {
        throw new LogicException('The scripted diagnostics database inserts nothing.');
    }

    /**
     * Accept a transaction start.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function beginTransaction(): void
    {
    }

    /**
     * Accept a commit.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function commit(): void
    {
    }

    /**
     * Accept a rollback.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function rollBack(): void
    {
    }

    /**
     * Report the version the scripted platform stands for.
     *
     * @return  string  A fixed version string.
     *
     * @since   2.0.0
     */
    public function getServerVersion(): string
    {
        return 'scripted';
    }

    /**
     * There is no native connection.
     *
     * @return  object  A placeholder object.
     *
     * @since   2.0.0
     */
    public function getNativeConnection(): object
    {
        return $this->database;
    }
}

/**
 * Prepared statement that collects bound values and answers on execution.
 *
 * @since  2.0.0
 */
final class ScriptedDiagnosticStatement implements Statement
{
    /**
     * Values bound so far.
     *
     * @var    array<int|string, mixed>
     * @since  2.0.0
     */
    private array $parameters = [];

    /**
     * Bind the statement to its database and text.
     *
     * @param  ScriptedDiagnosticDatabase  $database  Answers on execution.
     * @param  string                      $sql       Statement text.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly ScriptedDiagnosticDatabase $database, private readonly string $sql)
    {
    }

    /**
     * Collect one bound value.
     *
     * @param   int|string     $param  Position or name.
     * @param   mixed          $value  Value.
     * @param   ParameterType  $type   Ignored.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->parameters[$param] = $value;
    }

    /**
     * Answer through the scripted database.
     *
     * @return  Result  The scripted rows.
     *
     * @since   2.0.0
     */
    public function execute(): Result
    {
        return $this->database->answer($this->sql, $this->parameters);
    }
}

/**
 * Driver error carrying the SQLSTATE and native code a real engine would report.
 *
 * @since  2.0.0
 */
final class ScriptedDiagnosticDriverException extends AbstractException
{
}
