<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use RuntimeException;

final readonly class SqlStatementSplitter
{
    /** @return list<string> */
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
