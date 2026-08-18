<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\BusinessDefinition;

use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\NeutralBusinessFixture;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves site definition publication rejects OpenAPI component collisions before commit.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessDefinitionService::class)]
#[CoversClass(EntityTypeDefinition::class)]
final class OpenApiDefinitionPublicationAdmissionIntegrationTest extends TestCase
{
    /**
     * Leave a colliding second definition as a draft with no published history.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNormalizedComponentCollisionCannotBePublished(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $service = $container->get(BusinessDefinitionService::class);
        self::assertInstanceOf(BusinessDefinitionService::class, $service);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $first = NeutralBusinessFixture::document('claim' . $suffix, Uuid::uuid7()->toString());
        $first['handle'] = 'site.default.contract_' . $suffix . '.foo-bar';
        $firstDraft = $service->saveDraft($context, EntityTypeDefinition::fromArray($first));
        $service->publish($context, $firstDraft->definition->id, $firstDraft->revision);

        $second = NeutralBusinessFixture::document('other' . $suffix, Uuid::uuid7()->toString());
        $second['handle'] = 'site.default.contract_' . $suffix . '.foo.bar';
        $secondDraft = $service->saveDraft($context, EntityTypeDefinition::fromArray($second));
        try {
            $service->publish($context, $secondDraft->definition->id, $secondDraft->revision);
            self::fail('A normalized generated OpenAPI component collision must be rejected before commit.');
        } catch (InvalidBusinessDefinition $exception) {
            self::assertStringContainsString('collides or is unsafe', $exception->getMessage());
        }

        self::assertSame($secondDraft->revision, $service->draft($context, $secondDraft->definition->id)->revision);
        self::assertSame([], $service->history($context, $secondDraft->definition->id));
    }
}
