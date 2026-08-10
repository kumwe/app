<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessSurface\Delivery\Browser;

use JsonException;
use Kumwe\CMS\BusinessSurface\Delivery\Browser\GeneratedBusinessConfirmationQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GeneratedBusinessConfirmationQuery::class)]
/**
 * Verifies rejected second-factor material never reaches a retained browser model.
 *
 * @since  2.0.0
 */
final class GeneratedBusinessConfirmationQueryTest extends TestCase
{
    /**
     * Strip credential and browser-token bytes while preserving reviewed action input.
     *
     * @return  void
     *
     * @throws  JsonException  When the safe model cannot be encoded for the byte-level assertion.
     *
     * @since   2.0.0
     */
    public function testRejectedCredentialBytesNeverEnterTheRetainedModel(): void
    {
        $credential = 'single-use-recovery-code-44ee';
        $csrf = 'old-csrf-token-77aa';
        $query = GeneratedBusinessConfirmationQuery::retain([
            '_csrf' => $csrf,
            'operation' => 'action',
            'action' => 'approve',
            'confirmed' => '1',
            'verification_method' => 'recovery',
            'verification' => $credential,
            'input' => ['note' => 'Keep this reviewed command'],
        ]);
        $encoded = json_encode($query, JSON_THROW_ON_ERROR);

        self::assertSame('action', $query['confirm']);
        self::assertSame(['note' => 'Keep this reviewed command'], $query['input']);
        self::assertArrayNotHasKey('verification', $query);
        self::assertArrayNotHasKey('verification_method', $query);
        self::assertStringNotContainsString($credential, $encoded);
        self::assertStringNotContainsString($csrf, $encoded);
    }
}
