<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Application;

use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\PolicyBusinessRecordReader;
use Kumwe\Extension\Spi\Application\ExecutionContext;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordReadRequest;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

#[CoversClass(PolicyBusinessRecordReader::class)]
/**
 * Proves the SDK reader port refuses any execution context the host did not itself issue.
 *
 * @since  2.0.0
 */
final class PolicyBusinessRecordReaderTest extends TestCase
{
    /**
     * Prove a context that merely implements the public interface carries no authority.
     *
     * The SDK contract is explicit that implementing the interface is not proof of authority: only the
     * concrete host-issued context, whose provenance the authorization gateway later verifies, may reach
     * `BusinessRecordService::browse()`. A context minted by extension code is refused before any policy
     * work begins.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAContextTheHostDidNotIssueIsRefusedBeforeAnyPolicyWork(): void
    {
        $service = (new ReflectionClass(BusinessRecordService::class))->newInstanceWithoutConstructor();
        $reader = new PolicyBusinessRecordReader($service);
        $foreign = new class implements ExecutionContext {
            /**
             * Return the fixed site coordinate carried by this forged probe.
             *
             * @return  string  Site identifier.
             *
             * @since   2.0.0
             */
            public function siteIdentifier(): string
            {
                return 'default';
            }

            /**
             * Return the fixed actor coordinate carried by this forged probe.
             *
             * @return  string  Actor identifier.
             *
             * @since   2.0.0
             */
            public function actorId(): string
            {
                return '018f22e2-7c8b-7ab0-8f3a-88e8026bb901';
            }

            /**
             * Return no organization scope for this forged probe.
             *
             * @return  ?string  Always null.
             *
             * @since   2.0.0
             */
            public function organizationIdentifier(): ?string
            {
                return null;
            }

            /**
             * Return no workspace scope for this forged probe.
             *
             * @return  ?string  Always null.
             *
             * @since   2.0.0
             */
            public function workspaceIdentifier(): ?string
            {
                return null;
            }

            /**
             * Return the fixed request coordinate carried by this forged probe.
             *
             * @return  string  Request identifier.
             *
             * @since   2.0.0
             */
            public function requestId(): string
            {
                return 'forged-request';
            }

            /**
             * Return the fixed correlation coordinate carried by this forged probe.
             *
             * @return  string  Correlation identifier.
             *
             * @since   2.0.0
             */
            public function correlationId(): string
            {
                return 'forged-correlation';
            }

            /**
             * Return the fixed delivery surface carried by this forged probe.
             *
             * @return  string  Delivery surface name.
             *
             * @since   2.0.0
             */
            public function deliverySurface(): string
            {
                return 'http';
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('host-issued execution context');

        $reader->readPage(new BusinessRecordReadRequest(
            $foreign,
            'acme.assets.item',
            new RecordQuerySpecification(),
        ));
    }
}
