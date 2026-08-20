<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Support;

use Kumwe\App\Tests\Support\BrowserProjectManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Holds the PHP manifest reader to the documents it must refuse.
 *
 * The manifest is the single definition of the browser matrix, and its worth rests entirely on both
 * consumers refusing the same things. They did not: Playwright ran every journey for any `specs` value
 * that was not `right-to-left` while this side provisioned identities only for exactly `all`, so one
 * misspelled word ran the maker-checker journey on a project with no approval identity — reintroducing
 * the once-per-account TOTP refusal the manifest exists to prevent, with every guard still green.
 * `tools/verify-browser-manifest.mjs` pins the same list on the JavaScript side, and the two are
 * deliberately identical: a document one side accepts alone is the whole failure mode.
 *
 * @since  2.0.0
 */
#[CoversClass(BrowserProjectManifest::class)]
final class BrowserProjectManifestTest extends TestCase
{
    /**
     * Every malformed document, keyed by the reason it cannot be interpreted rather than refused.
     *
     * @return  array<string, array{string}>  Manifest JSON keyed by what is wrong with it.
     *
     * @since   2.0.0
     */
    public static function refusedManifests(): array
    {
        return [
            'a specs value that is neither scope' => ['{"retries":1,"projects":[{"name":"a","specs":"al"}]}'],
            'a specs value that is absent' => ['{"retries":1,"projects":[{"name":"a"}]}'],
            'a specs value that is not a string' => ['{"retries":1,"projects":[{"name":"a","specs":true}]}'],
            'a duplicated project name' => [
                '{"retries":1,"projects":[{"name":"a","specs":"all"},{"name":"a","specs":"right-to-left"}]}',
            ],
            'an empty project name' => ['{"retries":1,"projects":[{"name":"   ","specs":"all"}]}'],
            'a project name that is not a string' => ['{"retries":1,"projects":[{"name":7,"specs":"all"}]}'],
            'a negative retry budget' => ['{"retries":-1,"projects":[{"name":"a","specs":"all"}]}'],
            'a fractional retry budget' => ['{"retries":1.5,"projects":[{"name":"a","specs":"all"}]}'],
            'a missing retry budget' => ['{"projects":[{"name":"a","specs":"all"}]}'],
            'an empty project list' => ['{"retries":1,"projects":[]}'],
            'a projects value that is not an array' => ['{"retries":1,"projects":{}}'],
            'a document that is not an object' => ['[{"name":"a","specs":"all"}]'],
            'a document that is not JSON' => ['{"retries":1,'],
        ];
    }

    /**
     * A document either consumer would read differently is refused rather than interpreted.
     *
     * @param   string  $manifest  Malformed manifest JSON.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('refusedManifests')]
    public function testAManifestTheTwoConsumersWouldReadDifferentlyIsRefused(string $manifest): void
    {
        $this->expectException(RuntimeException::class);

        BrowserProjectManifest::parse($manifest, 'fixture');
    }

    /**
     * The committed manifest is readable, and every project it declares carries a known scope.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCommittedManifestIsValidAndEveryScopeIsKnown(): void
    {
        $matrix = BrowserProjectManifest::read(dirname(__DIR__, 3) . '/tests/Browser/projects.json');

        self::assertGreaterThanOrEqual(0, $matrix['retries']);
        self::assertNotSame([], $matrix['projects']);
        foreach ($matrix['projects'] as $project) {
            self::assertContains($project['specs'], BrowserProjectManifest::SPEC_SCOPES);
        }
    }
}
