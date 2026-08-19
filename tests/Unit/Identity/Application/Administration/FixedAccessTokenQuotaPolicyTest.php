<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Identity\Application\Administration;

use InvalidArgumentException;
use Kumwe\App\Identity\Application\Administration\FixedAccessTokenQuotaPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FixedAccessTokenQuotaPolicy::class)]
final class FixedAccessTokenQuotaPolicyTest extends TestCase
{
    public function testRejectsIssuanceAtConfiguredScopeQuota(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FixedAccessTokenQuotaPolicy(2))->assertAllowed(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb301',
            'default',
            'kumwe',
            'access',
            2,
        );
    }
}
