<?php

/**
 * Documentation-block conformance checker for the Kumwe coding standard.
 *
 * The checker walks the requested source roots, tokenises every PHP file and asserts that each
 * documentable member — class-like declaration, method, property, constant and enum case — carries a
 * documentation block shaped like `docs/coding-standard.md` describes. It is deliberately dependency
 * free so that it runs before `composer install` and inside minimal container images.
 *
 * Usage:
 *   php tools/verify-docblocks.php [--summary] [--json] [--limit=N] [path ...]
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
 * Single conformance violation discovered by the auditor.
 *
 * @since  2.0.0
 */
final class DocBlockViolation
{
    /**
     * Create a violation record.
     *
     * @param  string  $file     Path of the file the violation was found in.
     * @param  int     $line     One-based line the violation anchors to.
     * @param  string  $code     Machine-readable violation code, for example `MISSING_DOC`.
     * @param  string  $message  Human-readable explanation of what is missing or wrong.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $code,
        public readonly string $message,
    ) {
    }
}

/**
 * Tokenising auditor that reports documentation blocks missing from a source tree.
 *
 * The auditor understands only the subset of PHP grammar needed to locate documentable members, which
 * keeps it fast enough to run over the whole repository on every quality-assurance pass.
 *
 * @since  2.0.0
 */
final class DocBlockAuditor
{
    /**
     * Violations accumulated across every scanned file.
     *
     * @var    list<DocBlockViolation>
     * @since  2.0.0
     */
    private array $violations = [];

    /**
     * Counters describing how many members of each kind were inspected and documented.
     *
     * @var    array<string, array{total: int, documented: int}>
     * @since  2.0.0
     */
    private array $coverage = [];

