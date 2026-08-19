<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Runtime;

use Kumwe\App\Extension\Runtime\RuntimeCanonicalJson;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuntimeCanonicalJson::class)]
final class RuntimeCanonicalJsonTest extends TestCase
{
    public function testRecursivelySortsObjectKeysWithoutReorderingLists(): void
    {
        $left = ['z' => ['b' => 2, 'a' => 1], 'a' => [['y' => 2, 'x' => 1], 'tail']];
        $right = ['a' => [['x' => 1, 'y' => 2], 'tail'], 'z' => ['a' => 1, 'b' => 2]];

        self::assertSame(RuntimeCanonicalJson::encode($left), RuntimeCanonicalJson::encode($right));
        self::assertSame('{"a":[{"x":1,"y":2},"tail"],"z":{"a":1,"b":2}}', RuntimeCanonicalJson::encode($left));
    }
}
