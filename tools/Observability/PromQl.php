<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Observability;

/**
 * Parses PromQL into a small syntax tree the rule and dashboard gates can reason about offline.
 *
 * The grammar is PromQL's: selectors with label matchers, range and subquery selectors, `offset` and `@`
 * modifiers, aggregations with `by`/`without` before or after their argument, function calls, the full binary
 * operator precedence ladder with `bool`, `on`/`ignoring` and `group_left`/`group_right`, and unary signs.
 * Anything else is refused with the offending position. The parser does not evaluate; evaluation is promtool's
 * job in CI and in the drills, which is also what proves this parser and Prometheus agree on what a rule means.
 *
 * Nodes are arrays with a `kind` key: `number`, `string`, `selector`, `call`, `aggregate`, `binary`, `unary`,
 * `paren` and `subquery`.
 *
 * @since  2.0.0
 */
final class PromQl
{
    /**
     * Aggregation operators, and whether each takes a leading parameter.
     *
     * @var    array<string, bool>
     * @since  2.0.0
     */
    public const AGGREGATIONS = [
        'sum' => false, 'min' => false, 'max' => false, 'avg' => false, 'group' => false, 'stddev' => false,
        'stdvar' => false, 'count' => false, 'count_values' => true, 'bottomk' => true, 'topk' => true,
        'quantile' => true, 'limitk' => true, 'limit_ratio' => true,
    ];

    /**
     * Binary operators by precedence, lowest first.
     *
     * @var    list<list<string>>
     * @since  2.0.0
     */
    private const PRECEDENCE = [
        ['or'],
        ['and', 'unless'],
        ['==', '!=', '<=', '<', '>=', '>'],
        ['+', '-'],
        ['*', '/', '%', 'atan2'],
        ['^'],
    ];

    /**
     * Tokens of the expression being parsed.
     *
     * @var    list<array{type: string, value: string, position: int}>
     * @since  2.0.0
     */
    private array $tokens;

    /**
     * Index of the next token.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $cursor = 0;

    /**
     * Tokenize the expression.
     *
     * @param  string  $expression  PromQL source.
     * @param  string  $subject     Alert or panel the expression belongs to, for violations.
     *
     * @throws  RuleViolation  When the source contains a character PromQL does not.
     *
     * @since  2.0.0
     */
    private function __construct(private readonly string $expression, private readonly string $subject)
    {
        $this->tokens = $this->tokenize();
    }

    /**
     * Parse one PromQL expression.
     *
     * @param   string  $expression  PromQL source.
     * @param   string  $subject     Alert or panel the expression belongs to, for violations.
     *
     * @return  array<string, mixed>  Root node.
     *
     * @throws  RuleViolation  When the expression is not valid PromQL.
     *
     * @since   2.0.0
     */
    public static function parse(string $expression, string $subject): array
    {
        $parser = new self($expression, $subject);
        $node = $parser->binary(0);
        if ($parser->peek()['type'] !== 'eof') {
            throw $parser->violation('unexpected ' . $parser->describe($parser->peek()));
        }

        return $node;
    }

    /**
     * Parse a binary expression whose operators bind at least as tightly as the given level.
     *
     * @param   int  $level  Index into the precedence ladder.
     *
     * @return  array<string, mixed>  The node.
     *
     * @throws  RuleViolation  When an operand or a modifier is malformed.
     *
     * @since   2.0.0
     */
    private function binary(int $level): array
    {
        if ($level >= count(self::PRECEDENCE)) {
            return $this->unary();
        }
        $left = $this->binary($level + 1);
        while (true) {
            $token = $this->peek();
            $operator = $token['value'];
            if (
                !in_array($token['type'], ['operator', 'identifier'], true)
                || !in_array($operator, self::PRECEDENCE[$level], true)
            ) {
                return $left;
            }
            $this->cursor++;
            $bool = false;
            if ($this->keyword('bool')) {
                if (!in_array($operator, self::PRECEDENCE[2], true)) {
                    throw $this->violation('`bool` modifies only comparison operators');
                }
                $bool = true;
            }
            $matching = null;
            foreach (['on', 'ignoring'] as $modifier) {
                if ($this->keyword($modifier)) {
                    $matching = ['type' => $modifier, 'labels' => $this->labelList(), 'group' => null, 'include' => []];
                }
            }
            foreach (['group_left', 'group_right'] as $group) {
                if ($this->keyword($group)) {
                    $matching ??= ['type' => 'ignoring', 'labels' => [], 'group' => null, 'include' => []];
                    $matching['group'] = $group;
                    $matching['include'] = $this->peek()['value'] === '(' ? $this->labelList() : [];
                }
            }
            // `^` is right-associative; every other level is left-associative.
            $right = $operator === '^' ? $this->binary($level) : $this->binary($level + 1);
            $left = [
                'kind' => 'binary',
                'op' => $operator,
                'lhs' => $left,
                'rhs' => $right,
                'bool' => $bool,
                'matching' => $matching,
            ];
        }
    }

