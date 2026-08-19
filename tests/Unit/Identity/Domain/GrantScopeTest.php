<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Identity\Domain;

use InvalidArgumentException;
use Kumwe\App\Identity\Domain\GrantScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GrantScope::class)]
final class GrantScopeTest extends TestCase
{
    public function testGlobalScopeCoversEveryRequestedScope(): void
    {
        self::assertTrue(GrantScope::global()->covers(GrantScope::named('site', 'site-1')));
        self::assertTrue(GrantScope::global()->isGlobal());
    }

    public function testNamedScopeCoversOnlyAnExactScope(): void
    {
        $scope = GrantScope::named('site', 'site-1');

        self::assertTrue($scope->covers(GrantScope::named('site', 'site-1')));
        self::assertFalse($scope->covers(GrantScope::named('site', 'site-2')));
        self::assertFalse($scope->covers(GrantScope::named('content', 'site-1')));
        self::assertTrue($scope->equals(GrantScope::named('site', 'site-1')));
    }

    public function testRejectsAnIdentifierOnTheGlobalScope(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GrantScope::named('global', 'anything');
    }
}
