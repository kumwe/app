<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\BusinessSchema;

use Kumwe\CMS\BusinessRecord\Application\BusinessRecordService;
use Kumwe\CMS\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\CMS\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\CMS\BusinessSchema\Application\PhysicalSchemaGateway;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\NeutralBusinessFixture;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversNothing]
final class BusinessSchemaPhysicalDefaultIntegrationTest extends TestCase
{
    public function testExactDefaultsInstallAndReinspectOnTheConfiguredDatabaseEngine(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 12));
        $definitionId = Uuid::uuid7()->toString();
        $document = NeutralBusinessFixture::document($suffix, $definitionId);
        $defaults = [
            'amount' => '1.25',
            'price' => ['amount' => '19.99', 'currency' => 'NAD'],
            'quantity' => ['amount' => '2.5', 'unit' => 'unit'],
            'service_date' => '2026-08-08',
            'local_time' => '13:14:15',
            'recorded_at' => '2026-08-08T11:14:15.123456Z',
            'scheduled_for' => [
                'instant' => '2026-08-08T11:14:15.123456Z',
                'timezone' => 'Africa/Windhoek',
            ],
        ];
        foreach ($document['fields'] as &$field) {
            if (
                is_array($field)
                && is_string($field['handle'] ?? null)
                && array_key_exists($field['handle'], $defaults)
            ) {
                $field['default'] = $defaults[$field['handle']];
            }
        }
        unset($field);

        $definition = NeutralBusinessFixture::install($container, $context, $document);
        $schemas = $container->get(BusinessSchemaService::class);
        $physical = $container->get(PhysicalSchemaGateway::class);
        $records = $container->get(BusinessRecordService::class);
        self::assertInstanceOf(BusinessSchemaService::class, $schemas);
        self::assertInstanceOf(PhysicalSchemaGateway::class, $physical);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        $installation = $schemas->installation($context, $definition->id);
        self::assertNotNull($installation);

        self::assertNotNull($physical->inspect($installation->blueprint));

        $recordId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $definition->handle,
            ['name' => 'Portable defaults', 'credential' => 'default-test-secret'],
            NeutralBusinessFixture::idempotencyKey('defaults-' . $suffix),
            recordId: $recordId,
        ));
        $record = $records->read(new ReadRecordQuery($context, $definition->handle, $recordId));
        self::assertSame('draft', $record->values['status']);
        self::assertFalse($record->values['enabled']);
        self::assertSame('1.250000000000000000000000000000', $record->values['amount']->value());
        self::assertSame(
            ['amount' => '19.990000000000000000000000000000', 'currency' => 'NAD'],
            $record->values['price']->toArray(),
        );
        self::assertSame(
            ['amount' => '2.500000000000000000000000000000', 'unit' => 'unit'],
            $record->values['quantity']->toArray(),
        );
        self::assertSame('2026-08-08', $record->values['service_date']->format('Y-m-d'));
        self::assertSame('13:14:15.000000', $record->values['local_time']->format('H:i:s.u'));
        self::assertSame(
            '2026-08-08T11:14:15.123456+00:00',
            $record->values['recorded_at']->format('Y-m-d\TH:i:s.uP'),
        );
        self::assertSame(
            ['instant' => '2026-08-08T11:14:15.123456Z', 'timezone' => 'Africa/Windhoek'],
            $record->values['scheduled_for']->toArray(),
        );
    }
}
