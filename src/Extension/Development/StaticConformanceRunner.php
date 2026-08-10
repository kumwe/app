<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Development;

use ParseError;
use RuntimeException;
use ZipArchive;

/**
 * Performs bounded static conformance checks without loading or executing extension code.
 *
 * @since  2.0.0
 */
final readonly class StaticConformanceRunner
{
    /**
     * Timestamp assigned by the reproducible package builder to every entry.
     *
     * @var    int
     * @since  2.0.0
     */
    private const ZIP_EPOCH = 315532800;

    /**
     * Bind conformance to the production package inspector.
     *
     * @param  PackageInspector  $inspector  Safe archive and manifest inspection boundary.
     *
     * @since  2.0.0
     */
    public function __construct(private PackageInspector $inspector)
    {
    }

    /**
     * Inspect and statically validate one installable package.
     *
     * @param   string  $archiveFile  Canonical absolute package path.
     *
     * @return  ConformanceReport  Stable report containing every violation found.
     *
     * @throws  RuntimeException  When an inspected entry cannot be read within its safety bound.
     *
     * @since   2.0.0
     */
    public function run(string $archiveFile): ConformanceReport
    {
        $inspection = $this->inspector->inspect($archiveFile);
        $violations = [];
        $paths = $inspection->paths;
        $sorted = $paths;
        sort($sorted, SORT_STRING);
        if ($paths !== $sorted) {
            $violations[] = 'Archive entries must be sorted bytewise for deterministic packaging.';
        }

        $zip = new ZipArchive();
        if ($zip->open($inspection->archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The inspected extension package could not be reopened.');
        }
        if ($zip->numFiles !== count($paths)) {
            $zip->close();
            throw new RuntimeException('The extension package changed before conformance checks began.');
        }
        $metadataNormalized = true;
        try {
            foreach ($paths as $index => $path) {
                $metadataNormalized = $this->checkMetadata($zip, $index, $path, $violations)
                    && $metadataNormalized;
                if (str_ends_with($path, '/')) {
                    continue;
                }
                $contents = $zip->getFromIndex($index, 67_108_865, ZipArchive::FL_UNCHANGED);
                if (!is_string($contents)) {
                    throw new RuntimeException(sprintf('Package entry %s could not be read.', $path));
                }
                if ($this->textPath($path)) {
                    $this->checkMarkers($path, $contents, $violations);
                }
                if (str_ends_with(strtolower($path), '.php')) {
                    $this->checkPhp($path, $contents, $violations);
                }
            }
        } finally {
            $zip->close();
        }
        $digest = hash_file('sha256', $inspection->archive);
        if (!is_string($digest) || !hash_equals((string) $inspection->checksum, $digest)) {
            throw new RuntimeException('The extension package changed during conformance checks.');
        }

        $this->checkReferences($inspection, $violations);
        sort($violations, SORT_STRING);
        $checks = [
            'production_package_safety' => true,
            'manifest_schema' => true,
            'deterministic_entry_order' => $paths === $sorted,
            'deterministic_entry_metadata' => $metadataNormalized,
            'static_php_syntax' => !$this->containsPrefix($violations, 'PHP syntax failure'),
            'strict_types' => !$this->containsPrefix($violations, 'PHP file'),
            'complete_sources' => !$this->containsPrefix($violations, 'Unresolved marker'),
            'manifest_references' => !$this->containsPrefix($violations, 'Manifest reference'),
            'authoring_readme' => in_array('README.md', $paths, true),
        ];
        if (!$checks['authoring_readme']) {
            $violations[] = 'Manifest reference README.md is missing.';
            sort($violations, SORT_STRING);
        }

        return new ConformanceReport($inspection, $checks, $violations);
    }

    /**
     * Verify one entry carries the compression, timestamp, and Unix mode emitted by the builder.
     *
     * @param   ZipArchive    $zip         Open inspected package.
     * @param   int           $index       Central-directory index.
     * @param   string        $path        Expected entry path.
     * @param   list<string>  $violations  Accumulated violations.
     *
     * @return  bool  True when every deterministic metadata field matches.
     *
     * @since   2.0.0
     */
    private function checkMetadata(ZipArchive $zip, int $index, string $path, array &$violations): bool
    {
        $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
        $externalAttributes = $this->externalAttributes($zip, $index);
        $valid = is_array($stat)
            && ($stat['name'] ?? null) === $path
            && ($stat['comp_method'] ?? null) === ZipArchive::CM_STORE
            && ($stat['mtime'] ?? null) === self::ZIP_EPOCH
            && $externalAttributes !== null
            && $externalAttributes['operating_system'] === ZipArchive::OPSYS_UNIX
            && (($externalAttributes['attributes'] >> 16) & 0xFFFF) === 0100644
            && !str_ends_with($path, '/');
        if (!$valid) {
            $violations[] = sprintf('Archive metadata for %s is not deterministic.', $path);
        }

        return $valid;
    }

    /**
     * Read external attributes through the mutation-based ZipArchive API.
     *
     * @param   ZipArchive  $zip    Open inspected package.
     * @param   int         $index  Central-directory index.
     *
     * @return  ?array{operating_system: int, attributes: int}  Attributes, or null when unavailable.
     *
     * @since   2.0.0
     */
    private function externalAttributes(ZipArchive $zip, int $index): ?array
    {
        $operatingSystem = 0;
        $attributes = 0;
        if (!$zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
            return null;
        }
        if (!is_int($operatingSystem) || !is_int($attributes)) {
            return null;
        }

        return ['operating_system' => $operatingSystem, 'attributes' => $attributes];
    }

    /**
     * Check package references that can be resolved without autoloading extension classes.
     *
     * @param   PackageInspection  $inspection  Parsed manifest and path inventory.
     * @param   list<string>       $violations  Accumulated violations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function checkReferences(PackageInspection $inspection, array &$violations): void
    {
        $paths = array_fill_keys($inspection->paths, true);
        $autoload = $inspection->manifest->autoload();
        $classes = [$inspection->manifest->serviceProvider(), ...$inspection->manifest->migrations()];
        foreach ($classes as $class) {
            $resolved = false;
            foreach ($autoload as $prefix => $directory) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }
                $candidate = rtrim($directory, '/') . '/' . str_replace('\\', '/', substr($class, strlen($prefix)))
                    . '.php';
                if (isset($paths[$candidate])) {
                    $resolved = true;
                    break;
                }
            }
            if (!$resolved) {
                $violations[] = sprintf('Manifest reference class %s does not resolve to a packaged PHP file.', $class);
            }
        }
        foreach ($inspection->manifest->assets() as $asset) {
            if (!isset($paths[$asset])) {
                $violations[] = sprintf('Manifest reference asset %s is missing.', $asset);
            }
        }
        $contributions = $inspection->manifest->contributions();
        foreach ($contributions->views() as $view) {
            $this->requirePath('templates/views/administrator/' . $view->template, $paths, $violations);
        }
        foreach ($contributions->portalTemplates() as $template) {
            $this->requirePath('templates/views/portal/' . $template->template, $paths, $violations);
        }
    }

    /**
     * Require one manifest-declared package path.
     *
     * @param   string               $path        Required package path.
     * @param   array<string, bool>  $paths       Packaged path set.
     * @param   list<string>         $violations  Accumulated violations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function requirePath(string $path, array $paths, array &$violations): void
    {
        if (!isset($paths[$path])) {
            $violations[] = sprintf('Manifest reference template %s is missing.', $path);
        }
    }

    /**
     * Parse one PHP source file and require strict scalar semantics.
     *
     * @param   string        $path        Package path.
     * @param   string        $contents    PHP source bytes.
     * @param   list<string>  $violations  Accumulated violations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function checkPhp(string $path, string $contents, array &$violations): void
    {
        $tokens = null;
        try {
            $tokens = token_get_all($contents, TOKEN_PARSE);
        } catch (ParseError $failure) {
            $violations[] = sprintf('PHP syntax failure in %s: %s', $path, $failure->getMessage());
        }
        if (!is_array($tokens) || !$this->declaresStrictTypes($tokens)) {
            $violations[] = sprintf('PHP file %s must declare strict_types=1.', $path);
        }
    }

    /**
     * Require strict scalar semantics as the first executable source declaration.
     *
     * Token inspection prevents a comment or string containing `declare(strict_types=1)` from satisfying
     * the gate. PHP's parser separately rejects a real strict-types declaration that appears too late.
     *
     * @param   list<array{int, string, int}|string>  $tokens  Parsed PHP source tokens.
     *
     * @return  bool  True only for an actual leading `declare(strict_types=1);` statement.
     *
     * @since   2.0.0
     */
    private function declaresStrictTypes(array $tokens): bool
    {
        $significant = [];
        foreach ($tokens as $token) {
            if (
                is_array($token)
                && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ) {
                continue;
            }
            $significant[] = $token;
        }
        if (count($significant) < 7) {
            return false;
        }

        return is_array($significant[0])
            && $significant[0][0] === T_DECLARE
            && $significant[1] === '('
            && is_array($significant[2])
            && $significant[2][0] === T_STRING
            && strtolower($significant[2][1]) === 'strict_types'
            && $significant[3] === '='
            && is_array($significant[4])
            && $significant[4][0] === T_LNUMBER
            && $significant[4][1] === '1'
            && $significant[5] === ')'
            && $significant[6] === ';';
    }

    /**
     * Detect unresolved scaffold and unfinished-work markers.
     *
     * @param   string        $path        Package path.
     * @param   string        $contents    Text contents.
     * @param   list<string>  $violations  Accumulated violations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function checkMarkers(string $path, string $contents, array &$violations): void
    {
        if (
            preg_match('/@@[A-Z0-9_]+@@|\{\{[A-Z0-9_]+\}\}/D', $contents) === 1
            || preg_match('/\b(?:TODO|FIXME)\b/', $contents) === 1
        ) {
            $violations[] = sprintf('Unresolved marker remains in %s.', $path);
        }
    }

    /**
     * Decide whether an entry is a text format subject to marker scanning.
     *
     * @param   string  $path  Package path.
     *
     * @return  bool  True for supported text extensions and conventional text file names.
     *
     * @since   2.0.0
     */
    private function textPath(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['php', 'json', 'md', 'twig', 'yaml', 'yml', 'xml', 'css', 'js'], true)
            || in_array(basename($path), ['README', 'LICENSE'], true);
    }

    /**
     * Determine whether any violation starts with a stable category prefix.
     *
     * @param   list<string>  $violations  Sorted or unsorted violation messages.
     * @param   string        $prefix      Category prefix.
     *
     * @return  bool  True when at least one violation belongs to the category.
     *
     * @since   2.0.0
     */
    private function containsPrefix(array $violations, string $prefix): bool
    {
        foreach ($violations as $violation) {
            if (str_starts_with($violation, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
