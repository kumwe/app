<?php

declare(strict_types=1);

namespace @@PHP_NAMESPACE@@\Tests;

use DateTimeImmutable;
use @@PHP_NAMESPACE@@\Integration\DigestJobHandler;
use @@PHP_NAMESPACE@@\Integration\IntegrationDefinitions;
use @@PHP_NAMESPACE@@\Integration\IntegrationLedger;
use @@PHP_NAMESPACE@@\Integration\ItemDomainListener;
use @@PHP_NAMESPACE@@\Integration\ItemIntegrationConsumer;
use @@PHP_NAMESPACE@@\Integration\ItemProjectionBuilder;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\SystemIdentity;
use Kumwe\CMS\BusinessIntegration\Domain\DomainEvent;
use Kumwe\CMS\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\CMS\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\CMS\BusinessReporting\Application\ProjectionEvent;
use Kumwe\CMS\BusinessReporting\Application\ProjectionWriter;
use Kumwe\CMS\BusinessReporting\Domain\ProjectionDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Verifies signed integration parity and executable idempotent handler behavior.
 *
 * @since  2.0.0
 */
final class IntegrationContributionTest extends TestCase
{
    /**
     * Compare every generated integration contract with the exact signed manifest declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIntegrationDefinitionsMatchManifest(): void
    {
        $json = file_get_contents(dirname(__DIR__) . '/kumwe.json');
        self::assertIsString($json);
        $manifest = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        $integration = $manifest['contributions']['integration'];

        self::assertSame($integration['event_schemas'][0], IntegrationDefinitions::eventSchema()->toArray());
        self::assertSame($integration['domain_listeners'][0], IntegrationDefinitions::domainListener()->toArray());
        self::assertSame($integration['consumers'][0], IntegrationDefinitions::consumer()->toArray());
        self::assertSame($integration['jobs'][0], IntegrationDefinitions::job()->toArray());
        self::assertSame($integration['queues'][0], IntegrationDefinitions::queue()->toArray());
        self::assertSame($integration['schedules'][0], IntegrationDefinitions::schedule()->toArray());
        self::assertSame($integration['projections'][0], IntegrationDefinitions::projection()->toArray());
        self::assertSame($integration['reports'][0], IntegrationDefinitions::report()->toArray());
    }

    /**
     * Prove listeners, durable consumers, and jobs execute safely more than once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedHandlersAreIdempotentAndRunnable(): void
    {
        $ledger = new IntegrationLedger();
        $event = $this->event();
        $listener = new ItemDomainListener(IntegrationDefinitions::domainListener(), $ledger);
        $listener->handle($event);
        $listener->handle($event);
        $context = ExecutionContext::issueSystem(
            new \stdClass(),
            SystemIdentity::Worker,
            SiteContext::default(),
            'extension-test',
        );
        $consumerDefinition = IntegrationDefinitions::consumer();
        $consumer = new ItemIntegrationConsumer($consumerDefinition, $ledger);
        $integrationEvent = IntegrationEvent::fromDomain($event);
        $consumer->handle($integrationEvent, $context);
        $consumer->handle($integrationEvent, $context);
        $job = new DigestJobHandler($ledger);
        $job->handle(['message' => 'scheduled-health'], $context);
        $job->handle(['message' => 'scheduled-health'], $context);

        self::assertSame([
            'domain_events' => 1,
            'integration_events' => 1,
            'latest_job_digest' => hash('sha256', 'scheduled-health'),
        ], $ledger->snapshot());
    }

    /**
     * Prove the projection builder writes the same complete row for a declared immutable event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedProjectionBuilderIsRunnable(): void
    {
        $writer = new class implements ProjectionWriter {
            /** @var list<array{key: array<string, bool|int|string>, values: array<string, bool|int|string|null>}> */
            public array $rows = [];

            public ?string $projection = null;

            /** @var ?array{sequence: int, checksum: string} */
            public ?array $lastCheckpoint = null;

            public function begin(ProjectionDefinition $definition): void
            {
                $this->rows = [];
                $this->projection = $definition->identifier();
                $this->lastCheckpoint = null;
            }

            public function put(array $key, array $values): void
            {
                $this->rows[] = ['key' => $key, 'values' => $values];
            }

            public function remove(array $key): void
            {
                $this->rows = array_values(array_filter(
                    $this->rows,
                    static fn (array $row): bool => $row['key'] !== $key,
                ));
            }

            public function checkpoint(int $sequence, string $eventChecksum): void
            {
                $this->lastCheckpoint = ['sequence' => $sequence, 'checksum' => $eventChecksum];
            }

            public function commit(): string
            {
                return hash('sha256', json_encode($this->rows, JSON_THROW_ON_ERROR));
            }

            public function rollback(): void
            {
                $this->rows = [];
            }
        };
        $definition = IntegrationDefinitions::projection();
        $writer->begin($definition);
        (new ItemProjectionBuilder($definition))->apply($definition, new ProjectionEvent(
            1,
            '0f998d3d-cfd2-5362-b49e-916029a2a42f',
            '@@EXTENSION_DOTTED@@.item_observed',
            1,
            new DateTimeImmutable('2026-08-10T00:00:00+00:00'),
            ['item_id' => 'item-1', 'title' => 'Example item'],
        ), $writer);

        self::assertSame([[
            'key' => ['item_id' => 'item-1'],
            'values' => ['item_id' => 'item-1', 'title' => 'Example item'],
        ]], $writer->rows);
    }

    /**
     * Build one valid owned item event for executable-handler tests.
     *
     * @return  DomainEvent  Immutable version-one item-observed event.
     *
     * @since   2.0.0
     */
    private function event(): DomainEvent
    {
        return new DomainEvent(
            '@@EXTENSION_DOTTED@@.item_observed',
            1,
            '0f998d3d-cfd2-5362-b49e-916029a2a42f',
            new DateTimeImmutable('2026-08-10T00:00:00+00:00'),
            null,
            'system:worker',
            'default',
            null,
            '@@EXTENSION_DOTTED@@.item',
            'item-1',
            1,
            'extension-test',
            'extension-test',
            EventSensitivity::INTERNAL,
            ['item_id' => 'item-1', 'title' => 'Example item'],
        );
    }
}
