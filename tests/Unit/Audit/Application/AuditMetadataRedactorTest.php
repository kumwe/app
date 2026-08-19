<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Audit\Application;

use Kumwe\App\Audit\Application\AuditMetadataRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditMetadataRedactor::class)]
final class AuditMetadataRedactorTest extends TestCase
{
    public function testCredentialShapedKeysAreReplacedWhateverTheirSpelling(): void
    {
        $redacted = 0;
        $result = AuditMetadataRedactor::redact([
            'password' => 'hunter2',
            'API-Key' => 'live-key',
            'Authorization' => 'Bearer abc',
            'recovery_code' => '123456',
            'session token' => 'opaque',
            'request_id' => 'request-1',
        ], $redacted);

        self::assertSame(5, $redacted);
        self::assertSame('request-1', $result['request_id']);
        foreach (['password', 'API-Key', 'Authorization', 'recovery_code', 'session token'] as $key) {
            self::assertSame(AuditMetadataRedactor::PLACEHOLDER, $result[$key]);
        }
    }

    public function testNestedDocumentsAreRedactedInPlace(): void
    {
        $redacted = 0;
        $result = AuditMetadataRedactor::redact([
            'context' => ['nested' => ['client_secret' => 'value', 'name' => 'safe']],
        ], $redacted);

        self::assertSame(1, $redacted);
        self::assertSame(
            ['context' => ['nested' => ['client_secret' => AuditMetadataRedactor::PLACEHOLDER, 'name' => 'safe']]],
            $result,
        );
    }

    public function testLongOpaqueValuesAreRedactedWhateverTheKeyIsCalled(): void
    {
        $redacted = 0;
        $result = AuditMetadataRedactor::redact([
            'note' => str_repeat('a', 129),
            'sentence' => str_repeat('a word ', 40),
            'short' => str_repeat('a', 128),
        ], $redacted);

        self::assertSame(1, $redacted);
        self::assertSame(AuditMetadataRedactor::PLACEHOLDER, $result['note']);
        self::assertSame(str_repeat('a', 128), $result['short']);
        self::assertSame(str_repeat('a word ', 40), $result['sentence']);
    }

    public function testAnAlreadySafeDocumentIsReturnedUnchanged(): void
    {
        $redacted = 0;
        $metadata = ['request_id' => 'request-1', 'count' => 3, 'flags' => [true, false]];

        self::assertSame($metadata, AuditMetadataRedactor::redact($metadata, $redacted));
        self::assertSame(0, $redacted);
    }
}
