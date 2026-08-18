<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Support;

/**
 * Inlines catalogue wording back into a template source for source-inspection tests.
 *
 * Several architecture tests prove an interface contract by asserting that a template presents a
 * given sentence. Extraction moved the sentences into the message catalogue, so those tests now
 * resolve each `t('…')` and `t_html('…')` reference against the compiled en-GB catalogue before
 * asserting — which keeps them proving the same thing: the surface presents that wording.
 */
final class ResolvedWording
{
    /**
     * Compiled en-GB patterns keyed by identifier, loaded once.
     *
     * @var ?array<string, string>
     */
    private static ?array $catalogue = null;

    /**
     * The raw source followed by its wording-resolved form, for contains-style assertions.
     *
     * A contains assertion passes when its needle sits in either form, so contracts stated as
     * wording and contracts stated as identifiers or expressions both stay verifiable.
     */
    public static function withResolved(string $source): string
    {
        return $source . "\n{# resolved wording #}\n" . self::resolve($source);
    }

    /** Replace every t()/t_html() call in a template source with the quoted catalogue pattern. */
    public static function resolve(string $source): string
    {
        $catalogue = self::catalogue();
        $replacements = [];
        $offset = 0;
        while (
            preg_match(
                '/\bt(?:_html)?\(\s*\'([^\']+)\'/',
                $source,
                $match,
                PREG_OFFSET_CAPTURE,
                $offset,
            ) === 1
        ) {
            $start = (int) $match[0][1];
            $identifier = $match[1][0];
            $close = self::matchingParenthesis($source, strpos($source, '(', $start) ?: $start);
            $offset = $close + 1;
            if (!isset($catalogue[$identifier])) {
                continue;
            }
            $replacements[] = [$start, $close, "'" . $catalogue[$identifier] . "'"];
        }
        foreach (array_reverse($replacements) as [$start, $close, $replacement]) {
            $source = substr($source, 0, $start) . $replacement . substr($source, $close + 1);
        }

        return $source;
    }

    /** Find the offset of the parenthesis closing the one at $open, skipping string literals. */
    private static function matchingParenthesis(string $source, int $open): int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            $character = $source[$i];
            if ($quote !== null) {
                if ($character === '\\') {
                    $i++;
                    continue;
                }
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
                continue;
            }
            if ($character === '(') {
                $depth++;
                continue;
            }
            if ($character === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $open;
    }

    /** The compiled en-GB catalogue, read from the repository's published artifact. */
    private static function catalogue(): array
    {
        if (self::$catalogue !== null) {
            return self::$catalogue;
        }
        /** @var mixed $loaded */
        $loaded = require dirname(__DIR__, 2) . '/resources/localization/compiled/en-GB.php';
        $catalogue = [];
        if (is_array($loaded)) {
            foreach ($loaded as $identifier => $pattern) {
                if (is_string($identifier) && is_string($pattern)) {
                    $catalogue[$identifier] = $pattern;
                }
            }
        }

        return self::$catalogue = $catalogue;
    }
}
