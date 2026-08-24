<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Media;

use Kumwe\App\Application\Idempotency\IdempotencyLedger;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Studio\Application\Media\StudioMediaMutationIdempotency;
use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use stdClass;

/**
 * Proves authorize-upload replay stays stable without persisting its live transfer capability.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioMediaMutationIdempotency::class)]
final class StudioMediaMutationIdempotencyTest extends TestCase
{
    /**
     * Store a secret-free result and restore the same capability without rerunning authorization.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testGrantReplayNeverPersistsItsPlaintextToken(): void
    {
        $row = null;
        $ledger = $this->createMock(IdempotencyLedger::class);
        $ledger->expects(self::exactly(2))->method('reserve')->willReturnOnConsecutiveCalls(true, false);
        $ledger->expects(self::once())->method('complete')->willReturnCallback(
            static function (
                string $subject,
                string $operation,
                string $key,
                string $owner,
                int $status,
                string $body,
                array $headers,
            ) use (&$row): bool {
                $row = [
                    'authorization_fingerprint' => hash('sha256', $subject . "\0" . $operation),
                    'expires_at' => '2030-01-01T00:00:00+00:00',
                    'id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb711',
                    'request_digest' => hash('sha256', '{not-used-by-this-test}'),
                    'result_body' => $body,
                    'result_body_digest' => hash('sha256', $body),
                    'result_headers' => $headers,
                    'result_status' => $status,
                    'state' => 'completed',
                ];

                return true;
            },
        );
        $request = self::request();
        $intentDigest = hash('sha256', \Kumwe\App\Studio\Domain\Contract\CanonicalJson::stringify(
            (object) [
                'arguments' => $request->arguments,
                'expectedRevision' => null,
                'locale' => null,
                'protocolVersion' => $request->protocolVersion,
            ],
        ));
        $scope = 'studio-media:' . hash(
            'sha256',
            $request->operationId . "\0" . $request->resourceContextKey . "\0" . $request->sessionGeneration,
        );
        $ledger->method('find')->willReturnCallback(
            static function () use (&$row, $intentDigest, $scope): ?array {
                if (is_array($row)) {
                    $row['request_digest'] = $intentDigest;
                    $row['authorization_fingerprint'] = hash('sha256', 'actors/1' . "\0" . $scope);
                }

                return $row;
            },
        );
        $transactions = self::createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-08-24T12:00:00+00:00'));
        $idempotency = new StudioMediaMutationIdempotency($ledger, $transactions, $clock);
        $runs = 0;
        $operation = static function () use (&$runs): stdClass {
            ++$runs;

            return (object) [
                'expiresAt' => '2030-01-01T00:00:00.000Z',
                'headers' => (object) [
                    'Content-Type' => 'image/jpeg',
                    'X-Studio-Upload-Token' => 'plaintext-capability',
                ],
                'method' => 'PUT',
                'plan' => (object) ['maximumBytes' => 10, 'resumable' => false],
                'uploadId' => 'uploads/0123456789abcdef0123456789abcdef',
                'url' => 'https://example.invalid/uploads/0123456789abcdef0123456789abcdef',
            ];
        };
        $restore = static function (stdClass $stored): stdClass {
            self::assertInstanceOf(stdClass::class, $stored->headers);
            $grant = clone $stored;
            $grant->headers = clone $stored->headers;
            $grant->headers->{'X-Studio-Upload-Token'} = 'plaintext-capability';

            return $grant;
        };

        $first = $idempotency->runGrant($request, 'actors/1', $operation, $restore);
        $second = $idempotency->runGrant($request, 'actors/1', $operation, $restore);

        self::assertSame(1, $runs);
        self::assertInstanceOf(stdClass::class, $first);
        self::assertInstanceOf(stdClass::class, $second);
        self::assertInstanceOf(stdClass::class, $second->headers);
        self::assertIsString($second->headers->{'X-Studio-Upload-Token'});
        self::assertSame('plaintext-capability', $second->headers->{'X-Studio-Upload-Token'});
        self::assertIsArray($row);
        self::assertStringNotContainsString('plaintext-capability', $row['result_body']);
        self::assertStringNotContainsString('X-Studio-Upload-Token', $row['result_body']);
    }

    /**
     * Reserve before opening the mutation transaction and release only after a failed transaction rolls back.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testReservationAndFailureReleaseStayOutsideTheMutationTransaction(): void
    {
        $active = false;
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->expects(self::once())->method('transactional')->willReturnCallback(
            static function (callable $operation) use (&$active): mixed {
                self::assertFalse($active);
                $active = true;
                try {
                    return $operation();
                } finally {
                    $active = false;
                }
            },
        );
        $ledger = $this->createMock(IdempotencyLedger::class);
        $ledger->expects(self::once())->method('reserve')->willReturnCallback(
            static function (
                string $subject,
                string $operation,
                string $key,
                string $requestDigest,
                string $authorizationFingerprint,
                string $ownerToken,
            ) use (&$active): bool {
                self::assertFalse($active);

                return true;
            },
        );
        $ledger->expects(self::once())->method('release')->willReturnCallback(
            static function (
                string $subject,
                string $operation,
                string $key,
                string $ownerToken,
            ) use (&$active): bool {
                self::assertFalse($active);

                return true;
            },
        );
        $ledger->expects(self::never())->method('complete');
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-08-24T12:00:00+00:00'));
        $idempotency = new StudioMediaMutationIdempotency($ledger, $transactions, $clock);

        $this->expectException(RuntimeException::class);
        $idempotency->run(
            self::request(),
            'actors/1',
            static function (): never {
                throw new RuntimeException('mutation failed');
            },
        );
    }

    /**
     * Build one stable authorize-upload envelope whose correlation fields do not affect replay.
     *
     * @return  StudioHostRequest  Mutation request carrying one supplied key.
     *
     * @since  2.0.0
     */
    private static function request(): StudioHostRequest
    {
        return new StudioHostRequest(
            'studio.operation/media.authorize-upload',
            '0.1.0-draft.2',
            'requests/grant-1',
            'contexts/grant-1',
            'generation-1',
            (object) [
                'request' => (object) [
                    'byteSize' => 10,
                    'filename' => 'photo.jpg',
                    'mediaType' => 'image/jpeg',
                    'purpose' => 'studio.media/content',
                ],
            ],
            null,
            'idempotency/grant-1',
            null,
            null,
        );
    }
}