    /**
     * Number of files inspected so far.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $files = 0;

    /**
     * Number of files skipped because they pin their own bytes with a self-checksum.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $skipped = 0;

    /**
     * Configure the auditor.
     *
     * @param  string  $requiredSince      Value every `@since` tag must carry.
     * @param  int     $maximumLineLength  Widest line the coding standard tolerates.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly string $requiredSince,
        private readonly int $maximumLineLength,
    ) {
    }

    /**
     * Audit every PHP file below the supplied path.
     *
     * @param   string  $path  File or directory to inspect.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function scan(string $path): void
    {
        if (is_file($path)) {
            $this->auditFile($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        foreach ($files as $file) {
            $this->auditFile($file);
        }
    }

    /**
     * Print the accumulated findings.
     *
     * @param   bool  $summaryOnly  Suppress the individual violation lines.
     * @param   bool  $asJson       Emit machine-readable JSON instead of text.
     * @param   int   $limit        Print at most this many violations; zero prints them all.
     *
     * @return  int  Process exit status: zero when the tree conforms, one otherwise.
     *
     * @since   2.0.0
     */
    public function report(bool $summaryOnly, bool $asJson, int $limit = 0): int
    {
        ksort($this->coverage);

        if ($asJson) {
            echo json_encode([
                'files' => $this->files,
                'coverage' => $this->coverage,
                'violations' => array_map(
                    static fn (DocBlockViolation $v): array => [
                        'file' => $v->file,
                        'line' => $v->line,
                        'code' => $v->code,
                        'message' => $v->message,
                    ],
                    $this->violations,
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;

            return $this->violations === [] ? 0 : 1;
        }

        if (!$summaryOnly) {
            $shown = $limit > 0 ? array_slice($this->violations, 0, $limit) : $this->violations;

            foreach ($shown as $violation) {
                printf(
                    "%s:%d: [%s] %s%s",
                    $violation->file,
                    $violation->line,
                    $violation->code,
                    $violation->message,
                    PHP_EOL,
                );
            }

            if ($limit > 0 && count($this->violations) > $limit) {
                printf('... and %d more%s', count($this->violations) - $limit, PHP_EOL);
            }
        }

        printf('%sFiles inspected: %d%s', PHP_EOL, $this->files, PHP_EOL);

        if ($this->skipped > 0) {
            printf('  (%d self-checksumming migrations left untouched)%s', $this->skipped, PHP_EOL);
        }

        foreach ($this->coverage as $kind => $numbers) {
            $percentage = $numbers['total'] === 0 ? 100.0 : ($numbers['documented'] / $numbers['total']) * 100;
            printf(
                '  %-12s %5d/%-5d documented (%5.1f%%)%s',
                $kind,
                $numbers['documented'],
                $numbers['total'],
                $percentage,
                PHP_EOL,
            );
        }

        $byCode = [];

        foreach ($this->violations as $violation) {
            $byCode[$violation->code] = ($byCode[$violation->code] ?? 0) + 1;
        }

        ksort($byCode);

        printf('%sViolations: %d%s', PHP_EOL, count($this->violations), PHP_EOL);

        foreach ($byCode as $code => $count) {
            printf('  %-20s %d%s', $code, $count, PHP_EOL);
        }

        return $this->violations === [] ? 0 : 1;
    }

    /**
     * Audit a single PHP file.
     *
     * @param   string  $file  Path of the file to inspect.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function auditFile(string $file): void
    {
        $source = (string) file_get_contents($file);

        if (pins_its_own_bytes($source)) {
            $this->skipped++;

            return;
        }

        $this->files++;
        $tokens = token_get_all($source);

        $this->checkLineLengths($file, $source);
        $this->walk($file, $tokens);
    }

    /**
     * Flag lines wider than the coding standard allows.
     *
     * @param   string  $file    Path of the file being inspected.
     * @param   string  $source  Full file contents.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function checkLineLengths(string $file, string $source): void
    {
        foreach (explode("\n", $source) as $index => $line) {
            $width = mb_strlen(rtrim($line, "\r"));

            if ($width > $this->maximumLineLength) {
                $this->violations[] = new DocBlockViolation(
                    $file,
                    $index + 1,
                    'LINE_LENGTH',
                    sprintf('Line is %d characters wide; the limit is %d.', $width, $this->maximumLineLength),
                );
            }
        }
    }

    /**
     * Walk a token stream and audit every documentable member it declares.
     *
     * @param   string                                         $file    Path of the file being inspected.
     * @param   list<array{0: int, 1: string, 2: int}|string>  $tokens  Token stream produced by `token_get_all()`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function walk(string $file, array $tokens): void
    {
        $depth = 0;
        $parenthesis = 0;
        $classStack = [];
        $lastDoc = null;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                    if ($classStack !== [] && $depth < end($classStack)['depth']) {
                        array_pop($classStack);
                    }
                } elseif ($token === '(') {
                    $parenthesis++;
                } elseif ($token === ')') {
                    $parenthesis--;
                }

                if ($token === ';' || $token === '{' || $token === '}') {
                    $lastDoc = null;
                }

                continue;
            }

            [$id, $text, $line] = [$token[0], $token[1], $token[2]];

            if ($id === T_DOC_COMMENT) {
                $lastDoc = $text;

                continue;
            }

            if ($id === T_WHITESPACE || $id === T_COMMENT) {
                continue;
            }

            // Attributes, modifiers and the tokens of a declared type all sit between the doc block
            // and the name it documents, so none of them may discard the pending block.
            if (in_array($id, [
                T_ATTRIBUTE,
                T_FINAL,
                T_ABSTRACT,
                T_READONLY,
                T_STATIC,
                T_PUBLIC,
                T_PROTECTED,
                T_PRIVATE,
                T_VAR,
                T_STRING,
                T_ARRAY,
                T_CALLABLE,
                T_NAME_QUALIFIED,
                T_NAME_FULLY_QUALIFIED,
                T_NS_SEPARATOR,
            ], true)
                || (defined('T_PUBLIC_SET')
                    && in_array($id, [T_PUBLIC_SET, T_PROTECTED_SET, T_PRIVATE_SET], true))
            ) {
                continue;
            }

            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                if ($this->isAnonymousOrConstantFetch($tokens, $i)) {
                    $lastDoc = null;

                    continue;
                }

                $name = $this->nextName($tokens, $i);
                $this->record('class', $file, $line, $lastDoc, sprintf('%s %s', strtolower($text), $name), true, false);
                $classStack[] = ['depth' => $depth + 1, 'kind' => strtolower($text)];
                $lastDoc = null;

                continue;
            }

            if ($id === T_FUNCTION && $classStack !== []) {
                $name = $this->nextName($tokens, $i);

                if ($name === '') {
                    $lastDoc = null;

                    continue; // Closure.
                }

                $signature = $this->readSignature($tokens, $i);
                $this->recordMethod($file, $line, $lastDoc, $name, $signature);
                $lastDoc = null;

                continue;
            }

            if ($id === T_CONST && $classStack !== []) {
                $name = $this->nextConstantName($tokens, $i);
                $this->record('constant', $file, $line, $lastDoc, $name, true, true);
                $lastDoc = null;

                continue;
            }

            if ($id === T_CASE && $classStack !== [] && end($classStack)['kind'] === 'enum') {
                $name = $this->nextName($tokens, $i);
                $this->record('enum case', $file, $line, $lastDoc, $name, true, false);

                // PHPStan rejects a `@var` that names no variable outside a property or constant, so a
                // case block carrying one fails `composer analyse` with varTag.noVariable.
                if ($lastDoc !== null && preg_match('/^\s*\*\s*@var\b/m', $lastDoc) === 1) {
                    $this->violations[] = new DocBlockViolation(
                        $file,
                        $line,
                        'ENUM_CASE_VAR',
                        sprintf('Enum case %s must not carry an `@var` tag; PHPStan rejects it.', $name),
                    );
                }

                $lastDoc = null;

                continue;
            }

            if (
                $id === T_VARIABLE
                && $parenthesis === 0
                && $classStack !== []
                && $this->isPropertyDeclaration($tokens, $i, $depth, $classStack)
            ) {
                $this->record('property', $file, $line, $lastDoc, $text, true, true);
                $lastDoc = null;

                continue;
            }

            $lastDoc = null;
        }
    }

    /**
     * Record and validate a non-method member.
     *
     * @param   string       $kind        Member kind used in coverage counters.
     * @param   string       $file        Path of the file being inspected.
     * @param   int          $line        Line the member is declared on.
     * @param   string|null  $doc         Documentation block attached to the member, when present.
     * @param   string       $label       Human-readable member label used in messages.
     * @param   bool         $needsSince  Whether the member must carry a `@since` tag.
     * @param   bool         $needsVar    Whether the member must carry a `@var` tag.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function record(
        string $kind,
        string $file,
        int $line,
        ?string $doc,
        string $label,
        bool $needsSince,
        bool $needsVar,
    ): void {
        $this->coverage[$kind]['total'] = ($this->coverage[$kind]['total'] ?? 0) + 1;
        $this->coverage[$kind]['documented'] = $this->coverage[$kind]['documented'] ?? 0;

        if ($doc === null) {
            $this->violations[] = new DocBlockViolation(
                $file,
                $line,
                'MISSING_DOC',
                sprintf('%s %s has no documentation block.', ucfirst($kind), $label),
            );

            return;
        }

        $this->coverage[$kind]['documented']++;

        if ($this->summaryOf($doc) === '') {
            $this->violations[] = new DocBlockViolation(
                $file,
                $line,
                'MISSING_SUMMARY',
                sprintf('%s %s has no description.', ucfirst($kind), $label),
            );
        }

        if ($needsSince && !$this->hasSince($doc)) {
            $this->violations[] = new DocBlockViolation(
                $file,
                $line,
                'MISSING_SINCE',
                sprintf('%s %s is missing `@since %s`.', ucfirst($kind), $label, $this->requiredSince),
            );
        }

        if ($needsVar && preg_match('/@var\s+\S/', $doc) !== 1) {
            $this->violations[] = new DocBlockViolation(
                $file,
                $line,
                'MISSING_VAR',
                sprintf('%s %s needs an `@var` tag carrying a type.', ucfirst($kind), $label),
            );
        }
    }

    /**
     * Record and validate a method declaration.
     *
     * @param   string                                           $file       Path of the file being inspected.
     * @param   int                                              $line       Line the method is declared on.
     * @param string|null $doc Documentation block attached to the method, when present.
     * @param   string                                           $name       Method name.
     * @param   array{parameters: list<string>, return: string}  $signature  Parsed signature details.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function recordMethod(string $file, int $line, ?string $doc, string $name, array $signature): void
    {
        $this->coverage['method']['total'] = ($this->coverage['method']['total'] ?? 0) + 1;
        $this->coverage['method']['documented'] = $this->coverage['method']['documented'] ?? 0;

        if ($doc === null) {
            $this->violations[] = new DocBlockViolation(
                $file,
                $line,
                'MISSING_DOC',
                sprintf('Method %s() has no documentation block.', $name),
            );

            return;
        }

        $this->coverage['method']['documented']++;

        if ($this->summaryOf($doc) === '') {
            $this->violations[] = new DocBlockViolation(
                $file,
                $line,
                'MISSING_SUMMARY',
                sprintf('Method %s() has no description.', $name),
            );
        }

        if (!$this->hasSince($doc)) {
            $this->violations[] = new DocBlockViolation(
                $file,
                $line,
                'MISSING_SINCE',
                sprintf('Method %s() is missing `@since %s`.', $name, $this->requiredSince),
            );
        }

        // A tag value may wrap across several lines when its type is a multi-line array shape, so
        // fold continuation lines into their tag before scanning. The type may itself contain spaces
        // (`array{id: string, tags: list<string>}`), so the scan is non-greedy up to the first
        // variable token rather than assuming the type is a single word.
        $folded = (string) preg_replace('/\R\s*\*[ \t]*(?![@\/])/', ' ', $doc);
        preg_match_all('/@param\s+.*?(?:\.\.\.)?(\$[A-Za-z_][A-Za-z0-9_]*)/', $folded, $matches);
        $documented = $matches[1];

        foreach ($signature['parameters'] as $parameter) {
            if (!in_array($parameter, $documented, true)) {
                $this->violations[] = new DocBlockViolation(
                    $file,
                    $line,
                    'MISSING_PARAM',
                    sprintf('Method %s() does not document parameter %s.', $name, $parameter),
                );
            }
        }

        foreach ($documented as $parameter) {
            if (!in_array($parameter, $signature['parameters'], true)) {
                $this->violations[] = new DocBlockViolation(
                    $file,
                    $line,
                    'EXTRA_PARAM',
                    sprintf('Method %s() documents unknown parameter %s.', $name, $parameter),
                );
            }
        }

        $hasReturnTag = (bool) preg_match('/@return\s+\S/', $doc);
        $isConstructor = strtolower($name) === '__construct';

        if (!$hasReturnTag && !$isConstructor) {
            $this->violations[] = new DocBlockViolation(
                $file,
                $line,
                'MISSING_RETURN',
                sprintf('Method %s() is missing an `@return` tag.', $name),
            );
        }

        if ($hasReturnTag && $isConstructor) {
            $this->violations[] = new DocBlockViolation(
                $file,
                $line,
                'EXTRA_RETURN',
                'Constructors must not carry an `@return` tag.',
            );
        }
    }

    /**
     * Extract the free-text description from a documentation block.
     *
     * @param   string  $doc  Raw documentation block including its delimiters.
     *
     * @return  string  The description with comment markers removed, or an empty string when absent.
     *
     * @since   2.0.0
     */
    private function summaryOf(string $doc): string
    {
        $body = preg_replace('#^/\*\*|\*/$#', '', $doc) ?? '';
        $lines = [];

        foreach (explode("\n", $body) as $line) {
            $line = trim(ltrim(trim($line), '*'));

            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }

            $lines[] = $line;
        }

        return trim(implode(' ', $lines));
    }

    /**
     * Determine whether a documentation block carries the required `@since` tag.
     *
     * @param   string  $doc  Raw documentation block.
     *
     * @return  bool  True when the block declares the required version.
     *
     * @since   2.0.0
     */
    private function hasSince(string $doc): bool
    {
        return (bool) preg_match('/@since\s+' . preg_quote($this->requiredSince, '/') . '\b/', $doc);
    }

    /**
     * Decide whether a class-like keyword introduces an anonymous class or a `::class` fetch.
     *
     * @param   list<array{0: int, 1: string, 2: int}|string>  $tokens  Token stream.
     * @param   int                                            $index   Index of the keyword token.
     *
     * @return  bool  True when the keyword does not introduce a named declaration.
     *
     * @since   2.0.0
     */
    private function isAnonymousOrConstantFetch(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (is_array($token) && in_array($token[0], [T_NEW, T_DOUBLE_COLON], true)) {
                return true;
            }

            break;
        }

        return $this->nextName($tokens, $index) === '';
    }

    /**
     * Read the identifier that follows a declaration keyword.
     *
     * @param   list<array{0: int, 1: string, 2: int}|string>  $tokens  Token stream.
     * @param   int                                            $index   Index of the keyword token.
     *
     * @return  string  The identifier, or an empty string when the declaration is unnamed.
     *
     * @since   2.0.0
     */
    private function nextName(array $tokens, int $index): string
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }

