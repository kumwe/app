<?php

/**
 * Documentation-block formatter for the Kumwe coding standard.
 *
 * The formatter rewrites the whitespace inside multi-line documentation blocks so that tag values line
 * up the way `docs/coding-standard.md` section 3.3 describes, and so that tag groups are separated by a
 * single bare `*` line. It never changes the words of a block, the order of its tags, or a single line
 * of executable code, which keeps it safe to run across the whole tree.
 *
 * Usage:
 *   php tools/format-docblocks.php [--dry-run] [path ...]
 *
 * @since  2.0.0
 */

declare(strict_types=1);

/**
 * Decide whether a file pins its own bytes with a self-checksum.
 *
 * A migration that hashes `__FILE__` publishes that digest as an immutability contract: an installed
 * site compares it to decide whether the migration it already ran is the one shipped here. Adding or
 * realigning a documentation block changes the file's bytes and therefore the digest, which breaks
 * every existing installation's upgrade path. Such files are left exactly as released.
 *
 * @param  string  $source  Full file contents.
 *
 * @return bool True when the file must not be rewritten or reported on.
 *
 * @since  2.0.0
 */
function pins_its_own_bytes(string $source): bool
{
    return str_contains($source, "hash_file('sha256', __FILE__)")
        || str_contains($source, 'hash_file("sha256", __FILE__)');
}

/**
 * Rewrites documentation-block whitespace to the house alignment rules.
 *
 * @since  2.0.0
 */
final class DocBlockFormatter
{
    /**
     * Tag groups in the order section 3.2 of the coding standard requires.
     *
     * @var    array<string, int>
     * @since  2.0.0
     */
    private const GROUPS = [
        'template' => 1,
        'template-covariant' => 1,
        'extends' => 1,
        'implements' => 1,
        'use' => 1,
        'param' => 2,
        'param-out' => 2,
        'return' => 3,
        'throws' => 4,
        'var' => 5,
        'deprecated' => 5,
        'see' => 5,
        'link' => 5,
        'internal' => 5,
        'todo' => 5,
        'uses' => 5,
        'since' => 5,
    ];

    /**
     * Longest tag name that participates in column alignment, including the leading `@`.
     *
     * @var    int
     * @since  2.0.0
     */
    private const ALIGNABLE_WIDTH = 8;

    /**
     * Widest rendered line the coding standard tolerates.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAXIMUM_LINE_LENGTH = 120;

    /**
     * Number of files the formatter changed.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $changed = 0;

    /**
     * Number of files the formatter inspected.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $inspected = 0;

    /**
     * Format every PHP file below a path.
     *
     * @param   string  $path    File or directory to format.
     * @param   bool    $dryRun  Report what would change without writing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function run(string $path, bool $dryRun): void
    {
        foreach ($this->files($path) as $file) {
            $this->inspected++;
            $source = (string) file_get_contents($file);

            if (pins_its_own_bytes($source)) {
                continue;
            }

            $formatted = $this->formatSource($source);

            if ($formatted === $source) {
                continue;
            }

            $this->changed++;
            echo ($dryRun ? 'would format ' : 'formatted ') . $file . PHP_EOL;

            if (!$dryRun) {
                file_put_contents($file, $formatted);
            }
        }
    }

    /**
     * Report how many files were inspected and changed.
     *
     * @param   bool  $dryRun  Whether this run only reported what it would change.
     *
     * @return  int  Process exit status; non-zero when a check run found work still to do.
     *
     * @since   2.0.0
     */
    public function report(bool $dryRun): int
    {
        printf('%sInspected %d files, formatted %d.%s', PHP_EOL, $this->inspected, $this->changed, PHP_EOL);

        if ($dryRun && $this->changed > 0) {
            fwrite(
                STDERR,
                'Documentation blocks are not in their canonical form. Run composer docs:format and commit '
                . 'the result.' . PHP_EOL,
            );

            return 1;
        }

        return 0;
    }

    /**
     * Collect the PHP files below a path.
     *
     * @param   string  $path  File or directory to walk.
     *
     * @return  list<string>  Sorted absolute or relative file paths.
     *
     * @since   2.0.0
     */
    private function files(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        if (!is_dir($path)) {
            return [];
        }

        $found = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                $found[] = $entry->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Rewrite every multi-line documentation block in a source string.
     *
     * @param   string  $source  Full file contents.
     *
     * @return  string  The contents with documentation blocks realigned.
     *
     * @since   2.0.0
     */
    public function formatSource(string $source): string
    {
        $lines = explode("\n", $source);
        $output = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            if (!preg_match('#^(\s*)/\*\*\s*$#', $lines[$i], $open)) {
                $output[] = $lines[$i];

                continue;
            }

            $end = $this->findBlockEnd($lines, $i, $count);

            if ($end === null) {
                $output[] = $lines[$i];

                continue;
            }

            $block = array_slice($lines, $i, ($end - $i) + 1);
            foreach ($this->formatBlock($block, $open[1]) as $formatted) {
                $output[] = $formatted;
            }

            $i = $end;
        }

        return implode("\n", $output);
    }

