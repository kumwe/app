<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Identity\Domain;

use InvalidArgumentException;
use Kumwe\CMS\Identity\Domain\AuthorizationDecision;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthorizationDecision::class)]
final class AuthorizationDecisionTest extends TestCase
{
    public function testRepresentsExplicitAllowanceAndDenial(): void
    {
        $allow = AuthorizationDecision::allow('role.grant');
        $deny = AuthorizationDecision::deny('policy.blocked');

        self::assertTrue($allow->isAllowed());
        self::assertFalse($allow->isDenied());
        self::assertSame('role.grant', $allow->reason());
        self::assertTrue($deny->isDenied());
    }

    public function testRejectsHumanProseAsAReasonCode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthorizationDecision::deny('Access was denied');
    }
}
