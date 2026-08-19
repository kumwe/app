<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Automation;

use InvalidArgumentException;
use Kumwe\App\Application\Automation\CanonicalJson;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalJson::class)]
final class CanonicalJsonTest extends TestCase
{
    public function testObjectKeysAreSortedRecursivelyWhileListOrderIsPreserved(): void
    {
        $first = ['z' => 2, 'nested' => ['b' => true, 'a' => [3, 1]]];
        $second = ['nested' => ['a' => [3, 1], 'b' => true], 'z' => 2];

        self::assertSame(
            '{"nested":{"a":[3,1],"b":true},"z":2}',
            CanonicalJson::encode($first),
        );
        self::assertSame(CanonicalJson::digest($first), CanonicalJson::digest($second));
        self::assertNotSame(
            CanonicalJson::digest($first),
            CanonicalJson::digest(['nested' => ['a' => [1, 3], 'b' => true], 'z' => 2]),
        );
    }

    public function testUnsupportedValuesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CanonicalJson::encode(INF);
    }
}
