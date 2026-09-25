<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Studio;

use Doctrine\DBAL\Connection;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Delivery\Console\Command\StudioCompositionCommand;
use Kumwe\App\Delivery\Http\Api\Idempotency\HttpMutationPreauthorizer;
use Kumwe\App\Delivery\Http\Api\Studio\StudioCompositionApiHandler;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Container;
use Kumwe\App\OpenApi\Application\ProblemDetailsRegistry;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Studio\Application\Composition\StudioContentComposition;
use Kumwe\App\Studio\Application\Composition\StudioContentCompositionService;
use Kumwe\App\Tests\Support\MachineSurfaceHarness;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves an agent reads and provisions a Content type's Blueprint composition exactly as the composition screen.
 *
 * Each surface gets its own freshly published Content type, finds no composition, provisions the empty draft,
 * reads it back and provisions it again, all through `StudioContentCompositionService`, so the document, the
 * single `studio.composition.provision` audit event and the idempotent answer are the same on REST, the console
 * and MCP, and equal to what the service itself reads. A credential without `studio.mode.blueprint` is refused
 * on all three surfaces; a Content type that does not exist reads as not found and, because the write
 * pre-authorization cannot place it in the site, is refused before provisioning, while an unpublished version of
 * a real type is not found for both. The same assertions run on MariaDB and PostgreSQL.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioCompositionApiHandler::class)]
#[CoversClass(StudioCompositionCommand::class)]
#[CoversClass(KumweMcpHandlers::class)]
#[CoversClass(StudioContentCompositionService::class)]
#[CoversClass(StudioContentComposition::class)]
#[CoversClass(HttpMutationPreauthorizer::class)]
#[CoversClass(ProblemDetailsRegistry::class)]
final class StudioCompositionMachineEquivalenceIntegrationTest extends TestCase
{
    /**
     * Capabilities the composition screen demands.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array SCREEN = ['content.read', 'studio.mode.blueprint'];

    /**
     * Harness of the running test.
     *
     * @var    ?MachineSurfaceHarness
     * @since  2.0.0
     */
    private ?MachineSurfaceHarness $harness = null;

    /**
     * Revoke every token the running test issued.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        $this->harness?->cleanup();
        $this->harness = null;
    }

    /**
     * Find, provision, read and provision again yield the service's document and one audit event on every surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySurfaceProvisionsAndReadsLikeTheCompositionScreen(): void
    {
        [$container, $harness] = $this->boot();
        $service = $container->get(StudioContentCompositionService::class);
        self::assertInstanceOf(StudioContentCompositionService::class, $service);
        $traces = [];
        foreach (['rest', 'cli', 'mcp'] as $surface) {
            $token = $harness->token($surface, self::SCREEN);
            $type = self::publishType($container, $surface);
            $missing = $this->find($harness, $surface, $token, $type);
            $provisioned = $this->provision($harness, $surface, $token, $type);
            $read = $this->find($harness, $surface, $token, $type);
            $again = $this->provision($harness, $surface, $token, $type);
            $expected = $service->find(TestKernelFactory::administratorContext($container), $type, 1);
            self::assertNotNull($expected, $surface . ' provisioned nothing.');
            self::assertIsArray($provisioned['value'], $surface . ' did not provision.');
            $traces[$surface] = [
                'missing' => $missing['refused'],
                'provisioned' => $provisioned['value'] === self::roundTrip($expected->toArray()),
                'read' => $read['value'] === $provisioned['value'],
                'again' => $again['value'] === $provisioned['value'],
                'keys' => array_keys($provisioned['value']),
                'coordinate' => [
                    $provisioned['value']['content_type_id'],
                    $provisioned['value']['content_type_version'],
                    $provisioned['value']['binding_revision'],
                    $provisioned['value']['blueprint']['kind'],
                    $provisioned['value']['blueprint']['status'],
                ],
                'audit' => self::provisionEvents($container, $type),
            ];
            $traces[$surface]['coordinate'][0] = $traces[$surface]['coordinate'][0] === $type;
        }

        foreach ($traces as $surface => $trace) {
            self::assertSame([
                'missing' => true,
                'provisioned' => true,
                'read' => true,
                'again' => true,
                'keys' => ['content_type_id', 'content_type_version', 'binding_revision', 'model', 'blueprint'],
                'coordinate' => [true, 1, 1, 'blueprint', 'draft'],
                'audit' => 1,
            ], $trace, $surface . ' diverged from the composition screen.');
        }
    }

    /**
     * A credential without the Blueprint mode and an unknown Content type are refused alike on every surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsMatchOnEverySurface(): void
    {
        [$container, $harness] = $this->boot();
        $type = self::publishType($container, 'refusal');
        $unknown = '018f22e2-7c8b-7ab0-8f3a-' . bin2hex(random_bytes(6));
        $refusals = [];
        foreach (['rest', 'cli', 'mcp'] as $surface) {
            $reader = $harness->token($surface, ['content.read']);
            $screen = $harness->token($surface, self::SCREEN);
            $refusals[$surface] = [
                'read_denied' => $this->find($harness, $surface, $reader, $type)['refusal'],
                'provision_denied' => $this->provision($harness, $surface, $reader, $type)['refusal'],
                'read_unknown' => $this->find($harness, $surface, $screen, $unknown)['refusal'],
                'provision_unknown' => $this->provision($harness, $surface, $screen, $unknown)['refusal'],
                'provision_unpublished' => $this->provisionVersion($harness, $surface, $screen, $type, 2)['refusal'],
                'read_unpublished' => $this->findVersion($harness, $surface, $screen, $type, 2)['refusal'],
            ];
        }

        self::assertSame([
            'read_denied' => 403,
            'provision_denied' => 403,
            'read_unknown' => 404,
            'provision_unknown' => 403,
            'provision_unpublished' => 404,
            'read_unpublished' => 404,
        ], $refusals['rest']);
        self::assertSame([
            'read_denied' => 1,
            'provision_denied' => 1,
            'read_unknown' => 1,
            'provision_unknown' => 1,
            'provision_unpublished' => 1,
            'read_unpublished' => 1,
        ], $refusals['cli']);
        self::assertSame([
            'read_denied' => 'authorization.denied',
            'provision_denied' => 'authorization.denied',
            'read_unknown' => 'resource.not_found',
            'provision_unknown' => 'authorization.denied',
            'provision_unpublished' => 'resource.not_found',
            'read_unpublished' => 'resource.not_found',
        ], $refusals['mcp']);
        self::assertSame(0, self::provisionEvents($container, $type));
        self::assertNull($this->find($harness, 'rest', $harness->token('rest', self::SCREEN), $type)['value']);
    }

    /**
     * Boot the kernel and a harness bound to it.
     *
     * @return  array{Container, MachineSurfaceHarness}  Kernel and harness.
     *
     * @since   2.0.0
     */
    private function boot(): array
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $this->harness = new MachineSurfaceHarness($container, 'composition-parity');