    /**
     * Parse an optionally signed operand.
     *
     * @return  array<string, mixed>  The node.
     *
     * @throws  RuleViolation  When the operand is malformed.
     *
     * @since   2.0.0
     */
    private function unary(): array
    {
        $token = $this->peek();
        if ($token['type'] === 'operator' && ($token['value'] === '-' || $token['value'] === '+')) {
            $this->cursor++;

            return ['kind' => 'unary', 'op' => $token['value'], 'expr' => $this->unary()];
        }

        return $this->postfix($this->primary());
    }

    /**
     * Apply range, subquery, `offset` and `@` modifiers to an operand.
     *
     * @param   array<string, mixed>  $node  Operand.
     *
     * @return  array<string, mixed>  The modified node.
     *
     * @throws  RuleViolation  When a modifier is malformed or applied to something it cannot modify.
     *
     * @since   2.0.0
     */
    private function postfix(array $node): array
    {
        while (true) {
            $token = $this->peek();
            if ($token['value'] === '[' && $token['type'] === 'punctuation') {
                $this->cursor++;
                $range = $this->expect('duration')['value'];
                if ($this->peek()['value'] === ':') {
                    $this->cursor++;
                    $step = $this->peek()['type'] === 'duration' ? $this->expect('duration')['value'] : null;
                    $this->expectValue(']');
                    $node = ['kind' => 'subquery', 'expr' => $node, 'range' => $range, 'step' => $step];
                    continue;
                }
                $this->expectValue(']');
                if ($node['kind'] !== 'selector' || $node['range'] !== null) {
                    throw $this->violation('a range can only follow an instant vector selector');
                }
                $node['range'] = $range;
                continue;
            }
            if ($this->keyword('offset')) {
                $negative = $this->peek()['value'] === '-';
                if ($negative) {
                    $this->cursor++;
                }
                $node['offset'] = ($negative ? '-' : '') . $this->expect('duration')['value'];
                continue;
            }
            if ($token['value'] === '@') {
                $this->cursor++;
                $at = $this->peek();
                if ($at['type'] === 'number' || in_array($at['value'], ['start', 'end'], true)) {
                    $this->cursor++;
                    if ($at['type'] !== 'number') {
                        $this->expectValue('(');
                        $this->expectValue(')');
                    }
                    $node['at'] = $at['value'];
                    continue;
                }
                throw $this->violation('`@` needs a timestamp, `start()` or `end()`');
            }

            return $node;
        }
    }

    /**
     * Parse a literal, parenthesised expression, aggregation, call or selector.
     *
     * @return  array<string, mixed>  The node.
     *
     * @throws  RuleViolation  When no operand starts here.
     *
     * @since   2.0.0
     */
    private function primary(): array
    {
        $token = $this->peek();
        if ($token['type'] === 'number') {
            $this->cursor++;

            return ['kind' => 'number', 'value' => $token['value']];
        }
        if ($token['type'] === 'string') {
            $this->cursor++;

            return ['kind' => 'string', 'value' => $token['value']];
        }
        if ($token['value'] === '(' && $token['type'] === 'punctuation') {
            $this->cursor++;
            $inner = $this->binary(0);
            $this->expectValue(')');

            return ['kind' => 'paren', 'expr' => $inner];
        }
        if ($token['value'] === '{' && $token['type'] === 'punctuation') {
            return $this->selector(null);
        }
        if ($token['type'] !== 'identifier') {
            throw $this->violation('expected an operand, found ' . $this->describe($token));
        }
        $name = $token['value'];
        $next = $this->tokens[$this->cursor + 1] ?? null;
        if (in_array(strtolower($name), ['inf', 'nan'], true)) {
            $this->cursor++;

            return ['kind' => 'number', 'value' => $name];
        }
        if (
            array_key_exists($name, self::AGGREGATIONS)
            && $next !== null
            && ($next['value'] === '(' || in_array($next['value'], ['by', 'without'], true))
        ) {
            return $this->aggregate();
        }
        if ($next !== null && $next['value'] === '(' && $next['type'] === 'punctuation') {
            $this->cursor += 2;
            $arguments = [];
            if ($this->peek()['value'] !== ')') {
                do {
                    $arguments[] = $this->binary(0);
                } while ($this->consume(','));
            }
            $this->expectValue(')');

            return ['kind' => 'call', 'name' => $name, 'args' => $arguments];
        }
        $this->cursor++;

        return $this->selector($name);
    }

