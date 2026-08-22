<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Http\Handler;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Kumwe\App\Application\Readiness\ReadinessStatus;
use Kumwe\App\Delivery\Console\Command\HealthCheckCommand;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Http\Handler\LivenessHandler;
use Kumwe\App\Http\Handler\ReadinessHandler;
use Kumwe\App\Infrastructure\Persistence\Migration\Migration;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationPlan;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationRepository;
use Kumwe\App\Infrastructure\Persistence\Migration\NonTransactionalMigrationRecovery;
use Kumwe\App\Infrastructure\Persistence\ReadinessProbe;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Tests\Support\TranslatesConsoleOutput;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;

/**
 * Pins the 200/503 contract a load balancer branches on, and the 0/1 a container start-up probe does.
 *
 * Both were previously exercised only indirectly — through routing in the kernel test and through a
 * browser spec — so the status codes that decide whether a replica takes traffic had no direct
 * qualification evidence.
 */
#[CoversClass(ReadinessHandler::class)]
#[CoversClass(LivenessHandler::class)]
#[CoversClass(HealthCheckCommand::class)]
final class HealthEndpointTest extends TestCase
{
    public function testAReadyWorkerAnswersTwoHundredAndSaysOnlyThatItIsReady(): void
    {
        $response = (new ReadinessHandler(self::probe(true)))->handle(self::request());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ready'], json_decode((string) $response->getBody(), true));
    }

    public function testAnUnreadyWorkerAnswersFiveOhThreeSoTheBalancerDrainsIt(): void
    {
        $response = (new ReadinessHandler(self::probe(false)))->handle(self::request());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['status' => 'not_ready'], json_decode((string) $response->getBody(), true));
    }

    public function testTheReadinessBodyNamesNoDependencyBecauseTheProbeIsUnauthenticated(): void
    {
        $body = (string) (new ReadinessHandler(self::probe(false)))->handle(self::request())->getBody();

        foreach (['database', 'redis', 'migration', 'trust', 'runtime', 'postgres', 'mariadb'] as $leak) {
            self::assertStringNotContainsStringIgnoringCase($leak, $body);
        }
    }

    public function testTheProbeIsConsultedOnEveryRequestSoRecoveryIsImmediate(): void
    {
        $probe = new class implements ReadinessStatus {
            public int $calls = 0;

            public function ready(): bool
            {
                ++$this->calls;

                return $this->calls > 1;
            }
        };
        $handler = new ReadinessHandler($probe);

        self::assertSame(503, $handler->handle(self::request())->getStatusCode());
        self::assertSame(200, $handler->handle(self::request())->getStatusCode());
        self::assertSame(2, $probe->calls);
    }

    public function testLivenessAlwaysAnswersAliveAndTouchesNoDependency(): void
    {
        $response = (new LivenessHandler())->handle(self::request());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['status' => 'alive', 'product' => 'Kumwe App'],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function testTheHealthCommandExitsZeroWhenReady(): void
    {
        $output = new RecordingOutput();
        $probe = $this->deepProbe(true);

        self::assertSame(0, (new HealthCheckCommand($probe))->execute([], $output));
        self::assertSame(['Kumwe is ready.'], $output->lines);
        self::assertSame([], $output->errors);
    }

    public function testTheHealthCommandExitsOneWithAUniformMessageWhateverFailed(): void
    {
        $output = new RecordingOutput();
        $probe = $this->deepProbe(false);

        self::assertSame(1, (new HealthCheckCommand($probe))->execute([], $output));
        self::assertSame([], $output->lines);
        self::assertSame(['Kumwe is not ready.'], $output->errors);
    }

    public function testTheHealthCommandIgnoresArgumentsAndKeepsItsRegisteredName(): void
    {
        $output = new RecordingOutput();
        $probe = $this->deepProbe(true);
        $command = new HealthCheckCommand($probe);

        self::assertSame('app:health', $command->name());
        self::assertSame(0, $command->execute(['--site=anything', '--verbose'], $output));
    }

    /**
     * Build the real deep probe the command is typed against, with its ledger check made to pass or fail.
     */
    private function deepProbe(bool $ready): ReadinessProbe
    {
        $migration = new HealthReadinessMigration('20260804000100_create_system_tables');
        $schema = $this->createStub(AbstractSchemaManager::class);
        $schema->method('tablesExist')->willReturn($ready);
        $database = $this->createMock(Connection::class);
        $database->method('createSchemaManager')->willReturn($schema);
        $database->method('quoteSingleIdentifier')->willReturn('"kumwe_schema_migrations"');
        $database->method('fetchOne')->willReturn(1);
        $repository = $this->createStub(MigrationRepository::class);
        $repository->method('applied')->willReturn([$migration->id() => $migration->checksum()]);

        return new ReadinessProbe(
            $database,
            new NullLogger(),
            new TableNames($database, 'kumwe_'),
            $repository,
            new MigrationPlan([$migration]),
            $this->createStub(NonTransactionalMigrationRecovery::class),
        );
    }

    private static function probe(bool $ready): ReadinessStatus
    {
        return new class ($ready) implements ReadinessStatus {
            public function __construct(private bool $ready)
            {
            }

            public function ready(): bool
            {
                return $this->ready;
            }
        };
    }

    private static function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/health/ready');
    }
}

final readonly class HealthReadinessMigration implements Migration
{
    public function __construct(private string $migrationId)
    {
    }

    public function id(): string
    {
        return $this->migrationId;
    }

    public function checksum(): string
    {
        return hash('sha256', $this->migrationId);
    }

    public function up(Connection $database): void
    {
    }
}

final class RecordingOutput implements Output
{
    use TranslatesConsoleOutput;

    /** @var list<string> */
    public array $lines = [];

    /** @var list<string> */
    public array $errors = [];

    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }
}
