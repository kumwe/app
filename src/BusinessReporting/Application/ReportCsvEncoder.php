<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessReporting\Domain\ReportValueType;

/**
 * Deterministic UTF-8 CSV encoder with spreadsheet-formula injection defenses.
 *
 * Every field is quoted, line endings are CRLF, the first chunk is a UTF-8 BOM, and text whose first
 * meaningful character could be interpreted as a spreadsheet formula is prefixed with an apostrophe.
 * Exact numeric outputs retain their sign because their declared type, not their spelling, proves them.
 *
 * @since  2.0.0
 */
final class ReportCsvEncoder
{
    /**
     * Yield one header and one chunk per row without assembling the whole artifact again.
     *
     * @param   ReportExecutionResult  $result  Disclosure-safe typed report result.
     *
     * @return  iterable<string>  UTF-8 BOM followed by deterministic CSV records.
     *
     * @since   2.0.0
     */
    public function encode(ReportExecutionResult $result): iterable
    {
        yield "\xEF\xBB\xBF";
        $aliases = array_keys($result->labels);
        $header = [];
        foreach ($result->labels as $label) {
            $header[] = $this->field($label, ReportValueType::String);
        }
        yield implode(',', $header) . "\r\n";
        foreach ($result->rows as $row) {
            $fields = [];
            foreach ($aliases as $alias) {
                $fields[] = $this->field($row[$alias] ?? null, $result->types[$alias]);
            }
            yield implode(',', $fields) . "\r\n";
        }
    }

    /**
     * Encode one typed value as an always-quoted RFC 4180 field.
     *
     * @param   bool|int|string|null  $value  Safe report cell.
     * @param   ReportValueType       $type   Declared type deciding formula neutralization.
     *
     * @return  string  Quoted field with internal quotes doubled.
     *
     * @throws  InvalidArgumentException  When a string is invalid UTF-8 or contains a NUL byte.
     *
     * @since   2.0.0
     */
    private function field(bool|int|string|null $value, ReportValueType $type): string
    {
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
        if (!mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
            throw new InvalidArgumentException('A CSV cell must be valid UTF-8 without NUL bytes.');
        }
        if (
            in_array(
                $type,
                [
                    ReportValueType::String,
                    ReportValueType::Identifier,
                    ReportValueType::ConvertedMoney,
                    ReportValueType::ConvertedQuantity,
                ],
                true,
            )
            && preg_match('/^[\x00-\x20]*[=+\-@\t\r\n]/u', $text) === 1
        ) {
            $text = "'" . $text;
        }

        return '"' . str_replace('"', '""', $text) . '"';
    }
}