    /**
     * Parse an aggregation with its optional grouping clause, before or after its argument.
     *
     * @return  array<string, mixed>  The aggregation node.
     *
     * @throws  RuleViolation  When the grouping clause or argument list is malformed.
     *
     * @since   2.0.0
     */
    private function aggregate(): array
    {
        $operator = $this->expect('identifier')['value'];
        $grouping = $this->grouping();
        $this->expectValue('(');
        $parameter = null;
        if (self::AGGREGATIONS[$operator]) {
            $parameter = $this->binary(0);
            $this->expectValue(',');
        }
        $argument = $this->binary(0);
        $this->expectValue(')');
        $after = $this->grouping();
        if ($grouping !== null && $after !== null) {
            throw $this->violation('an aggregation takes one grouping clause');
        }

        return [
            'kind' => 'aggregate',
            'op' => $operator,
            'grouping' => $grouping ?? $after,
            'param' => $parameter,
            'expr' => $argument,
        ];
    }

    /**
     * Parse an optional `by (…)` or `without (…)` clause.
     *
     * @return  ?array{type: string, labels: list<string>}  The clause, or null when none follows.
     *
     * @throws  RuleViolation  When the label list is malformed.
     *
     * @since   2.0.0
     */
    private function grouping(): ?array
    {
        foreach (['by', 'without'] as $type) {
            if ($this->keyword($type)) {
                return ['type' => $type, 'labels' => $this->labelList()];
            }
        }

        return null;
    }

    /**
     * Parse a parenthesised, comma-separated label list.
     *
     * @return  list<string>  Label names in order.
     *
     * @throws  RuleViolation  When an entry is not a label name.
     *
     * @since   2.0.0
     */
    private function labelList(): array
    {
        $this->expectValue('(');
        $labels = [];
        while ($this->peek()['value'] !== ')') {
            $labels[] = $this->expect('identifier')['value'];
            if (!$this->consume(',')) {
                break;
            }
        }
        $this->expectValue(')');

        return $labels;
    }

    /**
     * Parse a vector selector, with or without a metric name.
     *
     * @param   ?string  $name  Metric name already consumed, or null for a bare `{…}` selector.
     *
     * @return  array<string, mixed>  The selector node.
     *
     * @throws  RuleViolation  When a matcher is malformed or the selector matches nothing by name or label.
     *
     * @since   2.0.0
     */
    private function selector(?string $name): array
    {
        $matchers = [];
        if ($this->peek()['value'] === '{') {
            $this->cursor++;
            while ($this->peek()['value'] !== '}') {
                $label = $this->expect('identifier')['value'];
                $operator = $this->expect('operator')['value'];
                if (!in_array($operator, ['=', '!=', '=~', '!~'], true)) {
                    throw $this->violation(sprintf('`%s` is not a label matcher', $operator));
                }
                $matchers[] = ['label' => $label, 'op' => $operator, 'value' => $this->expect('string')['value']];
                if (!$this->consume(',')) {
                    break;
                }
            }
            $this->expectValue('}');
        }
        if ($name === null && $matchers === []) {
            throw $this->violation('a selector needs a metric name or at least one matcher');
        }

        return ['kind' => 'selector', 'name' => $name, 'matchers' => $matchers, 'range' => null];
    }

