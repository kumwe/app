<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessIntegration;

use InvalidArgumentException;
use Kumwe\CMS\Application\Automation\JobHandler;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Automation\QueueRuntimePolicy;
use Kumwe\CMS\BusinessIntegration\Domain\JobContributionDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\QueueContributionDefinition;
use Kumwe\CMS\BusinessIntegration\Infrastructure\ContributedQueueRuntimePolicyCatalog;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Contribution\ManifestContributionSet;
use Kumwe\CMS\Extension\Runtime\RuntimeMaterializationState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueueRuntimePolicy::class)]
#[CoversClass(ContributedQueueRuntimePolicyCatalog::class)]
final class QueueRuntimePolicyTest extends TestCase
{
    public function testTrustedCatalogIntersectsProducerHandlerAndQueueAttemptBudgets(): void
    {
        $owner = ContributionOwner::extension('acme/example');
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registrar = $registries->registrar($owner, new ManifestContributionSet($owner), false);
        $registrar->queue(new QueueContributionDefinition('acme.example.priority', 45, 3, 2, 14));
        $registrar->jobHandler(new JobContributionDefinition(
            'acme.example.reconcile',
            1,
            '1.0.0',
            ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            'acme.example.priority',
            5,
        ), new QueuePolicyJobHandler());
        $registrar->complete();
        $catalog = new ContributedQueueRuntimePolicyCatalog(
            $registries,
            new RuntimeMaterializationState('replica-one', 17, str_repeat('a', 64), 'proof', true),
        );

        self::assertSame(3, $catalog->maximumAttempts('acme.example.priority', 'acme.example.reconcile', 9));
        self::assertSame(5, $catalog->maximumAttempts('default', 'acme.example.reconcile', 9));
        self::assertSame(3, $catalog->maximumAttempts('acme.example.priority', 'core.unregistered', 9));
        self::assertSame(9, $catalog->maximumAttempts('default', 'core.unregistered', 9));
        self::assertSame([
            'queue' => 'acme.example.priority',
            'lease_seconds' => 45,
            'maximum_attempts' => 3,
            'maximum_in_flight' => 2,
            'retention_days' => 14,
            'runtime_generation' => 17,
        ], $catalog->policy('acme.example.priority')?->toArray());
    }

    public function testQueueDeclarationRejectsAnIdentifierWiderThanDurableQueueColumns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new QueueContributionDefinition('acme.example.' . str_repeat('q', 60));
    }
}

final class QueuePolicyJobHandler implements JobHandler
{
    public function type(): string
    {
        return 'acme.example.reconcile';
    }

    public function handle(array $payload, ExecutionContext $context): void
    {
    }
}
