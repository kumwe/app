<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Observability;

use InvalidArgumentException;
use Kumwe\App\Infrastructure\Observability\ObservabilityContract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObservabilityContract::class)]
final class ObservabilityContractTest extends TestCase
{
    public function testTheShippedDeclarationLoadsAndIsPrivateByDefault(): void
    {
        $contract = ObservabilityContract::load(dirname(__DIR__, 4));

        self::assertSame('php://stderr', $contract->logDestination);
        self::assertSame('json', $contract->logFormat);
        self::assertSame('info', $contract->logDefaultLevel);
        self::assertContains('correlation_id', $contract->requiredContext);
        self::assertFalse($contract->metricsEnabled);
        self::assertFalse($contract->metricsPublic);
        self::assertFalse($contract->exposeHealthDetails);
        self::assertFalse($contract->tracingEnabled);
        self::assertSame('/metrics', $contract->metricsPath);
    }

    public function testRedactionMatchesOnContainmentSoDerivedKeysAreCaughtToo(): void
    {
        $contract = self::contract();

        self::assertTrue($contract->redactsKey('authorization'));
        self::assertTrue($contract->redactsKey('Authorization'));
        self::assertTrue($contract->redactsKey('refresh_token'));
        self::assertTrue($contract->redactsKey('set-cookie'));
        self::assertFalse($contract->redactsKey('queue'));
        self::assertFalse($contract->redactsKey('correlation_id'));
    }

    public function testForbiddenLabelsAreRefusedIncludingDerivedSpellings(): void
    {
        $contract = self::contract();

        self::assertTrue($contract->forbidsLabel('user_id'));
        self::assertTrue($contract->forbidsLabel('owner_user_id'));
        self::assertTrue($contract->forbidsLabel('content_id'));
        self::assertFalse($contract->forbidsLabel('method'));
        self::assertFalse($contract->forbidsLabel('status'));
    }

    public function testAnUnimplementedLogFormatIsRefusedRatherThanIgnored(): void
    {
        $declaration = self::declaration();
        $declaration['logging']['format'] = 'logfmt';

        $this->expectException(InvalidArgumentException::class);
        ObservabilityContract::fromArray($declaration);
    }

    public function testAnUnknownDefaultLevelIsRefused(): void
    {
        $declaration = self::declaration();
        $declaration['logging']['default_level'] = 'chatty';

        $this->expectException(InvalidArgumentException::class);
        ObservabilityContract::fromArray($declaration);
    }

    public function testAnEmptyRedactionListIsRefusedBecauseSilentlyDefaultingItWouldLeak(): void
    {
        $declaration = self::declaration();
        $declaration['logging']['redacted_fields'] = [];

        $this->expectException(InvalidArgumentException::class);
        ObservabilityContract::fromArray($declaration);
    }

    public function testAMetricsPathThatIsNotAnAbsoluteRequestPathIsRefused(): void
    {
        $declaration = self::declaration();
        $declaration['metrics']['path'] = 'metrics';

        $this->expectException(InvalidArgumentException::class);
        ObservabilityContract::fromArray($declaration);
    }

    public function testASampleRatioOutsideTheUnitIntervalIsRefused(): void
    {
        $declaration = self::declaration();
        $declaration['tracing']['sample_ratio'] = 1.5;

        $this->expectException(InvalidArgumentException::class);
        ObservabilityContract::fromArray($declaration);
    }

    private static function contract(): ObservabilityContract
    {
        return ObservabilityContract::fromArray(self::declaration());
    }

    /**
     * @return array<string, mixed>
     */
    private static function declaration(): array
    {
        return [
            'version' => 1,
            'logging' => [
                'destination' => 'php://stderr',
                'format' => 'json',
                'default_level' => 'info',
                'required_context' => ['correlation_id', 'release', 'runtime', 'outcome'],
                'redacted_fields' => ['authorization', 'cookie', 'password', 'secret', 'set-cookie', 'token'],
            ],
            'health' => [
                'liveness_path' => '/health/live',
                'readiness_path' => '/health/ready',
                'dependency_timeout_milliseconds' => 2_000,
                'expose_details' => false,
            ],
            'metrics' => [
                'enabled' => false,
                'path' => '/metrics',
                'public' => false,
                'forbidden_labels' => ['content_id', 'email', 'session_id', 'token_id', 'user_id'],
            ],
            'tracing' => ['enabled' => false, 'exporter' => 'none', 'sample_ratio' => 0.0],
        ];
    }
}
