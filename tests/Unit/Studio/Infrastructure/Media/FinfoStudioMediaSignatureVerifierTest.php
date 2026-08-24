<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Infrastructure\Media;

use Kumwe\App\Studio\Infrastructure\Media\FinfoStudioMediaSignatureVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Proves media detection is paired with an explicit supported file signature.
 *
 * @since  2.0.0
 */
#[CoversClass(FinfoStudioMediaSignatureVerifier::class)]
#[RequiresPhpExtension('fileinfo')]
final class FinfoStudioMediaSignatureVerifierTest extends TestCase
{
    /**
     * A valid PDF signature is accepted while arbitrary text is outside the supported set.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testDetectionAndMagicMustBothNameASupportedType(): void
    {
        $pdf = tempnam(sys_get_temp_dir(), 'studio-signature-');
        $text = tempnam(sys_get_temp_dir(), 'studio-signature-');
        self::assertIsString($pdf);
        self::assertIsString($text);
        file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");
        file_put_contents($text, 'plain text');
        $verifier = new FinfoStudioMediaSignatureVerifier();

        try {
            self::assertSame('application/pdf', $verifier->verify($pdf));
            self::assertNull($verifier->verify($text));
        } finally {
            @unlink($pdf);
            @unlink($text);
        }
    }
}
