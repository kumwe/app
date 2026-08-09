<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use RuntimeException;

/**
 * Cuts a migration script into the individual statements a connection can execute one at a time.
 *
 * A hand-written migration script is one string holding several statements, while the driver takes one
 * statement per call. Splitting on `;` alone corrupts such a script, because semicolons also occur
 * inside string literals, line and block comments, and PostgreSQL dollar-quoted bodies — which is
 * exactly where trigger and constraint procedures live. This splitter walks the script character by
 * character, tracks which of those regions it is inside, and treats a semicolon as a boundary only
 * outside all of them.
 *
 * @since  2.0.0
 */
final readonly class SqlStatementSplitter
{
    /**
     * Split a script into its executable statements, dropping the separators and any empty runs.
     *
     * Each statement comes back trimmed but otherwise verbatim, comments included, so it still reads
     * as the source did. Nested block comments are tracked by depth, a doubled quote inside a quoted
     * literal is an escaped quote rather than its end, and a dollar-quoted body closes only on its own
     * tag. A script that ends inside a quoted literal, a dollar-quoted body or a block comment is
     * rejected rather than returned as a final statement, so a truncated file cannot be half-applied.
     *
     * @param   string  $sql  Migration script holding one or more semicolon-separated statements.
     *
     * @return  list<string>  Trimmed statements in source order; empty when the script holds none.
     *
     * @throws  RuntimeException  When the script ends inside a quoted literal, a dollar-quoted body or
     *          a block comment.
     *
     * @since   2.0.0
     */
    public function split(string $sql): array
    {
        $statements = [];
        $statement = '';
        $length = strlen($sql);
        $quote = null;
        $dollarTag = null;
        $blockCommentDepth = 0;
        $lineComment = false;

        for ($index = 0; $index < $length; ++$index) {
            $character = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : null;

            if ($lineComment) {
                $statement .= $character;
                if ($character === "\n") {
                    $lineComment = false;
                }

                continue;
            }

            if ($blockCommentDepth > 0) {
                $statement .= $character;
                if ($character === '/' && $next === '*') {
                    ++$blockCommentDepth;
                    $statement .= $next;
                    ++$index;
                } elseif ($character === '*' && $next === '/') {
                    --$blockCommentDepth;
                    $statement .= $next;
                    ++$index;
                }

                continue;
            }

            if ($dollarTag !== null) {
                if (substr($sql, $index, strlen($dollarTag)) === $dollarTag) {
                    $statement .= $dollarTag;
                    $index += strlen($dollarTag) - 1;
                    $dollarTag = null;
                } else {
                    $statement .= $character;
                }

                continue;
            }

            if ($quote !== null) {
                $statement .= $character;
                if ($character === $quote) {
                    if ($next === $quote) {
                        $statement .= $next;
                        ++$index;
                    } else {
                        $quote = null;
                    }
                }

                continue;
            }

            if ($character === '-' && $next === '-') {
                $statement .= $character . $next;
                ++$index;
                $lineComment = true;
                continue;
            }

            if ($character === '/' && $next === '*') {
                $statement .= $character . $next;
                ++$index;
                $blockCommentDepth = 1;
                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
                $statement .= $character;
                continue;
            }

            $startsDollarQuote = $character === '$'
                && preg_match('/\A\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$/', substr($sql, $index), $match) === 1;

            if ($startsDollarQuote) {
                $dollarTag = $match[0];
                $statement .= $dollarTag;
                $index += strlen($dollarTag) - 1;
                continue;
            }

            if ($character === ';') {
                if (trim($statement) !== '') {
                    $statements[] = trim($statement);
                }
                $statement = '';
                continue;
            }

            $statement .= $character;
        }

        if ($quote !== null || $dollarTag !== null || $blockCommentDepth > 0) {
            throw new RuntimeException('The migration SQL contains an unterminated quoted section or comment.');
        }

        if (trim($statement) !== '') {
            $statements[] = trim($statement);
        }

        return $statements;
    }
}
