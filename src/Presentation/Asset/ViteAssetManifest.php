<?php

declare(strict_types=1);

namespace Kumwe\App\Presentation\Asset;

use JsonException;
use RuntimeException;

/**
 * Resolves a Vite source entry point to the content-hashed files a template must actually link.
 *
 * Templates cannot know the hashed filenames a build produces, so `SiteRenderer` and
 * `AdministratorRenderer` ask this class for an `AssetEntry` instead of hard-coding paths. Two
 * situations are treated very differently: a checkout that has never been built has no manifest at all,
 * and there the caller's fallbacks are returned so pages still render; a manifest that exists but is
 * unreadable, malformed, or silent about the requested entry is a broken deployment, and this class
 * raises rather than quietly serving a page with no stylesheet.
 *
 * @since  2.0.0
 */
final readonly class ViteAssetManifest
{
    /**
     * Bind the reader to one build manifest and the URL prefix its files are served under.
     *
     * @param  string  $manifestPath  Path of the Vite `manifest.json`; absent means "never built".
     * @param  string  $publicPrefix  URL prefix prepended to every manifest path, trailing slash included.
     *
     * @since  2.0.0
     */
    public function __construct(private string $manifestPath, private string $publicPrefix = '/assets/build/')
    {
    }

    /**
     * Resolve one entry point to the stylesheets and modules its page must link.
     *
     * The entry is named the way Vite names it, by source path — `assets/site/main.ts`, for instance.
     * The fallbacks apply only when the manifest file is missing entirely; every other problem is a
     * misbuild and raises instead. A resolved entry carries exactly one module and however many
     * stylesheets Vite extracted for it, each already prefixed with the public build path and therefore
     * safe to emit as an `href` or `src`.
     *
     * @param   string       $source              Manifest key, which is the Vite source path of the entry.
     * @param   string       $fallbackStylesheet  Stylesheet URL to link when no build has run yet.
     * @param   string|null  $fallbackModule      Module URL for that same case; null when none is needed.
     *
     * @return  AssetEntry  Public URLs for the entry, resolved from the manifest or from the fallbacks.
     *
     * @throws  RuntimeException  When the manifest is unreadable or malformed, or the entry is missing.
     *
     * @since   2.0.0
     */
    public function entry(string $source, string $fallbackStylesheet, ?string $fallbackModule = null): AssetEntry
    {
        if (!is_file($this->manifestPath)) {
            return new AssetEntry(
                [$fallbackStylesheet],
                $fallbackModule === null ? [] : [$fallbackModule],
            );
        }

        $contents = file_get_contents($this->manifestPath);
        if (!is_string($contents)) {
            throw new RuntimeException('The frontend asset manifest cannot be read.');
        }

        try {
            $manifest = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The frontend asset manifest is invalid JSON.', 0, $exception);
        }
        if (!is_array($manifest) || array_is_list($manifest)) {
            throw new RuntimeException('The frontend asset manifest must contain an object.');
        }

        $entry = $this->record($manifest, $source);
        if (($entry['isEntry'] ?? null) !== true || ($entry['src'] ?? null) !== $source) {
            throw new RuntimeException(sprintf(
                'The frontend asset entry %s must be a declared entry with matching source metadata.',
                $source,
            ));
        }
        $file = $entry['file'] ?? null;
        if (!is_string($file) || $file === '') {
            throw new RuntimeException(sprintf('The frontend asset entry %s has no module.', $source));
        }
        $module = $this->outputPath($file, $source . '.file');

        $stylesheets = [];
        $seenStylesheets = [];
        $state = [];
        $this->appendStaticStylesheets(
            $manifest,
            $source,
            $state,
            $seenStylesheets,
            $stylesheets,
            true,
        );

        return new AssetEntry($stylesheets, [$this->publicPrefix . $module]);
    }

    /**
     * Return one keyed manifest record and reject a missing or non-object value.
     *
     * @param   array<mixed>  $manifest  Decoded Vite manifest object.
     * @param   string        $key       Source entry or imported chunk key.
     *
     * @return  array<string, mixed>  Validated manifest record.
     *
     * @throws  RuntimeException  When the requested record is absent or malformed.
     *
     * @since   2.0.0
     */
    private function record(array $manifest, string $key): array
    {
        $record = $manifest[$key] ?? null;
        if (!is_array($record) || array_is_list($record)) {
            throw new RuntimeException(sprintf('The frontend asset entry %s is missing.', $key));
        }

        $validated = [];
        foreach ($record as $field => $value) {
            if (!is_string($field)) {
                throw new RuntimeException(sprintf(
                    'The frontend asset entry %s contains a non-string field name.',
                    $key,
                ));
            }
            $validated[$field] = $value;
        }

        return $validated;
    }

    /**
     * Append entry CSS first, then recursively imported static chunks in Vite's post-order.
     *
     * Vite's backend contract makes an entry's own CSS immediately render-blocking, then emits each
     * imported chunk after that chunk's dependencies. `dynamicImports` belong to a later module load
     * and must never be promoted into the initial page. The collections remain separate so a
     * malformed graph cannot silently alter order or coverage.
     *
     * @param   array<mixed>         $manifest     Decoded Vite manifest object.
     * @param   string               $key          Current entry or static chunk key.
     * @param   array<string, int>   $state        DFS state: one active, two complete.
     * @param   array<string, true>  $seen         Stylesheet outputs already linked.
     * @param   list<string>         $stylesheets  Public stylesheet URLs populated in place.
     * @param   bool                 $entry        Whether this is the requested entry, whose CSS leads.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a record, path, list or static import graph is malformed.
     *
     * @since   2.0.0
     */
    private function appendStaticStylesheets(
        array $manifest,
        string $key,
        array &$state,
        array &$seen,
        array &$stylesheets,
        bool $entry = false,
    ): void {
        if (($state[$key] ?? 0) === 1) {
            throw new RuntimeException(sprintf('The frontend asset static import graph contains a cycle at %s.', $key));
        }
        if (($state[$key] ?? 0) === 2) {
            return;
        }

        $state[$key] = 1;
        $record = $this->record($manifest, $key);
        $file = $record['file'] ?? null;
        if (!is_string($file) || $file === '') {
            throw new RuntimeException(sprintf('The frontend asset entry %s has no output file.', $key));
        }
        $file = $this->outputPath($file, $key . '.file');
        $recordStylesheets = $this->stringList($record, 'css', $key);
        if (str_ends_with($file, '.css')) {
            $recordStylesheets[] = $file;
        }
        foreach ($this->stringList($record, 'assets', $key) as $asset) {
            $asset = $this->outputPath($asset, $key . '.assets');
            if (str_ends_with($asset, '.css')) {
                $recordStylesheets[] = $asset;
            }
        }

        if ($entry) {
            $this->appendRecordStylesheets($recordStylesheets, $key, $seen, $stylesheets);
        }

        // Validate references without traversing them: dynamic chunks are not initial-document CSS.
        foreach ($this->stringList($record, 'dynamicImports', $key) as $dependency) {
            if (!array_key_exists($dependency, $manifest)) {
                throw new RuntimeException(sprintf(
                    'The frontend asset entry %s references missing dynamic import %s.',
                    $key,
                    $dependency,
                ));
            }
        }
        foreach ($this->stringList($record, 'imports', $key) as $dependency) {
            if (!array_key_exists($dependency, $manifest)) {
                throw new RuntimeException(sprintf(
                    'The frontend asset entry %s references missing static import %s.',
                    $key,
                    $dependency,
                ));
            }
            $this->appendStaticStylesheets($manifest, $dependency, $state, $seen, $stylesheets);
        }
        if (!$entry) {
            $this->appendRecordStylesheets($recordStylesheets, $key, $seen, $stylesheets);
        }
        $state[$key] = 2;
    }

    /**
     * Append one manifest record's validated CSS set, preserving the first graph occurrence.
     *
     * @param   list<string>         $recordStylesheets  CSS candidates in field order.
     * @param   string               $key                Manifest key used in diagnostics.
     * @param   array<string, true>  $seen               Stylesheet outputs already linked.
     * @param   list<string>         $stylesheets        Public stylesheet URLs populated in place.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a CSS field names a non-CSS or unsafe output.
     *
     * @since   2.0.0
     */
    private function appendRecordStylesheets(
        array $recordStylesheets,
        string $key,
        array &$seen,
        array &$stylesheets,
    ): void {
        foreach ($recordStylesheets as $stylesheet) {
            if (!str_ends_with($stylesheet, '.css')) {
                throw new RuntimeException(sprintf(
                    'The frontend asset entry %s names a non-CSS stylesheet %s.',
                    $key,
                    $stylesheet,
                ));
            }
            $stylesheet = $this->outputPath($stylesheet, $key . '.css');
            if (isset($seen[$stylesheet])) {
                continue;
            }
            $seen[$stylesheet] = true;
            $stylesheets[] = $this->publicPrefix . $stylesheet;
        }
    }

    /**
     * Validate a Vite record's optional unique string-list field.
     *
     * @param   array<string, mixed>  $record  Manifest record carrying the field.
     * @param   string                $field   Field name to validate.
     * @param   string                $key     Manifest key used in diagnostics.
     *
     * @return  list<string>  Deterministically ordered values.
     *
     * @throws  RuntimeException  When the field is not a list of unique non-empty strings.
     *
     * @since   2.0.0
     */
    private function stringList(array $record, string $field, string $key): array
    {
        if (!array_key_exists($field, $record)) {
            return [];
        }
        $values = $record[$field];
        if (!is_array($values) || !array_is_list($values)) {
            throw new RuntimeException(sprintf('The frontend asset entry %s has invalid %s.', $key, $field));
        }

        $seen = [];
        foreach ($values as $value) {
            if (!is_string($value) || $value === '' || isset($seen[$value])) {
                throw new RuntimeException(sprintf(
                    'The frontend asset entry %s has invalid or duplicate %s.',
                    $key,
                    $field,
                ));
            }
            $seen[$value] = true;
        }

        return array_keys($seen);
    }

    /**
     * Admit one normalized relative Vite output path.
     *
     * @param   string  $value  Relative Vite output path to validate.
     * @param   string  $field  Manifest field used in failure diagnostics.
     *
     * @return  string  Path safe to append to the configured public prefix.
     *
     * @throws  RuntimeException  When the output is absolute, traversing or platform-dependent.
     *
     * @since   2.0.0
     */
    private function outputPath(string $value, string $field): string
    {
        if (
            $value === ''
            || str_contains($value, "\0")
            || str_contains($value, '\\')
            || str_starts_with($value, '/')
            || preg_match('/^[A-Za-z]:/', $value) === 1
        ) {
            throw new RuntimeException(sprintf('The frontend asset output %s in %s is unsafe.', $value, $field));
        }
        foreach (explode('/', $value) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException(sprintf('The frontend asset output %s in %s is unsafe.', $value, $field));
            }
        }
        if (str_ends_with(strtolower($value), '.css') && !str_ends_with($value, '.css')) {
            throw new RuntimeException(sprintf(
                'The frontend asset output %s in %s must use the lowercase .css suffix.',
                $value,
                $field,
            ));
        }

        return $value;
    }
}
