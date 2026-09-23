<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Domain;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Domain\RecordValueGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Holds the record-value admission check to its float refusal.
 *
 * The exact decimal kernel belongs to kumwe/conversion and is proven there. What stays in App is the
 * write-path refusal that keeps a PHP float from ever reaching a decimal, money or quantity column; the
 * assertion moved here from the retired ExactDecimalTest when kumwe/conversion 0.1.5 was adopted
 * (KUMWE-MIG-2026-031).
 *
 * @since  2.0.0
 */
#[CoversClass(RecordValueGuard::class)]
final class RecordValueGuardTest extends TestCase
{
    /**
     * A PHP float is refused at the top of a value and inside a nested structure alike.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGenericRecordValuesRejectFloats(): void
    {
        try {
            $nodes = 0;
            RecordValueGuard::assertValue(['amount' => ['unrounded' => 0.1]], 0, $nodes);
            self::fail('A float nested inside a record value must be refused.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('floats', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('floats');
        $nodes = 0;
        RecordValueGuard::assertValue(0.1, 0, $nodes);
    }
}