    /**
     * Locate the closing line of a documentation block.
     *
     * @param   list<string>  $lines  All lines of the file.
     * @param   int           $start  Index of the opening `/**` line.
     * @param   int           $count  Total number of lines.
     *
     * @return  int|null  Index of the closing line, or null when the block never closes.
     *
     * @since   2.0.0
     */
    private function findBlockEnd(array $lines, int $start, int $count): ?int
    {
        for ($i = $start + 1; $i < $count; $i++) {
            if (preg_match('#^\s*\*/\s*$#', $lines[$i])) {
                return $i;
            }

            if (!preg_match('#^\s*\*#', $lines[$i])) {
                return null;
            }
        }

        return null;
    }

    /**
     * Rewrite one documentation block.
     *
     * @param   list<string>  $block   Lines of the block, opening and closing lines included.
     * @param   string        $indent  Leading whitespace the block is indented by.
     *
     * @return  list<string>  The rewritten block.
     *
     * @since   2.0.0
     */
    private function formatBlock(array $block, string $indent): array
    {
        $body = array_slice($block, 1, count($block) - 2);
        $prose = [];
        $entries = [];

        foreach ($body as $line) {
            $content = preg_replace('#^\s*\*\s?#', '', $line) ?? '';
            $content = rtrim($content);

            if (preg_match('/^@([A-Za-z][A-Za-z0-9-]*)\s*(.*)$/', $content, $match)) {
                $entries[] = ['tag' => $match[1], 'value' => trim($match[2]), 'continuations' => []];

                continue;
            }

            if ($entries !== []) {
                if ($content === '') {
                    continue;
                }

                $entries[count($entries) - 1]['continuations'][] = $content;

                continue;
            }

            $prose[] = $content;
        }

        while ($prose !== [] && end($prose) === '') {
            array_pop($prose);
        }

        $rendered = [$indent . '/**'];

        foreach ($prose as $line) {
            $rendered[] = rtrim($indent . ' * ' . $line);
        }

        if ($entries !== [] && $prose !== []) {
            $rendered[] = $indent . ' *';
        }

        foreach ($this->renderTags($entries, $indent) as $line) {
            $rendered[] = $line;
        }

        $rendered[] = $indent . ' */';

        return $rendered;
    }

    /**
     * Render the tag section of a block with house alignment and group separators.
     *
     * @param   list<array{tag: string, value: string, continuations: list<string>}>  $entries  Parsed tags.
     * @param   string                                                                $indent   Block indent.
     *
     * @return  list<string>  Rendered tag lines.
     *
     * @since   2.0.0
     */
    private function renderTags(array $entries, string $indent): array
    {
        if ($entries === []) {
            return [];
        }

        $column = $this->alignmentColumn($entries);
        $params = array_values(array_filter($entries, static fn (array $e): bool => $e['tag'] === 'param'));
        [$typeWidth, $variableWidth] = $this->parameterColumns($params);

        $lines = [];
        $previousGroup = null;

        foreach ($entries as $entry) {
            $group = self::GROUPS[$entry['tag']] ?? 6;

            if ($previousGroup !== null && $group !== $previousGroup) {
                $lines[] = $indent . ' *';
            }

            $previousGroup = $group;

            $name = '@' . $entry['tag'];
            $value = match ($entry['tag']) {
                'param' => $this->renderParameter($entry['value'], $typeWidth, $variableWidth),
                'return', 'throws', 'var' => $this->renderTypeAndDescription($entry['value']),
                default => $entry['value'],
            };

            $padded = strlen($name) <= self::ALIGNABLE_WIDTH
                ? str_pad($name, $column)
                : $name . ' ';

            $line = rtrim($indent . ' * ' . $padded . $value);

            if (mb_strlen($line) > self::MAXIMUM_LINE_LENGTH) {
                $line = rtrim($indent . ' * ' . $name . ' ' . preg_replace('/\s+/', ' ', $value));
            }

            $lines[] = $line;

            foreach ($this->reindent($entry['continuations']) as $continuation) {
                $lines[] = rtrim($indent . ' * ' . str_repeat(' ', $column) . $continuation);
            }
        }

        return $lines;
    }

    /**
     * Strip the common leading indentation from a tag's continuation lines.
     *
     * Removing the shared prefix keeps nested structures such as multi-line array shapes readable while
     * making the rendering idempotent: re-running the formatter cannot accumulate further indentation.
     *
     * @param   list<string>  $continuations  Continuation lines as they appear in the source block.
     *
     * @return  list<string>  The lines with their shared indentation removed.
     *
     * @since   2.0.0
     */
    private function reindent(array $continuations): array
    {
        $shared = null;

        foreach ($continuations as $line) {
            if (trim($line) === '') {
                continue;
            }

            $leading = strlen($line) - strlen(ltrim($line, ' '));
            $shared = $shared === null ? $leading : min($shared, $leading);
        }

        if ($shared === null || $shared === 0) {
            return $continuations;
        }

        return array_map(
            static fn (string $line): string => substr($line, $shared) ?: ltrim($line),
            $continuations,
        );
    }

