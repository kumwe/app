<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Authorization;

use Kumwe\App\Application\Authorization\AuthorizationDecision;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\StructuredLogAuthorizationDecisionRecorder;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(StructuredLogAuthorizationDecisionRecorder::class)]
final class StructuredLogAuthorizationDecisionRecorderTest extends TestCase
{
    public function testRecordsStructuredAllowDecision(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'Authorization decision.',
            self::callback(static fn (array $record): bool => $record === [
                'subject' => AuthorizationContext::SUBJECT,
                'action' => 'content.read',
                'resource_type' => 'content',
                'resource_id' => 'page-1',
                'site' => 'default',
                'authentication_strength' => 'bearer_token',
                'request_id' => 'test-request-0001',
                'correlation_id' => 'test-request-0001',
                'policy' => 'core.scoped-grants.v1',
                'reason' => 'matching_effective_grant',
                'allowed' => true,
            ]),
        );

        (new StructuredLogAuthorizationDecisionRecorder($logger))->record(
            AuthorizationContext::human(['content.read']),
            Capability::fromString('content.read'),
            AuthorizationResource::item('content', 'page-1'),
            new AuthorizationDecision(true, 'core.scoped-grants.v1', 'matching_effective_grant'),
        );
    }

    public function testRecordsStructuredDenyDecisionAtWarningLevel(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Authorization decision.',
            self::callback(static fn (array $record): bool => $record['allowed'] === false
                && $record['policy'] === 'core.registry.v1'
                && $record['reason'] === 'unsupported_action_resource'),
        );

        (new StructuredLogAuthorizationDecisionRecorder($logger))->record(
            AuthorizationContext::human(['content.read']),
            Capability::fromString('content.read'),
            AuthorizationResource::item('content', 'page-1'),
            new AuthorizationDecision(false, 'core.registry.v1', 'unsupported_action_resource'),
        );
    }
}
