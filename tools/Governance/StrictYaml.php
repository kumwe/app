<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Governance;

/**
 * Reads the YAML subset every Version 2 governance record is written in, and nothing beyond it.
 *
 * The subset is deliberately small so that a record has exactly one reading and no parser feature can smuggle
 * a value past a schema: block mappings and block sequences with two-space indentation, plain and quoted
 * scalars, the empty flow collections `[]` and `{}`, and `#` comments. Multi-line scalars, anchors, aliases,
 * tags, flow collections with content and multiple documents are refused with the line that carries them.
 * Front matter is the same subset between a leading `---` fence and the next `---` line.
 *
 * @since  2.0.0
 */
final readonly class StrictYaml
{
    /**
     * Keys are bare identifiers, or double-quoted when they need other characters.
     *
     * @var    string
     * @since  2.0.0
     */
    private const KEY_PATTERN = '/^(?:"((?:[^"\\\\]|\\\\.)*)"|([A-Za-z0-9_.\/-]+)):(?:[ ]+(.*))?$/';

    /**
     * Parse a complete YAML document into a mapping.
     *
     * @param   string  $yaml        Document bytes.
     * @param   string  $file        Path reported in violations.
     * @param   int     $lineOffset  Lines preceding the document in its file, for front matter.
     *
     * @return  array<string, mixed>  The top-level mapping; an empty document is an empty mapping.
     *
     * @throws  GovernanceViolation  When the document uses anything outside the subset.
     *
     * @since   2.0.0
     */
    public static function parse(string $yaml, string $file = 'yaml', int $lineOffset = 0): array
    {
        $lines = self::lines($yaml, $file, $lineOffset);
        if ($lines === []) {
            return [];
        }

        $index = 0;
        $value = self::node($lines, $index, 0, $file);
        if ($index < count($lines)) {
            throw self::violation(
                $file,
                $lines[$index]['line'],
                'content after the top-level block is not indented under a key',
            );
        }
        if (array_is_list($value) && $value !== []) {
            throw self::violation($file, $lines[0]['line'], 'the top-level node must be a mapping, not a sequence');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Split a markdown record into its YAML front matter and its body.
     *
     * @param   string  $markdown  Complete file bytes, starting with a `---` fence line.
     * @param   string  $file      Path reported in violations.
     *
     * @return  array{front_matter: array<string, mixed>, body: string}  The parsed fence and the text after it.
     *
     * @throws  GovernanceViolation  When either fence is missing or the front matter leaves the subset.
     *
     * @since   2.0.0
     */
    public static function parseFrontMatter(string $markdown, string $file = 'markdown'): array
    {
        $opening = null;
        foreach (["---\r\n", "---\n"] as $fence) {
            if (str_starts_with($markdown, $fence)) {
                $opening = strlen($fence);
                break;
            }
        }
        if ($opening === null) {
            throw GovernanceViolation::at(
                $file,
                'front matter must start on line 1 with a `---` fence',
                'begin the file with `---`, the YAML record, and a closing `---` line',
            );
        }

        $closing = null;
        $closingLength = 0;
        foreach (["\n---\r\n", "\n---\n"] as $fence) {
            $position = strpos($markdown, $fence, $opening - 1);
            if ($position !== false && ($closing === null || $position < $closing)) {
                $closing = $position;
                $closingLength = strlen($fence);
            }
        }
        if ($closing === null && str_ends_with($markdown, "\n---")) {
            $closing = strlen($markdown) - 4;
            $closingLength = 4;
        }
        if ($closing === null) {
            throw GovernanceViolation::at(
                $file,
                'front matter has no closing `---` fence',
                'close the YAML record with a line containing only `---`',
            );
        }

        $yaml = substr($markdown, $opening, $closing + 1 - $opening);
        $body = substr($markdown, $closing + $closingLength);

        return ['front_matter' => self::parse($yaml, $file, 1), 'body' => $body];
    }

    /**
     * Turn the document into significant lines with their indentation, refusing what the subset forbids.
     *
     * @param   string  $yaml        Document bytes.
     * @param   string  $file        Path reported in violations.
     * @param   int     $lineOffset  Lines preceding the document in its file.
     *
     * @return  list<array{indent: int, text: string, line: int}>  Non-blank, non-comment lines in order.
     *
     * @throws  GovernanceViolation  When the encoding, indentation or a document marker is outside the subset.
     *
     * @since   2.0.0
     */
    private static function lines(string $yaml, string $file, int $lineOffset): array
    {
        if (!mb_check_encoding($yaml, 'UTF-8')) {
            throw GovernanceViolation::at($file, 'the document is not valid UTF-8', 'save the record as UTF-8');
        }

        $result = [];
        foreach (explode("\n", $yaml) as $offset => $raw) {
            $line = $lineOffset + $offset + 1;
            $text = rtrim($raw, "\r");
            if (str_contains($text, "\r")) {
                throw self::violation($file, $line, 'a carriage return appears inside the line');
            }
            if (str_contains($text, "\t")) {
                throw self::violation($file, $line, 'tab characters are not allowed; indent with two spaces');
            }
            $text = rtrim($text, ' ');
            $indent = strlen($text) - strlen(ltrim($text, ' '));
            $content = substr($text, $indent);
            if ($content === '' || str_starts_with($content, '#')) {
                continue;
            }
            if ($content === '---' || str_starts_with($content, '--- ') || $content === '...') {
                throw self::violation($file, $line, 'document markers are only allowed as front-matter fences');
            }
            if (str_starts_with($content, '%')) {
                throw self::violation($file, $line, 'YAML directives are not allowed');
            }
            if ($indent % 2 !== 0) {
                throw self::violation(
                    $file,
                    $line,
                    sprintf('indentation of %d spaces is not a multiple of two', $indent),
                );
            }
            $result[] = ['indent' => $indent, 'text' => $content, 'line' => $line];
        }

        return $result;
    }

    /**
     * Parse the block that starts at the current line: a sequence when it starts with a dash, else a mapping.
     *
     * @param   list<array{indent: int, text: string, line: int}>  $lines   Significant lines.
     * @param   int                                                $index   Cursor into the lines, advanced.
     * @param   int                                                $indent  Indentation the block must use.
     * @param   string                                             $file    Path reported in violations.
     *
     * @return  array<int|string, mixed>  The parsed block.
     *
     * @throws  GovernanceViolation  When the block mixes shapes or steps its indentation wrongly.
     *
     * @since   2.0.0
     */
    private static function node(array &$lines, int &$index, int $indent, string $file): array
    {
        $entry = $lines[$index];
        if ($entry['indent'] !== $indent) {
            throw self::violation(
                $file,
                $entry['line'],
                sprintf('expected an indentation of %d spaces, found %d', $indent, $entry['indent']),
            );
        }

        return self::isSequenceItem($entry['text'])
            ? self::sequence($lines, $index, $indent, $file)
            : self::mapping($lines, $index, $indent, $file);
    }

    /**
     * Parse consecutive `key: value` lines at one indentation.
     *
     * @param   list<array{indent: int, text: string, line: int}>  $lines   Significant lines.
     * @param   int                                                $index   Cursor into the lines, advanced.
     * @param   int                                                $indent  Indentation of the keys.
     * @param   string                                             $file    Path reported in violations.
     *
     * @return  array<string, mixed>  Keys in document order.
     *
     * @throws  GovernanceViolation  When a line is not a key, a key repeats or a nested block is misindented.
     *
     * @since   2.0.0
     */
    private static function mapping(array &$lines, int &$index, int $indent, string $file): array
    {
        $result = [];
        $count = count($lines);
        while ($index < $count) {
            $entry = $lines[$index];
            if ($entry['indent'] < $indent) {
                break;
            }
            if ($entry['indent'] > $indent) {
                throw self::violation(
                    $file,
                    $entry['line'],
                    sprintf('unexpected indentation of %d spaces inside a mapping at %d', $entry['indent'], $indent),
                );
            }
            if (self::isSequenceItem($entry['text'])) {
                throw self::violation(
                    $file,
                    $entry['line'],
                    'a sequence item appears where a mapping key was expected; indent items under their key',
                );
            }
            if (preg_match(self::KEY_PATTERN, $entry['text'], $match) !== 1) {
                throw self::violation($file, $entry['line'], 'the line is not a `key: value` pair');
            }
            $key = $match[2] !== '' ? $match[2] : self::unescapeDouble($match[1], $file, $entry['line']);
            if (array_key_exists($key, $result)) {
                throw self::violation($file, $entry['line'], sprintf('the key "%s" is repeated', $key));
            }
            $index++;
            $rest = $match[3] ?? '';
            if ($rest === '' || str_starts_with($rest, '#')) {
                $result[$key] = self::nested($lines, $index, $indent, $file);
                continue;
            }
            $result[$key] = self::scalar($rest, $file, $entry['line']);
        }

        return $result;
    }

    /**
     * Parse the block nested under a key or a bare dash, or null when nothing is nested.
     *
     * @param   list<array{indent: int, text: string, line: int}>  $lines   Significant lines.
     * @param   int                                                $index   Cursor into the lines, advanced.
     * @param   int                                                $indent  Indentation of the owning line.
     * @param   string                                             $file    Path reported in violations.
     *
     * @return  array<int|string, mixed>|null  The nested block, or null for an empty value.
     *
     * @throws  GovernanceViolation  When the nested block is indented by anything but two more spaces.
     *
     * @since   2.0.0
     */
    private static function nested(array &$lines, int &$index, int $indent, string $file): ?array
    {
        if ($index >= count($lines) || $lines[$index]['indent'] <= $indent) {
            return null;
        }
        if ($lines[$index]['indent'] !== $indent + 2) {
            throw self::violation(
                $file,
                $lines[$index]['line'],
                sprintf('a nested block must be indented by exactly two spaces (expected %d)', $indent + 2),
            );
        }

        return self::node($lines, $index, $indent + 2, $file);
    }

    /**
     * Parse consecutive `- item` lines at one indentation.
     *
     * A `- key: value` item is a mapping whose first key shares the dash line; the line is re-read as if it
     * were indented two further spaces so the mapping parser handles the continuation keys.
     *
     * @param   list<array{indent: int, text: string, line: int}>  $lines   Significant lines.
     * @param   int                                                $index   Cursor into the lines, advanced.
     * @param   int                                                $indent  Indentation of the dashes.
     * @param   string                                             $file    Path reported in violations.
     *
     * @return  list<mixed>  Items in document order.
     *
     * @throws  GovernanceViolation  When a line is not an item or the dash is not followed by one space.
     *
     * @since   2.0.0
     */
    private static function sequence(array &$lines, int &$index, int $indent, string $file): array
    {
        $result = [];
        $count = count($lines);
        while ($index < $count) {
            $entry = $lines[$index];
            if ($entry['indent'] < $indent) {
                break;
            }
            if ($entry['indent'] > $indent) {
                throw self::violation(
                    $file,
                    $entry['line'],
                    sprintf('unexpected indentation of %d spaces inside a sequence at %d', $entry['indent'], $indent),
                );
            }
            if (!self::isSequenceItem($entry['text'])) {
                throw self::violation(
                    $file,
                    $entry['line'],
                    'a mapping key appears where a sequence item was expected',
                );
            }
            if ($entry['text'] === '-') {
                $index++;
                $result[] = self::nested($lines, $index, $indent, $file);
                continue;
            }
            $rest = substr($entry['text'], 2);
            if (str_starts_with($rest, ' ')) {
                throw self::violation($file, $entry['line'], 'a sequence dash must be followed by exactly one space');
            }
            if (self::isSequenceItem($rest) || preg_match(self::KEY_PATTERN, $rest) === 1) {
                $lines[$index] = ['indent' => $indent + 2, 'text' => $rest, 'line' => $entry['line']];
                $result[] = self::node($lines, $index, $indent + 2, $file);
                continue;
            }
            $index++;
            $result[] = self::scalar($rest, $file, $entry['line']);
        }

        return $result;
    }

    /**
     * Decide whether a line is a sequence item.
     *
     * @param   string  $text  Line content without indentation.
     *
     * @return  bool  True for `-` alone or a line starting with `- `.
     *
     * @since   2.0.0
     */
    private static function isSequenceItem(string $text): bool
    {
        return $text === '-' || str_starts_with($text, '- ');
    }

    /**
     * Read one scalar value, refusing every construct the subset does not admit.
     *
     * @param   string  $text  Value text after the key or dash, with trailing spaces removed.
     * @param   string  $file  Path reported in violations.
     * @param   int     $line  Line of the value.
     *
     * @return  array<never, never>|bool|int|string|null  The typed value; `[]` for the empty flow collections.
     *
     * @throws  GovernanceViolation  When the value is a block scalar, anchor, alias, tag or flow collection.
     *
     * @since   2.0.0
     */
    private static function scalar(string $text, string $file, int $line): array|bool|int|string|null
    {
        $first = $text[0];
        if ($first === '"') {
            $end = self::closingQuote($text, '"', $file, $line);
            self::assertTrailing(substr($text, $end + 1), $file, $line);

            return self::unescapeDouble(substr($text, 1, $end - 1), $file, $line);
        }
        if ($first === "'") {
            $end = self::closingQuote($text, "'", $file, $line);
            self::assertTrailing(substr($text, $end + 1), $file, $line);

            return str_replace("''", "'", substr($text, 1, $end - 1));
        }

        $comment = strpos($text, ' #');
        $plain = rtrim($comment === false ? $text : substr($text, 0, $comment), ' ');
        if ($plain === '[]' || $plain === '{}') {
            return [];
        }
        if (str_starts_with($plain, '|') || str_starts_with($plain, '>')) {
            throw self::violation($file, $line, 'multi-line block scalars (`|`, `>`) are not allowed');
        }
        if (str_starts_with($plain, '&')) {
            throw self::violation($file, $line, 'anchors are not allowed');
        }
        if (str_starts_with($plain, '*')) {
            throw self::violation($file, $line, 'aliases are not allowed');
        }
        if (str_starts_with($plain, '!')) {
            throw self::violation($file, $line, 'tags are not allowed');
        }
        if (str_starts_with($plain, '[') || str_starts_with($plain, '{')) {
            throw self::violation($file, $line, 'flow collections with content are not allowed; use block syntax');
        }
        if (str_starts_with($plain, '@') || str_starts_with($plain, '`')) {
            throw self::violation($file, $line, 'a plain scalar cannot start with `@` or a backtick; quote it');
        }
        if ($plain === '-' || str_starts_with($plain, '- ')) {
            throw self::violation($file, $line, 'a sequence item cannot follow a key on the same line');
        }
        if (str_contains($plain, ': ') || str_ends_with($plain, ':')) {
            throw self::violation($file, $line, 'a plain scalar cannot contain `: `; quote the value');
        }
        if ($plain === 'null' || $plain === '~') {
            return null;
        }
        if ($plain === 'true') {
            return true;
        }
        if ($plain === 'false') {
            return false;
        }
        if (preg_match('/^-?[0-9]+$/', $plain) === 1 && (string) (int) $plain === $plain) {
            return (int) $plain;
        }

        return $plain;
    }

    /**
     * Find the closing quote of a quoted scalar.
     *
     * @param   string  $text   Value text starting with the quote character.
     * @param   string  $quote  The quote character.
     * @param   string  $file   Path reported in violations.
     * @param   int     $line   Line of the value.
     *
     * @return  int  Offset of the closing quote.
     *
     * @throws  GovernanceViolation  When the scalar is not closed on its line.
     *
     * @since   2.0.0
     */
    private static function closingQuote(string $text, string $quote, string $file, int $line): int
    {
        $length = strlen($text);
        for ($offset = 1; $offset < $length; $offset++) {
            $character = $text[$offset];
            if ($quote === '"' && $character === '\\') {
                $offset++;
                continue;
            }
            if ($character === $quote) {
                if ($quote === "'" && $offset + 1 < $length && $text[$offset + 1] === "'") {
                    $offset++;
                    continue;
                }

                return $offset;
            }
        }

        throw self::violation($file, $line, 'a quoted scalar is not closed on its line');
    }

    /**
     * Require that only a comment follows a quoted scalar.
     *
     * @param   string  $rest  Text after the closing quote.
     * @param   string  $file  Path reported in violations.
     * @param   int     $line  Line of the value.
     *
     * @return  void
     *
     * @throws  GovernanceViolation  When other content follows the quote.
     *
     * @since   2.0.0
     */
    private static function assertTrailing(string $rest, string $file, int $line): void
    {
        $trimmed = ltrim($rest, ' ');
        if ($trimmed === '' || (str_starts_with($trimmed, '#') && str_starts_with($rest, ' '))) {
            return;
        }

        throw self::violation($file, $line, 'unexpected content after a quoted scalar');
    }

    /**
     * Resolve the three escapes a double-quoted scalar may use.
     *
     * @param   string  $inner  Text between the quotes.
     * @param   string  $file   Path reported in violations.
     * @param   int     $line   Line of the value.
     *
     * @return  string  The unescaped text.
     *
     * @throws  GovernanceViolation  When an escape other than `\"`, `\\` or `\n` appears.
     *
     * @since   2.0.0
     */
    private static function unescapeDouble(string $inner, string $file, int $line): string
    {
        $result = '';
        $length = strlen($inner);
        for ($offset = 0; $offset < $length; $offset++) {
            $character = $inner[$offset];
            if ($character !== '\\') {
                $result .= $character;
                continue;
            }
            $next = $inner[$offset + 1] ?? '';
            $result .= match ($next) {
                '"' => '"',
                '\\' => '\\',
                'n' => "\n",
                default => throw self::violation(
                    $file,
                    $line,
                    sprintf('the escape `\\%s` is not allowed; only `\\"`, `\\\\` and `\\n` are', $next),
                ),
            };
            $offset++;
        }

        return $result;
    }

    /**
     * Build the violation every refusal in this parser raises.
     *
     * @param   string  $file  Path reported in violations.
     * @param   int     $line  One-based line in the file.
     * @param   string  $rule  What the subset refused.
     *
     * @return  GovernanceViolation  Ready to throw.
     *
     * @since   2.0.0
     */
    private static function violation(string $file, int $line, string $rule): GovernanceViolation
    {
        return GovernanceViolation::at(
            sprintf('%s:%d', $file, $line),
            $rule,
            'rewrite the record in the StrictYaml subset (block mappings and sequences, plain or quoted scalars)',
        );
    }
}
