<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Host;

use Kumwe\App\Studio\Application\Host\StudioPermissionHostPort;
use Kumwe\App\Tests\Support\StudioProducerRequest;
use Kumwe\Producer\Error\HostRefusal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(StudioPermissionHostPort::class)]
/**
 * Proves the permission port explains and refreshes only from the live session authority.
 *
 * @since  2.0.0
 */
final class StudioPermissionHostPortTest extends TestCase
{
    /**
     * Prove a held operation explains as allowed and a foreign one is withheld with a neutral reason.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExplainAnswersFromTheLiveAuthorityWithoutDisclosingPolicy(): void
    {
        $allowed = StudioProducerRequest::authorized(
            'studio.operation/permission.explain',
            (object) ['operation' => 'studio.permission/save'],
        );
        $decision = (new StudioPermissionHostPort($allowed->authority))
            ->explain($allowed->arguments(), $allowed->context());
        self::assertTrue($decision->value->allowed);

        $withheld = StudioProducerRequest::authorized(
            'studio.operation/permission.explain',
            (object) ['operation' => 'studio.permission/publish'],
            capabilities: ['content.read', 'studio.mode.content'],
        );
        $refused = (new StudioPermissionHostPort($withheld->authority))
            ->explain($withheld->arguments(), $withheld->context());
        self::assertFalse($refused->value->allowed);
        self::assertSame('studio.permission/withheld', $refused->value->reason->key);
    }

    /**
     * Prove the refresh snapshot reports the sorted live permissions and session generation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefreshReportsTheLiveSnapshot(): void
    {
        $request = StudioProducerRequest::authorized('studio.operation/permission.refresh', null);
        $snapshot = (new StudioPermissionHostPort($request->authority))
            ->refresh($request->arguments(), $request->context());

        self::assertSame($request->snapshot->generation, $snapshot->value->sessionGeneration);
        self::assertContains('studio.permission/save', $snapshot->value->permissions);
    }

    /**
     * Prove arguments outside the closed explain shape are refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testForeignExplainArgumentsAreRefused(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/permission.explain',
            (object) ['operation' => 'studio.permission/save'],
        );

        $this->expectException(HostRefusal::class);

        (new StudioPermissionHostPort($request->authority))
            ->explain((object) ['operation' => 'x', 'extra' => true], $request->context());
    }

    /**
     * Prove refresh accepts only an absent or exactly empty argument object.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testForeignRefreshArgumentsAreRefused(): void
    {
        $request = StudioProducerRequest::authorized('studio.operation/permission.refresh', null);

        try {
            (new StudioPermissionHostPort($request->authority))
                ->refresh((object) ['extra' => true], $request->context());
            self::fail('The foreign refresh arguments were unexpectedly accepted.');
        } catch (HostRefusal $refused) {
            self::assertSame('invalid-request', $refused->error()->category());
            self::assertSame('studio.host/invalid-arguments', $refused->error()->diagnostics()[0]->code());
        }
    }

    /**
     * Prove mutation-only context coordinates are refused on the read-only permission port.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMutationContextIsRefusedOnTheReadOnlyPort(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/permission.refresh',
            null,
            idempotencyKey: 'idempotency/permission-refresh',
        );

        try {
            (new StudioPermissionHostPort($request->authority))->refresh(null, $request->context());
            self::fail('The mutation-shaped context was unexpectedly accepted.');
        } catch (HostRefusal $refused) {
            self::assertSame('invalid-request', $refused->error()->category());
            self::assertSame('studio.host/invalid-context', $refused->error()->diagnostics()[0]->code());
        }
    }
}
