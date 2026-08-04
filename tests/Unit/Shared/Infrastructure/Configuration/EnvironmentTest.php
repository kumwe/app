<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Shared\Infrastructure\Configuration;

use InvalidArgumentException;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Environment::class)]
final class EnvironmentTest extends TestCase
{
    public function testReadsTypedValues(): void
    {
        $environment = new Environment([
            'NAME' => 'Kumwe',
            'ENABLED' => 'yes',
            'LIMIT' => '25',
            'HOSTS' => 'kumwe.test, admin.kumwe.test',
        ]);

        self::assertSame('Kumwe', $environment->string('NAME'));
        self::assertTrue($environment->boolean('ENABLED'));
        self::assertSame(25, $environment->positiveInteger('LIMIT', 10));
        self::assertSame(['kumwe.test', 'admin.kumwe.test'], $environment->commaSeparatedList('HOSTS'));
    }

    public function testMissingRequiredValueIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Environment([]))->string('APP_SECRET');
    }

    public function testInvalidBooleanIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Environment(['ENABLED' => 'sometimes']))->boolean('ENABLED');
    }

    public function testNonPositiveIntegerIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Environment(['LIMIT' => '0']))->positiveInteger('LIMIT', 1);
    }
}