        return [$container, $this->harness];
    }

    /**
     * Read one composition on one surface.
     *
     * @param   MachineSurfaceHarness  $harness  Harness.
     * @param   string                 $surface  `rest`, `cli` or `mcp`.
     * @param   string                 $token    Surface token.
     * @param   string                 $type     Content type UUID, always at version one.
     *
     * @return  array{value: mixed, refused: bool, refusal: int|string|null}  Document, or the surface's refusal.
     *
     * @since   2.0.0
     */
    private function find(MachineSurfaceHarness $harness, string $surface, string $token, string $type): array
    {
        return $this->findVersion($harness, $surface, $token, $type, 1);
    }

    /**
     * Read one composition at an explicit Content type version on one surface.
     *
     * @param   MachineSurfaceHarness  $harness  Harness.
     * @param   string                 $surface  `rest`, `cli` or `mcp`.
     * @param   string                 $token    Surface token.
     * @param   string                 $type     Content type UUID.
     * @param   int                    $version  Content type version.
     *
     * @return  array{value: mixed, refused: bool, refusal: int|string|null}  Document, or the surface's refusal.
     *
     * @since   2.0.0
     */
    private function findVersion(
        MachineSurfaceHarness $harness,
        string $surface,
        string $token,
        string $type,
        int $version,
    ): array {
        return match ($surface) {
            'rest' => self::restOutcome($harness->rest(
                $token,
                'GET',
                '/api/v1/content-types/' . $type . '/versions/' . $version . '/composition',
            ), 'studio-composition-not-found'),
            'cli' => self::cliOutcome($harness->cli(StudioCompositionCommand::class, $token, [
                'get',
                '--content-type=' . $type,
                '--version=' . $version,
            ]), 'No Blueprint composition is provisioned for this Content type version.'),
            default => self::mcpOutcome($harness->mcp($token, 'kumwe_studio_composition_get', [
                'contentType' => $type,
                'version' => $version,
            ]), 'resource.not_found'),
        };
    }

    /**
     * Provision one composition on one surface under a fresh operation identity.
     *
     * @param   MachineSurfaceHarness  $harness  Harness.
     * @param   string                 $surface  `rest`, `cli` or `mcp`.
     * @param   string                 $token    Surface token.
     * @param   string                 $type     Content type UUID, always at version one.
     *
     * @return  array{value: mixed, refused: bool, refusal: int|string|null}  Document, or the surface's refusal.
     *
     * @since   2.0.0
     */
    private function provision(MachineSurfaceHarness $harness, string $surface, string $token, string $type): array
    {
        return $this->provisionVersion($harness, $surface, $token, $type, 1);
    }

    /**
     * Provision one composition at an explicit Content type version on one surface.
     *
     * @param   MachineSurfaceHarness  $harness  Harness.
     * @param   string                 $surface  `rest`, `cli` or `mcp`.
     * @param   string                 $token    Surface token.
     * @param   string                 $type     Content type UUID.
     * @param   int                    $version  Content type version.
     *
     * @return  array{value: mixed, refused: bool, refusal: int|string|null}  Document, or the surface's refusal.
     *
     * @since   2.0.0
     */
    private function provisionVersion(
        MachineSurfaceHarness $harness,
        string $surface,
        string $token,
        string $type,
        int $version,
    ): array {
        $operation = 'composition-parity-' . bin2hex(random_bytes(8));

        return match ($surface) {
            'rest' => self::restOutcome($harness->rest(
                $token,
                'POST',
                '/api/v1/content-types/' . $type . '/versions/' . $version . '/composition',
                null,
                ['Idempotency-Key' => $operation],
            ), 'studio-composition-not-found'),
            'cli' => self::cliOutcome($harness->cli(StudioCompositionCommand::class, $token, [
                'provision',
                '--content-type=' . $type,
                '--version=' . $version,
            ]), null),
            default => self::mcpOutcome($harness->mcp($token, 'kumwe_studio_composition_provision', [
                'operationId' => $operation,
                'contentType' => $type,
                'version' => $version,
            ]), 'resource.not_found'),
        };
    }

    /**
     * Normalize one REST response.
     *
     * @param   array{status: int, body: mixed, raw: string, headers: array<string, string>}  $response  Response.
     * @param   string  $notFound  Problem type suffix of the not-found answer.
     *
     * @return  array{value: mixed, refused: bool, refusal: int|string|null}  Outcome.
     *
     * @since   2.0.0
     */
    private static function restOutcome(array $response, string $notFound): array
    {
        if ($response['status'] === 200) {
            self::assertSame('no-store', $response['headers']['cache-control'] ?? null);

            return ['value' => $response['body'], 'refused' => false, 'refusal' => null];
        }
        $body = $response['body'];

        return [
            'value' => null,
            'refused' => is_array($body) && ($body['type'] ?? null) === 'urn:kumwe:problem:' . $notFound,
            'refusal' => $response['status'],
        ];
    }

    /**
     * Normalize one console run.
     *
     * @param   array{status: int, stdout: mixed, stderr: string}  $run       Run.
     * @param   ?string                                            $notFound  Error line of the not-found answer.
     *
     * @return  array{value: mixed, refused: bool, refusal: int|string|null}  Outcome.
     *
     * @since   2.0.0
     */
    private static function cliOutcome(array $run, ?string $notFound): array
    {
        if ($run['status'] === 0) {
            return ['value' => $run['stdout'], 'refused' => false, 'refusal' => null];
        }

        return [
            'value' => null,
            'refused' => $notFound === null || $run['stderr'] === $notFound,
            'refusal' => $run['status'],
        ];
    }

    /**
     * Normalize one MCP tool call.
     *
     * @param   array{error: bool, value: mixed}  $call      Tool call.
     * @param   string                            $notFound  Error code of the not-found answer.
     *
     * @return  array{value: mixed, refused: bool, refusal: int|string|null}  Outcome.
     *
     * @since   2.0.0
     */
    private static function mcpOutcome(array $call, string $notFound): array
    {
        if (!$call['error']) {
            return ['value' => $call['value'], 'refused' => false, 'refusal' => null];
        }
        $code = is_array($call['value']) && is_string($call['value']['code'] ?? null) ? $call['value']['code'] : null;

        return ['value' => null, 'refused' => $code === $notFound, 'refusal' => $code];
    }

    /**
     * Publish one fresh Content type at version one on the core workflow.
     *
     * @param   Container  $container  Kernel.
     * @param   string     $surface    Surface the type is published for.
     *
     * @return  string  Content type UUID.
     *
     * @since   2.0.0
     */
    private static function publishType(Container $container, string $surface): string
    {
        $models = $container->get(ContentModelService::class);
        self::assertInstanceOf(ContentModelService::class, $models);

        return $models->createContentType(
            TestKernelFactory::administratorContext($container),
            'composition-parity-' . $surface . '-' . bin2hex(random_bytes(5)),
            'Composition parity ' . $surface,
            ContentService::CORE_WORKFLOW_ID,
            [
                'type' => 'object',
                'properties' => ['body' => ['type' => 'string']],
                'required' => ['body'],
                'additionalProperties' => false,
            ],
        )->id;
    }

    /**
     * Encode and decode one service document as a machine caller receives it.
     *
     * @param   array<string, mixed>  $document  Service document.
     *
     * @return  mixed  Decoded JSON.
     *
     * @since   2.0.0
     */
    private static function roundTrip(array $document): mixed
    {
        return json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
    }

    /**
     * Count the provisioning audit events recorded for one Content type.
     *
     * @param   Container  $container  Kernel.
     * @param   string     $type       Content type UUID.
     *
     * @return  int  Number of `studio.composition.provision` events.
     *
     * @since   2.0.0
     */
    private static function provisionEvents(Container $container, string $type): int
    {
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);

        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE action = ? AND subject_type = ? AND subject_id = ?',
            $tables->quoted('audit_events'),
        ), ['studio.composition.provision', 'content_type', strtolower($type)]);
    }
}
