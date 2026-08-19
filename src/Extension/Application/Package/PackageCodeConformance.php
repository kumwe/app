<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Package;

use Kumwe\App\Extension\Domain\ExtensionManifest;
use ParseError;

/**
 * The bounded, code-free static checks a package's contents are judged by, wherever they are judged.
 *
 * Two callers share this. `StaticConformanceRunner` runs it from the SDK, over a package an author is
 * about to publish, alongside the archive-metadata checks only a builder cares about.
 * `PackageAdmissionScanner` runs it during installation, over a package an installation is about to
 * unpack. Keeping the checks here rather than in either caller is what makes those two answers the
 * same answer: an author who fixes what `extension:conformance` reports has fixed what admission will
 * refuse, and the messages match because they are produced once.
 *
 * Nothing here loads, includes or executes packaged code. PHP files are tokenized with `TOKEN_PARSE`,
 * which parses without binding a single symbol, and every other check is a string or path comparison.
 *
 * @since  2.0.0
 */
final readonly class PackageCodeConformance
{
    /**
     * Parse one PHP source file and require strict scalar semantics.
     *
     * @param   string  $path      Package path quoted in each violation.
     * @param   string  $contents  PHP source bytes.
     *
     * @return  list<string>  Violations found; empty when the file parses and declares strict types.
     *
     * @since   2.0.0
     */
    public function phpViolations(string $path, string $contents): array
    {
        $violations = [];
        $tokens = null;
        try {
            $tokens = token_get_all($contents, TOKEN_PARSE);
        } catch (ParseError $failure) {
            $violations[] = sprintf('PHP syntax failure in %s: %s', $path, $failure->getMessage());
        }
        if (!is_array($tokens) || !$this->declaresStrictTypes($tokens)) {
            $violations[] = sprintf('PHP file %s must declare strict_types=1.', $path);
        }

        return $violations;
    }

    /**
     * Detect unresolved scaffold and unfinished-work markers in packaged text.
     *
     * @param   string  $path      Package path quoted in the violation.
     * @param   string  $contents  Text contents.
     *
     * @return  list<string>  A single violation when a marker remains, otherwise empty.
     *
     * @since   2.0.0
     */
    public function markerViolations(string $path, string $contents): array
    {
        if (
            preg_match('/@@[A-Z0-9_]+@@|\{\{[A-Z0-9_]+\}\}/D', $contents) === 1
            || preg_match('/\b(?:TODO|FIXME)\b/', $contents) === 1
        ) {
            return [sprintf('Unresolved marker remains in %s.', $path)];
        }

        return [];
    }

    /**
     * Check the manifest references that can be resolved without autoloading extension classes.
     *
     * The provider class, every declared migration, every declared asset and every contributed template
     * must resolve to a path the package actually carries. A manifest that names a class the package
     * does not ship is not a style problem: it is a package that will fatal the moment its provider is
     * resolved, which is after installation has already published it.
     *
     * @param   ExtensionManifest  $manifest  Strict parsed package manifest.
     * @param   list<string>       $paths     Every path the package carries.
     *
     * @return  list<string>  Sorted violations; empty when every declared reference resolves.
     *
     * @since   2.0.0
     */
    public function referenceViolations(ExtensionManifest $manifest, array $paths): array
    {
        $present = array_fill_keys($paths, true);
        $violations = [];
        $autoload = $manifest->autoload();
        $classes = [$manifest->serviceProvider(), ...$manifest->migrations()];
        foreach ($classes as $class) {
            $resolved = false;
            foreach ($autoload as $prefix => $directory) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }
                $candidate = rtrim($directory, '/') . '/'
                    . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (isset($present[$candidate])) {
                    $resolved = true;
                    break;
                }
            }
            if (!$resolved) {
                $violations[] = sprintf(
                    'Manifest reference class %s does not resolve to a packaged PHP file.',
                    $class,
                );
            }
        }
        foreach ($manifest->assets() as $asset) {
            if (!isset($present[$asset])) {
                $violations[] = sprintf('Manifest reference asset %s is missing.', $asset);
            }
        }
        $contributions = $manifest->contributions();
        foreach ($contributions->views() as $view) {
            $path = 'templates/views/administrator/' . $view->template;
            if (!isset($present[$path])) {
                $violations[] = sprintf('Manifest reference template %s is missing.', $path);
            }
        }
        foreach ($contributions->portalTemplates() as $template) {
            $path = 'templates/views/portal/' . $template->template;
            if (!isset($present[$path])) {
                $violations[] = sprintf('Manifest reference template %s is missing.', $path);
            }
        }

        return $violations;
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
    public function isTextPath(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['php', 'json', 'md', 'twig', 'yaml', 'yml', 'xml', 'css', 'js'], true)
            || in_array(basename($path), ['README', 'LICENSE'], true);
    }

    /**
     * Report whether a path names packaged PHP source.
     *
     * @param   string  $path  Package path.
     *
     * @return  bool  True when the entry is a `.php` file in any letter case.
     *
     * @since   2.0.0
     */
    public function isPhpPath(string $path): bool
    {
        return str_ends_with(strtolower($path), '.php');
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
}
