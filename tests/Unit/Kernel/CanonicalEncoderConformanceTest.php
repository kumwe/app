<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Kernel;

use DateTimeImmutable;
use Kumwe\App\Application\Automation\ChangePlan;
use Kumwe\App\Application\Automation\ScheduleOccurrenceKey;
use Kumwe\App\Kernel\NativeComputationFactory;
use Kumwe\App\Tests\Support\NativeComputationContainer;
use Kumwe\CanonicalJson\CanonicalEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Keeps persisted App digests stable across the native encoder integration.
 *
 * The canonical-json and computation packages own generic encoding and corpus conformance. These tests
 * cover App payload families, change plans and schedule occurrence keys through the production binding.
 *
 * @since  2.0.0
 */
#[CoversClass(NativeComputationFactory::class)]
#[CoversClass(ChangePlan::class)]
#[CoversClass(ScheduleOccurrenceKey::class)]
final class CanonicalEncoderConformanceTest extends TestCase
{
    /**
     * Bytes and digests the retired App encoder produced for one representative payload per family.
     *
     * @var    array<string, array{value: array<string, mixed>|list<mixed>, encoded: string, digest: string}>
     * @since  2.0.0
     */
    private const array FAMILIES = [
        'idempotency request' => [
            'value' => [
                'method' => 'POST',
                'path' => '/api/v1/business/records/acme.invoice',
                'site' => 'default',
                'body' => [
                    'reference' => 'INV-0001',
                    'amount' => '12.500000',
                    'lines' => [['sku' => 'A/1', 'quantity' => 2], ['sku' => 'B-2', 'quantity' => 1]],
                    'note' => 'Ångström ✓ 1/2',
                ],
            ],
            'encoded' => '{"body":{"amount":"12.500000","lines":[{"quantity":2,"sku":"A/1"},{"quantity":1,'
                . '"sku":"B-2"}],"note":"Ångström ✓ 1/2","reference":"INV-0001"},"method":"POST",'
                . '"path":"/api/v1/business/records/acme.invoice","site":"default"}',
            'digest' => 'fad985151c888442d3f63ee1eb5963bc4215223c2f33f7253201dd65df74fc2d',
        ],
        'outbox envelope' => [
            'value' => [
                'event_type' => 'kumwe.business_record.mutated',
                'schema_version' => 1,
                'event_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb301',
                'occurred_at' => '2026-08-04T12:00:00.123456+00:00',
                'actor_id' => 'user:one',
                'system_identity' => null,
                'site_identifier' => 'default',
                'organization_id' => null,
                'aggregate_type' => 'business_record',
                'aggregate_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb302',
                'aggregate_version' => 3,
                'correlation_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb303',
                'causation_id' => null,
                'sensitivity' => 'internal',
                'payload' => [
                    'definition' => 'acme.invoice',
                    'record_id' => 'INV-0001',
                    'changed' => ['amount', 'status'],
                    'ratio' => 1.0,
                    'enabled' => true,
                ],
            ],
            'encoded' => '{"actor_id":"user:one","aggregate_id":"018f22e2-7c8b-7ab0-8f3a-88e8026bb302",'
                . '"aggregate_type":"business_record","aggregate_version":3,"causation_id":null,'
                . '"correlation_id":"018f22e2-7c8b-7ab0-8f3a-88e8026bb303",'
                . '"event_id":"018f22e2-7c8b-7ab0-8f3a-88e8026bb301","event_type":"kumwe.business_record.mutated",'
                . '"occurred_at":"2026-08-04T12:00:00.123456+00:00","organization_id":null,'
                . '"payload":{"changed":["amount","status"],"definition":"acme.invoice","enabled":true,"ratio":1.0,'
                . '"record_id":"INV-0001"},"schema_version":1,"sensitivity":"internal","site_identifier":"default",'
                . '"system_identity":null}',
            'digest' => 'cbaabc635705a55ee23b37a19e74fe9452028695e22ebe415b87edb0ba47e070',
        ],
        'projection source envelope' => [
            'value' => [
                'event_type' => 'kumwe.business_record.deleted',
                'schema_version' => 2,
                'event_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb401',
                'occurred_at' => '2026-08-05T08:30:15.000001+02:00',
                'actor_id' => null,
                'system_identity' => 'scheduler',
                'site_identifier' => 'default',
                'organization_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb402',
                'aggregate_type' => 'business_record',
                'aggregate_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb403',
                'aggregate_version' => 1,
                'correlation_id' => null,
                'causation_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb404',
                'sensitivity' => 'restricted',
                'payload' => [
                    'definition' => 'acme.invoice',
                    'record_id' => 'INV-0002',
                    'balance' => '-0.00',
                    'weights' => [0.5, -0.0, 3],
                ],
            ],
            'encoded' => '{"actor_id":null,"aggregate_id":"018f22e2-7c8b-7ab0-8f3a-88e8026bb403",'
                . '"aggregate_type":"business_record","aggregate_version":1,'
                . '"causation_id":"018f22e2-7c8b-7ab0-8f3a-88e8026bb404","correlation_id":null,'
                . '"event_id":"018f22e2-7c8b-7ab0-8f3a-88e8026bb401","event_type":"kumwe.business_record.deleted",'
                . '"occurred_at":"2026-08-05T08:30:15.000001+02:00",'
                . '"organization_id":"018f22e2-7c8b-7ab0-8f3a-88e8026bb402",'
                . '"payload":{"balance":"-0.00","definition":"acme.invoice","record_id":"INV-0002",'
                . '"weights":[0.5,-0.0,3]},"schema_version":2,"sensitivity":"restricted","site_identifier":"default",'
                . '"system_identity":"scheduler"}',
            'digest' => 'd51c40d372275db0fd8f734f6e33745e19fb322c837ab97d93b286e62b4358f4',
        ],
        'access-control snapshot' => [
            'value' => [
                ['018f22e2-7c8b-7ab0-8f3a-88e8026bb501', 'content.delete', 'global', null],
                [
                    '018f22e2-7c8b-7ab0-8f3a-88e8026bb502',
                    'content.update',
                    'content',
                    '018f22e2-7c8b-7ab0-8f3a-88e8026bb503',
                ],
            ],
            'encoded' => '[["018f22e2-7c8b-7ab0-8f3a-88e8026bb501","content.delete","global",null],'
                . '["018f22e2-7c8b-7ab0-8f3a-88e8026bb502","content.update","content",'
                . '"018f22e2-7c8b-7ab0-8f3a-88e8026bb503"]]',
            'digest' => 'aaba04f41563b268ca1dde3d38fb471bd8c7d18fcc6c9885facfeb05d2274cb7',
        ],
    ];

