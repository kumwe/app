<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessSurface\Application;

use Kumwe\CMS\Application\Authorization\AuthenticatedSurface;
use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\AuthorizationDecision;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessDefinition\Application\FieldTypeDefinitionResolver;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\CMS\BusinessSecurity\Application\BusinessRecordAccessController;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurface;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurfaceService;
use Kumwe\CMS\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(BusinessSurfaceService::class)]
/**
 * Proves generated-business facade failures and presentation helpers remain safely bounded.
 *
 * @since  2.0.0
 */
final class BusinessSurfaceServiceTest extends TestCase
{
    /**
     * Proves record-addressed reads hide catalog denial while discovery keeps its collection semantics.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReadNormalizesUnavailableDefinitionWithoutChangingDiscovery(): void
    {
        $service = $this->service($this->emptyCatalog());

        foreach (BusinessSurface::cases() as $surface) {
            $context = $this->context($surface);
            self::assertSame([], $service->discover($context, $surface), $surface->value);

            try {
                $service->read($context, $surface, 'site.default.missing', 'missing-record');
                self::fail(
                    'An unavailable record-addressed definition must be indistinguishable from a missing record.',
                );
            } catch (BusinessRecordNotFound $exception) {
                self::assertSame('business_record.not_found', $exception->stableCode(), $surface->value);
            }
        }
    }

    /**
     * Proves selector labels are normalized to one line and a UTF-8-safe byte limit.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRelationshipChoiceLabelsAreSingleLineAndUtf8ByteBounded(): void
    {
        $choiceText = (new ReflectionClass(BusinessSurfaceService::class))->getMethod('choiceText');

        self::assertSame('Alpha Beta', $choiceText->invoke(null, " Alpha\n\tBeta ", 120));
        $bounded = $choiceText->invoke(null, str_repeat("\u{1F680}", 40), 120);
        self::assertIsString($bounded);
        self::assertLessThanOrEqual(120, strlen($bounded));
        self::assertSame(1, preg_match('//u', $bounded));
    }

    /**
     * Build a catalog that authorizes reads but contains no matching definition.
     *
     * @return  BusinessSurfaceCatalog  Executable empty catalog fixture.
     *
     * @since   2.0.0
     */
    private function emptyCatalog(): BusinessSurfaceCatalog
    {
        $definitions = $this->createStub(BusinessRecordDefinitionResolver::class);
        $definitions->method('activeInstalled')->willReturn([]);
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('decide')->willReturn(new AuthorizationDecision(true, 'test', 'allowed'));
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );

        return new BusinessSurfaceCatalog(
            $definitions,
            $this->createStub(BusinessRecordAccessController::class),
            $this->createStub(FieldTypeDefinitionResolver::class),
            $authorization,
            $transactions,
            RuntimeMaterializationState::unavailable('business-surface-service-test'),
        );
    }

    /**
     * Build a facade whose unreachable collaborators remain intentionally uninitialized.
     *
     * @param   BusinessSurfaceCatalog  $catalog  Exact catalog exercised by the read boundary.
     *
     * @return  BusinessSurfaceService  Reflection-backed focused fixture.
     *
     * @since   2.0.0
     */
    private function service(BusinessSurfaceCatalog $catalog): BusinessSurfaceService
    {
        $reflection = new ReflectionClass(BusinessSurfaceService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('catalog')->setValue($service, $catalog);

        return $service;
    }

    /**
     * Mint one provenance-matched context with generated read authority.
     *
     * @param   BusinessSurface  $surface  Generated adapter under test.
     *
     * @return  ExecutionContext  Authenticated caller context.
     *
     * @since   2.0.0
     */
    private function context(BusinessSurface $surface): ExecutionContext
    {
        $authenticated = match ($surface) {
            BusinessSurface::Administrator => AuthenticatedSurface::Administrator,
            BusinessSurface::Portal => AuthenticatedSurface::Portal,
            BusinessSurface::Api => AuthenticatedSurface::Api,
            BusinessSurface::Cli => AuthenticatedSurface::Cli,
            BusinessSurface::Mcp => AuthenticatedSurface::Mcp,
        };

        return AuthorizationContext::principal(['business.record.read'])->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'business-surface-read-normalization-test',
            surface: $authenticated,
        );
    }
}
