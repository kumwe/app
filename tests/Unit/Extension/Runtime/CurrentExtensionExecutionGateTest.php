<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Runtime;

use Kumwe\App\Extension\Runtime\CurrentExtensionExecutionGate;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeGenerationAuthority;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CurrentExtensionExecutionGate::class)]
/**
 * Pins the fail-closed policy between resident extension code and live generation authority.
 *
 * @since  2.0.0
 */
final class CurrentExtensionExecutionGateTest extends TestCase
{
    /**
     * Admit the exact trusted publication while live authority still agrees with it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTrustedCurrentGenerationIsAdmitted(): void
    {
        $loaded = $this->trustedState();
        $authority = $this->createMock(ExtensionRuntimeGenerationAuthority::class);
        $authority->expects(self::exactly(2))->method('isCurrent')->with($loaded)->willReturn(true);
        $gate = new CurrentExtensionExecutionGate($authority, $loaded);

        self::assertTrue($gate->isCurrent());
        $gate->assertCurrent();
    }

    /**
     * Refuse a trusted publication once authority reports that another generation replaced it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStaleTrustedGenerationIsRefused(): void
    {
        $authority = $this->createStub(ExtensionRuntimeGenerationAuthority::class);
        $authority->method('isCurrent')->willReturn(false);
        $gate = new CurrentExtensionExecutionGate($authority, $this->trustedState());

        self::assertFalse($gate->isCurrent());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stale or untrusted extension generation');
        $gate->assertCurrent();
    }

    /**
     * Treat unreadable authority as stale instead of allowing resident code to execute optimistically.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAuthorityReadFailureIsRefused(): void
    {
        $authority = $this->createStub(ExtensionRuntimeGenerationAuthority::class);
        $authority->method('isCurrent')->willThrowException(new RuntimeException('authority unavailable'));
        $gate = new CurrentExtensionExecutionGate($authority, $this->trustedState());

        self::assertFalse($gate->isCurrent());
    }

    /**
     * Refuse an untrusted boot state without consulting live authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUntrustedBootStateIsRefusedWithoutAuthorityRead(): void
    {
        $authority = $this->createMock(ExtensionRuntimeGenerationAuthority::class);
        $authority->expects(self::never())->method('isCurrent');
        $gate = new CurrentExtensionExecutionGate(
            $authority,
            RuntimeMaterializationState::unavailable('test-replica'),
        );

        self::assertFalse($gate->isCurrent());
    }

    /**
     * Build one stable trusted publication state for authority comparisons.
     *
     * @return  RuntimeMaterializationState  Trusted generation fixture.
     *
     * @since   2.0.0
     */
    private function trustedState(): RuntimeMaterializationState
    {
        return new RuntimeMaterializationState(
            'test-replica',
            17,
            str_repeat('a', 64),
            str_repeat('b', 64),
            true,
        );
    }
}