    /**
     * Digest the retired App encoder produced for the change-plan payload `changePlan()` previews.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string CHANGE_PLAN_DIGEST = 'ddda5105016a9989b83871666ff19f78f3ede70aaf1b55bd7563ab4d8a6538fe';

    /**
     * Digest the retired App encoder produced for schedule `schedule-1` at `2026-08-04T12:00:00.123456Z`.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string OCCURRENCE_DIGEST = 'e62ee652a2ae35cd95f8c8c23aaf01027dcdcdb738bfaf1eef7c2f8519ef77fa';

    /**
     * Every persisted digest family keeps the bytes and digests the retired App encoder wrote for it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryPersistedDigestFamilyKeepsTheBytesTheRetiredAppEncoderProduced(): void
    {
        $encoder = NativeComputationContainer::encoder();
        foreach (self::FAMILIES as $family => $vector) {
            self::assertSame($vector['encoded'], $encoder->encode($vector['value']), $family);
            self::assertSame($vector['digest'], $encoder->digest($vector['value']), $family);
        }
        self::assertSame(self::CHANGE_PLAN_DIGEST, self::changePlan($encoder, [
            'version' => 3,
            'content' => ['title' => 'Kumwe', 'state' => 'draft'],
        ])->digest());
        self::assertSame(self::CHANGE_PLAN_DIGEST, self::changePlan($encoder, [
            'content' => ['state' => 'draft', 'title' => 'Kumwe'],
            'version' => 3,
        ])->digest());
        self::assertSame(
            'schedule:' . self::OCCURRENCE_DIGEST,
            (string) ScheduleOccurrenceKey::for(
                $encoder,
                'schedule-1',
                new DateTimeImmutable('2026-08-04T14:00:00.123456+02:00'),
            ),
        );
    }

    /**
     * Preview one change plan through the encoder under test.
     *
     * @param   CanonicalEncoder      $encoder  Encoder the plan is fingerprinted with.
     * @param   array<string, mixed>  $payload  Arguments the previewed command carries.
     *
     * @return  ChangePlan  Plan over the `content.publish` command.
     *
     * @since   2.0.0
     */
    private static function changePlan(CanonicalEncoder $encoder, array $payload): ChangePlan
    {
        return ChangePlan::create(
            $encoder,
            'plan-1',
            'content.publish',
            $payload,
            new DateTimeImmutable('2026-08-04T12:00:00+00:00'),
            300,
        );
    }
}