            return '';
        }

        return '';
    }

    /**
     * Read the first constant name declared by a `const` statement.
     *
     * @param   list<array{0: int, 1: string, 2: int}|string>  $tokens  Token stream.
     * @param   int                                            $index   Index of the `const` token.
     *
     * @return  string  The constant name, or an empty string when it cannot be determined.
     *
     * @since   2.0.0
     */
    private function nextConstantName(array $tokens, int $index): string
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_STRING], true)) {
                if ($token[0] === T_STRING) {
                    // A typed constant places the type before the name; keep scanning for the `=`.
                    $next = $this->peekSignificant($tokens, $i);

                    if ($next === '=' || $next === ',' || $next === ';') {
                        return $token[1];
                    }
                }

                continue;
            }

            return '';
        }

        return '';
    }

    /**
     * Return the next significant token rendered as text.
     *
     * @param   list<array{0: int, 1: string, 2: int}|string>  $tokens  Token stream.
     * @param   int                                            $index   Index to scan forward from.
     *
     * @return  string  Token text, or an empty string at end of stream.
     *
     * @since   2.0.0
     */
    private function peekSignificant(array $tokens, int $index): string
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT) {
                    continue;
                }

                return $token[1];
            }

            return $token;
        }

        return '';
    }

    /**
     * Decide whether a variable token begins a property declaration.
     *
     * @param   list<array{0: int, 1: string, 2: int}|string>  $tokens      Token stream.
     * @param   int                                            $index       Index of the variable token.
     * @param   int                                            $depth       Current brace depth.
     * @param   list<array{depth: int, kind: string}>          $classStack  Enclosing class-like contexts.
     *
     * @return  bool  True when the variable is a declared property rather than a local or parameter.
     *
     * @since   2.0.0
     */
    private function isPropertyDeclaration(array $tokens, int $index, int $depth, array $classStack): bool
    {
        if ($depth !== end($classStack)['depth']) {
            return false;
        }

        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            // Nullable markers and union or intersection separators are part of the declared type.
            if (is_string($token)) {
                if ($token === '?' || $token === '|' || $token === '&') {
                    continue;
                }

                return false;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_STRING, T_DOC_COMMENT], true)) {
                continue;
            }

            if (in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR, T_READONLY, T_STATIC], true)) {
                return true;
            }

            if (defined('T_PUBLIC_SET') && in_array($token[0], [T_PUBLIC_SET, T_PROTECTED_SET, T_PRIVATE_SET], true)) {
                return true;
            }

            if (in_array($token[0], [T_ARRAY, T_CALLABLE], true)) {
                continue;
            }

            // Nullable markers, union pipes and qualified type names precede the variable.
            if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                continue;
            }

            return false;
        }

        return false;
    }

    /**
     * Parse the parameter names and return type of a function declaration.
     *
     * @param   list<array{0: int, 1: string, 2: int}|string>  $tokens  Token stream.
     * @param   int                                            $index   Index of the `function` token.
     *
     * @return  array{parameters: list<string>, return: string}  Parameter variables and the return type text.
     *
     * @since   2.0.0
     */
    private function readSignature(array $tokens, int $index): array
    {
        $parameters = [];
        $return = '';
        $parenthesis = 0;
        $started = false;
        $afterParameters = false;

        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '(') {
                $parenthesis++;
                $started = true;

                continue;
            }

            if ($text === ')') {
                $parenthesis--;

                if ($parenthesis === 0) {
                    $afterParameters = true;
                }

                continue;
            }

            if ($started && $parenthesis === 1 && is_array($token) && $token[0] === T_VARIABLE) {
                $parameters[] = $token[1];

                continue;
            }

            if ($afterParameters) {
                if ($text === '{' || $text === ';') {
                    break;
                }

                if ($text !== ':' && !(is_array($token) && $token[0] === T_WHITESPACE)) {
                    $return .= $text;
                }
            }
        }

        return ['parameters' => $parameters, 'return' => $return];
    }
}

$arguments = array_slice($argv, 1);
$summaryOnly = in_array('--summary', $arguments, true);
$asJson = in_array('--json', $arguments, true);
$limit = 0;

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--limit=')) {
        $limit = (int) substr($argument, 8);
    }
}

$paths = array_values(array_filter($arguments, static fn (string $a): bool => !str_starts_with($a, '--')));

if ($paths === []) {
    $paths = ['src'];
}

$auditor = new DocBlockAuditor('2.0.0', 120);

foreach ($paths as $path) {
    $auditor->scan($path);
}

exit($auditor->report($summaryOnly, $asJson, $limit));
