<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Functional\Security;

use Kumwe\App\Delivery\Http\Api\Idempotency\PersistentIdempotencyMiddleware;
use Kumwe\App\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Tests\Support\SecurityHttpHarness;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Pins that an `Idempotency-Key` belongs to the actor and operation that first used it.
 *
 * A key is a client-chosen string, so two tenants can pick the same one by accident or by design. Scoped
 * wrongly, the second caller would be served the first caller's stored response — a disclosure — or be
 * refused its own legitimate mutation. The test drives the production REST pipeline with real tokens and
 * requires each actor's key to replay only that actor's result, content reuse to be refused for the owner,
 * the same key to stay independent across operations, and a revoked owner to be refused before any replay.
 *
 * @since  2.0.0
 */
#[CoversClass(PersistentIdempotencyMiddleware::class)]
#[CoversClass(RequireIdempotencyKeyMiddleware::class)]
final class IdempotencyKeyOwnershipTest extends TestCase
{
    /**
     * One key used by two actors yields two independent mutations, never a cross-actor replay.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAKeyReplaysOnlyForTheActorAndOperationThatOwnIt(): void
    {
        $harness = SecurityHttpHarness::boot();
        $owner = $harness->machineActor(['navigation.manage']);
        $other = $harness->machineActor(['navigation.manage']);
        $suffix = bin2hex(random_bytes(6));
        $key = 'shared-key-' . $suffix;
        $ownerBody = ['handle' => 'owner_' . $suffix, 'title' => 'Owner menu'];
        $otherBody = ['handle' => 'other_' . $suffix, 'title' => 'Other menu'];

        $first = $this->post($harness, '/api/v1/menus', $owner['token'], $ownerBody, $key);
        self::assertSame(201, $first->getStatusCode());
        $ownerMenu = $this->decode($first);
        $replay = $this->post($harness, '/api/v1/menus', $owner['token'], $ownerBody, $key);
        self::assertSame(201, $replay->getStatusCode());
        self::assertSame('true', $replay->getHeaderLine('Idempotency-Replayed'));
        self::assertSame($ownerMenu['id'], $this->decode($replay)['id']);
        $reused = $this->post($harness, '/api/v1/menus', $owner['token'], $otherBody, $key);
        self::assertSame(422, $reused->getStatusCode(), 'The owner cannot reuse the key for other content.');
        self::assertSame('urn:kumwe:problem:idempotency-key-reused', $this->decode($reused)['type'] ?? null);

        $independent = $this->post($harness, '/api/v1/menus', $other['token'], $otherBody, $key);
        self::assertSame(201, $independent->getStatusCode(), 'Another actor runs its own mutation.');
        self::assertSame('', $independent->getHeaderLine('Idempotency-Replayed'));
        $otherMenu = $this->decode($independent);
        self::assertNotSame($ownerMenu['id'], $otherMenu['id']);
        self::assertStringNotContainsString($ownerMenu['id'], (string) $independent->getBody());
        $sameContent = $this->post($harness, '/api/v1/menus', $other['token'], $ownerBody, $key);
        self::assertNotSame(201, $sameContent->getStatusCode(), 'The other actor is never served the owner result.');
        self::assertStringNotContainsString($ownerMenu['id'], (string) $sameContent->getBody());

        $item = $this->post(
            $harness,
            '/api/v1/menus/' . $ownerMenu['id'] . '/items',
            $owner['token'],
            [
                'parent_id' => null,
                'title' => 'Item under the same key',
                'slug' => 'item-' . $suffix,
                'position' => 1,
                'target_type' => 'url',
                'target_url' => 'https://kumwe.test/item',
            ],
            $key,
        );
        self::assertSame(201, $item->getStatusCode(), 'The key is scoped to its operation.');
        self::assertSame('', $item->getHeaderLine('Idempotency-Replayed'));
    }

    /**
     * A key's stored result is withheld from its own owner once the owner loses the authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARevokedOwnerIsRefusedBeforeAnyReplay(): void
    {
        $harness = SecurityHttpHarness::boot();
        $owner = $harness->machineActor(['navigation.manage']);
        $suffix = bin2hex(random_bytes(6));
        $body = ['handle' => 'revoked_' . $suffix, 'title' => 'Revoked owner menu'];
        $first = $this->post($harness, '/api/v1/menus', $owner['token'], $body, 'revoked-key-' . $suffix);
        self::assertSame(201, $first->getStatusCode());
        $menu = $this->decode($first);

        $access = $harness->container->get(AccessControlService::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        $access->revokeGrant(
            TestKernelFactory::administratorContext($harness->container),
            $owner['grants']['navigation.manage'],
        );

        $replay = $this->post($harness, '/api/v1/menus', $owner['token'], $body, 'revoked-key-' . $suffix);
        self::assertContains($replay->getStatusCode(), [401, 403]);
        self::assertStringNotContainsString($menu['id'], (string) $replay->getBody());
    }

    /**
     * Send one keyed JSON mutation.
     *
     * @param   SecurityHttpHarness   $harness  Booted harness.
     * @param   string                $path     API path.
     * @param   string                $token    Bearer token.
     * @param   array<string, mixed>  $body     JSON body.
     * @param   string                $key      Idempotency key.
     *
     * @return  ResponseInterface  Pipeline response.
     *
     * @since   2.0.0
     */
    private function post(
        SecurityHttpHarness $harness,
        string $path,
        string $token,
        array $body,
        string $key,
    ): ResponseInterface {
        return $harness->handle($harness->api('POST', $path, $token, $body, ['Idempotency-Key' => $key]));
    }

    /**
     * Decode a JSON response body.
     *
     * @param   ResponseInterface  $response  Response to decode.
     *
     * @return  array<string, mixed>  Decoded document.
     *
     * @since   2.0.0
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertTrue(!isset($decoded['id']) || is_string($decoded['id']));

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
