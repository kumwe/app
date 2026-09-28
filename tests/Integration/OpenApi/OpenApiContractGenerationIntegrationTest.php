<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\OpenApi;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessRecord\Application\InstalledBusinessRecordDefinitionResolver;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\OpenApi\Application\CompiledOpenApiContract;
use Kumwe\App\OpenApi\Application\OpenApiContractCache;
use Kumwe\App\OpenApi\Application\OpenApiContractCompiler;
use Kumwe\App\OpenApi\Application\OpenApiContractLimits;
use Kumwe\App\OpenApi\Application\OpenApiContractService;
use Kumwe\App\OpenApi\Application\OpenApiContractUnavailable;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Context\Value\AuthenticationStrength;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

/**
 * Proves definition publication invalidates caller-specific OpenAPI generations without stale reuse.
 *
 * @since  2.0.0
 */
#[CoversClass(OpenApiContractService::class)]
#[CoversClass(OpenApiContractCompiler::class)]
#[CoversClass(InstalledBusinessRecordDefinitionResolver::class)]
final class OpenApiContractGenerationIntegrationTest extends TestCase
{
    /**
     * Withdraw one installed version without hiding healthy definitions or tolerating corrupt installation metadata.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectedInstalledVersionIsOmittedButCatalogCorruptionStillFailsClosed(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $definitions = $container->get(BusinessDefinitionService::class);
        $resolver = $container->get(BusinessRecordDefinitionResolver::class);
        $contracts = $container->get(OpenApiContractService::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(BusinessDefinitionService::class, $definitions);
        self::assertInstanceOf(BusinessRecordDefinitionResolver::class, $resolver);
        self::assertInstanceOf(OpenApiContractService::class, $contracts);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $healthy = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::document('healthy' . $suffix, Uuid::uuid7()->toString()),
        );
        $withdrawn = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::document('reject' . $suffix, Uuid::uuid7()->toString()),
        );
        $principal = AuthenticatedPrincipal::of($context);
        self::assertNotNull($principal);
        $api = $principal->context($context->site(), AuthenticationStrength::BearerToken, 'withdrawal-' . $suffix);
        $before = $contracts->contract($api);
        $definitions->reject($context, $withdrawn->id, $withdrawn->definitionVersion);

        $after = $contracts->contract($api);
        self::assertNotSame($before->generation, $after->generation);
        self::assertStringContainsString(
            'Business_' . str_replace(['.', '-'], '_', $healthy->handle) . '_Record',
            $after->json,
        );
        self::assertStringNotContainsString(
            'Business_' . str_replace(['.', '-'], '_', $withdrawn->handle) . '_Record',
            $after->json,
        );
        self::assertSame($healthy->id, $resolver->forCreate($context, $healthy->id)->definition->id);
        try {
            $resolver->forCreate($context, $withdrawn->id);
            self::fail('A rejected installed version must remain unavailable to direct record operations.');
        } catch (BusinessRecordDefinitionUnavailable) {
            self::assertCount(1, $definitions->history($context, $withdrawn->id));
        }

        $publication = $definitions->published($context, $healthy->id);
        $changed = EntityTypeDefinition::fromArray([
            ...$healthy->toArray(),
            'singular_label' => 'Different immutable catalog bytes',
        ]);
        foreach (['checksum', 'missing'] as $corruption) {
            $database->beginTransaction();
            try {
                $identity = ['definition_id' => $healthy->id, 'version' => $healthy->definitionVersion];
                if ($corruption === 'missing') {
                    $database->delete($tables->raw('business_definition_versions'), $identity);
                } else {
                    $database->update($tables->raw('business_definition_versions'), [
                        'canonical_payload' => $changed->toArray(),
                        'checksum' => $changed->checksum(),
                        'compatibility_plan' => [
                            ...$publication->compatibility->toArray(),
                            'to_checksum' => $changed->checksum(),
                        ],
                    ], $identity, ['canonical_payload' => Types::JSON, 'compatibility_plan' => Types::JSON]);
                }
                try {
                    $resolver->activeInstalled($context);
                    self::fail('A missing or mismatched installed version must not be silently omitted.');
                } catch (BusinessRecordSchemaUnavailable $refusal) {
                    self::assertSame(
                        'An active installed definition disagrees with its immutable catalog version.',
                        $refusal->getMessage(),
                    );
                }
            } finally {
                $database->rollBack();
            }
        }
        self::assertSame($healthy->id, $resolver->forCreate($context, $healthy->id)->definition->id);
    }

    /**
     * Compile an exact new generation after installing a newly visible definition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDefinitionInstallationProducesAndCachesOnlyNewExactGeneration(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $administrator = TestKernelFactory::administratorContext($container);
        $principal = $administrator->principal();
        self::assertNotNull($principal);
        $api = $principal->context(
            $administrator->site(),
            AuthenticationStrength::BearerToken,
            'openapi-generation-' . bin2hex(random_bytes(8)),
        );
        $contracts = $container->get(OpenApiContractService::class);
        self::assertInstanceOf(OpenApiContractService::class, $contracts);
        $before = $contracts->contract($api);

        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $document = NeutralBusinessFixture::document($suffix, Uuid::uuid7()->toString());
        $definition = NeutralBusinessFixture::install($container, $administrator, $document);
        $after = $contracts->contract($api);

        self::assertNotSame($before->generation, $after->generation);
        self::assertNotSame($before->checksum, $after->checksum);
        self::assertStringContainsString(
            'Business_' . str_replace(['.', '-'], '_', $definition->handle) . '_Record',
            $after->json,
        );
        self::assertEquals($after, $contracts->contract($api));
    }

    /**
     * Fail a large current compile before any cache publication can occur.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOversizedCurrentContractFailsBeforeCachePublication(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $administrator = TestKernelFactory::administratorContext($container);
        $principal = $administrator->principal();
        self::assertNotNull($principal);
        $api = $principal->context(
            $administrator->site(),
            AuthenticationStrength::BearerToken,
            'openapi-large-' . bin2hex(random_bytes(8)),
        );
        $catalog = $container->get(BusinessSurfaceCatalog::class);
        self::assertInstanceOf(BusinessSurfaceCatalog::class, $catalog);
        $path = dirname(__DIR__, 3) . '/api/openapi/kumwe-v1.json';
        $json = file_get_contents($path);
        self::assertIsString($json);
        /** @var array<string, mixed> $core */
        $core = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $core['info']['description'] = str_repeat('x', OpenApiContractLimits::MAX_CONTRACT_BYTES);
        $cache = new class implements OpenApiContractCache {
            /**
             * Whether an oversized compiled value reached publication.
             *
             * @var    bool
             * @since  2.0.0
             */
            public bool $putCalled = false;

            /**
             * Force compilation for every exact generation.
             *
             * @param   string  $generation  Exact requested generation.
             *
             * @return  ?CompiledOpenApiContract  Always null.
             *
             * @since   2.0.0
             */
            public function get(string $generation): ?CompiledOpenApiContract
            {
                return null;
            }

            /**
             * Record an unsafe publication attempt.
             *
             * @param   CompiledOpenApiContract  $contract  Candidate verified value.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function put(CompiledOpenApiContract $contract): void
            {
                $this->putCalled = true;
            }
        };
        $service = new OpenApiContractService(
            $core,
            $catalog,
            new OpenApiContractCompiler(),
            $cache,
            new NullLogger(),
        );

        try {
            $service->contract($api);
            self::fail('An oversized current contract was served.');
        } catch (OpenApiContractUnavailable $exception) {
            self::assertStringContainsString('safe byte bound', $exception->getPrevious()?->getMessage() ?? '');
        }
        self::assertFalse($cache->putCalled);
    }
}
