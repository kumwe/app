<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Identity\Infrastructure\StepUp;

use Kumwe\App\Identity\Application\StepUp\StepUpRejected;
use Kumwe\App\Identity\Infrastructure\StepUp\SodiumStepUpRecoveryCodeHasher;
use Kumwe\App\Identity\Infrastructure\StepUp\SodiumStepUpSecretCipher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SodiumStepUpSecretCipher::class)]
#[CoversClass(SodiumStepUpRecoveryCodeHasher::class)]
final class SodiumStepUpSecretCipherTest extends TestCase
{
    public function testCiphertextRequiresTheExactCredentialBinding(): void
    {
        $cipher = new SodiumStepUpSecretCipher(str_repeat('k', 32));
        $sealed = $cipher->encrypt('authenticator-secret', 'credential-a:actor-a');

        self::assertStringStartsWith('v1.', $sealed);
        self::assertStringNotContainsString('authenticator-secret', $sealed);
        self::assertSame('authenticator-secret', $cipher->decrypt($sealed, 'credential-a:actor-a'));

        $this->expectException(StepUpRejected::class);
        $cipher->decrypt($sealed, 'credential-b:actor-a');
    }

    public function testRecoveryDigestsAreKeyedAndStable(): void
    {
        $code = '0123456789abcdef0123456789abcdef';
        $first = (new SodiumStepUpRecoveryCodeHasher(str_repeat('a', 32)))->digest($code);
        $second = (new SodiumStepUpRecoveryCodeHasher(str_repeat('b', 32)))->digest($code);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        self::assertNotSame($first, $second);
    }
}
