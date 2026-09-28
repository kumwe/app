<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Observability;

use DateTimeImmutable;
use Kumwe\Access\AuthorizationDecision;
use Kumwe\Access\AuthorizationDecisionRecorder;
use Kumwe\Access\AuthorizationResource;
use Kumwe\Access\Capability;
use Kumwe\Access\DecisionState;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\App\Identity\Application\Administration\AuthenticationRateLimiter;
use Kumwe\App\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\App\Identity\Application\Authentication\ScopedAccessTokenVerifier;
use Kumwe\App\Infrastructure\Observability\MeteredAccessTokenVerifier;
use Kumwe\App\Infrastructure\Observability\MeteredAuthenticationRateLimiter;
use Kumwe\App\Infrastructure\Observability\MeteredAuthorizationDecisionRecorder;
use Kumwe\App\Infrastructure\Observability\MetricCatalog;
use Kumwe\App\Infrastructure\Observability\MetricSample;
use Kumwe\App\Infrastructure\Observability\ObservabilityContract;
use Kumwe\App\Infrastructure\Observability\OperationalStatusCollector;
use Kumwe\App\Infrastructure\Observability\PrometheusExposition;
use Kumwe\App\Tests\Support\RecordingMetricRecorder;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Pins the recovery, storage, trust and security signals the P7-D alerts evaluate.
 *
 * The recovery gauges must read exactly what the shell tools record and treat anything else as never
 * recorded; the storage gauges must read a real filesystem; and each security decorator must count its one
 * event without changing the decision it wraps.
 *
 * @since  2.0.0
 */
