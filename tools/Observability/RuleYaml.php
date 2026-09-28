<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Observability;

/**
 * Reads the YAML subset the shipped Prometheus rule, Alertmanager and promtool files are written in.
 *
 * `composer qa` runs without network access, so the rule gate cannot lean on `promtool` to parse the files.
 * This reader accepts exactly what those files use — block mappings and sequences, plain, single- and
 * double-quoted scalars, folded and literal block scalars (`>`, `>-`, `|`, `|-`), flow sequences of scalars and
 * `#` comments — and refuses anchors, aliases, tags, flow mappings with content and multiple documents with
 * the line that carries them, so a rule file has one reading here and in Prometheus. Scalars come back as
 * strings; an empty value comes back as null. CI additionally runs the real `promtool check rules` and
 * `amtool check-config`, so a disagreement between this reader and Prometheus fails the build too.
 *
 * @since  2.0.0
 */
final class RuleYaml
{
    /**
     * Raw lines of the document being read, without line terminators.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $lines;

    /**
     * Index of the next line to read.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $cursor = 0;

    /**
     * Keep the document and the name violations are reported against.
     *
     * @param  string  $yaml  Document bytes.
     * @param  string  $file  Path reported in violations.
     *
     * @since  2.0.0
     */
    private function __construct(string $yaml, private readonly string $file)
    {
        $this->lines = explode("\n", str_replace("\r\n", "\n", $yaml));
    }

    /**
     * Parse a whole document into its top-level mapping.
     *
     * @param   string  $yaml  Document bytes.
     * @param   string  $file  Path reported in violations.
     *
     * @return  array<string, mixed>  The top-level mapping.
     *
     * @throws  RuleViolation  When the document uses anything outside the subset.
     *
     * @since   2.0.0
     */
    public static function parse(string $yaml, string $file): array
    {
        if (!mb_check_encoding($yaml, 'UTF-8')) {
            throw RuleViolation::at($file, 'the document is not valid UTF-8');
        }
        $reader = new self($yaml, $file);
        $reader->skip();
        if ($reader->done()) {
            return [];
        }
        [$indent, $text] = $reader->current();
        if ($indent !== 0 || self::isItem($text)) {
            throw $reader->violation('the top-level node must be a mapping starting in column one');
        }
        $value = $reader->mapping(0);
        $reader->skip();
        if (!$reader->done()) {
            throw $reader->violation('content after the top-level mapping is not indented under a key');
        }

        return $value;
    }

    /**
     * Parse a block mapping whose keys sit at exactly this indentation.
     *
     * @param   int  $indent  Indentation of the mapping's keys.
     *
     * @return  array<string, mixed>  The mapping.
     *
     * @throws  RuleViolation  When a key repeats, is malformed or is indented inconsistently.
     *
     * @since   2.0.0
     */
    private function mapping(int $indent): array
    {
        $mapping = [];
        while (true) {
            $this->skip();
            if ($this->done()) {
                break;
            }
            [$current, $text] = $this->current();
            if ($current < $indent) {
                break;
            }
            if ($current > $indent) {
                throw $this->violation(sprintf('expected indentation %d, found %d', $indent, $current));
            }
            if (self::isItem($text)) {
                break;
            }
            if (preg_match('/^(?:"([^"\\\\]*)"|([A-Za-z0-9_.\/-]+)):(?:[ ]+(.*))?$/D', $text, $match) !== 1) {
                throw $this->violation('expected a `key: value` entry');
            }
            $key = $match[1] !== '' ? $match[1] : $match[2];
            if (array_key_exists($key, $mapping)) {
                throw $this->violation(sprintf('the key `%s` repeats', $key));
            }
            $rest = trim($match[3] ?? '');
            $this->cursor++;
            $mapping[$key] = $this->value($rest, $indent);
        }

        return $mapping;
    }

    /**
     * Parse a block sequence whose dashes sit at exactly this indentation.
     *
     * @param   int  $indent  Indentation of the sequence's dashes.
     *
     * @return  list<mixed>  The sequence.
     *
     * @throws  RuleViolation  When an item is malformed or indented inconsistently.
     *
     * @since   2.0.0
     */
    private function sequence(int $indent): array
    {
        $items = [];
        while (true) {
            $this->skip();
            if ($this->done()) {
                break;
            }
            [$current, $text] = $this->current();
            if ($current < $indent || ($current === $indent && !self::isItem($text))) {
                break;
            }
            if ($current > $indent) {
                throw $this->violation(sprintf('expected indentation %d, found %d', $indent, $current));
            }
            $rest = trim(substr($text, 1));
            if ($rest !== '' && preg_match('/^(?:"[^"\\\\]*"|[A-Za-z0-9_.\/-]+):(?:[ ]|$)/', $rest) === 1) {
                // A mapping that starts on the dash line continues at the dash's indentation plus two.
                $this->lines[$this->cursor] = str_repeat(' ', $indent + 2) . $rest;
                $items[] = $this->mapping($indent + 2);
                continue;
            }
            $this->cursor++;
            $items[] = $this->value($rest, $indent);
        }

        return $items;
    }

