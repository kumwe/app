<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Governance;

use stdClass;

/**
 * Renders the capability index as `v1.json`, `v1.sha256` and `capability-index.md`, and checks the committed copy.
 *
 * The JSON bytes define the index digest; the markdown embeds that digest and is the committed authority under
 * `docs/architecture/`. `check()` regenerates the markdown in memory and compares it byte for byte with the
 * committed file, and compares the digest the file embeds with the digest of the regenerated JSON, so a stale
 * document is refused whether its prose or its digest fell behind.
 *
 * @since  2.0.0
 */
final readonly class CapabilityIndexWriter
{
    /**
     * Repository-relative path of the generated JSON.
     *
     * @var    string
     * @since  2.0.0
     */
    public const JSON_PATH = 'build/capability-index/v1.json';

    /**
     * Repository-relative path of the generated digest line.
     *
     * @var    string
     * @since  2.0.0
     */
    public const DIGEST_PATH = 'build/capability-index/v1.sha256';

    /**
     * Repository-relative path of the committed markdown authority.
     *
     * @var    string
     * @since  2.0.0
     */
    public const MARKDOWN_PATH = 'docs/architecture/capability-index.md';

    /**
     * Widest line the markdown may contain.
     *
     * @var    int
     * @since  2.0.0
     */
    private const WIDTH = 120;

    /**
     * Encode the document as the canonical JSON bytes.
     *
     * @param   array<string, mixed>  $document  Index document from `CapabilityIndexBuilder::build()`.
     *
     * @return  string  Pretty-printed JSON with unescaped slashes and Unicode and one trailing newline.
     *
     * @since   2.0.0
     */
    public static function json(array $document): string
    {
        $encodable = $document;
        if (($encodable['ownership'] ?? []) === []) {
            $encodable['ownership'] = new stdClass();
        }
        /** @var list<array<string, mixed>> $packages */
        $packages = $encodable['packages'] ?? [];
        foreach ($packages as $offset => $package) {
            /** @var array<string, mixed> $injection */
            $injection = $package['dependency_injection'] ?? [];
            if (($injection['aliases'] ?? []) === []) {
                $injection['aliases'] = new stdClass();
                $packages[$offset]['dependency_injection'] = $injection;
            }
        }
        $encodable['packages'] = $packages;

        return json_encode(
            $encodable,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /**
     * The index digest of the JSON bytes.
     *
     * @param   string  $json  Output of `json()`.
     *
     * @return  string  Lowercase hexadecimal SHA-256.
     *
     * @since   2.0.0
     */
    public static function digest(string $json): string
    {
        return hash('sha256', $json);
    }

    /**
     * The contents of `v1.sha256`.
     *
     * @param   string  $digest  Index digest.
     *
     * @return  string  `<hex>  v1.json` and a newline, the `sha256sum` line format.
     *
     * @since   2.0.0
     */
    public static function digestLine(string $digest): string
    {
        return $digest . '  v1.json' . "\n";
    }

    /**
     * Read the digest a committed markdown document embeds.
     *
     * @param   string  $markdown  Complete file bytes.
     *
     * @return  string|null  The hexadecimal digest, or null when the document carries none.
     *
     * @since   2.0.0
     */
    public static function embeddedDigest(string $markdown): ?string
    {
        return preg_match('/^- Index digest: sha256:([a-f0-9]{64})$/m', $markdown, $match) === 1 ? $match[1] : null;
    }

    /**
     * Write the three artifacts under a repository root.
     *
     * @param   string                $root      Absolute repository root.
     * @param   array<string, mixed>  $document  Index document.
     *
     * @return  array{json: string, digest: string, markdown: string}  What was written.
     *
     * @throws  GovernanceViolation  When a file cannot be written.
     *
     * @since   2.0.0
     */
    public static function write(string $root, array $document): array
    {
        $json = self::json($document);
        $digest = self::digest($json);
        $markdown = self::markdown($document, $digest);
        foreach ([
            self::JSON_PATH => $json,
            self::DIGEST_PATH => self::digestLine($digest),
            self::MARKDOWN_PATH => $markdown,
        ] as $relative => $bytes) {
            $path = $root . '/' . $relative;
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
                throw GovernanceViolation::at($relative, 'the directory cannot be created', 'check permissions');
            }
            if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                throw GovernanceViolation::at($relative, 'the file cannot be written', 'check permissions');
            }
        }

        return ['json' => $json, 'digest' => $digest, 'markdown' => $markdown];
    }

    /**
     * Compare the committed markdown with what the document renders to now.
     *
     * @param   string                $root      Absolute repository root.
     * @param   array<string, mixed>  $document  Index document.
     *
     * @return  array{digest: string, problems: list<string>}  The current digest and every drift found.
     *
     * @since   2.0.0
     */
    public static function check(string $root, array $document): array
    {
        $json = self::json($document);
        $digest = self::digest($json);
        $markdown = self::markdown($document, $digest);
        $problems = [];
        $committed = is_file(
            $root . '/' . self::MARKDOWN_PATH,
        ) ? file_get_contents($root . '/' . self::MARKDOWN_PATH) : false;
        if (!is_string($committed)) {
            $problems[] = sprintf(
                '%s is missing. Fix: run composer kumwe:capability-index and commit the result.',
                self::MARKDOWN_PATH,
            );

            return ['digest' => $digest, 'problems' => $problems];
        }
        $embedded = self::embeddedDigest($committed);
        if ($embedded === null) {
            $problems[] = sprintf(
                '%s carries no "Index digest" line. Fix: run composer kumwe:capability-index.',
                self::MARKDOWN_PATH,
            );
        } elseif ($embedded !== $digest) {
            $problems[] = sprintf(
                '%s embeds the stale digest sha256:%s; the current index digest is sha256:%s. '
                . 'Fix: run composer kumwe:capability-index and commit the result.',
                self::MARKDOWN_PATH,
                $embedded,
                $digest,
            );
        } elseif ($committed !== $markdown) {
            $problems[] = sprintf(
                '%s differs from the regenerated index. '
                . 'Fix: run composer kumwe:capability-index and commit the result; never hand-edit it.',
                self::MARKDOWN_PATH,
            );
        }

        return ['digest' => $digest, 'problems' => $problems];
    }

    /**
     * Render the committed markdown authority.
     *
     * @param   array<string, mixed>  $document  Index document.
     * @param   string                $digest    Index digest of its JSON bytes.
     *
     * @return  string  Markdown with no line wider than 120 columns.
     *
     * @since   2.0.0
     */
    public static function markdown(array $document, string $digest): string
    {
        /** @var list<array<string, mixed>> $packages */
        $packages = $document['packages'];
        $version2 = count(
            array_filter(
                $packages,
                static fn (array $package): bool => $package['manifest_status'] === 'v2-manifested',
            ),
        );
        $lines = [
            '# Capability index',
            '',
            ...self::paragraph(
                'Generated by `composer kumwe:capability-index` from `composer.lock` and the installed '
                . '`vendor/kumwe/*` '
                . 'manifests; verified by `composer kumwe:capability-index-check`. Do not edit this file by hand: '
                . 'regenerate it. The JSON form lives in `build/capability-index/v1.json` and is not committed.',
            ),
            '',
            '- Index digest: sha256:' . $digest,
            '- Composer lock digest: sha256:' . self::string($document['composer_lock_sha256']),
            '- Generator: `' . self::string($document['generator']) . '`',
            sprintf(
                '- Packages: %d (%d v2-manifested, %d legacy-unmanifested)',
                count($packages),
                $version2,
                count($packages) - $version2,
            ),
            '',
        ];
        foreach ($packages as $package) {
            array_push($lines, ...self::package($package));
        }

        $lines[] = '## Extracted namespaces';
        $lines[] = '';
        /** @var list<array{old_namespace: string, package: string, migration_id: string}> $extracted */
        $extracted = $document['extracted_namespaces'];
        array_push($lines, ...self::table(
            ['Old namespace', 'Package', 'Migration'],
            array_map(
                static fn (
                    array $row,
                ): array => ['`' . $row['old_namespace'] . '`', $row['package'], $row['migration_id']],
                $extracted,
            ),
        ));
        $lines[] = '';
        $lines[] = '## Removed App symbols';
        $lines[] = '';
        /** @var list<array{old_fqcn: string, new_fqcn: string, package: string, migration_id: string}> $removed */
        $removed = $document['removed_symbols'];
        $rows = [];
        foreach ($removed as $row) {
            $rows[] = [
                '`' . $row['old_fqcn'] . '`',
                '`' . $row['new_fqcn'] . '`',
                $row['package'],
                $row['migration_id'],
            ];
        }
        array_push($lines, ...self::table(['Old FQCN', 'New FQCN', 'Package', 'Migration'], $rows));

        return implode("\n", $lines) . "\n";
    }

    /**
     * Render one package section.
     *
     * @param   array<string, mixed>  $package  Package entry.
     *
     * @return  list<string>  Lines, ending with a blank line.
     *
     * @since   2.0.0
     */
    private static function package(array $package): array
    {
        $name = self::string($package['package']);
        /** @var list<string> $license */
        $license = $package['license'];
        /** @var list<string> $namespaces */
        $namespaces = $package['canonical_namespaces'];
        /** @var list<string> $nonResponsibilities */
        $nonResponsibilities = $package['non_responsibilities'];
        $lines = [
            '## ' . $name,
            '',
            '- Version: `' . self::string($package['installed_version']) . '`',
            '- Repository: ' . self::string($package['repository']),
            '- Source reference: `' . self::string($package['source_reference']) . '`',
            '- Dist reference: `' . self::string($package['dist_reference']) . '`',
            '- License: ' . ($license === [] ? 'none declared' : implode(', ', $license)),
            '- Status: ' . self::string($package['manifest_status']),
        ];
        /** @var array<string, mixed>|null $legacy */
        $legacy = $package['legacy'];
        if ($legacy !== null) {
            array_push($lines, ...self::bullet('Legacy reason: ' . self::string($legacy['reason'])));
            $lines[] = sprintf(
                '- Legacy approval: %s on %s',
                self::string($legacy['approved_by']),
                self::string($legacy['approved_on']),
            );
            $verified = $legacy['verified_legacy_release'];
            $lines[] = '- Verified legacy release: ' . ($verified === null ? 'none' : '`' . $verified . '`');
            /** @var list<string> $retired */
            $retired = $legacy['retired_app_namespaces'];
            $retiredText = $retired === [] ? 'none recorded' : implode(', ', self::code($retired));
            array_push($lines, ...self::bullet('Retired App namespaces: ' . $retiredText));
        }
        $lines[] = '- Release gate eligible: ' . ($package['release_gate_eligible'] === true ? 'yes' : 'no');
        array_push($lines, ...self::bullet('Canonical namespaces: ' . implode(', ', self::code($namespaces))));
        array_push($lines, ...self::bullet('Responsibility: ' . self::string($package['responsibility'])));
        $lines[] = '- Non-responsibilities:' . ($nonResponsibilities === [] ? ' none declared' : '');
        foreach ($nonResponsibilities as $item) {
            array_push($lines, ...self::bullet($item, 1));
        }
        $lines[] = '';

        $lines[] = '### Capabilities';
        $lines[] = '';
        /** @var list<array{id: string, title: string, symbols: list<string>,
         *   documentation: list<string>}> $capabilities */
        $capabilities = $package['capabilities'];
        if ($capabilities === []) {
            $lines[] = '_None declared._';
        }
        foreach ($capabilities as $capability) {
            array_push($lines, ...self::bullet('`' . $capability['id'] . '` — ' . $capability['title']));
            array_push($lines, ...self::bullet('Symbols: ' . implode(', ', self::code($capability['symbols'])), 1));
            array_push(
                $lines,
                ...self::bullet(
                    'Documentation: ' . (
                        $capability['documentation'] === [] ? 'none' : implode(
                            ', ',
                            self::code($capability['documentation']),
                        )
                    ),
                    1,
                ),
            );
        }
        $lines[] = '';

        $lines[] = '### Dependency injection';
        $lines[] = '';
        /** @var array{config_provider: string|null, provider_absence_reason: string|null,
         *   factories: list<array{service: string, factory: string, lifetime: string}>,
         *   aliases: array<string, string>,
         *   configuration_keys: list<array{key: string, default: mixed, description: string}>} $injection */
        $injection = $package['dependency_injection'];
        if ($injection['config_provider'] === null) {
            array_push(
                $lines,
                ...self::bullet('Provider: none — ' . ($injection['provider_absence_reason'] ?? 'no reason recorded')),
            );
        } else {
            $lines[] = '- Provider: `' . $injection['config_provider'] . '`';
        }
        $lines[] = '- Factories:' . ($injection['factories'] === [] ? ' none' : '');
        foreach ($injection['factories'] as $factory) {
            array_push(
                $lines,
                ...self::bullet(
                    sprintf('`%s` built by `%s` (%s)', $factory['service'], $factory['factory'], $factory['lifetime']),
                    1,
                ),
            );
        }
        $lines[] = '- Aliases:' . ($injection['aliases'] === [] ? ' none' : '');
        foreach ($injection['aliases'] as $alias => $target) {
            array_push($lines, ...self::bullet(sprintf('`%s` resolves to `%s`', $alias, $target), 1));
        }
        $lines[] = '- Configuration keys:' . ($injection['configuration_keys'] === [] ? ' none' : '');
        foreach ($injection['configuration_keys'] as $key) {
            $default = json_encode(
                $key['default'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            array_push(
                $lines,
                ...self::bullet(sprintf('`%s` (default `%s`) — %s', $key['key'], $default, $key['description']), 1),
            );
        }
        $lines[] = '';

        $lines[] = '### Native requirements';
        $lines[] = '';
        /** @var array{extension: string, abi_major: int, capabilities: list<string>,
         *   corpus_digests: list<string>}|null $native */
        $native = $package['native_requirements'];
        if ($native === null) {
            $lines[] = 'None.';
        } else {
            $lines[] = sprintf('- Extension: `%s` (ABI major %d)', $native['extension'], $native['abi_major']);
            array_push(
                $lines,
                ...self::bullet(
                    'Capabilities: ' . (
                        $native['capabilities'] === [] ? 'none' : implode(', ', self::code($native['capabilities']))
                    ),
                ),
            );
            array_push(
                $lines,
                ...self::bullet(
                    'Corpus digests: ' . (
                        $native['corpus_digests'] === [] ? 'none' : implode(
                            ', ',
                            self::code($native['corpus_digests']),
                        )
                    ),
                ),
            );
        }
        $lines[] = '';

        $lines[] = '### Deprecations';
        $lines[] = '';
        /** @var list<array{symbol: string, since: string, replacement: string|null}> $deprecations */
        $deprecations = $package['deprecations'];
        if ($deprecations === []) {
            $lines[] = 'None.';
        }
        foreach ($deprecations as $deprecation) {
            $replacement = $deprecation['replacement'] === null
                ? 'no replacement'
                : 'replaced by `' . $deprecation['replacement'] . '`';
            array_push(
                $lines,
                ...self::bullet(
                    sprintf('`%s` since %s, %s', $deprecation['symbol'], $deprecation['since'], $replacement),
                ),
            );
        }
        $lines[] = '';

        $lines[] = '### Public symbols';
        $lines[] = '';
        /** @var list<string> $symbols */
        $symbols = $package['public_symbols'];
        array_push($lines, ...self::paragraph(sprintf(
            '%d public symbols from `%s` (digest `%s`).',
            count($symbols),
            self::string($package['public_symbols_source']),
            self::string($package['public_api_digest']),
        )));
        /** @var array{charter: string|null, readme: string|null, public_api: string|null} $documentation */
        $documentation = $package['documentation'];
        $lines[] = '';
        $lines[] = '- Charter: ' . (
            $documentation['charter'] === null ? 'none' : '`' . $documentation['charter'] . '`'
        );
        $lines[] = '- README: ' . ($documentation['readme'] === null ? 'none' : '`' . $documentation['readme'] . '`');
        $lines[] = '- Public API: ' . (
            $documentation['public_api'] === null ? 'none' : '`' . $documentation['public_api'] . '`'
        );
        $lines[] = '';

        $lines[] = '### Handoff';
        $lines[] = '';
        /** @var array{path: string, sha256: string, migration_id: string, change_set: string}|null $handoff */
        $handoff = $package['handoff'];
        if ($handoff === null) {
            $lines[] = 'None (' . self::string($package['manifest_status']) . ').';
        } else {
            $lines[] = '- Path: `' . $handoff['path'] . '`';
            $lines[] = '- Digest: `sha256:' . $handoff['sha256'] . '`';
            $lines[] = '- Migration: `' . $handoff['migration_id'] . '`';
            $lines[] = '- Change set: `' . $handoff['change_set'] . '`';
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * Render rows as a markdown table, or as bullets when a row would exceed the width limit.
     *
     * @param   list<string>        $headers  Column headings.
     * @param   list<list<string>>  $rows     Cell text per row.
     *
     * @return  list<string>  Lines.
     *
     * @since   2.0.0
     */
    private static function table(array $headers, array $rows): array
    {
        if ($rows === []) {
            return ['_None recorded._'];
        }
        $widths = array_map('strlen', $headers);
        foreach ($rows as $row) {
            foreach ($row as $column => $cell) {
                $widths[$column] = max($widths[$column], strlen($cell));
            }
        }
        $render = static function (array $cells) use ($widths): string {
            $padded = [];
            foreach ($cells as $column => $cell) {
                $padded[] = str_pad($cell, $widths[$column]);
            }

            return '| ' . implode(' | ', $padded) . ' |';
        };
        $lines = [$render(
            $headers,
        ), '|' . implode('|', array_map(static fn (int $width): string => str_repeat('-', $width + 2), $widths)) . '|'];
        foreach ($rows as $row) {
            $lines[] = $render($row);
        }
        foreach ($lines as $line) {
            if (strlen($line) > self::WIDTH) {
                $lines = [];
                foreach ($rows as $row) {
                    $parts = [];
                    foreach ($row as $column => $cell) {
                        $parts[] = $headers[$column] . ': ' . $cell;
                    }
                    array_push($lines, ...self::bullet(implode('; ', $parts)));
                }

                return $lines;
            }
        }

        return $lines;
    }

    /**
     * Render one bullet, wrapped to the width limit with continuation lines indented.
     *
     * @param   string  $text   Bullet text.
     * @param   int     $level  Nesting level; each level indents by two spaces.
     *
     * @return  list<string>  Lines.
     *
     * @since   2.0.0
     */
    private static function bullet(string $text, int $level = 0): array
    {
        $indent = str_repeat('  ', $level);
        $wrapped = wordwrap($text, self::WIDTH - strlen($indent) - 2, "\n", true);
        $lines = [];
        foreach (explode("\n", $wrapped) as $offset => $line) {
            $lines[] = $indent . ($offset === 0 ? '- ' : '  ') . $line;
        }

        return $lines;
    }

    /**
     * Render a paragraph wrapped to the width limit.
     *
     * @param   string  $text  Paragraph text.
     *
     * @return  list<string>  Lines.
     *
     * @since   2.0.0
     */
    private static function paragraph(string $text): array
    {
        return explode("\n", wordwrap($text, self::WIDTH, "\n", true));
    }

    /**
     * Wrap each value in backticks.
     *
     * @param   list<string>  $values  Names or paths.
     *
     * @return  list<string>  Code spans.
     *
     * @since   2.0.0
     */
    private static function code(array $values): array
    {
        return array_map(static fn (string $value): string => '`' . $value . '`', $values);
    }

    /**
     * Render a decoded value for a line.
     *
     * @param   mixed  $value  The value.
     *
     * @return  string  The string itself, or its JSON encoding otherwise.
     *
     * @since   2.0.0
     */
    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
    }
}
