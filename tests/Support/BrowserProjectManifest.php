<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use JsonException;
use RuntimeException;

/**
 * The one reader for the browser project manifest on the PHP side.
 *
 * The manifest exists so a project cannot run the portal journeys without an approval identity: the
 * Playwright configuration builds its matrix from it and the fixture seeder provisions from it. That
 * guarantee only holds if both sides refuse the same documents, and they did not. The configuration
 * treated any `specs` value other than `right-to-left` as "run every journey" while the seeder
 * provisioned only for exactly `all`, so a misspelling ran the maker-checker journey on a project with
 * no identity — recreating the once-per-account TOTP refusal the manifest was introduced to prevent,
 * and doing it while every guard still passed. The refusals here mirror `tests/Browser/manifest.mjs`
 * exactly, which is what makes the mismatch unrepresentable rather than merely unlikely.
 *
 * @since  2.0.0
 */
final readonly class BrowserProjectManifest
{
    /**
     * Every value `specs` may take; anything else is refused rather than interpreted.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const array SPEC_SCOPES = ['all', 'right-to-left'];

    /**
     * Read and validate a manifest, or fail naming the first thing wrong with it.
     *
     * @param   string  $source  Manifest JSON.
     * @param   string  $origin  Path reported in failures, so the message names the file to fix.
     *
     * @return  array{retries: int, projects: list<array{name: string, specs: string}>}  Validated manifest.
     *
     * @throws  RuntimeException  When the document is not a manifest this project can act on.
     *
     * @since   2.0.0
     */
    public static function parse(string $source, string $origin = 'tests/Browser/projects.json'): array
    {
        try {
            /** @var mixed $raw */
            $raw = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('%s is not valid JSON: %s', $origin, $exception->getMessage()));
        }
        if (!is_array($raw) || array_is_list($raw)) {
            throw new RuntimeException(sprintf('%s must be a JSON object.', $origin));
        }

        $retries = $raw['retries'] ?? null;
        if (!is_int($retries) || $retries < 0) {
            throw new RuntimeException(sprintf(
                '%s needs "retries" as a non-negative integer; found %s.',
                $origin,
                json_encode($retries),
            ));
        }
        $projects = $raw['projects'] ?? null;
        if (!is_array($projects) || !array_is_list($projects) || $projects === []) {
            throw new RuntimeException(sprintf('%s needs a non-empty "projects" array.', $origin));
        }

        $validated = [];
        $seen = [];
        foreach ($projects as $index => $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new RuntimeException(sprintf('%s project %d must be a JSON object.', $origin, $index));
            }
            $name = $entry['name'] ?? null;
            if (!is_string($name) || trim($name) === '') {
                throw new RuntimeException(sprintf(
                    '%s project %d needs a non-empty "name"; found %s.',
                    $origin,
                    $index,
                    json_encode($name),
                ));
            }
            if (in_array($name, $seen, true)) {
                throw new RuntimeException(sprintf(
                    '%s declares "%s" more than once; project names address fixtures and must be unique.',
                    $origin,
                    $name,
                ));
            }
            $seen[] = $name;
            $specs = $entry['specs'] ?? null;
            if (!is_string($specs) || !in_array($specs, self::SPEC_SCOPES, true)) {
                throw new RuntimeException(sprintf(
                    '%s project "%s" needs "specs" to be one of %s; found %s. A value that is neither runs '
                    . 'every journey in Playwright while the seeder provisions nothing, which is the drift '
                    . 'this manifest exists to make impossible.',
                    $origin,
                    $name,
                    implode(' | ', self::SPEC_SCOPES),
                    json_encode($specs),
                ));
            }
            $validated[] = ['name' => $name, 'specs' => $specs];
        }

        return ['retries' => $retries, 'projects' => $validated];
    }

    /**
     * Read and validate the manifest from a file.
     *
     * @param   string  $path  Absolute path to the manifest.
     *
     * @return  array{retries: int, projects: list<array{name: string, specs: string}>}  Validated manifest.
     *
     * @throws  RuntimeException  When the file cannot be read or is not a usable manifest.
     *
     * @since   2.0.0
     */
    public static function read(string $path): array
    {
        $source = file_get_contents($path);
        if (!is_string($source)) {
            throw new RuntimeException(sprintf('%s cannot be read.', $path));
        }

        return self::parse($source, $path);
    }
}
