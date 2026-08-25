<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Release;

use Kumwe\App\Studio\Application\Release\StudioReleaseRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Proves the runtime release coordinate comes from one validated canonical record and its exact bytes.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioReleaseRecord::class)]
final class StudioReleaseRecordTest extends TestCase
{
    /**
     * Project a coordinated release and refuse malformed, foreign, or staggered package records.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReleaseProjectionAndRefusalsRemainBoundToCanonicalBytes(): void
    {
        $bytes = file_get_contents(
            dirname(__DIR__, 5) . '/resources/studio-contract/studio-release.json',
        );
        self::assertIsString($bytes);
        $release = StudioReleaseRecord::fromJson($bytes);
        $document = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame($document['release'] ?? null, $release->release);
        self::assertSame(hash('sha256', $bytes), $release->recordSha256);

        $invalidRecords = [
            'malformed shape' => '[]',
            'foreign namespace' => json_encode([
                'kind' => 'studio-release',
                'release' => $release->release,
                'packages' => ['@foreign/studio' => $release->release],
            ], JSON_THROW_ON_ERROR),
            'staggered package' => json_encode([
                'kind' => 'studio-release',
                'release' => $release->release,
                'packages' => ['@kumwe/studio' => '0.0.0-staggered'],
            ], JSON_THROW_ON_ERROR),
        ];
        foreach ($invalidRecords as $case => $invalidRecord) {
            try {
                StudioReleaseRecord::fromJson($invalidRecord);
                self::fail('An invalid Studio release record was accepted: ' . $case);
            } catch (UnexpectedValueException $failure) {
                self::assertNotSame('', $failure->getMessage(), $case);
            }
        }
    }
}
