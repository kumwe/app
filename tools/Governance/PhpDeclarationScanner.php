<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Governance;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Tokenizer-based scanner of PHP source: declared class-likes, their public surface and every name they reference.
 *
 * Built on `token_get_all()` so that comments and string literals never masquerade as declarations or
 * references. For each file it reports the namespace, the `use` imports, each declared class, interface,
 * trait or enum with its modifiers, parent, interfaces, `@internal` marker, public constants, public
 * properties (promoted constructor properties included), public methods with typed parameters and return
 * types, and enum cases. It also reports every referenced fully qualified name after import resolution, and
 * the string literals separately, so a namespace-reference check can decide what a string literal means.
 *
 * One instance scans one file and carries the cursor while it does; the public entry points are static.
 *
 * @since  2.0.0
 */
final class PhpDeclarationScanner
{
    /**
     * Words that name built-in types or the current class rather than a class reference.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const BUILTIN = [
        'self', 'static', 'parent', 'int', 'float', 'string', 'bool', 'array', 'iterable', 'callable', 'object',
        'mixed', 'void', 'never', 'null', 'false', 'true',
    ];

    /**
     * Token stream of the file.
     *
     * @var    list<array{0: int, 1: string, 2: int}|string>
     * @since  2.0.0
     */
    private array $tokens;

    /**
     * Number of tokens.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $count;

    /**
     * Cursor into the token stream.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $index = 0;

    /**
     * Current brace depth.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $depth = 0;

    /**
     * Current namespace without a leading backslash.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $namespace = '';

    /**
     * Brace depth at which file-level statements of the current namespace sit.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $namespaceDepth = 0;

    /**
     * Class imports of the current namespace, alias to fully qualified name.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private array $imports = [];

    /**
     * The most recent file-level documentation block not yet attached to a declaration.
     *
     * @var    string|null
     * @since  2.0.0
     */
    private ?string $pendingDoc = null;

    /**
     * Declarations found so far.
     *
     * @var    list<array<string, mixed>>
     * @since  2.0.0
     */
    private array $declarations = [];

    /**
     * Index of the declaration whose body is open, or null outside a class-like body.
     *
     * @var    int|null
     * @since  2.0.0
     */
    private ?int $current = null;

    /**
     * Brace depth at which the open declaration was declared.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $currentDepth = -1;

    /**
     * Header state of a declaration whose `extends`/`implements` list is still being read.
     *
     * @var    array{mode: string|null, declaration: int|null}|null
     * @since  2.0.0
     */
    private ?array $header = null;

    /**
     * Modifier tokens seen since the last member boundary.
     *
     * @var    list<int>
     * @since  2.0.0
     */
    private array $buffer = [];

    /**
     * Whether the next name token is a class reference because of the token before it.
     *
     * @var    bool
     * @since  2.0.0
     */
    private bool $expectClass = false;

    /**
     * Open attribute groups with their bracket and parenthesis depths.
     *
     * @var    list<array{brackets: int, parens: int}>
     * @since  2.0.0
     */
    private array $attributes = [];

    /**
     * Referenced names keyed by name and line.
     *
     * @var    array<string, array{name: string, line: int}>
     * @since  2.0.0
     */
    private array $references = [];

    /**
     * String literals with their lines.
     *
     * @var    list<array{value: string, line: int}>
     * @since  2.0.0
     */
    private array $strings = [];

    /**
     * Tokenize one source text.
     *
     * @param  string  $source  Complete PHP source.
     * @param  string  $file    Display path reported in the result.
     *
     * @since  2.0.0
     */
    private function __construct(string $source, private readonly string $file)
    {
        $this->tokens = token_get_all($source);
        $this->count = count($this->tokens);
    }

    /**
     * Scan one PHP file.
     *
     * @param   string       $path     Absolute path of the file.
     * @param   string|null  $display  Path reported in the result; defaults to the given path.
     *
     * @return  array{file: string, namespace: string, imports: array<string, string>,
     *          declarations: list<array<string, mixed>>, references: list<array{name: string, line: int}>,
     *          strings: list<array{value: string, line: int}>}  The scan.
     *
     * @throws  GovernanceViolation  When the file cannot be read.
     *
     * @since   2.0.0
     */
    public static function scanFile(string $path, ?string $display = null): array
    {
        $source = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($source)) {
            throw GovernanceViolation::at($display ?? $path, 'the PHP file cannot be read', 'restore the file');
        }

