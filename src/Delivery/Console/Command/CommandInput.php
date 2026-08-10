<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use InvalidArgumentException;
use JsonException;

/**
 * Shared parsing and validation of the `--name=value` options every console command is invoked with.
 *
 * A command receives nothing but a flat list of strings, and the application services behind it accept
 * nothing but well-formed values, so each helper here bridges exactly one step of that gap and throws
 * with a message naming the option at fault. Keeping them in one place is what makes `--name=value` mean
 * the same thing across the whole `kumwe` command set, and gives operators one wording for the same
 * mistake. `secretFile()` is the reason this matters most: passwords and tokens are read from protected
 * files rather than from arguments, so they never land in shell history or the process table.
 *
 * @since  2.0.0
 */
final class CommandInput
{
    /**
     * Largest protected input document accepted from disk.
     *
     * The console is not covered by the HTTP body limiter, so file-backed payloads need their own fixed
     * ceiling before they are read or decoded. Two mebibytes matches the default HTTP request bound while
     * leaving ordinary record batches and query documents ample room.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_PROTECTED_FILE_BYTES = 2_097_152;

    /**
     * Parse a raw argument list into an option map keyed by option name.
     *
     * Options are strict `--name=value` pairs with a lowercase name. The value may be empty, so
     * `--surface=` yields an empty string rather than an error; a later occurrence of a name replaces an
     * earlier one.
     *
     * @param   list<string>  $arguments  Arguments to parse, with any leading positional ones already removed.
     *
     * @return  array<string, string>  Values keyed by option name without the leading `--`; empty when no
     *          arguments were supplied.
     *
     * @throws  InvalidArgumentException  When an argument is not a `--name=value` pair.
     *
     * @since   2.0.0
     */
    public static function options(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z][a-z0-9-]*)=(.*)$/D', $argument, $matches) !== 1) {
                throw new InvalidArgumentException('Command options must use --name=value syntax.');
            }
            $options[$matches[1]] = $matches[2];
        }
        return $options;
    }

    /**
     * Read an option the command cannot run without.
     *
     * The value is trimmed before the emptiness check, so an option supplied as whitespace is treated as
     * absent rather than passed on as a blank identifier.
     *
     * @param   array<string, string>  $options  Parsed option map to read from.
     * @param   string                 $name     Option name without the leading `--`.
     *
     * @return  string  The value with surrounding whitespace removed, never empty.
     *
     * @throws  InvalidArgumentException  When the option is absent or trims to an empty string.
     *
     * @since   2.0.0
     */
    public static function required(array $options, string $name): string
    {
        $value = trim($options[$name] ?? '');
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('The --%s option is required.', $name));
        }
        return $value;
    }

    /**
     * Read an option that must name a whole number above zero.
     *
     * This is how the management commands take the `--version` an optimistic-locking update compares
     * against. The digit pattern is checked before the cast, so a value such as `3abc` or `-1` is
     * rejected outright instead of being silently coerced to an integer the caller would then trust.
     *
     * @param   array<string, string>  $options  Parsed option map to read from.
     * @param   string                 $name     Option name without the leading `--`.
     *
     * @return  int  The value as an integer, always 1 or greater.
     *
     * @throws  InvalidArgumentException  When the option is absent, blank, or is not a run of digits
     *          denoting a number above zero.
     *
     * @since   2.0.0
     */
    public static function positiveInteger(array $options, string $name): int
    {
        $value = self::required($options, $name);
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1 || $integer === false) {
            throw new InvalidArgumentException(sprintf('The --%s option must be a positive integer.', $name));
        }

        return $integer;
    }

    /**
     * Read an option that must be a whole number from zero through a declared upper bound.
     *
     * Relationship positions legitimately begin at zero, unlike optimistic record versions. Decimal
     * syntax is checked before conversion and `FILTER_VALIDATE_INT` catches platform overflow, so a huge
     * digit string can never be silently saturated to `PHP_INT_MAX`.
     *
     * @param   array<string, string>  $options  Parsed option map to read from.
     * @param   string                 $name     Option name without the leading `--`.
     * @param   int                    $maximum  Largest accepted value, at least zero.
     *
     * @return  int  Validated integer between zero and the declared maximum inclusive.
     *
     * @throws  InvalidArgumentException  When the option is absent, not canonical decimal, overflows, or
     *          falls outside the declared range.
     *
     * @since   2.0.0
     */
    public static function nonNegativeInteger(array $options, string $name, int $maximum = PHP_INT_MAX): int
    {
        $value = self::required($options, $name);
        $integer = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => $maximum],
        ]);
        if ($maximum < 0 || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1 || $integer === false) {
            throw new InvalidArgumentException(sprintf('The --%s option is outside its non-negative bound.', $name));
        }

        return $integer;
    }

    /**
     * Decode an option carrying a JSON object into an associative array.
     *
     * The text is decoded twice on purpose. The first pass decodes to objects so that a JSON array, a
     * string or a number can be rejected — `json_decode($text, true)` would flatten a JSON array into a
     * PHP array indistinguishable from an object's. The second pass produces the associative array the
     * application services take. Nesting is capped at 64 levels, which bounds the work a hostile payload
     * can cause.
     *
     * @param   array<string, string>  $options  Parsed option map to read from.
     * @param   string                 $name     Option name without the leading `--`.
     * @param   string                 $default  JSON text to decode when the option is absent.
     *
     * @return  array<string, mixed>  Object members keyed by property name; empty for `{}`.
     *
     * @throws  JsonException  When the text is not well-formed JSON or nests deeper than 64 levels.
     * @throws  InvalidArgumentException  When the text decodes to anything other than a JSON object.
     * @throws  \LogicException  When a value that validated as an object failed to decode to an
     *          associative array, which the two decode passes make unreachable.
     *
     * @since   2.0.0
     */
    public static function jsonObject(array $options, string $name, string $default = '{}'): array
    {
        $encoded = $options[$name] ?? $default;
        $object = json_decode($encoded, false, 64, JSON_THROW_ON_ERROR);
        if (!$object instanceof \stdClass) {
            throw new InvalidArgumentException(sprintf('The --%s option must be a JSON object.', $name));
        }

        $value = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new \LogicException('A validated JSON object did not decode to an associative array.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Decode a required option carrying a JSON array whose every entry is an object.
     *
     * This is how ordered collections reach the application layer from the console — a content model's
     * states and transitions, for instance. Order is preserved, and every entry is checked before any is
     * returned, so a caller never receives a partly validated list. An entry has to carry at least one
     * member: an empty `{}` decodes to an empty array, which is indistinguishable from a nested list and
     * is rejected with the rest.
     *
     * @param   array<string, string>  $options  Parsed option map to read from.
     * @param   string                 $name     Option name without the leading `--`.
     *
     * @return  list<array<string, mixed>>  Decoded objects in the order the operator listed them.
     *
     * @throws  JsonException  When the text is not well-formed JSON or nests deeper than 64 levels.
     * @throws  InvalidArgumentException  When the option is absent or blank, the text does not decode to a
     *          JSON array, or an entry is not a JSON object.
     *
     * @since   2.0.0
     */
    public static function jsonObjectList(array $options, string $name): array
    {
        $value = json_decode(self::required($options, $name), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('The --%s option must be a JSON array.', $name));
        }
        foreach ($value as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new InvalidArgumentException(sprintf('Every --%s item must be a JSON object.', $name));
            }
        }

        /** @var list<array<string, mixed>> $value */
        return $value;
    }

    /**
     * Render a command result as the pretty-printed JSON the management commands print.
     *
     * Slashes are left unescaped so that paths and URLs in the result stay readable in a terminal and
     * can be copied straight back into another command.
     *
     * @param   array<string, mixed>  $value  Result structure returned by an application service.
     *
     * @return  string  Indented JSON text, without a trailing newline.
     *
     * @throws  JsonException  When the value holds data JSON cannot represent, such as malformed UTF-8.
     *
     * @since   2.0.0
     */
    public static function render(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Decode an owner-protected JSON file whose top level must be an object.
     *
     * Record values and query documents may contain personal or commercially sensitive data, so they are
     * never accepted inline as command arguments. The file is opened only after the same absolute-path,
     * regular-file, non-symlink and owner-only checks used for credentials, and its size is bounded before
     * JSON decoding. A first object-mode decode distinguishes `{}` from `[]`; the second produces the
     * associative structure consumed by application commands.
     *
     * @param   string  $path  Absolute path to an owner-protected JSON document.
     *
     * @return  array<string, mixed>  Decoded object members, preserving exact string values.
     *
     * @throws  JsonException  When the document is malformed or nests deeper than 64 levels.
     * @throws  InvalidArgumentException  When the path or file protection is unsafe, the document exceeds
     *          the byte bound, or its top level is not an object.
     * @throws  \LogicException  When an object-mode decode succeeded but the associative decode did not.
     *
     * @since   2.0.0
     */
    public static function protectedJsonObject(string $path): array
    {
        $encoded = self::protectedFileContents($path);
        $object = json_decode($encoded, false, 64, JSON_THROW_ON_ERROR);
        if (!$object instanceof \stdClass) {
            throw new InvalidArgumentException('A protected JSON document must contain an object.');
        }

        $value = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new \LogicException('A validated JSON object did not decode to an associative array.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Decode an owner-protected JSON file whose top level is a bounded string list.
     *
     * Ordered relationship identities are carried this way rather than inline, keeping business identities
     * out of the process table. An empty list is valid, every item must be a string, and more than one
     * thousand entries is refused before a command object is assembled.
     *
     * @param   string  $path  Absolute path to an owner-protected JSON document.
     *
     * @return  list<string>  String values in their declared order.
     *
     * @throws  JsonException  When the document is malformed or nests deeper than 64 levels.
     * @throws  InvalidArgumentException  When the file is unsafe, the top level is not a list, an item is
     *          not a string, or the list exceeds one thousand entries.
     *
     * @since   2.0.0
     */
    public static function protectedJsonStringList(string $path): array
    {
        $encoded = self::protectedFileContents($path);
        $decoded = json_decode($encoded, false, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('A protected JSON document must contain a list.');
        }
        $values = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($values) || !array_is_list($values) || count($values) > 1000) {
            throw new InvalidArgumentException('A protected JSON string list exceeds its declared bound.');
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException('A protected JSON list must contain only strings.');
            }
        }

        return $values;
    }

    /**
     * Read a secret — an access token or a password — out of a file the operator has protected.
     *
     * Secrets are never accepted as arguments, where they would be visible in shell history and in the
     * process table, so this is the only way one enters a console command. The file has to be named by an
     * absolute path, be a readable regular file rather than a symlink pointing somewhere else, and carry
     * no group or other permission bits at all; anything short of that is refused rather than warned
     * about, because a secret that other accounts could already read is no longer a secret.
     *
     * @param   string  $path  Absolute filesystem path of the file holding the secret.
     *
     * @return  string  File contents with surrounding whitespace removed, never empty.
     *
     * @throws  InvalidArgumentException  When the path is relative, names no readable regular file, is a
     *          symlink, is readable or writable by group or others, or the file trims to nothing.
     *
     * @since   2.0.0
     */
    public static function secretFile(string $path): string
    {
        $secret = trim(self::protectedFileContents($path));
        if ($secret === '') {
            throw new InvalidArgumentException('The secret file is empty.');
        }
        return $secret;
    }

    /**
     * Read one bounded owner-only file while proving the opened inode is the one that was inspected.
     *
     * A pre-open `lstat()` rejects a final-component symlink and unsafe mode. The file is then opened in
     * binary read-only mode and compared by device and inode with `fstat()` before any bytes are trusted,
     * closing the ordinary replace-between-check-and-open race. A second mode and size check covers a file
     * changed around the open, and the stream read stops one byte beyond the ceiling so growth cannot turn a
     * previously safe file into an unbounded allocation.
     *
     * @param   string  $path  Absolute path to the protected file.
     *
     * @return  string  File contents exactly as stored, never larger than the declared bound.
     *
     * @throws  InvalidArgumentException  When the path, file type, permissions, identity, readability or
     *          size is unsafe.
     *
     * @since   2.0.0
     */
    private static function protectedFileContents(string $path): string
    {
        $before = $path === '' ? false : @lstat($path);
        if (
            !str_starts_with($path, DIRECTORY_SEPARATOR)
            || !is_array($before)
            || (($before['mode'] ?? 0) & 0o170000) !== 0o100000
            || (($before['mode'] ?? 0) & 0o077) !== 0
            || !isset($before['size'])
            || !is_int($before['size'])
            || $before['size'] > self::MAX_PROTECTED_FILE_BYTES
        ) {
            throw new InvalidArgumentException(
                'Protected files must be absolute, owner-only, bounded, readable regular files, not symlinks.',
            );
        }

        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new InvalidArgumentException('The protected file could not be read safely.');
        }
        try {
            $opened = fstat($stream);
            if (
                !is_array($opened)
                || ($opened['dev'] ?? null) !== ($before['dev'] ?? null)
                || ($opened['ino'] ?? null) !== ($before['ino'] ?? null)
                || (($opened['mode'] ?? 0) & 0o170000) !== 0o100000
                || (($opened['mode'] ?? 0) & 0o077) !== 0
                || !isset($opened['size'])
                || !is_int($opened['size'])
                || $opened['size'] > self::MAX_PROTECTED_FILE_BYTES
            ) {
                throw new InvalidArgumentException('The protected file changed while it was being opened.');
            }
            $contents = stream_get_contents($stream, self::MAX_PROTECTED_FILE_BYTES + 1);
            if (!is_string($contents) || strlen($contents) > self::MAX_PROTECTED_FILE_BYTES) {
                throw new InvalidArgumentException('The protected file exceeds its declared size bound.');
            }

            return $contents;
        } finally {
            fclose($stream);
        }
    }
}
