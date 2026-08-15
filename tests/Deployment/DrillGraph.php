<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Deployment;

/**
 * Resolve the class graph a deployment drill reaches, so nothing in it can be unloadable in the image.
 *
 * The drills used to name each collaborator in a `require` list, and a harness that gained a class without
 * gaining a line still passed every cheaper job before dying in the deployed image with "class not found".
 * Retiring the list fixed that instance; walking the graph is what keeps the failure mode retired, because
 * a harness cannot acquire a collaborator without this walk reaching it.
 *
 * The walk is deliberately wider than the imports. The collaborator that broke was in the harness's own
 * namespace, referenced by its bare name with no `use` statement at all, so a check that read only imports
 * would have watched the defect go past.
 *
 * @since  2.0.0
 */
final readonly class DrillGraph
{
    /**
     * Namespace prefix the drills' own classes live under, which the production classmap never carries.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string PREFIX = 'Kumwe\\CMS\\Tests\\';

    /**
     * Collect every class under the test namespace an entry point reaches, transitively.
     *
     * @param   string  $root        Artifact root.
     * @param   string  $entryPoint  Absolute path to the entry point script.
     *
     * @return  list<string>  Fully qualified class names, in discovery order.
     *
     * @since   2.0.0
     */
    public static function reachedBy(string $root, string $entryPoint): array
    {
        $pending = self::referenced($entryPoint);
        $seen = [];

        while ($pending !== []) {
            $class = array_shift($pending);
            if (isset($seen[$class])) {
                continue;
            }
            $seen[$class] = true;
            $file = self::fileFor($root, $class);
            if (!is_file($file)) {
                continue;
            }
            foreach (self::referenced($file) as $referenced) {
                if (!isset($seen[$referenced])) {
                    $pending[] = $referenced;
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * Read the source of the harness class an entry point dispatches to.
     *
     * @param   string  $root        Artifact root.
     * @param   string  $entryPoint  Absolute path to the entry point script.
     *
     * @return  string  The harness source, or the entry point's own source when it names no harness.
     *
     * @since   2.0.0
     */
    public static function harnessSource(string $root, string $entryPoint): string
    {
        foreach (self::referenced($entryPoint) as $class) {
            $file = self::fileFor($root, $class);
            $contents = is_file($file) ? file_get_contents($file) : false;
            if (is_string($contents) && str_contains($contents, 'function main(')) {
                return $contents;
            }
        }

        return (string) file_get_contents($entryPoint);
    }

    /**
     * Read the test-namespace class names one file reaches, imported or named in its own namespace.
     *
     * @param   string  $file  Absolute path to a PHP file.
     *
     * @return  list<string>  Fully qualified class names.
     *
     * @since   2.0.0
     */
    public static function referenced(string $file): array
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            return [];
        }

        $names = [];
        $pattern = '/^use\s+(' . preg_quote(self::PREFIX, '/') . '[A-Za-z0-9_\\\\]+)\s*(?:as\s+\w+)?;/m';
        if (preg_match_all($pattern, $contents, $matches) > 0) {
            foreach ($matches[1] as $name) {
                $names[] = $name;
            }
        }

        $namespace = '';
        if (preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+);/m', $contents, $matched) === 1) {
            $namespace = $matched[1];
        }
        if ($namespace !== '' && str_starts_with($namespace . '\\', self::PREFIX)) {
            $directory = dirname($file);
            foreach (token_get_all($contents) as $token) {
                if (!is_array($token) || $token[0] !== T_STRING) {
                    continue;
                }
                if (preg_match('/^[A-Z][A-Za-z0-9_]*$/D', $token[1]) !== 1) {
                    continue;
                }
                if (!is_file($directory . '/' . $token[1] . '.php')) {
                    continue;
                }
                $names[] = $namespace . '\\' . $token[1];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Translate a test-namespace class name into the file the drill loader resolves it from.
     *
     * @param   string  $root   Artifact root.
     * @param   string  $class  Fully qualified class name under the test namespace.
     *
     * @return  string  Absolute path the class would be loaded from.
     *
     * @since   2.0.0
     */
    private static function fileFor(string $root, string $class): string
    {
        $relative = str_replace('\\', '/', substr($class, strlen(self::PREFIX)));

        return $root . '/tests/' . $relative . '.php';
    }
}
