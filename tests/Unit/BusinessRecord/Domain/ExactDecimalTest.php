<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Domain;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Domain\RecordValueGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordValueGuard::class)]
final class ExactDecimalTest extends TestCase
{
    public function testGenericRecordValuesRejectFloats(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $nodes = 0;
        RecordValueGuard::assertValue(0.1, 0, $nodes);
    }
}
