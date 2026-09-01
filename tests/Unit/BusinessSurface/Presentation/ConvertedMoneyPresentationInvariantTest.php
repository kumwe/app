<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSurface\Presentation;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Domain\ClientAssertedInstant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClientAssertedInstant::class)]
/**
 * Pins the refusals that keep a client-asserted capture instant honest beside a converted figure.
 *
 * The invariants that keep a converted figure and its evidence from coming apart in a presentation are
 * proven where the model lives, in kumwe/extension-sdk and kumwe/conversion. What the App owns is the
 * instant a client asserts a figure was captured at: the text has to be a real RFC 3339 instant with an
 * offset, and a well-formed string that names no calendar date is refused rather than trusted.
 *
 * @since  2.0.0
 */
final class ConvertedMoneyPresentationInvariantTest extends TestCase
{
    /**
     * A client-asserted instant refuses text that is not an RFC 3339 instant with an offset.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAClientAssertedInstantRefusesTextThatIsNotAnInstant(): void
    {
        foreach (['2026-08-14', 'yesterday', '2026-08-14T09:30:00', ''] as $malformed) {
            try {
                ClientAssertedInstant::fromPortableString($malformed);
                self::fail('A capture instant accepted "' . $malformed . '".');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('RFC 3339', $exception->getMessage());
            }
        }

        self::assertSame(
            '2026-08-14T09:30:00.000000+00:00',
            ClientAssertedInstant::fromPortableString('2026-08-14T11:30:00+02:00')->toPortableString(),
        );
    }

    /**
     * A capture instant whose text is well formed but names no real date is refused.
     *
     * The grammar admits `2026-13-45`, because a month is two digits by shape. Only the calendar can
     * reject it, so the parse result is checked rather than assumed from the pattern.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACaptureInstantThatParsesToNoRealDateIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RFC 3339');
        ClientAssertedInstant::fromPortableString('2026-13-45T00:00:00+00:00');
    }
}
