<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use JsonException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Phase 5 graphical migrations to their route, payload, KIS, and presentation-mode contracts.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class PhaseFiveInterfaceParityTest extends TestCase
{
    /**
     * Repository root containing the runtime templates and focused parity artifact.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $root;

    /**
     * Resolve the repository root before each static parity check.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /**
     * Proves migrated templates retain the declared server routes, payload names, and protected KIS markers.
     *
     * @return  void
     *
     * @throws  JsonException  When the committed parity artifact is not valid JSON.
     *
     * @since  2.0.0
     */
    public function testPhaseFiveTemplatesRetainServerFirstParity(): void
    {
        $manifest = $this->manifest();

        foreach ($manifest['documents'] as $document) {
            $contents = $this->contents($document['path']);
            foreach ($document['surface_ids'] as $surfaceId) {
                self::assertStringContainsString(
                    $surfaceId,
                    $contents,
                    sprintf('%s lost its Phase 5 surface identifier %s.', $document['path'], $surfaceId),
                );
            }
            foreach ($document['must_contain'] as $required) {
                self::assertStringContainsString(
                    $required,
                    $contents,
                    sprintf('%s lost required Phase 5 parity token %s.', $document['path'], $required),
                );
            }
            foreach ($document['must_not_contain'] as $prohibited) {
                self::assertStringNotContainsString(
                    $prohibited,
                    $contents,
                    sprintf('%s restored prohibited one-off markup %s.', $document['path'], $prohibited),
                );
            }
        }
    }

    /**
     * Proves core and reference templates explicitly cover the cross-mode Phase 5 presentation matrix.
     *
     * @return  void
     *
     * @throws  JsonException  When the committed parity artifact is not valid JSON.
     *
     * @since  2.0.0
     */
    public function testPhaseFiveStylesCoverRequiredInteractionModes(): void
    {
        $manifest = $this->manifest();

        foreach ($manifest['mode_stylesheets'] as $stylesheet) {
            $contents = $this->contents($stylesheet['path']);
            foreach ($stylesheet['must_contain'] as $required) {
                self::assertStringContainsString(
                    $required,
                    $contents,
                    sprintf('%s does not cover %s.', $stylesheet['path'], $required),
                );
            }
        }
    }

    /**
     * Proves installable-template qualification keeps its static, lifecycle, recovery, and reset owners visible.
     *
     * @return  void
     *
     * @throws  JsonException  When the committed parity artifact is not valid JSON.
     *
     * @since  2.0.0
     */
    public function testTemplateQualificationOwnersRemainExecutableAndDocumented(): void
    {
        $manifest = $this->manifest();

        foreach ($manifest['qualification_sources'] as $source) {
            $contents = $this->contents($source['path']);
            foreach ($source['must_contain'] as $required) {
                self::assertStringContainsString(
                    $required,
                    $contents,
                    sprintf('%s lost qualification owner %s.', $source['path'], $required),
                );
            }
        }
    }

    /**
     * Decode the closed Phase 5 source-parity artifact.
     *
     * @return  array{
     *     documents: list<array{
     *         path: string,
     *         surface_ids: list<string>,
     *         must_contain: list<string>,
     *         must_not_contain: list<string>
     *     }>,
     *     mode_stylesheets: list<array{path: string, must_contain: list<string>}>,
     *     qualification_sources: list<array{path: string, must_contain: list<string>}>
     * }  Source-bound contracts grouped by verification concern.
     *
     * @throws  JsonException  When the committed artifact is not valid JSON.
     *
     * @since  2.0.0
     */
    private function manifest(): array
    {
        /** @var array{
         *     documents: list<array{
         *         path: string,
         *         surface_ids: list<string>,
         *         must_contain: list<string>,
         *         must_not_contain: list<string>
         *     }>,
         *     mode_stylesheets: list<array{path: string, must_contain: list<string>}>,
         *     qualification_sources: list<array{path: string, must_contain: list<string>}>
         * } $manifest
         */
        $manifest = json_decode(
            $this->contents('tests/Fixtures/InterfaceStandard/phase-five-surface-parity.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $manifest;
    }

    /**
     * Read a repository file or fail with its relative path.
     *
     * @param   string  $path  Repository-relative path to inspect.
     *
     * @return  string  Complete file contents.
     *
     * @since  2.0.0
     */
    private function contents(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        self::assertIsString($contents, sprintf('Unable to read %s.', $path));

        return $contents;
    }
}