        return self::scanSource($source, $display ?? $path);
    }

    /**
     * Scan PHP source held in memory.
     *
     * @param   string  $source  Complete PHP source.
     * @param   string  $file    Path reported in the result.
     *
     * @return  array{file: string, namespace: string, imports: array<string, string>,
     *          declarations: list<array<string, mixed>>, references: list<array{name: string, line: int}>,
     *          strings: list<array{value: string, line: int}>}  The scan.
     *
     * @since   2.0.0
     */
    public static function scanSource(string $source, string $file): array
    {
        return (new self($source, $file))->run();
    }

    /**
     * Scan every PHP file below a directory, in path order.
     *
     * @param   string  $root     Absolute directory to walk.
     * @param   string  $display  Prefix for the reported file paths, without a trailing slash.
     *
     * @return  list<array{file: string, namespace: string, imports: array<string, string>,
     *          declarations: list<array<string, mixed>>, references: list<array{name: string, line: int}>,
     *          strings: list<array{value: string, line: int}>}>  One scan per file, sorted by path.
     *
     * @throws  GovernanceViolation  When the directory is missing or a file cannot be read.
     *
     * @since   2.0.0
     */
    public static function scanTree(string $root, string $display): array
    {
        if (!is_dir($root)) {
            throw GovernanceViolation::at($display, 'the source directory is missing', 'restore the tree');
        }
        $files = [];
        /** @var iterable<string, SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($entries as $entry) {
            if (!$entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
            $files[$relative] = $entry->getPathname();
        }
        ksort($files, SORT_STRING);

        $scans = [];
        foreach ($files as $relative => $path) {
            $scans[] = self::scanFile($path, $display === '' ? $relative : $display . '/' . $relative);
        }

        return $scans;
    }

    /**
     * Walk the token stream once.
     *
     * @return  array{file: string, namespace: string, imports: array<string, string>,
     *          declarations: list<array<string, mixed>>, references: list<array{name: string, line: int}>,
     *          strings: list<array{value: string, line: int}>}  The scan.
     *
     * @since   2.0.0
     */
    private function run(): array
    {
        for ($this->index = 0; $this->index < $this->count; $this->index++) {
            $token = $this->tokens[$this->index];
            if (is_string($token)) {
                $this->punctuation($token);
                continue;
            }
            [$id, $text, $line] = $token;
            if ($id === T_WHITESPACE || $id === T_COMMENT) {
                continue;
            }
            if ($id === T_DOC_COMMENT) {
                if ($this->atFileLevel()) {
                    $this->pendingDoc = $text;
                }
                continue;
            }
            $expect = $this->expectClass;
            $this->expectClass = false;
            $this->token($id, $text, $line, $expect);
        }

        foreach ($this->declarations as &$declaration) {
            sort($declaration['constants'], SORT_STRING);
            ksort($declaration['properties'], SORT_STRING);
            ksort($declaration['methods'], SORT_STRING);
        }
        unset($declaration);

        $references = array_values($this->references);
        usort($references, static fn (array $left, array $right): int => [$left['line'], $left['name']]
            <=> [$right['line'], $right['name']]);

        return [
            'file' => $this->file,
            'namespace' => $this->namespace,
            'imports' => $this->imports,
            'declarations' => $this->declarations,
            'references' => $references,
            'strings' => $this->strings,
        ];
    }

    /**
     * Handle one significant token.
     *
     * @param   int     $id      Token identifier.
     * @param   string  $text    Token text.
     * @param   int     $line    Token line.
     * @param   bool    $expect  Whether the preceding token made a name here a class reference.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function token(int $id, string $text, int $line, bool $expect): void
    {
        switch ($id) {
            case T_CONSTANT_ENCAPSED_STRING:
                $this->strings[] = ['value' => substr($text, 1, -1), 'line' => $line];
                break;
            case T_ENCAPSED_AND_WHITESPACE:
                $this->strings[] = ['value' => $text, 'line' => $line];
                break;
            case T_NAMESPACE:
                $this->namespaceStatement();
                break;
            case T_USE:
                if ($this->atFileLevel()) {
                    $this->importStatement();
                } elseif ($this->atMemberLevel()) {
                    $this->traitUse();
                }
                break;
            case T_ATTRIBUTE:
                $this->attributes[] = ['brackets' => 1, 'parens' => 0];
                $this->expectClass = true;
                break;
            case T_CLASS:
            case T_INTERFACE:
            case T_TRAIT:
            case T_ENUM:
                if (!$this->previousIs(T_DOUBLE_COLON)) {
                    $this->classLike($id, $line, $this->previousIs(T_NEW));
                }
                break;
            case T_EXTENDS:
            case T_IMPLEMENTS:
                if ($this->header !== null) {
                    $this->header['mode'] = $id === T_EXTENDS ? 'extends' : 'implements';
                }
                $this->expectClass = true;
                break;
            case T_FUNCTION:
            case T_FN:
                $this->functionDeclaration($id === T_FN);
                break;
            case T_CONST:
                if ($this->atMemberLevel()) {
                    $this->constantDeclaration();
                }
                break;
            case T_CASE:
                if ($this->atMemberLevel() && $this->current !== null
                    && $this->declarations[$this->current]['kind'] === 'enum'
                ) {
                    $this->enumCase();
                }
                break;
            case T_VARIABLE:
                if ($this->atMemberLevel()) {
                    $this->propertyDeclaration();
                }
                break;
            case T_CATCH:
                $this->catchClause();
                break;
            case T_NEW:
            case T_INSTANCEOF:
                $this->expectClass = true;
                break;
            case T_CURLY_OPEN:
            case T_DOLLAR_OPEN_CURLY_BRACES:
                $this->depth++;
                break;
            case T_ABSTRACT:
            case T_FINAL:
            case T_READONLY:
            case T_PUBLIC:
            case T_PROTECTED:
            case T_PRIVATE:
            case T_STATIC:
            case T_VAR:
            case T_PUBLIC_SET:
            case T_PROTECTED_SET:
            case T_PRIVATE_SET:
                if ($this->atMemberLevel() || $this->atFileLevel()) {
                    $this->buffer[] = $id;
                }
                break;
            case T_ARRAY:
            case T_CALLABLE:
                if ($this->atMemberLevel() && $this->buffer !== []) {
                    $this->propertyDeclaration();
                }
                break;
            case T_STRING:
            case T_NAME_QUALIFIED:
            case T_NAME_FULLY_QUALIFIED:
            case T_NAME_RELATIVE:
                if ($this->atMemberLevel() && $this->buffer !== [] && $this->header === null) {
                    $this->propertyDeclaration();
                    break;
                }
                $this->nameToken($id, $text, $line, $expect);
                break;
            default:
                break;
        }
    }

    /**
     * Handle a single-character token.
     *
     * @param   string  $token  The character.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function punctuation(string $token): void
    {
        $this->expectClass = false;
        switch ($token) {
            case '{':
                if ($this->atFileLevel()) {
                    $this->pendingDoc = null;
                }
                $this->depth++;
                $this->header = null;
                $this->buffer = [];
                break;
            case '}':
                $this->depth--;
                if ($this->current !== null && $this->depth === $this->currentDepth) {
                    $this->current = null;
                    $this->currentDepth = -1;
                }
                if ($this->depth < $this->namespaceDepth) {
                    $this->namespace = '';
                    $this->namespaceDepth = 0;
                    $this->imports = [];
                }
                $this->buffer = [];
                break;
            case ';':
                if ($this->atFileLevel()) {
                    $this->pendingDoc = null;
                }
                $this->buffer = [];
                break;
            case ',':
                if ($this->header !== null && $this->header['mode'] !== null) {
                    $this->expectClass = true;
                }
                if ($this->attributes !== []) {
                    $group = $this->attributes[count($this->attributes) - 1];
                    if ($group['brackets'] === 1 && $group['parens'] === 0) {
                        $this->expectClass = true;
                    }
                }
                break;
            case '?':
                if ($this->atMemberLevel() && $this->buffer !== [] && $this->header === null) {
                    $this->propertyDeclaration();
                }
                break;
            case '(':
            case ')':
            case '[':
            case ']':
                $this->attributeBracket($token);
                break;
            default:
                break;
        }
    }

    /**
     * Track brackets inside an open attribute group.
     *
     * @param   string  $token  One of the four bracket characters.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function attributeBracket(string $token): void
    {
        if ($this->attributes === []) {
            return;
        }
        $last = count($this->attributes) - 1;
        match ($token) {
            '(' => $this->attributes[$last]['parens']++,
            ')' => $this->attributes[$last]['parens']--,
            '[' => $this->attributes[$last]['brackets']++,
            ']' => $this->attributes[$last]['brackets']--,
        };
        if ($this->attributes[$last]['brackets'] === 0) {
            array_pop($this->attributes);
        }
    }

    /**
     * Record a name token as a class reference when its position says it is one.
     *
     * @param   int     $id      Token identifier.
     * @param   string  $text    Token text.
     * @param   int     $line    Token line.
     * @param   bool    $expect  Whether the preceding token made this a class reference.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function nameToken(int $id, string $text, int $line, bool $expect): void
    {
        $next = $this->peek($this->index);
        $beforeScope = is_array($next) && $next[0] === T_DOUBLE_COLON;
        if ($id === T_STRING) {
            if (in_array(strtolower($text), self::BUILTIN, true)) {
                return;
            }
            if (!$expect && !$beforeScope) {
                return;
            }
        } elseif (!$expect && !$beforeScope && $next === '(') {
            return;
        }

        $name = $this->resolve($id, $text);
        $this->reference($name, $line);
        $header = $this->header;
        if ($expect && $header !== null && $header['mode'] !== null && $header['declaration'] !== null) {
            $declaration = &$this->declarations[$header['declaration']];
            if ($header['mode'] === 'extends' && $declaration['kind'] === 'class') {
                $declaration['parent'] = $name;
            } else {
                $declaration['interfaces'][] = $name;
            }
            unset($declaration);
        }
    }

    /**
     * Read a `namespace` statement.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function namespaceStatement(): void
    {
        $name = '';
        $cursor = $this->index;
        $next = $this->peek($cursor, $cursor);
        if (is_array($next) && in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true)) {
            $name = $next[1];
            $next = $this->peek($cursor, $cursor);
        }
        if ($next === '{') {
            $this->namespace = $name;
            $this->depth++;
            $this->namespaceDepth = $this->depth;
            $this->imports = [];
            $this->pendingDoc = null;
            $this->index = $cursor;

            return;
        }
        $this->namespace = $name;
        $this->namespaceDepth = $this->depth;
        $this->imports = [];
        $this->index = $cursor;
        $this->pendingDoc = null;
        $this->buffer = [];
    }

    /**
     * Read a file-level `use` statement, ignoring function and constant imports.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function importStatement(): void
    {
        $cursor = $this->index;
        $token = $this->peek($cursor, $cursor);
        if (is_array($token) && ($token[0] === T_FUNCTION || $token[0] === T_CONST)) {
            $this->skipTo([';'], $cursor);
            $this->index = $cursor - 1;

            return;
        }

        while ($cursor < $this->count) {
            if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                break;
            }
            $name = ltrim($token[1], '\\');
            $line = $token[2];
            $token = $this->peek($cursor, $cursor);
            if (is_array($token) && $token[0] === T_NS_SEPARATOR) {
                $token = $this->peek($cursor, $cursor);
                if ($token === '{') {
                    $this->groupImport($name, $cursor);
                    $token = $this->peek($cursor, $cursor);
                }
            } elseif (is_array($token) && $token[0] === T_AS) {
                $alias = $this->peek($cursor, $cursor);
                $this->import(is_array($alias) ? $alias[1] : $name, $name, $line);
                $token = $this->peek($cursor, $cursor);
            } else {
                $this->import(self::shortName($name), $name, $line);
            }
            if ($token === ',') {
                $token = $this->peek($cursor, $cursor);
                continue;
            }
            break;
        }
        $this->skipTo([';'], $cursor);
        $this->index = $cursor - 1;
    }

    /**
     * Read the members of a group import `use Prefix\{A, B as C}`.
     *
     * @param   string  $prefix  Namespace prefix before the brace.
     * @param   int     $cursor  Index of the opening brace, advanced to the closing brace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function groupImport(string $prefix, int &$cursor): void
    {
        $skipping = false;
        while ($cursor < $this->count) {
            $token = $this->peek($cursor, $cursor);
            if ($token === '}' || $token === null) {
                return;
            }
            if ($token === ',') {
                $skipping = false;
                continue;
            }
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_FUNCTION || $token[0] === T_CONST) {
                $skipping = true;
                continue;
            }
            if ($skipping || !in_array($token[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                continue;
            }
            $name = $prefix . '\\' . $token[1];
            $alias = self::shortName($name);
            $following = $this->peek($cursor);
            if (is_array($following) && $following[0] === T_AS) {
                $this->peek($cursor, $cursor);
                $aliasToken = $this->peek($cursor, $cursor);
                $alias = is_array($aliasToken) ? $aliasToken[1] : $alias;
            }
            $this->import($alias, $name, $token[2]);
        }
    }

    /**
     * Record one class import.
     *
     * @param   string  $alias  Name the file uses.
     * @param   string  $name   Fully qualified name without a leading backslash.
     * @param   int     $line   Line of the import.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function import(string $alias, string $name, int $line): void
    {
        $this->imports[$alias] = $name;
        $this->reference($name, $line);
    }

    /**
     * Read a `use Trait;` statement inside a class body, including a conflict-resolution block.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function traitUse(): void
    {
        $cursor = $this->index;
        while ($cursor < $this->count) {
            $token = $this->peek($cursor, $cursor);
            if ($token === null || $token === ';') {
                break;
            }
            if ($token === '{') {
                $this->skipBalanced('{', '}', $cursor);
                break;
            }
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $this->reference($this->resolve($token[0], $token[1]), $token[2]);
            }
        }
        $this->index = $cursor;
        $this->buffer = [];
    }

    /**
     * Register a class-like declaration and open its header.
     *
     * @param   int   $id         Declaring keyword token.
     * @param   int   $line       Line of the keyword.
     * @param   bool  $anonymous  Whether this is `new class`, which declares nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function classLike(int $id, int $line, bool $anonymous): void
    {
        $modifiers = $this->buffer;
        $this->buffer = [];
        if ($anonymous) {
            $this->header = ['mode' => null, 'declaration' => null];

            return;
        }
        $cursor = $this->index;
        $name = $this->peek($cursor, $cursor);
        if (!is_array($name) || $name[0] !== T_STRING) {
            return;
        }
        $this->index = $cursor;
        $fqcn = $this->namespace === '' ? $name[1] : $this->namespace . '\\' . $name[1];
        $internal = $this->pendingDoc !== null && preg_match('/(?<![\w@])@internal\b/', $this->pendingDoc) === 1;
        $this->pendingDoc = null;

        $this->declarations[] = [
            'fqcn' => $fqcn,
            'short_name' => $name[1],
            'kind' => match ($id) {
                T_INTERFACE => 'interface',
                T_TRAIT => 'trait',
                T_ENUM => 'enum',
                default => 'class',
            },
            'final' => in_array(T_FINAL, $modifiers, true),
            'abstract' => in_array(T_ABSTRACT, $modifiers, true),
            'readonly' => in_array(T_READONLY, $modifiers, true),
            'parent' => null,
            'interfaces' => [],
            'internal' => $internal,
            'line' => $line,
            'constants' => [],
            'properties' => [],
            'methods' => [],
            'cases' => [],
        ];
        $this->current = count($this->declarations) - 1;
        $this->currentDepth = $this->depth;
        $this->header = ['mode' => null, 'declaration' => $this->current];
    }

    /**
     * Read a function, method, closure or arrow-function signature.
     *
     * @param   bool  $arrow  Whether the keyword was `fn`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function functionDeclaration(bool $arrow): void
    {
        $member = $this->atMemberLevel();
        $modifiers = $this->buffer;
        $this->buffer = [];
        $cursor = $this->index;
        $token = $this->peek($cursor, $cursor);
        $ampersands = [T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG];
        if (is_array($token) && in_array($token[0], $ampersands, true)) {
            $token = $this->peek($cursor, $cursor);
        }
        $name = null;
        if (is_array($token) && $this->peek($cursor) === '(') {
            $name = $token[1];
            $token = $this->peek($cursor, $cursor);
        }
        if ($token !== '(') {
            $this->index = $cursor - 1;

            return;
        }
        $parameters = [];
        $promoted = [];
        $this->parameters($cursor, $parameters, $promoted);

        $token = $this->peek($cursor);
        if (is_array($token) && $token[0] === T_USE) {
            $this->peek($cursor, $cursor);
            $this->peek($cursor, $cursor);
            $this->skipBalanced('(', ')', $cursor);
            $token = $this->peek($cursor);
        }
        $return = null;
        if ($token === ':') {
            $this->peek($cursor, $cursor);
            $return = $this->type($cursor, ['{', ';', T_DOUBLE_ARROW, ','], $arrow);
        }
        $this->index = $cursor;

        if (!$member || $name === null) {
            return;
        }
        $public = !in_array(T_PROTECTED, $modifiers, true) && !in_array(T_PRIVATE, $modifiers, true);
        if (!$public || $this->current === null) {
            return;
        }
        $declaration = &$this->declarations[$this->current];
        $declaration['methods'][$name] = [
            'static' => in_array(T_STATIC, $modifiers, true),
            'parameters' => $parameters,
            'return' => $return,
        ];
        if ($name === '__construct') {
            foreach ($promoted as $property => $facts) {
                $declaration['properties'][$property] = $facts;
            }
        }
        unset($declaration);
    }

    /**
     * Read a parameter list from the opening parenthesis to the closing one.
     *
     * @param int $cursor Index of `(`, advanced past `)`.
     * @param   list<array{name: string, type: string|null, optional: bool, variadic: bool,
     *          by_reference: bool}>                                $parameters  Parameters in order.
     * @param   array<string, array{type: string|null, static: bool, readonly: bool}>  $promoted
     *          Promoted public properties by name.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function parameters(int &$cursor, array &$parameters, array &$promoted): void
    {
        while ($cursor < $this->count) {
            $token = $this->peek($cursor);
            if ($token === null || $token === ')') {
                $this->peek($cursor, $cursor);

                return;
            }
            if (is_array($token) && $token[0] === T_ATTRIBUTE) {
                $this->peek($cursor, $cursor);
                $this->attributeGroup($cursor);
                continue;
            }
            $modifiers = [];
            while (is_array($token) && in_array($token[0], [
                T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY, T_FINAL, T_PUBLIC_SET, T_PROTECTED_SET, T_PRIVATE_SET,
            ], true)) {
                $modifiers[] = $token[0];
                $this->peek($cursor, $cursor);
                $token = $this->peek($cursor);
            }
            $type = $this->type($cursor, [T_VARIABLE, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_ELLIPSIS], false);
            $byReference = false;
            $variadic = false;
            $token = $this->peek($cursor, $cursor);
            if (is_array($token) && $token[0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG) {
                $byReference = true;
                $token = $this->peek($cursor, $cursor);
            }
            if (is_array($token) && $token[0] === T_ELLIPSIS) {
                $variadic = true;
                $token = $this->peek($cursor, $cursor);
            }
            if (!is_array($token) || $token[0] !== T_VARIABLE) {
                $this->skipTo([')'], $cursor);

                return;
            }
            $name = substr($token[1], 1);
            $optional = false;
            $token = $this->peek($cursor);
            if ($token === '=') {
                $optional = true;
                $this->peek($cursor, $cursor);
                $this->expression($cursor, [',', ')']);
                $token = $this->peek($cursor);
            }
            $parameters[] = [
                'name' => $name,
                'type' => $type,
                'optional' => $optional,
                'variadic' => $variadic,
                'by_reference' => $byReference,
            ];
            $hidden = in_array(T_PROTECTED, $modifiers, true) || in_array(T_PRIVATE, $modifiers, true);
            $promotedPublic = in_array(T_PUBLIC, $modifiers, true) || ($modifiers !== [] && !$hidden);
            if ($promotedPublic) {
                $promoted[$name] = ['type' => $type, 'static' => false, 'readonly' => in_array(
                    T_READONLY,
                    $modifiers,
                    true,
                )];
            }
            if ($token === ',') {
                $this->peek($cursor, $cursor);
            }
        }
    }

    /**
     * Read a type declaration up to a terminator, resolving class names and recording them as references.
     *
     * @param   int               $cursor       Index before the first type token, advanced to the terminator.
     * @param   list<int|string>  $terminators  Tokens that end the type; the cursor stops before them.
     * @param   bool              $arrow        Whether `=>` ends the type, for arrow functions.
     *
     * @return  string|null  The rendered type, or null when no type is declared.
     *
     * @since   2.0.0
     */
    private function type(int &$cursor, array $terminators, bool $arrow): ?string
    {
        $rendered = '';
        while ($cursor < $this->count) {
            $token = $this->peek($cursor);
            if ($token === null) {
                break;
            }
            $id = is_array($token) ? $token[0] : $token;
            if (in_array($id, $terminators, true) || ($arrow && $id === T_DOUBLE_ARROW)) {
                break;
            }
            if (is_string($token)) {
                if (!in_array($token, ['?', '|', '(', ')'], true)) {
                    break;
                }
                $rendered .= $token;
                $this->peek($cursor, $cursor);
                continue;
            }
            switch ($token[0]) {
                case T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG:
                    $rendered .= '&';
                    break;
                case T_ARRAY:
                case T_CALLABLE:
                case T_STATIC:
                    $rendered .= strtolower($token[1]);
                    break;
                case T_STRING:
                    if (in_array(strtolower($token[1]), self::BUILTIN, true)) {
                        $rendered .= strtolower($token[1]);
                        break;
                    }
                    $name = $this->resolve(T_STRING, $token[1]);
                    $this->reference($name, $token[2]);
                    $rendered .= $name;
                    break;
                case T_NAME_QUALIFIED:
                case T_NAME_FULLY_QUALIFIED:
                case T_NAME_RELATIVE:
                    $name = $this->resolve($token[0], $token[1]);
                    $this->reference($name, $token[2]);
                    $rendered .= $name;
                    break;
                default:
                    return $rendered === '' ? null : $rendered;
            }
            $this->peek($cursor, $cursor);
        }

        return $rendered === '' ? null : $rendered;
    }

    /**
     * Read a class constant declaration, registering its public names.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function constantDeclaration(): void
    {
        $modifiers = $this->buffer;
        $this->buffer = [];
        $public = !in_array(T_PROTECTED, $modifiers, true) && !in_array(T_PRIVATE, $modifiers, true);
        $cursor = $this->index;
        while ($cursor < $this->count) {
            $names = [];
            while (true) {
                $token = $this->peek($cursor, $cursor);
                if ($token === null || $token === '=' || $token === ';') {
                    break;
                }
                if (is_array($token)) {
                    $names[] = $token;
                }
            }
            $last = $names === [] ? null : $names[count($names) - 1];
            foreach (array_slice($names, 0, -1) as $typeToken) {
                $qualified = in_array($typeToken[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true);
                $plain = $typeToken[0] === T_STRING && !in_array(strtolower($typeToken[1]), self::BUILTIN, true);
                if ($qualified || $plain) {
                    $this->reference($this->resolve($typeToken[0], $typeToken[1]), $typeToken[2]);
                }
            }
            if ($last !== null && $public && $this->current !== null) {
                $this->declarations[$this->current]['constants'][] = $last[1];
            }
            if ($token !== '=') {
                break;
            }
            $this->expression($cursor, [',', ';']);
            $token = $this->peek($cursor, $cursor);
            if ($token !== ',') {
                break;
            }
        }
        $this->index = $cursor;
    }

    /**
     * Read a property declaration starting at its type or variable, registering public names.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function propertyDeclaration(): void
    {
        $modifiers = $this->buffer;
        $this->buffer = [];
        $public = in_array(T_PUBLIC, $modifiers, true) || in_array(T_VAR, $modifiers, true);
        $cursor = $this->index - 1;
        $type = $this->type($cursor, [T_VARIABLE], false);
        while ($cursor < $this->count) {
            $token = $this->peek($cursor, $cursor);
            if (!is_array($token) || $token[0] !== T_VARIABLE) {
                break;
            }
            if ($public && $this->current !== null) {
                $this->declarations[$this->current]['properties'][substr($token[1], 1)] = [
                    'type' => $type,
                    'static' => in_array(T_STATIC, $modifiers, true),
                    'readonly' => in_array(T_READONLY, $modifiers, true),
                ];
            }
            $token = $this->peek($cursor);
            if ($token === '=') {
                $this->peek($cursor, $cursor);
                $this->expression($cursor, [',', ';', '{']);
                $token = $this->peek($cursor);
            }
            if ($token === '{') {
                $this->peek($cursor, $cursor);
                $this->skipBalanced('{', '}', $cursor);
                break;
            }
            if ($token === ',') {
                $this->peek($cursor, $cursor);
                continue;
            }
            break;
        }
        $this->index = $cursor;
    }

    /**
     * Read an enum case.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function enumCase(): void
    {
        $cursor = $this->index;
        $token = $this->peek($cursor, $cursor);
        if (is_array($token) && $this->current !== null) {
            $this->declarations[$this->current]['cases'][] = $token[1];
        }
        $this->expression($cursor, [';']);
        $this->index = $cursor;
    }

    /**
     * Read the exception types of a `catch` clause.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function catchClause(): void
    {
        $cursor = $this->index;
        if ($this->peek($cursor, $cursor) !== '(') {
            return;
        }
        $this->type($cursor, [T_VARIABLE, ')'], false);
        $this->index = $cursor;
    }

    /**
     * Skip an expression while recording the class references it contains.
     *
     * @param   int           $cursor       Index before the expression, advanced to before the terminator.
     * @param   list<string>  $terminators  Characters that end the expression at nesting depth zero.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function expression(int &$cursor, array $terminators): void
    {
        $nesting = 0;
        $expect = false;
        while ($cursor < $this->count) {
            $at = $cursor;
            $token = $this->peek($cursor, $at);
            if ($token === null) {
                return;
            }
            if (is_string($token)) {
                if ($nesting === 0 && in_array($token, $terminators, true)) {
                    return;
                }
                if (in_array($token, ['(', '[', '{'], true)) {
                    $nesting++;
                } elseif (in_array($token, [')', ']', '}'], true)) {
                    $nesting--;
                }
                $expect = false;
                $cursor = $at;
                continue;
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $this->strings[] = ['value' => substr($token[1], 1, -1), 'line' => $token[2]];
            }
            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                $following = $this->peek($at);
                $scoped = is_array($following) && $following[0] === T_DOUBLE_COLON;
                $builtin = $token[0] === T_STRING && in_array(strtolower($token[1]), self::BUILTIN, true);
                if (!$builtin && ($expect || $scoped)) {
                    $this->reference($this->resolve($token[0], $token[1]), $token[2]);
                }
            }
            $expect = $token[0] === T_NEW || $token[0] === T_INSTANCEOF;
            $cursor = $at;
        }
    }

    /**
     * Skip an attribute group inside a parameter list, recording its attribute names.
     *
     * @param   int  $cursor  Index of the `#[` token, advanced past the closing bracket.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function attributeGroup(int &$cursor): void
    {
        $brackets = 1;
        $parens = 0;
        $expect = true;
        while ($cursor < $this->count && $brackets > 0) {
            $token = $this->peek($cursor, $cursor);
            if ($token === null) {
                return;
            }
            if (is_string($token)) {
                match ($token) {
                    '[' => $brackets++,
                    ']' => $brackets--,
                    '(' => $parens++,
                    ')' => $parens--,
                    default => null,
                };
                $expect = $token === ',' && $brackets === 1 && $parens === 0;
                continue;
            }
            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                $following = $this->peek($cursor);
                if ($expect || (is_array($following) && $following[0] === T_DOUBLE_COLON)) {
                    $this->reference($this->resolve($token[0], $token[1]), $token[2]);
                }
            }
            $expect = false;
        }
    }

    /**
     * Advance a cursor from an opening bracket to its matching closing bracket.
     *
     * @param   string  $open    Opening character.
     * @param   string  $close   Closing character.
     * @param   int     $cursor  Index of the opener, advanced to the matching closer.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function skipBalanced(string $open, string $close, int &$cursor): void
    {
        $nesting = 0;
        while ($cursor < $this->count) {
            $token = $this->tokens[$cursor];
            if ($token === $open) {
                $nesting++;
            } elseif ($token === $close) {
                $nesting--;
                if ($nesting === 0) {
                    return;
                }
            }
            $cursor++;
        }
    }

    /**
     * Advance a cursor to the first of the given characters.
     *
     * @param   list<string>  $characters  Characters that stop the scan.
     * @param   int           $cursor      Index to advance; it stops on the character.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function skipTo(array $characters, int &$cursor): void
    {
        while ($cursor < $this->count && !in_array($this->tokens[$cursor], $characters, true)) {
            $cursor++;
        }
    }

    /**
     * Look at the next significant token after an index.
     *
     * @param   int       $from     Index to look after.
     * @param   int|null  $advance  When given, receives the index of the returned token.
     *
     * @return  array{0: int, 1: string, 2: int}|string|null  The token, or null at the end of the stream.
     *
     * @since   2.0.0
     */
    private function peek(int $from, ?int &$advance = null): array|string|null
    {
        for ($cursor = $from + 1; $cursor < $this->count; $cursor++) {
            $token = $this->tokens[$cursor];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (func_num_args() > 1) {
                $advance = $cursor;
            }

            return $token;
        }
        if (func_num_args() > 1) {
            $advance = $this->count;
        }

        return null;
    }

    /**
     * Decide whether the previous significant token has a given identifier.
     *
     * @param   int  $id  Token identifier.
     *
     * @return  bool  True when the nearest non-whitespace token before the cursor matches.
     *
     * @since   2.0.0
     */
    private function previousIs(int $id): bool
    {
        for ($cursor = $this->index - 1; $cursor >= 0; $cursor--) {
            $token = $this->tokens[$cursor];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) && $token[0] === $id;
        }

        return false;
    }

    /**
     * Resolve a name token to a fully qualified name through the imports and the namespace.
     *
     * @param   int     $id    Token identifier.
     * @param   string  $text  Token text.
     *
     * @return  string  Fully qualified name without a leading backslash.
     *
     * @since   2.0.0
     */
    private function resolve(int $id, string $text): string
    {
        if ($id === T_NAME_FULLY_QUALIFIED) {
            return ltrim($text, '\\');
        }
        if ($id === T_NAME_RELATIVE) {
            $rest = substr($text, strlen('namespace\\'));

            return $this->namespace === '' ? $rest : $this->namespace . '\\' . $rest;
        }
        $segments = explode('\\', $text);
        if (isset($this->imports[$segments[0]])) {
            $segments[0] = $this->imports[$segments[0]];

            return implode('\\', $segments);
        }

        return $this->namespace === '' ? $text : $this->namespace . '\\' . $text;
    }

    /**
     * Record a resolved reference.
     *
     * @param   string  $name  Fully qualified name.
     * @param   int     $line  Line of the reference.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function reference(string $name, int $line): void
    {
        $this->references[$name . "\0" . $line] = ['name' => $name, 'line' => $line];
    }

    /**
     * Decide whether the cursor sits directly inside the open declaration's body.
     *
     * @return  bool  True at member level.
     *
     * @since   2.0.0
     */
    private function atMemberLevel(): bool
    {
        return $this->current !== null && $this->depth === $this->currentDepth + 1;
    }

    /**
     * Decide whether the cursor sits at statement level of the current namespace.
     *
     * @return  bool  True at file level.
     *
     * @since   2.0.0
     */
    private function atFileLevel(): bool
    {
        return $this->current === null && $this->depth === $this->namespaceDepth;
    }

    /**
     * The last segment of a qualified name.
     *
     * @param   string  $name  Fully qualified name.
     *
     * @return  string  The short name.
     *
     * @since   2.0.0
     */
    private static function shortName(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }
}
