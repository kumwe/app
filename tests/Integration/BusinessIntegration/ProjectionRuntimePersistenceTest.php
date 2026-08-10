<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\BusinessIntegration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kumwe\CMS\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\CMS\BusinessIntegration\Application\TrustedRuntimeGenerationGuard;
use Kumwe\CMS\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\CMS\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\CMS\BusinessIntegration\Infrastructure\DoctrineOutboxStore;
use Kumwe\CMS\BusinessReporting\Application\ProjectionBuilder;
use Kumwe\CMS\BusinessReporting\Application\ProjectionEvent;
use Kumwe\CMS\BusinessReporting\Application\ProjectionWriter;
use Kumwe\CMS\BusinessReporting\Domain\ProjectionDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ProjectionFieldDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ProjectionSourceDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ReportValueType;
use Kumwe\CMS\BusinessReporting\Infrastructure\DoctrineProjectionRuntime;
use Kumwe\CMS\BusinessReporting\Infrastructure\DoctrineProjectionStore;
use Kumwe\CMS\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\CMS\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\CMS\Infrastructure\Persistence\Migration\BusinessIntegrationSdkMigration;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

#[CoversClass(DoctrineProjectionStore::class)]
#[CoversClass(DoctrineProjectionRuntime::class)]
final class ProjectionRuntimePersistenceTest extends TestCase
{
    private Connection $database;

    private TableNames $tables;

    private DoctrineTransactionManager $transactions;

    private ProjectionRuntimeClock $clock;

    private DoctrineOutboxStore $outbox;