    /**
     * Split the source into tokens.
     *
     * @return  list<array{type: string, value: string, position: int}>  Tokens ending with `eof`.
     *
     * @throws  RuleViolation  When a character starts no token.
     *
     * @since   2.0.0
     */
    private function tokenize(): array
    {
        $patterns = [
            'space' => '\s+',
            'duration' => '(?:[0-9]+(?:ms|s|m|h|d|w|y))+(?![A-Za-z0-9_])',
            'number' => '0[xX][0-9a-fA-F]+|(?:[0-9]+\.?[0-9]*|\.[0-9]+)(?:[eE][+-]?[0-9]+)?',
            'string' => '"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|`[^`]*`',
            'identifier' => '[A-Za-z_][A-Za-z0-9_:]*',
            'operator' => '=~|!~|==|!=|<=|>=|[-+*\/%^<>=]',
            'punctuation' => '[(){}\[\],:@]',
        ];
        $tokens = [];
        $position = 0;
        $length = strlen($this->expression);
        while ($position < $length) {
            foreach ($patterns as $type => $pattern) {
                if (preg_match('/\G(?:' . $pattern . ')/', $this->expression, $match, 0, $position) === 1) {
                    if ($type !== 'space') {
                        $value = $match[0];
                        if ($type === 'string') {
                            $value = stripcslashes(substr($value, 1, -1));
                        }
                        $tokens[] = ['type' => $type, 'value' => $value, 'position' => $position];
                    }
                    $position += strlen($match[0]);
                    continue 2;
                }
            }
            throw RuleViolation::at(
                $this->subject,
                sprintf('unexpected character `%s` at offset %d of the expression', $this->expression[$position], $position),
            );
        }
        $tokens[] = ['type' => 'eof', 'value' => '', 'position' => $length];

        return $tokens;
    }

    /**
     * Read the next token without consuming it.
     *
     * @return  array{type: string, value: string, position: int}  The token.
     *
     * @since   2.0.0
     */
    private function peek(): array
    {
        return $this->tokens[$this->cursor];
    }

    /**
     * Consume an identifier keyword when it is next.
     *
     * @param   string  $keyword  Keyword to consume.
     *
     * @return  bool  Whether it was consumed.
     *
     * @since   2.0.0
     */
    private function keyword(string $keyword): bool
    {
        $token = $this->peek();
        if ($token['type'] === 'identifier' && $token['value'] === $keyword) {
            $this->cursor++;

            return true;
        }

        return false;
    }

    /**
     * Consume a punctuation token when it is next.
     *
     * @param   string  $value  Punctuation to consume.
     *
     * @return  bool  Whether it was consumed.
     *
     * @since   2.0.0
     */
    private function consume(string $value): bool
    {
        if ($this->peek()['value'] === $value && $this->peek()['type'] === 'punctuation') {
            $this->cursor++;

            return true;
        }

        return false;
    }

    /**
     * Consume the next token, requiring its type.
     *
     * @param   string  $type  Required token type.
     *
     * @return  array{type: string, value: string, position: int}  The token.
     *
     * @throws  RuleViolation  When the next token has another type.
     *
     * @since   2.0.0
     */
    private function expect(string $type): array
    {
        $token = $this->peek();
        if ($token['type'] !== $type) {
            throw $this->violation(sprintf('expected a %s, found %s', $type, $this->describe($token)));
        }
        $this->cursor++;

        return $token;
    }

    /**
     * Consume the next token, requiring its exact text.
     *
     * @param   string  $value  Required token text.
     *
     * @return  void
     *
     * @throws  RuleViolation  When the next token differs.
     *
     * @since   2.0.0
     */
    private function expectValue(string $value): void
    {
        $token = $this->peek();
        if ($token['value'] !== $value || !in_array($token['type'], ['punctuation', 'operator'], true)) {
            throw $this->violation(sprintf('expected `%s`, found %s', $value, $this->describe($token)));
        }
        $this->cursor++;
    }

    /**
     * Describe a token for a violation message.
     *
     * @param   array{type: string, value: string, position: int}  $token  Token.
     *
     * @return  string  Human-readable description.
     *
     * @since   2.0.0
     */
    private function describe(array $token): string
    {
        return $token['type'] === 'eof' ? 'the end of the expression' : sprintf('`%s`', $token['value']);
    }

    /**
     * Build a violation at the current token.
     *
     * @param   string  $rule  What was wrong.
     *
     * @return  RuleViolation  The violation.
     *
     * @since   2.0.0
     */
    private function violation(string $rule): RuleViolation
    {
        return RuleViolation::at(
            $this->subject,
            sprintf('%s at offset %d of the expression', $rule, $this->peek()['position']),
        );
    }
}
