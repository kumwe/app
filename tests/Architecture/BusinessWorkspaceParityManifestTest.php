<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
/**
 * Keeps the Phase 2 administrator workspace migration bound to its pre-KIS behavior contract.
 *
 * @since  2.0.0
 */
final class BusinessWorkspaceParityManifestTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        require_once dirname(__DIR__, 2) . '/tools/verify-business-workspace-parity.php';
    }

    /**
     * Routes, capabilities, inputs, actions, payloads, safeguards, and no-JS guarantees stay in parity.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBusinessWorkspaceParityManifestMatchesProductionSources(): void
    {
        $violations = (new \BusinessWorkspaceParityVerifier(dirname(__DIR__, 2)))->violations();

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }
}