    /**
     * Compute the column every alignable tag value starts in.
     *
     * @param   list<array{tag: string, value: string, continuations: list<string>}>  $entries  Parsed tags.
     *
     * @return  int  Zero-based offset from the `@` character.
     *
     * @since   2.0.0
     */
    private function alignmentColumn(array $entries): int
    {
        $widest = 0;

        foreach ($entries as $entry) {
            $length = strlen('@' . $entry['tag']);

            if ($length <= self::ALIGNABLE_WIDTH && $length > $widest) {
                $widest = $length;
            }
        }

        return max($widest + 2, 8);
    }

    /**
     * Compute the type and variable column widths shared by a block's `@param` entries.
     *
     * @param   list<array{tag: string, value: string, continuations: list<string>}>  $params  Parameter tags.
     *
     * @return  array{0: int, 1: int}  Type column width and variable column width.
     *
     * @since   2.0.0
     */
    private function parameterColumns(array $params): array
    {
        $type = 0;
        $variable = 0;

        foreach ($params as $param) {
            $parts = $this->splitParameter($param['value']);

            if ($parts === null) {
                continue;
            }

            $type = max($type, mb_strlen($parts['type']));
            $variable = max($variable, mb_strlen($parts['variable']));
        }

        return [$type, $variable];
    }

    /**
     * Render a single `@param` value with its columns padded.
     *
     * @param   string  $value          Raw tag value.
     * @param   int     $typeWidth      Width of the shared type column.
     * @param   int     $variableWidth  Width of the shared variable column.
     *
     * @return  string  The padded value.
     *
     * @since   2.0.0
     */
    private function renderParameter(string $value, int $typeWidth, int $variableWidth): string
    {
        $parts = $this->splitParameter($value);

        if ($parts === null) {
            return $value;
        }

        $rendered = $this->pad($parts['type'], $typeWidth) . '  ';
        $rendered .= $parts['description'] === ''
            ? $parts['variable']
            : $this->pad($parts['variable'], $variableWidth) . '  ' . $parts['description'];

        return rtrim($rendered);
    }

    /**
     * Separate a type from its trailing description with the house two-space gap.
     *
     * The split is bracket aware, so a generic or array-shape type keeps the spaces inside its own
     * angle, brace, bracket, and parenthesis groups. A type whose brackets never close on this line —
     * a multi-line array shape — is returned untouched.
     *
     * @param   string  $value  Raw tag value.
     *
     * @return  string  The value with exactly two spaces between the type and the description.
     *
     * @since   2.0.0
     */
    private function renderTypeAndDescription(string $value): string
    {
        $depth = 0;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $character = $value[$i];

            if (str_contains('<{[(', $character)) {
                $depth++;

                continue;
            }

            if (str_contains('>}])', $character)) {
                $depth--;

                continue;
            }

            if ($character !== ' ' || $depth !== 0) {
                continue;
            }

            $before = $value[$i - 1] ?? '';
            $after = ltrim(substr($value, $i));

            // Spaces that belong to a spelled-out union or intersection are part of the type.
            if (str_contains('|&', $before) || str_contains('|&', $after[0] ?? '')) {
                continue;
            }

            if ($after === '') {
                break;
            }

            return rtrim(substr($value, 0, $i)) . '  ' . $after;
        }

        return $value;
    }

    /**
     * Split a `@param` value into its type, variable, and description.
     *
     * @param   string  $value  Raw tag value.
     *
     * @return  array{type: string, variable: string, description: string}|null  Parsed parts, or null
     *          when no variable is present.
     *
     * @since   2.0.0
     */
    private function splitParameter(string $value): ?array
    {
        if (!preg_match('/^(.*?)(?<![^\s])((?:\.\.\.)?\$[A-Za-z_][A-Za-z0-9_]*)(\s+(.*))?$/', $value, $match)) {
            return null;
        }

        $type = trim($match[1]);

        if ($type === '') {
            return null;
        }

        return [
            'type' => $type,
            'variable' => $match[2],
            'description' => trim($match[4] ?? ''),
        ];
    }

    /**
     * Pad a string to a column width using spaces.
     *
     * @param   string  $value  Text to pad.
     * @param   int     $width  Target width.
     *
     * @return  string  The padded text.
     *
     * @since   2.0.0
     */
    private function pad(string $value, int $width): string
    {
        $missing = $width - mb_strlen($value);

        return $missing > 0 ? $value . str_repeat(' ', $missing) : $value;
    }
}

$arguments = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $arguments, true);
$paths = array_values(array_filter($arguments, static fn (string $a): bool => !str_starts_with($a, '--')));

if ($paths === []) {
    $paths = ['src'];
}

$formatter = new DocBlockFormatter();

foreach ($paths as $path) {
    $formatter->run($path, $dryRun);
}

exit($formatter->report($dryRun));
