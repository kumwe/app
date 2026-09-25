<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Audit;

use Kumwe\App\Audit\Infrastructure\Storage\FilesystemAuditArchiveVerifier;
use Kumwe\Audit\Domain\StoredAuditArchive;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins that an audit range is pruned only after its archive reads back whole and declares that range.
 *
 * @since  2.0.0
 */
#[CoversClass(FilesystemAuditArchiveVerifier::class)]
final class FilesystemAuditArchiveVerifierTest extends TestCase
{
    /**
     * Private archive directory for one test.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $directory = '';

    /**
     * Create the private archive directory.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kumwe-archive-verify-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
    }

    /**
     * Remove the private archive directory.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    /**
     * An intact archive passes; truncation, a foreign range, a wrong count and an unsafe key are refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOnlyAnIntactArchiveOfTheExactRangeIsRestorable(): void
    {
        $bytes = json_encode(['kumwe_audit_archive' => 1, 'from_position' => 3, 'to_position' => 4]) . "\n"
            . "{\"position\":3}\n{\"position\":4}\n";
        file_put_contents($this->directory . '/archive.ndjson', $bytes);
        $archive = new StoredAuditArchive('archive.ndjson', strlen($bytes), hash('sha256', $bytes));
        $verifier = new FilesystemAuditArchiveVerifier($this->directory);
        $verifier->assertRestorable($archive, 3, 4, 2);

        $refusals = 0;
        foreach (
            [
                [$archive, 3, 5, 2],
                [$archive, 3, 4, 3],
                [new StoredAuditArchive('../escape', 1, str_repeat('a', 64)), 3, 4, 2],
                [new StoredAuditArchive('missing.ndjson', 1, str_repeat('a', 64)), 3, 4, 2],
            ] as [$candidate, $from, $to, $count]
        ) {
            try {
                $verifier->assertRestorable($candidate, $from, $to, $count);
            } catch (RuntimeException) {
                $refusals++;
            }
        }
        file_put_contents($this->directory . '/archive.ndjson', substr($bytes, 0, -3));
        try {
            $verifier->assertRestorable($archive, 3, 4, 2);
        } catch (RuntimeException) {
            $refusals++;
        }
        self::assertSame(5, $refusals);
        $this->expectException(RuntimeException::class);
        new FilesystemAuditArchiveVerifier('relative/path');
    }

    /**
     * Archives that hash correctly but prove nothing are refused with the reason that disqualifies them.
     *
     * An empty file whose recorded size and checksum match is still not an export of zero events, because
     * every archive carries its manifest line. A readable manifest that is not JSON cannot declare the
     * pruned range, and a symbolic link into the private store is not an archive the store wrote.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMatchingChecksumsDoNotMakeAnEmptyUnmanifestedOrLinkedArchiveRestorable(): void
    {
        $verifier = new FilesystemAuditArchiveVerifier($this->directory);
        $refusal = function (StoredAuditArchive $archive, int $count) use ($verifier): string {
            try {
                $verifier->assertRestorable($archive, 3, 4, $count);
            } catch (RuntimeException $exception) {
                return $exception->getMessage();
            }
            self::fail('The archive must be refused.');
        };

        file_put_contents($this->directory . '/empty.ndjson', '');
        self::assertSame(
            'The audit archive does not hold exactly the exported events.',
            $refusal(new StoredAuditArchive('empty.ndjson', 0, hash('sha256', '')), 0),
        );

        $unmanifested = "not a manifest\n{\"position\":3}\n{\"position\":4}\n";
        file_put_contents($this->directory . '/unmanifested.ndjson', $unmanifested);
        self::assertSame(
            'The audit archive manifest does not declare the pruned range.',
            $refusal(new StoredAuditArchive(
                'unmanifested.ndjson',
                strlen($unmanifested),
                hash('sha256', $unmanifested),
            ), 2),
        );

        symlink($this->directory . '/unmanifested.ndjson', $this->directory . '/linked.ndjson');
        self::assertSame(
            'The audit archive is not present in the private store.',
            $refusal(new StoredAuditArchive(
                'linked.ndjson',
                strlen($unmanifested),
                hash('sha256', $unmanifested),
            ), 2),
        );
    }
}
