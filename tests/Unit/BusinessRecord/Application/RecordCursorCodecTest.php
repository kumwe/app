<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Application\RecordCursorCodec;
use Kumwe\Extension\Spi\BusinessRecord\Query\CursorPosition;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordCursor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordCursorCodec::class)]
/**
 * Proves the host signing boundary round-trips and authenticates canonical SDK cursor values.
 *
 * @since  2.0.0
 */
final class RecordCursorCodecTest extends TestCase
{
    /**
     * A signed cursor round-trips while payload or signature tampering is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSignedCursorRoundTripsAndRejectsPayloadOrSignatureTampering(): void
    {
        $codec = new RecordCursorCodec(str_repeat('cursor-key-', 4));
        $position = new CursorPosition(
            str_repeat('a', 64),
            ['Alpha', '12.340000'],
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e11',
        );
        $cursor = $codec->encode($position);
        $decoded = $codec->decode($cursor);

        self::assertSame($position->toArray(), $decoded->toArray());

        $wide = new CursorPosition(
            str_repeat('b', 64),
            array_fill(0, 5, str_repeat("\u{1F680}", 512)),
            '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
        );
        self::assertSame($wide->toArray(), $codec->decode($codec->encode($wide))->toArray());

        $token = $cursor->value();
        $replacement = str_ends_with($token, 'A') ? 'B' : 'A';
        $tampered = RecordCursor::fromString(substr($token, 0, -1) . $replacement);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('signature');
        $codec->decode($tampered);
    }
}
