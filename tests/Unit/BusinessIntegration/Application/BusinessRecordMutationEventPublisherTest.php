<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessIntegration\Application;

use DateTimeImmutable;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\BusinessIntegration\Application\BusinessRecordMutationEventPublisher;
use Kumwe\App\BusinessIntegration\Application\DomainEventHandler;
use Kumwe\App\BusinessIntegration\Application\OutboxStore;
use Kumwe\App\BusinessIntegration\Domain\DomainListenerDefinition;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves extension generation authority is checked before contributed mutation listeners execute.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessRecordMutationEventPublisher::class)]
final class BusinessRecordMutationEventPublisherTest extends TestCase
{
    /**
     * A stale runtime refuses the whole listener-and-outbox boundary without invoking extension code.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStaleGenerationRefusesBeforeListenerOrOutboxInvocation(): void
    {
        $definition = new DomainListenerDefinition(
            'acme.listener.record_mutated',
            'core.business_record.mutated',
            [1],
            '1.0.0',
        );
        $handler = $this->createMock(DomainEventHandler::class);
        $handler->expects(self::never())->method('handle');
        $contributions = new ExtensionContributionRegistrySet();
        $contributions->domainListeners()->register(
            ContributionOwner::extension('acme/listener'),
            $definition,
            $handler,
        );
        $outbox = $this->createMock(OutboxStore::class);
        $outbox->expects(self::never())->method('append');
        $execution = $this->createMock(ExtensionExecutionGate::class);
        $execution->expects(self::once())
            ->method('assertCurrent')
            ->willThrowException(new RuntimeException('The extension runtime generation is stale.'));
        $publisher = new BusinessRecordMutationEventPublisher(
            $contributions->validateIntegrationContributions(),
            $contributions,
            $outbox,
            $execution,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The extension runtime generation is stale.');
        $publisher->publish(
            ExecutionContext::issueSystem(
                new \stdClass(),
                SystemIdentity::CommandLine,
                SiteContext::default(),
                'mutation-request',
                'mutation-correlation',
            ),
            'site.default.contact',
            1,
            '0191574f-f0b8-7bf3-a9aa-91c6b8244f92',
            2,
            'update',
            ['name'],
            new DateTimeImmutable('2026-08-22T10:15:30Z'),
        );
    }
}