#[CoversClass(OperationalStatusCollector::class)]
#[CoversClass(MeteredAuthorizationDecisionRecorder::class)]
#[CoversClass(MeteredAuthenticationRateLimiter::class)]
#[CoversClass(MeteredAccessTokenVerifier::class)]
#[CoversClass(MetricCatalog::class)]
final class OperationalSignalsTest extends TestCase
{
    /**
     * Recorded outcomes become timestamps, and absent, malformed or foreign documents publish zero.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRecordedOutcomesBecomeTimestampsAndAnythingElseReadsAsNeverRecorded(): void
    {
        $directory = self::directory();
        file_put_contents($directory . '/backup.json', json_encode([
            'schema' => 'kumwe-operation-status/v1',
            'operation' => 'backup',
            'last_outcome' => 'failure',
            'last_success_at' => 1_790_000_000,
            'last_failure_at' => 1_790_003_600,
        ], JSON_THROW_ON_ERROR));
        file_put_contents($directory . '/restore_verify.json', '{not json');
        file_put_contents($directory . '/restore.json', json_encode([
            'schema' => 'kumwe-operation-status/v1',
            'operation' => 'backup',
            'last_success_at' => 1_790_000_000,
        ], JSON_THROW_ON_ERROR));

        $values = self::values((new OperationalStatusCollector($directory, [], self::clock()))->collect());

        self::assertSame(1_790_000_000.0, $values['kumwe_recovery_last_success_timestamp_seconds{backup}']);
        self::assertSame(1_790_003_600.0, $values['kumwe_recovery_last_failure_timestamp_seconds{backup}']);
        self::assertSame(0.0, $values['kumwe_recovery_last_success_timestamp_seconds{restore_verify}']);
        self::assertSame(0.0, $values['kumwe_recovery_last_success_timestamp_seconds{restore}'], 'Foreign document.');
        self::assertSame(0.0, $values['kumwe_recovery_last_failure_timestamp_seconds{restore}']);
        self::assertCount(6, $values);
    }

    /**
     * Declared volumes on a real filesystem publish free and total bytes; undeclared or missing ones publish nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeclaredVolumesPublishRealFilesystemHeadroom(): void
    {
        $directory = self::directory();
        $collector = new OperationalStatusCollector($directory, [
            'storage' => $directory,
            'media' => $directory . '/absent',
            'tenant-uploads' => $directory,
        ], self::clock());

        $values = self::values($collector->collect());

        self::assertGreaterThan(0.0, $values['kumwe_storage_capacity_bytes{storage}']);
        self::assertGreaterThanOrEqual(0.0, $values['kumwe_storage_free_bytes{storage}']);
        self::assertLessThanOrEqual(
            $values['kumwe_storage_capacity_bytes{storage}'],
            $values['kumwe_storage_free_bytes{storage}'],
        );
        self::assertArrayNotHasKey('kumwe_storage_free_bytes{media}', $values);
        self::assertArrayNotHasKey('kumwe_storage_free_bytes{tenant-uploads}', $values);
    }

    /**
     * The trust gauge follows the runtime generation this process loaded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheTrustGaugeFollowsTheLoadedRuntimeGeneration(): void
    {
        $directory = self::directory();
        $trusted = new RuntimeMaterializationState('replica-a', 4, str_repeat('a', 64), str_repeat('b', 64), true);
        $untrusted = new RuntimeMaterializationState('replica-a', -1, '', '', false);

        $on = self::values((new OperationalStatusCollector($directory, [], self::clock(), $trusted))->collect());
        $off = self::values((new OperationalStatusCollector($directory, [], self::clock(), $untrusted))->collect());

        self::assertSame(1.0, $on['kumwe_extension_runtime_trusted']);
        self::assertSame(0.0, $off['kumwe_extension_runtime_trusted']);
    }

    /**
     * Every sample the collector emits renders through the catalogue, so each family and label is declared.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySampleRendersThroughTheDeclaredCatalogue(): void
    {
        $directory = self::directory();
        $samples = (new OperationalStatusCollector(
            $directory,
            ['storage' => $directory],
            self::clock(),
            new RuntimeMaterializationState('replica-a', 1, str_repeat('a', 64), str_repeat('b', 64), true),
        ))->collect();
        $catalog = MetricCatalog::create(ObservabilityContract::load(dirname(__DIR__, 4)), '2.0.0', 'http');

        $body = (new PrometheusExposition())->render($catalog, $samples);

        self::assertStringContainsString('kumwe_recovery_last_success_timestamp_seconds{operation="backup"} 0', $body);
        self::assertStringContainsString('kumwe_storage_free_bytes{volume="storage"}', $body);
        self::assertStringContainsString('kumwe_extension_runtime_trusted 1', $body);
        self::assertLessThan(256, $catalog->maximumSeries());
    }

    /**
     * A denial is counted and still recorded; an allowance is recorded without being counted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADeniedDecisionIsCountedAndStillRecorded(): void
    {
        $metrics = new RecordingMetricRecorder();
        $inner = new class implements AuthorizationDecisionRecorder {
            /**
             * Decisions the wrapped recorder received.
             *
             * @var    int
             * @since  2.0.0
             */
            public int $recorded = 0;

