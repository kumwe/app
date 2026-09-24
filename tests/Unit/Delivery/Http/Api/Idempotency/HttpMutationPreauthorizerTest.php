<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Idempotency;

use Kumwe\Access\AuthorizationGateway;
use Kumwe\Access\AuthorizationResource;
use Kumwe\Access\Capability;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Delivery\Http\Api\Idempotency\HttpMutationPreauthorizer;
use Kumwe\App\Identity\Application\Administration\TokenDelegationPreauthorizer;
use Kumwe\App\Identity\Application\Administration\TokenRotationPreauthorizer;
use Kumwe\App\Identity\Application\Administration\AccessControlRepository;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\ExecutionContext;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the exact capability and resource each browser-parity mutation is pre-authorized against.
 *
 * The idempotency ledger consults this map before it reads or reserves a key, so a caller who may not perform
 * the write can neither probe for nor replay another caller's result. Each route added for machine parity must
 * name the capability and resource the application service itself will authorize, never a broader one.
 *
 * @since  2.0.0
 */
#[CoversClass(HttpMutationPreauthorizer::class)]
final class HttpMutationPreauthorizerTest extends TestCase
{
    /**
     * Every parity route is pre-authorized against the capability and resource its service enforces.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testParityMutationsArePreauthorizedAgainstTheirExactResource(): void
    {
        $user = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';
        $approval = '018f22e2-7c8b-7ab0-8f3a-88e8026bb604';
        $cases = [
            ['POST', '/api/v1/media', 'content.update', 'media', '*'],
            ['DELETE', '/api/v1/media/asset-1', 'content.delete', 'media', '*'],
            ['PUT', '/api/v1/wording/overrides', 'localization.overrides.manage', 'message_override', '*'],
            ['POST', '/api/v1/wording/overrides/withdraw', 'localization.overrides.manage', 'message_override', '*'],
            [
                'POST',
                '/api/v1/business/approvals/' . $approval . '/cancel',
                'business.approval.request',
                'approval_request',
                $approval,
            ],
            ['POST', '/api/v1/users/' . $user . '/password-reset', 'users.manage', 'user', $user],
            ['POST', '/api/v1/users/' . $user . '/step-up/revoke', 'users.manage', 'user', $user],
            ['POST', '/api/v1/users/' . $user . '/sessions/terminate', 'users.manage', 'user', $user],
        ];
        foreach ($cases as [$method, $path, $capability, $type, $identifier]) {
            $calls = [];
            $authorization = $this->createStub(AuthorizationGateway::class);
            $authorization->method('assertAllowed')->willReturnCallback(
                static function (
                    ExecutionContext $context,
                    Capability $action,
                    AuthorizationResource $resource,
                ) use (&$calls): void {
                    $calls[] = [$action->value(), $resource->type(), $resource->identifier()];
                },
            );

            $this->preauthorizer($authorization)->authorize(
                (new ServerRequestFactory())->createServerRequest($method, $path),
                AuthorizationContext::human([$capability]),
            );

            self::assertSame([[$capability, $type, $identifier]], $calls, $method . ' ' . $path);
        }
    }

    /**
     * Build the pre-authorizer over a gateway double and inert identity collaborators.
     *
     * @param   AuthorizationGateway  $authorization  Gateway double recording each decision.
     *
     * @return  HttpMutationPreauthorizer  Pre-authorizer under test.
     *
     * @since   2.0.0
     */
    private function preauthorizer(AuthorizationGateway $authorization): HttpMutationPreauthorizer
    {
        $repository = $this->createStub(AccessControlRepository::class);
        $delegation = new TokenDelegationPreauthorizer($repository, $authorization);

        return new HttpMutationPreauthorizer(
            $authorization,
            (new ReflectionClass(ContentService::class))->newInstanceWithoutConstructor(),
            $repository,
            $delegation,
            new TokenRotationPreauthorizer($repository, $authorization, $delegation),
        );
    }
}
