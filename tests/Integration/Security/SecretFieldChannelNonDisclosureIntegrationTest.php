<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Security;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\BinaryType;
use Doctrine\DBAL\Types\BlobType;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use Kumwe\App\BusinessRecord\Application\BusinessRecordMutationPublication;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Pins that a secret-sensitivity field value exists in plaintext in no channel at all.
 *
 * A record carrying a secret is created and then updated through the production record service, which
 * drives every channel a value can leak through in one transaction: the generated table, the revision
 * history, the audit trail and its anchors, the integration outbox and journal, the idempotency ledger
 * that stores results for replay, and the application log. Every column of every table the installation
 * owns is then searched for either plaintext, together with every log record written meanwhile, and the
 * read view is checked for withholding the value. Secrets are sealed before they are stored, so a single
 * plaintext hit anywhere is a disclosure.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessRecordService::class)]
#[CoversClass(BusinessRecordMutationPublication::class)]
final class SecretFieldChannelNonDisclosureIntegrationTest extends TestCase
{
    /**
     * Neither the created nor the updated secret appears in any table, log record or read view.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASecretValueAppearsInPlaintextInNoTableLogOrReadView(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $database = $container->get(Connection::class);
        $logger = $container->get(LoggerInterface::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(Logger::class, $logger);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $definition = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::document($suffix, Uuid::uuid7()->toString()),
        );
        $logs = new TestHandler();
        $logger->pushHandler($logs);
        $created = 'created-secret-sentinel-' . bin2hex(random_bytes(8));
        $updated = 'updated-secret-sentinel-' . bin2hex(random_bytes(8));
        $recordId = Uuid::uuid7()->toString();

        try {
            $values = NeutralBusinessFixture::recordValues('Secret channel record');
            $values['credential'] = $created;
            $first = $records->create(new CreateRecordCommand(
                $context,
                $definition->handle,
                $values,
                NeutralBusinessFixture::idempotencyKey('secret-channel-create-' . $suffix),
                recordId: $recordId,
            ));
            $records->update(new UpdateRecordCommand(
                $context,
                $definition->handle,
                $recordId,
                $first->version,
                ['credential' => $updated, 'name' => 'Secret channel record, rotated'],
                NeutralBusinessFixture::idempotencyKey('secret-channel-update-' . $suffix),
            ));
            $view = $records->read(new ReadRecordQuery($context, $definition->handle, $recordId));
        } finally {
            $logger->popHandler();
        }

        self::assertSame('Secret channel record, rotated', $view->values['name'] ?? null);
        $control = 'Secret channel record, rotated';
        $hits = $this->plaintextHits($database, [$control, $created, $updated]);
        self::assertNotSame([], $hits[$control], 'The search finds a stored non-secret value, so empty means absent.');
        $rendered = json_encode($view->values, JSON_THROW_ON_ERROR);
        foreach ([$created, $updated] as $sentinel) {
            self::assertStringNotContainsString($sentinel, $rendered, 'The read view withholds the secret.');
            self::assertSame([], $hits[$sentinel], 'No column stores the plaintext.');
            foreach ($logs->getRecords() as $record) {
                self::assertStringNotContainsString($sentinel, json_encode(
                    [$record->message, $record->context, $record->extra],
                    JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR,
                ));
            }
        }
    }

    /**
     * Search every character, JSON and binary column of every installation table for plaintext values.
     *
     * @param   Connection    $database  Suite database.
     * @param   list<string>  $needles   Plaintexts to look for, searched in one pass per column.
     *
     * @return  array<string, list<string>>  For each needle, `table.column` of every column containing it.
     *
     * @since   2.0.0
     */
    private function plaintextHits(Connection $database, array $needles): array
    {
        $prefix = Environment::fromGlobals()->string('DB_TABLE_PREFIX', 'kumwe_');
        $postgres = $database->getDatabasePlatform() instanceof PostgreSQLPlatform;
        $schema = $database->createSchemaManager();
        $hits = array_fill_keys($needles, []);
        foreach ($schema->listTableNames() as $table) {
            if (!str_starts_with($table, $prefix)) {
                continue;
            }
            foreach ($schema->listTableColumns($table) as $column) {
                $type = $column->getType();
                $binary = $type instanceof BlobType || $type instanceof BinaryType;
                $textual = $type instanceof StringType || $type instanceof TextType || $type instanceof JsonType;
                if (!$binary && !$textual) {
                    continue;
                }
                $name = $database->quoteSingleIdentifier($column->getName());
                $text = match (true) {
                    $postgres && $binary => sprintf("encode(%s, 'escape')", $name),
                    $postgres => sprintf('CAST(%s AS TEXT)', $name),
                    default => sprintf('CAST(%s AS CHAR)', $name),
                };
                $sums = implode(', ', array_map(
                    static fn (int $index): string => sprintf(
                        'SUM(CASE WHEN %s LIKE ? THEN 1 ELSE 0 END) AS n%d',
                        $text,
                        $index,
                    ),
                    array_keys($needles),
                ));
                $counts = $database->fetchNumeric(
                    sprintf('SELECT %s FROM %s', $sums, $database->quoteSingleIdentifier($table)),
                    array_map(static fn (string $needle): string => '%' . $needle . '%', $needles),
                );
                foreach ($needles as $index => $needle) {
                    if ((int) (($counts ?: [])[$index] ?? 0) > 0) {
                        $hits[$needle][] = $table . '.' . $column->getName();
                    }
                }
            }
        }

        return $hits;
    }
}