            /**
             * Count one recorded decision.
             *
             * @param   ExecutionContext       $context   Actor the decision was made for.
             * @param   Capability             $action    Capability exercised.
             * @param   AuthorizationResource  $resource  Resource aimed at.
             * @param   AuthorizationDecision  $decision  Outcome.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function record(
                ExecutionContext $context,
                Capability $action,
                AuthorizationResource $resource,
                AuthorizationDecision $decision,
            ): void {
                $this->recorded++;
            }
        };
        $recorder = new MeteredAuthorizationDecisionRecorder($inner, $metrics);
        $context = SystemPrincipal::issue(new \stdClass(), SystemIdentity::Worker)
            ->context(SiteContext::default(), 'signals-probe-request');

        $recorder->record(
            $context,
            Capability::fromString('content.edit'),
            AuthorizationResource::item('content', 'one'),
            new AuthorizationDecision(DecisionState::Deny, 'core.scoped-grants.v1', 'no_matching_grant'),
        );
        $recorder->record(
            $context,
            Capability::fromString('content.edit'),
            AuthorizationResource::item('content', 'one'),
            new AuthorizationDecision(DecisionState::Allow, 'core.scoped-grants.v1', 'matching_effective_grant'),
        );

        self::assertSame(2, $inner->recorded);
        self::assertSame([[
            'metric' => MetricCatalog::SECURITY_EVENTS,
            'labels' => ['event' => 'permission_denied'],
            'value' => 1.0,
        ]], $metrics->increments);
    }

    /**
     * A failed sign-in and a throttled attempt are each counted, and the throttle still propagates unchanged.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFailedAndThrottledSignInsAreCountedWithoutChangingTheDecision(): void
    {
        $metrics = new RecordingMetricRecorder();
        $throttle = new AuthenticationThrottled();
        $inner = self::createMock(AuthenticationRateLimiter::class);
        $inner->expects(self::exactly(2))->method('assertAllowed')->willReturnCallback(
            static function (string $subject) use ($throttle): void {
                if ($subject === 'spent') {
                    throw $throttle;
                }
            },
        );
        $inner->expects(self::exactly(2))->method('record');
        $limiter = new MeteredAuthenticationRateLimiter($inner, $metrics);

        $limiter->assertAllowed('fresh', 'origin');
        $limiter->record('fresh', 'origin', false);
        $limiter->record('fresh', 'origin', true);
        try {
            $limiter->assertAllowed('spent', 'origin');
            self::fail('A spent budget must still refuse.');
        } catch (AuthenticationThrottled $refused) {
            self::assertSame($throttle, $refused);
        }

        self::assertSame(
            ['authentication_failed', 'authentication_throttled'],
            array_map(static fn (array $increment): string => $increment['labels']['event'], $metrics->increments),
        );
    }

    /**
     * A token that authenticates nobody is counted on both verification paths and the null answer is kept.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARejectedTokenIsCountedOnBothPathsAndTheAnswerIsUnchanged(): void
    {
        $metrics = new RecordingMetricRecorder();
        $inner = self::createMock(ScopedAccessTokenVerifier::class);
        $inner->expects(self::once())->method('verify')->with('forged', 'kumwe-cli', 'management', 'default')
            ->willReturn(null);
        $inner->expects(self::once())->method('verifyScoped')->with('forged', 'kumwe-http', 'api', 'site-b')
            ->willReturn(null);
        $verifier = new MeteredAccessTokenVerifier($inner, $metrics);

        self::assertNull($verifier->verify('forged', 'kumwe-cli', 'management'));
        self::assertNull($verifier->verifyScoped('forged', 'kumwe-http', 'api', 'site-b'));

        self::assertCount(2, $metrics->increments);
        foreach ($metrics->increments as $increment) {
            self::assertSame(MetricCatalog::SECURITY_EVENTS, $increment['metric']);
            self::assertSame(['event' => 'token_rejected'], $increment['labels']);
        }
    }

    /**
     * Index samples by family and first label value.
     *
     * @param   list<MetricSample>  $samples  Collected samples.
     *
     * @return  array<string, float>  Values keyed as `family{label}` or `family`.
     *
     * @since   2.0.0
     */
    private static function values(array $samples): array
    {
        $values = [];
        foreach ($samples as $sample) {
            $label = $sample->labels === [] ? '' : '{' . (string) array_values($sample->labels)[0] . '}';
            $values[$sample->name . $label] = $sample->value;
        }

        return $values;
    }

    /**
     * Supply a fixed clock.
     *
     * @return  ClockInterface  Clock reading 2026-09-24T12:00:00Z.
     *
     * @since   2.0.0
     */
    private static function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            /**
             * Read the fixed instant.
             *
             * @return  DateTimeImmutable  The instant.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-24T12:00:00+00:00');
            }
        };
    }

    /**
     * Create an empty temporary directory removed after the test.
     *
     * @return  string  Absolute path.
     *
     * @since   2.0.0
     */
    private static function directory(): string
    {
        $directory = sys_get_temp_dir() . '/kumwe-signals-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        register_shutdown_function(static function () use ($directory): void {
            foreach (glob($directory . '/*') ?: [] as $file) {
                is_dir($file) ? rmdir($file) : unlink($file);
            }
            rmdir($directory);
        });

        return $directory;
    }
}
