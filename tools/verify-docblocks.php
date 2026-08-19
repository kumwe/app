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
 *   php tools/verify-docblocks.php --emit-baseline --expires=YYYY-MM-DD --recorded-at=YYYY-MM-DD \
 *       [--recorded-from=URL ...] tests
 *   php tools/verify-docblocks.php --baseline=docs/quality/test-docblock-baseline.json [--today=DATE] tests
 *
 * `--emit-baseline` prints the shrinking record of documentation debt under tests/ (V2-QA-010). It
 * never writes the file itself, and it takes its dates as arguments so an unchanged tree re-emits
 * byte-identically. `--baseline=` arms the gate: any violation under tests/ that the record does not
 * carry fails, a recorded entry that no longer matches anything fails, and a record that is itself
 * malformed — a missing owner, finding, justification or expiry, an expired entry, a duplicated key,
 * or a count that disagrees with the entries — fails. `--today=` moves the clock for testing.
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
     * @param  string  $member   Stable member label used by the shrinking baseline (empty when not applicable).
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $code,
        public readonly string $message,
        public readonly string $member = '',
    ) {
    }

    /**
     * Stable key for baseline comparison: relative file, code, and member label.
     *
     * @param   string  $root  Repository root used to relativise absolute paths.
     *
     * @return  string  Deterministic baseline key.
     *
     * @since   2.0.0
     */
    public function baselineKey(string $root): string
    {
        $file = $this->file;
        $prefix = rtrim($root, '/') . '/';
        if (str_starts_with($file, $prefix)) {
            $file = substr($file, strlen($prefix));
        }

        return $file . "\0" . $this->code . "\0" . $this->member;
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
     * Return every violation discovered by the most recent scan.
     *
     * @return  list<DocBlockViolation>  Ordered list of findings.
     *
     * @since   2.0.0
     */
    public function violations(): array
    {
        return $this->violations;
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
            $trimmed = rtrim($line, "\r");
            // ext-mbstring is required by composer.json, but this tool runs without vendor/ and the
            // fallback must count characters rather than bytes: this codebase's blocks are full of
            // em-dashes, and counting their three bytes each would fail lines that are within the limit.
            $width = function_exists('mb_strlen')
                ? mb_strlen($trimmed)
                : (int) preg_match_all('/./u', $trimmed);

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
    /**
     * Find the bracket that closes the attribute opening at the given offset.
     *
     * `#[` opens an attribute group and `]` closes it, and either may nest: an argument may be an array
     * literal, and a group may itself carry a nested attribute. Counting both openings against both
     * closings is what makes the skip exact rather than a guess at the next `]`.
     *
     * @param   list<array{int, string, int}|string>  $tokens  Token stream being walked.
     * @param   int                                   $start   Offset of the `#[` token itself.
     * @param   int                                   $count   Total token count, as the walk measured it.
     *
     * @return  int  Offset of the closing `]`, or the last token when the stream ends unbalanced.
     *
     * @since   2.0.0
     */
    private function attributeEnd(array $tokens, int $start, int $count): int
    {
        $open = 1;
        for ($i = $start + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token)) {
                if ($token[0] === T_ATTRIBUTE) {
                    $open++;
                }

                continue;
            }
            if ($token === '[') {
                $open++;
            } elseif ($token === ']') {
                $open--;
                if ($open === 0) {
                    return $i;
                }
            }
        }

        return $count - 1;
    }

    private function walk(string $file, array $tokens): void
    {
        $depth = 0;
        $parenthesis = 0;
        $classStack = [];
        $anonymous = 0;
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

            // An attribute sits between the doc block and the name it documents, and its arguments may
            // contain anything an expression may contain — `::class`, strings, arrays, nested attributes.
            // Stepping through those tokens one at a time would let any of them discard the pending block,
            // so the whole attribute is skipped in one move, up to the bracket that closes it.
            if ($id === T_ATTRIBUTE) {
                $i = $this->attributeEnd($tokens, $i, $count);

                continue;
            }

            // Modifiers and the tokens of a declared type also sit between the doc block and the name it
            // documents, so none of them may discard the pending block.
            if (in_array($id, [
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
                $kind = $this->classDeclarationKind($tokens, $i);
                if ($kind === 'constant-fetch') {
                    $lastDoc = null;

                    continue;
                }
                if ($kind === 'anonymous') {
                    // Not documented as a declaration, but its members are still recorded, so it needs
                    // an identity of its own. The ordinal is by order of appearance in the file, which
                    // is stable for as long as the file is.
                    $anonymous++;
                    $classStack[] = [
                        'depth' => $depth + 1,
                        'kind' => strtolower($text),
                        'name' => sprintf('class@%d', $anonymous),
                    ];
                    $lastDoc = null;

                    continue;
                }

                $name = $this->nextName($tokens, $i);
                $this->record(
                    'class',
                    $file,
                    $line,
                    $lastDoc,
                    sprintf('%s %s', strtolower($text), $name),
                    true,
                    false,
                    $name,
                );
                $classStack[] = ['depth' => $depth + 1, 'kind' => strtolower($text), 'name' => $name];
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
                $this->recordMethod($file, $line, $lastDoc, $name, $signature, end($classStack)['name']);
                $lastDoc = null;

                continue;
            }

            if ($id === T_CONST && $classStack !== []) {
                $name = $this->nextConstantName($tokens, $i);
                $this->record('constant', $file, $line, $lastDoc, $name, true, true, end($classStack)['name']);
                $lastDoc = null;

                continue;
            }

            if ($id === T_CASE && $classStack !== [] && end($classStack)['kind'] === 'enum') {
                $name = $this->nextName($tokens, $i);
                $this->record('enum case', $file, $line, $lastDoc, $name, true, false, end($classStack)['name']);

                // PHPStan rejects a `@var` that names no variable outside a property or constant, so a
                // case block carrying one fails `composer analyse` with varTag.noVariable.
                if ($lastDoc !== null && preg_match('/^\s*\*\s*@var\b/m', $lastDoc) === 1) {
                    $this->violations[] = new DocBlockViolation(
                        $file,
                        $line,
                        'ENUM_CASE_VAR',
                        sprintf('Enum case %s must not carry an `@var` tag; PHPStan rejects it.', $name),
                        end($classStack)['name'] . '::' . $name,
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
                $this->record('property', $file, $line, $lastDoc, $text, true, true, end($classStack)['name']);
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
     * @param   string       $declaring   Name of the class, interface, trait or enum that declares it.
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
        string $declaring,
    ): void {
        // A test file may declare several classes — phpcs.xml exempts tests/ from one-class-per-file —
        // so a bare member name is not unique within a file and cannot key a baseline on its own.
        $member = $kind === 'class' ? $declaring : $declaring . '::' . $label;
        $this->coverage[$kind]['total'] = ($this->coverage[$kind]['total'] ?? 0) + 1;
        $this->coverage[$kind]['documented'] = $this->coverage[$kind]['documented'] ?? 0;

        if ($doc === null) {
            $this->violations[] = new DocBlockViolation(
                $file,
                $line,
                'MISSING_DOC',
                sprintf('%s %s has no documentation block.', ucfirst($kind), $label),
                $member,
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
                $member,
            );
        }

        if ($needsSince && !$this->hasSince($doc)) {
            $this->violations[] = new DocBlockViolation(
                $file,
                $line,
                'MISSING_SINCE',
                sprintf('%s %s is missing `@since %s`.', ucfirst($kind), $label, $this->requiredSince),
                $member,
            );
        }

        if ($needsVar && preg_match('/@var\s+\S/', $doc) !== 1) {
            $this->violations[] = new DocBlockViolation(
                $file,
                $line,
                'MISSING_VAR',
                sprintf('%s %s needs an `@var` tag carrying a type.', ucfirst($kind), $label),
                $member,
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
     * @param   string                                           $declaring  Declaring class, interface,
     *          trait or enum name, which qualifies the member label the baseline keys on.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function recordMethod(
        string $file,
        int $line,
        ?string $doc,
        string $name,
        array $signature,
        string $declaring,
    ): void {
        $member = $declaring . '::' . $name . '()';
        $this->coverage['method']['total'] = ($this->coverage['method']['total'] ?? 0) + 1;
        $this->coverage['method']['documented'] = $this->coverage['method']['documented'] ?? 0;

        if ($doc === null) {
            $this->violations[] = new DocBlockViolation(
                $file,
                $line,
                'MISSING_DOC',
                sprintf('Method %s() has no documentation block.', $name),
                $member,
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
                $member,
            );
        }

        if (!$this->hasSince($doc)) {
            $this->violations[] = new DocBlockViolation(
                $file,
                $line,
                'MISSING_SINCE',
                sprintf('Method %s() is missing `@since %s`.', $name, $this->requiredSince),
                $member,
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
                    $member . ' ' . $parameter,
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
                    $member . ' ' . $parameter,
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
                $member,
            );
        }

        if ($hasReturnTag && $isConstructor) {
            $this->violations[] = new DocBlockViolation(
                $file,
                $line,
                'EXTRA_RETURN',
                'Constructors must not carry an `@return` tag.',
                $member,
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
        return $this->classDeclarationKind($tokens, $index) !== 'named';
    }

    /**
     * Tell a named declaration, an anonymous class and a `::class` fetch apart.
     *
     * Anonymous classes matter to the baseline: a test file often builds several, each with a `handle()`
     * or an `up()`, and attributing all of them to the enclosing test class gives them one key between
     * them — which is a free pass for whichever comes second.
     *
     * @param   list<array{0: int, 1: string, 2: int}|string>  $tokens  Token stream.
     * @param   int                                            $index   Index of the declaration keyword.
     *
     * @return  string  One of `named`, `anonymous` or `constant-fetch`.
     *
     * @since   2.0.0
     */
    private function classDeclarationKind(array $tokens, int $index): string
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (is_array($token) && $token[0] === T_DOUBLE_COLON) {
                return 'constant-fetch';
            }

            if (is_array($token) && $token[0] === T_NEW) {
                return 'anonymous';
            }

            break;
        }

        return $this->nextName($tokens, $index) === '' ? 'anonymous' : 'named';
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

            if (is_string($token) && ($token === '?' || $token === '|')) {
                continue; // Nullable or union constant type.
            }

            if (
                is_array($token)
                && in_array($token[0], [T_WHITESPACE, T_STRING, T_ARRAY, T_CALLABLE, T_NS_SEPARATOR], true)
            ) {
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

// The suite includes this file to exercise the gate itself; the same guard the interface-programme
// verifier uses keeps the command line from running when it does.
if (defined('KUMWE_DOCBLOCKS_LIBRARY_ONLY')) {
    return;
}

$root = dirname(__DIR__);
$arguments = array_slice($argv, 1);
$summaryOnly = in_array('--summary', $arguments, true);
$asJson = in_array('--json', $arguments, true);
$emitBaseline = in_array('--emit-baseline', $arguments, true);
$baselinePath = null;
$today = date('Y-m-d');
$limit = 0;
$expires = '';
$recordedAt = '';
$sources = [];

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--limit=')) {
        $limit = (int) substr($argument, 8);
        continue;
    }
    if (str_starts_with($argument, '--baseline=')) {
        $baselinePath = substr($argument, strlen('--baseline='));
        continue;
    }
    if (str_starts_with($argument, '--today=')) {
        $today = substr($argument, strlen('--today='));
        continue;
    }
    if (str_starts_with($argument, '--expires=')) {
        $expires = substr($argument, strlen('--expires='));
        continue;
    }
    if (str_starts_with($argument, '--recorded-at=')) {
        $recordedAt = substr($argument, strlen('--recorded-at='));
        continue;
    }
    if (str_starts_with($argument, '--recorded-from=')) {
        $sources[] = substr($argument, strlen('--recorded-from='));
        continue;
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

if ($emitBaseline) {
    // Dates are arguments, never date(): re-emitting an unchanged tree has to produce a byte-identical
    // document, or the record cannot be diffed against the one in the repository.
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) !== 1) {
        fwrite(STDERR, "--emit-baseline needs --expires=YYYY-MM-DD.\n");

        exit(1);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $recordedAt) !== 1) {
        fwrite(STDERR, "--emit-baseline needs --recorded-at=YYYY-MM-DD.\n");

        exit(1);
    }
    fwrite(
        STDOUT,
        emitDocblockBaseline($auditor->violations(), $root, $paths, $expires, $recordedAt, $sources) . "\n",
    );

    exit(0);
}

if ($baselinePath !== null) {
    exit(compareDocblockBaseline(
        $auditor->violations(),
        $baselinePath,
        $root,
        $paths,
        $today,
        $summaryOnly,
        $asJson,
        $limit,
    ));
}

exit($auditor->report($summaryOnly, $asJson, $limit));

/**
 * Relativise an absolute path against the repository root.
 *
 * @param   string  $file  Absolute or already-relative path.
 * @param   string  $root  Repository root.
 *
 * @return  string  Repository-relative path with forward slashes.
 *
 * @since   2.0.0
 */
function docblockRelativePath(string $file, string $root): string
{
    $prefix = rtrim($root, '/') . '/';
    if (str_starts_with($file, $prefix)) {
        $file = substr($file, strlen($prefix));
    }

    return str_replace('\\', '/', $file);
}

/**
 * Decide whether a repository-relative path sits inside one of the scanned scopes.
 *
 * The baselined scope is whatever the caller asked to scan rather than a directory name written into
 * the tool, so the gate covers exactly what it was pointed at and can be exercised against a fixture
 * tree of its own.
 *
 * @param   string        $relative  Repository-relative path.
 * @param   list<string>  $scopes    Scanned paths, repository-relative.
 *
 * @return  bool  True when the path is inside one of them.
 *
 * @since   2.0.0
 */
function docblockWithinScope(string $relative, array $scopes): bool
{
    foreach ($scopes as $scope) {
        $scope = trim(str_replace('\\', '/', $scope), '/');
        if ($scope === '' || $relative === $scope || str_starts_with($relative, $scope . '/')) {
            return true;
        }
    }

    return false;
}

/**
 * Emit the shrinking baseline document for the documentation debt currently under `tests/`.
 *
 * Every violation that names a member is baseline-managed. A violation that names none — the line-width
 * check is the only one — is never recorded, because `phpcs.xml` already holds `tests/` to the same
 * 120-character limit and the tree carries no such debt to record.
 *
 * @param   list<DocBlockViolation>  $violations  Findings from the most recent scan.
 * @param   string                   $root        Repository root for relative paths.
 * @param   list<string>             $scopes      Scanned paths the record covers.
 * @param   string                   $expires     Expiry stamped on every recorded entry.
 * @param   string                   $recordedAt  Date the record was taken.
 * @param   list<string>             $sources     Verification runs the record was taken from.
 *
 * @return  string  Pretty-printed JSON baseline document.
 *
 * @since   2.0.0
 */
function emitDocblockBaseline(
    array $violations,
    string $root,
    array $scopes,
    string $expires,
    string $recordedAt,
    array $sources,
): string {
    $entries = [];
    foreach ($violations as $violation) {
        if ($violation->member === '') {
            continue;
        }
        $file = docblockRelativePath($violation->file, $root);
        if (!docblockWithinScope($file, $scopes)) {
            continue;
        }
        $entries[] = [
            'file' => $file,
            'line' => $violation->line,
            'member' => $violation->member,
            'code' => $violation->code,
            'owner' => 'quality-engineering',
            'finding' => 'V2-QA-010',
            'expires' => $expires,
            'justification' => 'Pre-existing documentation debt under tests/ recorded when the gate was '
                . 'armed; the record only ever shrinks.',
        ];
    }

    usort(
        $entries,
        static function (array $left, array $right): int {
            return [$left['file'], $left['member'], $left['code'], $left['line']]
                <=> [$right['file'], $right['member'], $right['code'], $right['line']];
        },
    );

    return json_encode(
        [
            'baseline' => 'kumwe-test-documentation-blocks',
            'finding' => 'V2-QA-010',
            'authority' => 'docs/coding-standard.md',
            'note' => 'Every documentation violation under tests/ that existed when the gate was armed. '
                . 'It is a record, not a permission: tools/verify-docblocks.php fails on any violation '
                . 'under tests/ that is not listed here, fails when a listed entry no longer violates '
                . 'anything so the entry must be deleted, fails when an entry passes its expiry, and '
                . 'fails when two entries share one key. Entries are keyed by file, code and '
                . 'class-qualified member, so deleting one without doing the work fails the build and '
                . 'the count is a burn-down rather than a number anyone can edit.',
            'scope' => implode(', ', $scopes) . ' — src/ carries no debt and is held to the standard '
                . 'directly by composer docs:api.',
            'recorded_at' => $recordedAt,
            'recorded_from' => $sources,
            'entry_count' => count($entries),
            'entries' => $entries,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
}

/**
 * Read and validate the recorded baseline document.
 *
 * An exemption nobody owns is a permission, so every entry must name its owner, the finding that
 * removes it, a justification and a well-formed expiry — the same four an exemption in
 * `tools/verify-dependency-graph.php` must carry. A malformed entry is an error rather than something
 * to skip: silently dropping it would quietly widen the gate.
 *
 * @param   array<int, mixed>  $rawEntries  Entries as read from the document.
 * @param   string             $today       ISO date used for expiry checks.
 *
 * @return  array{entries: array<string, array<string, mixed>>, errors: list<string>}  Entries by key,
 *          and every structural complaint found while reading them.
 *
 * @since   2.0.0
 */
function readDocblockBaseline(array $rawEntries, string $today): array
{
    $entries = [];
    $errors = [];

    foreach ($rawEntries as $index => $entry) {
        if (!is_array($entry)) {
            $errors[] = sprintf('Baseline entry at position %d is not an object.', $index);
            continue;
        }
        $file = $entry['file'] ?? null;
        $code = $entry['code'] ?? null;
        $member = $entry['member'] ?? null;
        if (
            !is_string($file) || $file === ''
            || !is_string($code) || $code === ''
            || !is_string($member) || $member === ''
        ) {
            $errors[] = sprintf('Baseline entry at position %d needs "file", "code" and "member".', $index);
            continue;
        }
        $key = $file . "\0" . $code . "\0" . $member;
        $label = sprintf('%s %s (%s)', $file, $member, $code);
        if (isset($entries[$key])) {
            $errors[] = sprintf(
                'Baseline entry %s is recorded twice. Two entries sharing one key make the second '
                . 'a free pass for anything that collides with it.',
                $label,
            );
            continue;
        }
        $owner = $entry['owner'] ?? null;
        $finding = $entry['finding'] ?? null;
        $justification = $entry['justification'] ?? null;
        if (
            !is_string($owner) || trim($owner) === '' || $owner === 'UNASSIGNED'
            || !is_string($finding) || trim($finding) === '' || $finding === 'UNASSIGNED'
            || !is_string($justification) || trim($justification) === ''
        ) {
            $errors[] = sprintf(
                'Baseline entry %s needs a named owner, the finding that removes it, and a '
                . 'justification. An exemption nobody owns is a permission.',
                $label,
            );
        }
        $expires = $entry['expires'] ?? null;
        if (!is_string($expires) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) !== 1) {
            $errors[] = sprintf('Baseline entry %s needs an expiry date as YYYY-MM-DD.', $label);
        } elseif ($expires < $today) {
            $errors[] = sprintf(
                'Baseline entry %s expired on %s. Document the member or record a new decision; an '
                . 'exemption does not outlive the work that justified it.',
                $label,
                $expires,
            );
        }
        $entries[$key] = $entry;
    }

    return ['entries' => $entries, 'errors' => $errors];
}

/**
 * Compare scan results with the recorded documentation baseline for `tests/`.
 *
 * Three things fail the build: a violation under `tests/` that the record does not carry, a recorded
 * entry that no longer matches anything, and a record that is itself malformed. Together they make the
 * entry count a burn-down: it cannot be lowered except by documenting the member the entry names.
 *
 * @param   list<DocBlockViolation>  $violations    Findings from the most recent scan.
 * @param   string                   $baselinePath  Path to the baseline JSON document.
 * @param   string                   $root          Repository root for relative paths.
 * @param   list<string>             $scopes        Scanned paths the record covers.
 * @param   string                   $today         ISO date used for expiry checks.
 * @param   bool                     $summaryOnly   Suppress individual hard-fail lines.
 * @param   bool                     $asJson        Emit machine-readable JSON.
 * @param   int                      $limit         Cap on printed hard-fail lines; zero prints all.
 *
 * @return  int  Process exit status.
 *
 * @since   2.0.0
 */
function compareDocblockBaseline(
    array $violations,
    string $baselinePath,
    string $root,
    array $scopes,
    string $today,
    bool $summaryOnly,
    bool $asJson,
    int $limit,
): int {
    if (!is_file($baselinePath)) {
        fwrite(STDERR, sprintf("%s is missing.\n", $baselinePath));

        return 1;
    }
    $raw = file_get_contents($baselinePath);
    if ($raw === false) {
        fwrite(STDERR, sprintf("%s could not be read.\n", $baselinePath));

        return 1;
    }
    /** @var mixed $document */
    $document = json_decode($raw, true);
    if (!is_array($document) || !isset($document['entries']) || !is_array($document['entries'])) {
        fwrite(STDERR, sprintf("%s must declare an entries array.\n", basename($baselinePath)));

        return 1;
    }

    $read = readDocblockBaseline(array_values($document['entries']), $today);
    /** @var array<string, array<string, mixed>> $baselineByKey */
    $baselineByKey = $read['entries'];
    $structural = $read['errors'];

    $declared = $document['entry_count'] ?? null;
    if (!is_int($declared) || $declared !== count($document['entries'])) {
        $structural[] = sprintf(
            'The recorded entry_count must equal the number of entries; the document says %s and '
            . 'carries %d. The count is the burn-down number, so it is not allowed to drift.',
            is_int($declared) ? (string) $declared : 'nothing',
            count($document['entries']),
        );
    }

    $hardFails = [];
    $matchedKeys = [];

    foreach ($violations as $violation) {
        $relative = docblockRelativePath($violation->file, $root);
        if (!docblockWithinScope($relative, $scopes) || $violation->member === '') {
            // Outside the baselined tree, or a file-level finding the record cannot key. Either way it
            // is held to the standard directly, exactly as src/ is.
            $hardFails[] = $violation;
            continue;
        }

        $key = $violation->baselineKey($root);
        if (!isset($baselineByKey[$key])) {
            $hardFails[] = $violation;
            continue;
        }
        $matchedKeys[$key] = true;
    }

    $stale = [];
    foreach ($baselineByKey as $key => $entry) {
        if (!isset($matchedKeys[$key])) {
            $stale[] = sprintf(
                '%s %s (%s)',
                is_string($entry['file'] ?? null) ? $entry['file'] : '?',
                is_string($entry['member'] ?? null) ? $entry['member'] : '?',
                is_string($entry['code'] ?? null) ? $entry['code'] : '?',
            );
        }
    }

    if ($asJson) {
        echo json_encode([
            'hard_fails' => array_map(
                static fn (DocBlockViolation $v): array => [
                    'file' => $v->file,
                    'line' => $v->line,
                    'code' => $v->code,
                    'message' => $v->message,
                    'member' => $v->member,
                ],
                $hardFails,
            ),
            'stale_baseline_entries' => $stale,
            'structural_errors' => $structural,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;

        return ($hardFails === [] && $stale === [] && $structural === []) ? 0 : 1;
    }

    if (!$summaryOnly) {
        $shown = $limit > 0 ? array_slice($hardFails, 0, $limit) : $hardFails;
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
    }
    if ($structural !== []) {
        fwrite(STDERR, "The baseline document itself is not valid:\n");
        foreach ($structural as $error) {
            fwrite(STDERR, '  - ' . $error . "\n");
        }
    }
    if ($stale !== []) {
        fwrite(
            STDERR,
            'These baseline entries no longer violate anything and must be deleted '
            . "so the baseline only ever shrinks:\n",
        );
        foreach ($stale as $entry) {
            fwrite(STDERR, '  - ' . $entry . "\n");
        }
    }
    if ($hardFails !== []) {
        fwrite(STDERR, sprintf("%d documentation violation(s) outside the baseline.\n", count($hardFails)));
    }

    return ($hardFails === [] && $stale === [] && $structural === []) ? 0 : 1;
}
