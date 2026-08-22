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
 * exactly, which is what makes the mismatch unrepresentable rather than merely unlikely. Both readers
 * are held to one corpus, `tests/Browser/manifest-cases.json`, which carries raw sources rather than
 * structured documents so the forms the two languages read differently survive into the cases.
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
    public const array SPEC_SCOPES = ['all', 'right-to-left', 'breadth'];

    /**
     * The largest retry budget the manifest may declare.
     *
     * The ceiling is a correctness device, not a preference. Above it the two languages stop agreeing:
     * `9007199254740993` is held exactly here and rounded by JavaScript, and `1e21` is a float to
     * `is_int` but an integer to `Number.isInteger`. Refusing every such magnitude keeps the two
     * readings identical for every document either side accepts, and no real matrix reruns a journey a
     * hundred times.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAX_RETRIES = 100;

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

        /** @var mixed $declaredRetries */
        $declaredRetries = $raw['retries'] ?? null;
        $retries = self::retryBudget($declaredRetries);
        if ($retries === null) {
            throw new RuntimeException(sprintf(
                '%s needs "retries" as a whole number from 0 to %d; found %s.',
                $origin,
                self::MAX_RETRIES,
                json_encode($declaredRetries),
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
     * Read a declared retry budget both consumers agree on, or nothing if there is no such reading.
     *
     * JSON has one number type, so `1`, `1.0` and `1e0` are the same value written three ways. PHP can
     * tell them apart after decoding and JavaScript cannot, which makes a rule keyed on the written
     * form one the two sides cannot both implement. The rule is therefore keyed on the value, and
     * `-0` — which decodes to zero here and to negative zero there — is read as zero on both sides.
     *
     * @param   mixed  $value  Decoded `retries` value.
     *
     * @return  int|null  The budget, or null when the value is not one both consumers read alike.
     *
     * @since   2.0.0
     */
    private static function retryBudget(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 && $value <= self::MAX_RETRIES ? $value : null;
        }
        if (!is_float($value) || !is_finite($value) || floor($value) !== $value) {
            return null;
        }
        if ($value < 0.0 || $value > (float) self::MAX_RETRIES) {
            return null;
        }

        return (int) $value;
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