    /**
     * Read the value that follows a key or a dash.
     *
     * @param   string  $rest    Text after the indicator on the same line.
     * @param   int     $indent  Indentation of the key or dash owning the value.
     *
     * @return  mixed  A nested block, a scalar string, a list of scalars, or null.
     *
     * @throws  RuleViolation  When the value uses a construct outside the subset.
     *
     * @since   2.0.0
     */
    private function value(string $rest, int $indent): mixed
    {
        if ($rest === '' || str_starts_with($rest, '#')) {
            $this->skip();
            if ($this->done()) {
                return null;
            }
            [$next, $text] = $this->current();
            if ($next > $indent) {
                return self::isItem($text) ? $this->sequence($next) : $this->mapping($next);
            }
            if ($next === $indent && self::isItem($text)) {
                return $this->sequence($next);
            }

            return null;
        }
        if (preg_match('/^([>|])(-?)$/D', $rest, $block) === 1) {
            return $this->block($block[1] === '>', $block[2] === '-', $indent);
        }

        return $this->scalar($rest);
    }

    /**
     * Read a folded or literal block scalar.
     *
     * @param   bool  $folded  Whether lines fold into spaces rather than keeping their breaks.
     * @param   bool  $strip   Whether the final line break is removed.
     * @param   int   $indent  Indentation of the owning key; content must sit deeper.
     *
     * @return  string  The scalar.
     *
     * @throws  RuleViolation  When the block has no content.
     *
     * @since   2.0.0
     */
    private function block(bool $folded, bool $strip, int $indent): string
    {
        $content = [];
        $blockIndent = null;
        while (!$this->done()) {
            $raw = rtrim($this->lines[$this->cursor]);
            if ($raw === '') {
                $content[] = '';
                $this->cursor++;
                continue;
            }
            $current = strlen($raw) - strlen(ltrim($raw, ' '));
            if ($current <= $indent) {
                break;
            }
            $blockIndent ??= $current;
            if ($current < $blockIndent) {
                break;
            }
            $content[] = substr($raw, $blockIndent);
            $this->cursor++;
        }
        while ($content !== [] && $content[array_key_last($content)] === '') {
            array_pop($content);
        }
        if ($content === []) {
            throw $this->violation('a block scalar has no content');
        }
        $text = $folded ? self::fold($content) : implode("\n", $content);

        return $strip ? $text : $text . "\n";
    }

    /**
     * Fold block lines the way YAML does: single breaks become spaces, blank lines become breaks.
     *
     * @param   list<string>  $content  Block lines with the block indentation removed.
     *
     * @return  string  Folded text.
     *
     * @since   2.0.0
     */
    private static function fold(array $content): string
    {
        $text = '';
        $pendingBreaks = 0;
        foreach ($content as $line) {
            if ($line === '') {
                $pendingBreaks++;
                continue;
            }
            if ($text !== '') {
                $text .= $pendingBreaks > 0 ? str_repeat("\n", $pendingBreaks) : ' ';
            }
            $text .= $line;
            $pendingBreaks = 0;
        }

        return $text;
    }

    /**
     * Read a single-line scalar or a flow sequence of scalars.
     *
     * @param   string  $text  Scalar text, possibly followed by a comment.
     *
     * @return  string|list<string>|null  The scalar string, the list of flow items, or null for `~`/`null`.
     *
     * @throws  RuleViolation  When the scalar uses a construct outside the subset.
     *
     * @since   2.0.0
     */
    private function scalar(string $text): string|array|null
    {
        $first = $text[0];
        if ($first === '"' || $first === "'") {
            [$value, $rest] = $this->quoted($text);
            if ($rest !== '' && !str_starts_with($rest, '#')) {
                throw $this->violation('text follows a quoted scalar');
            }

            return $value;
        }
        if ($first === '[') {
            return $this->flow($text);
        }
        if ($text === '{}') {
            return null;
        }
        if (in_array($first, ['{', '&', '*', '!', '%', '@', '`', '|', '>'], true)) {
            throw $this->violation(sprintf('the indicator `%s` is outside the supported subset', $first));
        }
        $comment = strpos($text, ' #');
        $value = rtrim($comment === false ? $text : substr($text, 0, $comment));

        return in_array($value, ['~', 'null'], true) ? null : $value;
    }

