<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Media;

use Kumwe\App\Application\Idempotency\IdempotencyLedger;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Studio\Application\Media\StudioMediaMutationIdempotency;
use Kumwe\App\Studio\Application\Media\StudioMediaPortRejected;
use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
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
     * Refuse every malformed completed-row representation before it can be replayed.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testMalformedCompletedRowsNeverReplay(): void
    {
        $request = self::request();
        $valid = self::completedRow($request);
        $cases = [
            'missing body' => array_diff_key($valid, ['result_body' => true]),
            'wrong body digest' => array_replace($valid, ['result_body_digest' => str_repeat('0', 64)]),
            'malformed JSON' => array_replace($valid, [
                'result_body' => '{',
                'result_body_digest' => hash('sha256', '{'),
            ]),
            'non-numeric status' => array_replace($valid, ['result_status' => '200.0']),
            'malformed error body' => self::rowWithBody($valid, 422, '[]'),
        ];

        foreach ($cases as $case => $row) {
            self::assertRejected(
                'studio.media/idempotency-corrupt',
                fn () => $this->collision($request, $row)->run(
                    $request,
                    'actors/1',
                    static fn (): never => throw new RuntimeException('A completed replay reran its mutation.'),
                ),
                $case,
            );
        }

        $scalar = self::rowWithBody($valid, 200, '[]');
        self::assertRejected(
            'studio.media/idempotency-corrupt',
            fn () => $this->collision($request, $scalar)->runGrant(
                $request,
                'actors/1',
                static fn (): never => throw new RuntimeException('A completed replay reran its grant.'),
                static fn (stdClass $stored): stdClass => $stored,
            ),
        );
    }

    /**
     * Refuse collision rows that lost identity, expiry, intent, authority or a supported live state.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testCollisionRowsFailClosedAcrossEveryTrustCoordinate(): void
    {
        $request = self::request();
        $valid = self::completedRow($request);
        $cases = [
            'vanished row' => null,
            'missing identity' => array_diff_key($valid, ['id' => true]),
            'malformed expiry' => array_replace($valid, ['expires_at' => 'not-an-instant']),
            'changed intent' => array_replace($valid, ['request_digest' => str_repeat('0', 64)]),
            'changed authority' => array_replace($valid, ['authorization_fingerprint' => str_repeat('0', 64)]),
            'unknown state' => array_replace($valid, ['state' => 'unknown']),
            'live owner' => array_replace($valid, [
                'locked_until' => '2030-01-01T00:00:00+00:00',
                'state' => 'in_progress',
            ]),
        ];
        $codes = [
            'studio.media/idempotency-corrupt',
            'studio.media/idempotency-corrupt',
            'studio.media/idempotency-corrupt',
            'studio.media/idempotency-reused',
            'studio.media/idempotency-authority-changed',
            'studio.media/idempotency-corrupt',
            'studio.media/idempotency-in-flight',
        ];

        foreach (array_values($cases) as $index => $row) {
            self::assertRejected(
                $codes[$index],
                fn () => $this->collision($request, $row)->run(
                    $request,
                    'actors/1',
                    static fn (): never => throw new RuntimeException('A collided mutation was executed.'),
                ),
                array_keys($cases)[$index],
            );
        }

        $expired = array_replace($valid, ['expires_at' => '2020-01-01T00:00:00+00:00']);
        self::assertRejected(
            'studio.media/idempotency-in-flight',
            fn () => $this->collision($request, $expired)->run(
                $request,
                'actors/1',
                static fn (): never => throw new RuntimeException('An unclaimed expired mutation was executed.'),
            ),
        );
    }

    /**
     * Release a newly owned reservation when settlement, the mutation, or grant projection fails.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testOwnedReservationFailuresAreReleasedAndRemainNonReplayable(): void
    {
        $request = self::request();
        $operations = [
            'lost success settlement' => static fn (): stdClass => (object) ['ok' => true],
            'non-durable refusal' => static fn (): never => throw new StudioMediaPortRejected(
                'validation-failed',
                'studio.media/upload-failed',
            ),
            'lost durable-refusal settlement' => static fn (): never => throw new StudioMediaPortRejected(
                'validation-failed',
                'studio.media/upload-failed',
                true,
            ),
        ];

        foreach ($operations as $case => $operation) {
            $ledger = $this->createMock(IdempotencyLedger::class);
            $ledger->expects(self::once())->method('reserve')->willReturn(true);
            $ledger->expects(self::once())->method('release')->willReturn(true);
            if ($case === 'non-durable refusal') {
                $ledger->expects(self::never())->method('complete');
            } else {
                $ledger->expects(self::once())->method('complete')->willReturn(false);
            }
            self::assertRejected(
                $case === 'non-durable refusal'
                    ? 'studio.media/upload-failed'
                    : 'studio.media/idempotency-in-flight',
                fn () => $this->idempotency($ledger)->run($request, 'actors/1', $operation),
                $case,
            );
        }

        $ledger = $this->createMock(IdempotencyLedger::class);
        $ledger->expects(self::once())->method('reserve')->willReturn(true);
        $ledger->expects(self::never())->method('complete');
        $ledger->expects(self::once())->method('release')->willReturn(true);
        self::assertRejected(
            'studio.media/idempotency-corrupt',
            fn () => $this->idempotency($ledger)->runGrant(
                $request,
                'actors/1',
                static fn (): stdClass => new stdClass(),
                static fn (stdClass $stored): stdClass => $stored,
            ),
        );
    }

    /**
     * Propagate both durable and non-durable refusals when no replay key was supplied.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testUnkeyedRefusalsRetainTheirCommitSemantics(): void
    {
        $ledger = self::createStub(IdempotencyLedger::class);
        $idempotency = $this->idempotency($ledger);
        $request = self::request(null);

        self::assertRejected(
            'studio.media/upload-failed',
            fn () => $idempotency->run(
                $request,
                'actors/1',
                static fn (): never => throw new StudioMediaPortRejected(
                    'validation-failed',
                    'studio.media/upload-failed',
                    true,
                ),
            ),
        );
        self::assertRejected(
            'studio.media/upload-too-large',
            fn () => $idempotency->runGrant(
                $request,
                'actors/1',
                static fn (): never => throw new StudioMediaPortRejected(
                    'limit-exceeded',
                    'studio.media/upload-too-large',
                    true,
                ),
                static fn (stdClass $stored): stdClass => $stored,
            ),
        );
        self::assertRejected(
            'studio.media/upload-refused',
            fn () => $idempotency->run(
                $request,
                'actors/1',
                static fn (): never => throw new StudioMediaPortRejected(
                    'forbidden',
                    'studio.media/upload-refused',
                ),
            ),
        );
    }

    /**
     * Build a collision service over one deterministic stored row.
     *
     * @param   StudioHostRequest         $request  Request whose collision is under test.
     * @param   array<string, mixed>|null  $row      Stored collision row, or a vanished row.
     *
     * @return  StudioMediaMutationIdempotency  Idempotency service wired to the collision.
     *
     * @since  2.0.0
     */
    private function collision(StudioHostRequest $request, ?array $row): StudioMediaMutationIdempotency
    {
        $ledger = self::createStub(IdempotencyLedger::class);
        $ledger->method('reserve')->willReturn(false);
        $ledger->method('find')->willReturn($row);

        return $this->idempotency($ledger);
    }

    /**
     * Compose one deterministic idempotency boundary with an immediate transaction manager.
     *
     * @param   IdempotencyLedger  $ledger  Ledger double used by the scenario.
     *
     * @return  StudioMediaMutationIdempotency  Ready idempotency service.
     *
     * @since  2.0.0
     */
    private function idempotency(IdempotencyLedger $ledger): StudioMediaMutationIdempotency
    {
        $transactions = self::createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-08-24T12:00:00+00:00'));

        return new StudioMediaMutationIdempotency($ledger, $transactions, $clock);
    }

    /**
     * Build one valid completed replay row for the supplied request.
     *
     * @param   StudioHostRequest  $request  Request whose exact intent and authority are retained.
     *
     * @return  array<string, mixed>  Complete replay row.
     *
     * @since  2.0.0
     */
    private static function completedRow(StudioHostRequest $request): array
    {
        $scope = 'studio-media:' . hash(
            'sha256',
            $request->operationId . "\0" . $request->resourceContextKey . "\0" . $request->sessionGeneration,
        );
        $digest = hash('sha256', CanonicalJson::stringify((object) [
            'arguments' => $request->arguments,
            'expectedRevision' => $request->expectedRevision,
            'locale' => $request->locale,
            'protocolVersion' => $request->protocolVersion,
        ]));

        return self::rowWithBody([
            'authorization_fingerprint' => hash('sha256', 'actors/1' . "\0" . $scope),
            'expires_at' => '2030-01-01T00:00:00+00:00',
            'id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb711',
            'locked_until' => '2026-08-24T11:59:00+00:00',
            'request_digest' => $digest,
            'state' => 'completed',
        ], 200, '{"ok":true}');
    }

    /**
     * Attach an integrity-protected body and status to one replay row.
     *
     * @param   array<string, mixed>  $row     Stored row coordinates.
     * @param   int                   $status  Stored result status.
     * @param   string                $body    Stored canonical result body.
     *
     * @return  array<string, mixed>  Completed row with result members.
     *
     * @since  2.0.0
     */
    private static function rowWithBody(array $row, int $status, string $body): array
    {
        return array_replace($row, [
            'result_body' => $body,
            'result_body_digest' => hash('sha256', $body),
            'result_status' => $status,
        ]);
    }

    /**
     * Assert one invocation fails with the stable non-disclosing media diagnostic.
     *
     * @param   string    $code      Expected diagnostic code.
     * @param   callable  $callback  Invocation expected to fail.
     * @param   string    $case      Optional scenario label.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    private static function assertRejected(string $code, callable $callback, string $case = ''): void
    {
        try {
            $callback();
            self::fail('The invalid Studio media replay was accepted: ' . $case);
        } catch (StudioMediaPortRejected $failure) {
            self::assertSame($code, $failure->failureCode, $case);
        }
    }

    /**
     * Build one stable authorize-upload envelope whose correlation fields do not affect replay.
     *
     * @param   string|null  $idempotencyKey  Optional mutation replay key.
     *
     * @return  StudioHostRequest  Mutation request carrying one supplied key.
     *
     * @since  2.0.0
     */
    private static function request(?string $idempotencyKey = 'idempotency/grant-1'): StudioHostRequest
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
            $idempotencyKey,
            null,
            null,
        );
    }
}