    private ProjectionDefinition $definition;

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->tables = new TableNames($this->database, '');
        $this->transactions = new DoctrineTransactionManager($this->database);
        $this->clock = new ProjectionRuntimeClock();
        $schema = new EventSchemaDefinition(
            'business.record.changed',
            1,
            EventSensitivity::INTERNAL,
            [
                'type' => 'object',
                'required' => ['record_id', 'value'],
                'properties' => [
                    'record_id' => ['type' => 'string'],
                    'value' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
        );
        $contracts = new EventContractRegistry([$schema], []);
        $migration = new BusinessIntegrationSdkMigration($this->tables);
        $migration->up($this->database);
        $migration->up($this->database);
        $this->outbox = new DoctrineOutboxStore(
            $this->database,
            $this->tables,
            $this->transactions,
            $this->clock,
            $contracts,
        );
        $this->definition = new ProjectionDefinition(
            'acme.record_activity',
            1,
            '1.0.0',
            EventSensitivity::INTERNAL,
            [new ProjectionSourceDefinition('business.record.changed', [1])],
            [
                new ProjectionFieldDefinition('record_id', ReportValueType::Identifier),
                new ProjectionFieldDefinition('value', ReportValueType::String),
            ],
            ['record_id'],
            2,
        );
    }

    public function testLiveApplyIsIdempotentAndManualRebuildIsReproducible(): void
    {
        $builder = new ProjectionRuntimeBuilder($this->definition);
        $runtime = $this->runtime($builder);
        $first = $this->event(1, 'first');
        $this->append($first);
        $runtime->apply($first);
        $runtime->apply($first);
        self::assertSame(1, $builder->applications);

        $second = $this->event(2, 'second');
        $this->append($second);
        $runtime->apply($second);
        $runtime->apply($second);
        self::assertSame(2, $builder->applications);
        self::assertSame('second', $this->activeValue());

        $firstRebuild = $runtime->rebuild($this->definition->identifier());
        $firstGeneration = $runtime->inventory()[0]['active_generation'];
        $secondRebuild = $runtime->rebuild($this->definition->identifier());
        $secondGeneration = $runtime->inventory()[0]['active_generation'];

        self::assertSame(2, $firstRebuild->eventCount);
        self::assertSame($firstRebuild->sourceChecksum, $secondRebuild->sourceChecksum);
        self::assertSame($firstRebuild->projectionChecksum, $secondRebuild->projectionChecksum);
        self::assertIsArray($firstGeneration);
        self::assertIsArray($secondGeneration);
        self::assertNotSame($firstGeneration['generation_id'], $secondGeneration['generation_id']);
        self::assertSame($secondRebuild->projectionChecksum, $secondGeneration['projection_checksum']);
        self::assertSame(1, (int) $this->database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE projection_id = ? AND status = 'active'",
            $this->tables->quoted('business_projection_generations'),
        ), [$this->definition->identifier()]));
        self::assertSame(2, (int) $this->database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE projection_id = ? AND status = 'superseded'",
            $this->tables->quoted('business_projection_generations'),
        ), [$this->definition->identifier()]));
        self::assertSame(2, (int) $this->database->fetchOne(sprintf(
            'SELECT last_sequence FROM %s WHERE singleton_id = 1',
            $this->tables->quoted('business_projection_event_head'),
        )));
        self::assertSame('second', $this->activeValue());
    }

    public function testFailedReplacementPreservesThePreviouslyActiveGeneration(): void
    {
        $first = $this->event(1, 'first');
        $second = $this->event(2, 'second');
        $this->append($first);
        $this->append($second);
        $healthy = $this->runtime(new ProjectionRuntimeBuilder($this->definition));
        $healthy->apply($second);
        $before = $healthy->inventory()[0]['active_generation'];
        self::assertIsArray($before);

        $failing = $this->runtime(new ProjectionRuntimeBuilder($this->definition, 2));
        try {
            $failing->rebuild($this->definition->identifier());
            self::fail('A failed builder must abort its replacement generation.');
        } catch (RuntimeException $exception) {
            self::assertSame('projection builder failed', $exception->getMessage());
        }

        $after = $healthy->inventory()[0]['active_generation'];
        self::assertIsArray($after);
        self::assertSame($before['generation_id'], $after['generation_id']);
        self::assertSame($before['projection_checksum'], $after['projection_checksum']);
        self::assertSame(0, (int) $this->database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE status = 'building'",
            $this->tables->quoted('business_projection_generations'),
        )));
        self::assertSame('second', $this->activeValue());
    }

    private function runtime(ProjectionRuntimeBuilder $builder): DoctrineProjectionRuntime
    {
        return new DoctrineProjectionRuntime(
            $this->database,
            $this->tables,
            $this->transactions,
            $this->clock,
            new ProjectionRuntimeGenerationGuard(),
            new RuntimeMaterializationState('projection-test', 7, '', '', true),
            [['definition' => $this->definition, 'implementation' => $builder]],
        );
    }

    private function append(IntegrationEvent $event): void
    {
        $this->transactions->transactional(function () use ($event): void {
            $this->outbox->append($event);
        });
    }

    private function event(int $version, string $value): IntegrationEvent
    {
        return new IntegrationEvent(
            'business.record.changed',
            1,
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            'actor-1',
            null,
            'default',
            'organization-1',
            'business.record',
            'record-7',
            $version,
            'correlation-1',
            'request-' . $version,
            EventSensitivity::INTERNAL,
            ['record_id' => 'record-7', 'value' => $value],
        );
    }

    private function activeValue(): string
    {
        $value = $this->database->fetchOne(sprintf(
            'SELECT r.row_values FROM %s r INNER JOIN %s g ON g.generation_id = r.generation_id '
            . "WHERE g.projection_id = ? AND g.status = 'active'",
            $this->tables->quoted('business_projection_rows'),
            $this->tables->quoted('business_projection_generations'),
        ), [$this->definition->identifier()]);
        self::assertIsString($value);
        $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['value'] ?? null);

        return $decoded['value'];
    }
}

final class ProjectionRuntimeBuilder implements ProjectionBuilder
{
    public int $applications = 0;

    public function __construct(
        private readonly ProjectionDefinition $definition,
        private readonly ?int $failOnSequence = null,
    ) {
    }

    public function definition(): ProjectionDefinition
    {
        return $this->definition;
    }

    public function apply(
        ProjectionDefinition $definition,
        ProjectionEvent $event,
        ProjectionWriter $writer,
    ): void {
        if ($event->sequence === $this->failOnSequence) {
            throw new RuntimeException('projection builder failed');
        }
        ++$this->applications;
        $recordId = $event->payload['record_id'] ?? null;
        $value = $event->payload['value'] ?? null;
        if (!is_string($recordId) || !is_string($value)) {
            throw new RuntimeException('projection event payload is invalid');
        }
        $writer->put(
            ['record_id' => $recordId],
            ['record_id' => $recordId, 'value' => $value],
        );
    }
}

final readonly class ProjectionRuntimeGenerationGuard implements TrustedRuntimeGenerationGuard
{
    public function assertCurrent(string $generation): void
    {
        if ($generation !== '7') {
            throw new RuntimeException('projection runtime generation is stale');
        }
    }
}

final readonly class ProjectionRuntimeClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-10T12:00:00+00:00');
    }
}