    /**
     * Read a quoted scalar from the start of the text.
     *
     * @param   string  $text  Text starting with a quote.
     *
     * @return  array{string, string}  The unescaped value and whatever follows the closing quote, trimmed.
     *
     * @throws  RuleViolation  When the quote is never closed or uses an unsupported escape.
     *
     * @since   2.0.0
     */
    private function quoted(string $text): array
    {
        $quote = $text[0];
        $value = '';
        $length = strlen($text);
        for ($index = 1; $index < $length; $index++) {
            $character = $text[$index];
            if ($quote === "'" && $character === "'") {
                if (($text[$index + 1] ?? '') === "'") {
                    $value .= "'";
                    $index++;
                    continue;
                }

                return [$value, trim(substr($text, $index + 1))];
            }
            if ($quote === '"' && $character === '\\') {
                $escaped = $text[$index + 1] ?? '';
                $value .= match ($escaped) {
                    '"' => '"',
                    '\\' => '\\',
                    'n' => "\n",
                    't' => "\t",
                    '/' => '/',
                    default => throw $this->violation(sprintf('the escape `\\%s` is not supported', $escaped)),
                };
                $index++;
                continue;
            }
            if ($quote === '"' && $character === '"') {
                return [$value, trim(substr($text, $index + 1))];
            }
            $value .= $character;
        }

        throw $this->violation('a quoted scalar is never closed');
    }

    /**
     * Read a single-line flow sequence of scalars, such as `[instance, volume]`.
     *
     * @param   string  $text  Text starting with `[`.
     *
     * @return  list<string>  The items.
     *
     * @throws  RuleViolation  When the sequence nests, spans lines or is never closed.
     *
     * @since   2.0.0
     */
    private function flow(string $text): array
    {
        $items = [];
        $rest = trim(substr($text, 1));
        while (true) {
            if ($rest === '') {
                throw $this->violation('a flow sequence is never closed on its line');
            }
            if ($rest[0] === ']') {
                $tail = trim(substr($rest, 1));
                if ($tail !== '' && !str_starts_with($tail, '#')) {
                    throw $this->violation('text follows a flow sequence');
                }

                return $items;
            }
            if ($rest[0] === '"' || $rest[0] === "'") {
                [$item, $rest] = $this->quoted($rest);
            } else {
                if (preg_match('/^([^,\]\[{}]+)/', $rest, $match) !== 1) {
                    throw $this->violation('a flow sequence item is not a scalar');
                }
                $item = trim($match[1]);
                $rest = trim(substr($rest, strlen($match[1])));
            }
            $items[] = $item;
            if (str_starts_with($rest, ',')) {
                $rest = trim(substr($rest, 1));
            } elseif (!str_starts_with($rest, ']')) {
                throw $this->violation('flow sequence items must be separated by commas');
            }
        }
    }

    /**
     * Advance past blank and comment-only lines, refusing document markers and tabs on the way.
     *
     * @return  void
     *
     * @throws  RuleViolation  When a skipped or next line uses a forbidden construct.
     *
     * @since   2.0.0
     */
    private function skip(): void
    {
        while (!$this->done()) {
            $raw = $this->lines[$this->cursor];
            if (str_contains($raw, "\t")) {
                throw $this->violation('tab characters are not allowed; indent with spaces');
            }
            $trimmed = trim($raw);
            if ($trimmed === '---' || $trimmed === '...' || str_starts_with($trimmed, '%')) {
                throw $this->violation('document markers and directives are not allowed');
            }
            if ($trimmed !== '' && !str_starts_with($trimmed, '#')) {
                return;
            }
            $this->cursor++;
        }
    }

    /**
     * Report whether every line has been read.
     *
     * @return  bool  True at the end of the document.
     *
     * @since   2.0.0
     */
    private function done(): bool
    {
        return $this->cursor >= count($this->lines);
    }

    /**
     * Read the current line's indentation and content.
     *
     * @return  array{int, string}  Indentation in spaces and the trimmed content.
     *
     * @since   2.0.0
     */
    private function current(): array
    {
        $raw = rtrim($this->lines[$this->cursor]);

        return [strlen($raw) - strlen(ltrim($raw, ' ')), ltrim($raw, ' ')];
    }

    /**
     * Report whether a line's content is a sequence item.
     *
     * @param   string  $text  Trimmed line content.
     *
     * @return  bool  True for `-` alone or `- ` followed by content.
     *
     * @since   2.0.0
     */
    private static function isItem(string $text): bool
    {
        return $text === '-' || str_starts_with($text, '- ');
    }

    /**
     * Build a violation naming the current line.
     *
     * @param   string  $rule  What was expected and what was found.
     *
     * @return  RuleViolation  The violation.
     *
     * @since   2.0.0
     */
    private function violation(string $rule): RuleViolation
    {
        return RuleViolation::at(sprintf('%s:%d', $this->file, min($this->cursor, count($this->lines) - 1) + 1), $rule);
    }
}
