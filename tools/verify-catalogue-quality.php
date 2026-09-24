#!/usr/bin/env php
<?php

/**
 * Qualify every interface catalogue in its own right: ICU validity, plural coverage and real translation.
 *
 * `composer translation:check` proves each catalogue is complete and compiles; it cannot prove the
 * wording is usable in its language. This gate reads the authored XLIFF of all nine Version 2
 * catalogues and fails on three kinds of defect a completeness check lets through:
 *
 *   1. ICU. Every pattern is compiled and then formatted by PHP's `MessageFormatter` for its own locale
 *      with representative arguments — counts across the plural boundaries, every `select` key, a
 *      timestamp for dates — and any ICU refusal or formatting failure fails the build. A translation
 *      must also name exactly the arguments its source names, so a dropped or misspelt `{name}` cannot
 *      render as a literal brace. The `core.studio.shell.*` corpus uses Studio's own placeholder grammar
 *      and is consumed without App ICU formatting (see `MessageCatalogueCompiler`), so it is held to
 *      placeholder parity instead.
 *   2. Plural categories per CLDR, as the installed ICU applies them. For each locale the categories ICU
 *      selects for the counts 0 to 1000 are probed — `one`/`other` for English, Afrikaans and German,
 *      `one`/`two`/`other` for Hebrew, all six for Arabic, `other` alone for Simplified Chinese — and
 *      every `plural` in that catalogue must declare each of them, unless an explicit `=n` selector
 *      covers every count that would reach it. A missing form is not a crash: ICU silently falls back to
 *      `other`, which is exactly why it needs a gate. Categories reached only beyond 1000 (the Spanish
 *      and Portuguese `many`, used for exact multiples of a million) are reported, not required: their
 *      `other` form is grammatical there.
 *   3. Untranslated wording. A non-English target identical to its source fails when the source carries
 *      a letter and is not code-like — no `{`, no `/`, not an upper-case token such as `HTTP API`, not
 *      made only of product names — unless the reasoned register below records why the identical word
 *      is correct in that language. A register entry nothing matches fails too, so the register cannot
 *      outlive the words it excuses.
 *   4. Markup. A message may carry only the inline elements `t_html` treats as safe — `code`, `em`,
 *      `span` and `strong` — around its own words. Anything else, such as an icon's `<svg>`, is structure
 *      that belongs in the template: `t()` escapes it, so it renders as literal markup text.
 *
 * Usage:
 *   php tools/verify-catalogue-quality.php [--catalogues=<directory>]
 *
 * `--catalogues` points at a directory of `<locale>.xlf` files, which is how the architecture test proves
 * the gate red on a deliberately broken copy; the default is `resources/localization/messages`.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\Localization\Infrastructure\XliffCatalogueReader;
use Kumwe\Localization\Application\SupportedLocales;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Composer dependencies are required to qualify message catalogues.\n");
    exit(78);
}
require $autoload;

if (!extension_loaded('intl')) {
    fwrite(STDERR, "ext-intl is required: the catalogues are qualified with the ICU the runtime formats with.\n");
    exit(78);
}

/**
 * Product names and product terms that are the same word in every language.
 *
 * Text made only of these, digits, whitespace and punctuation is exempt from the identical-target rule.
 * `Blueprint` is the Studio authoring concept (ADR 0020, `STUDIO-PROD-*`), named the same way in Studio's
 * own interface; a catalogue may still translate it, as Hebrew does.
 *
 * @var  list<string>
 */
const KUMWE_PRODUCT_TERMS = ['Kumwe Interface Standard', 'Kumwe App', 'Kumwe', 'Blueprint'];

/**
 * The reasoned register of targets that are correctly identical to their English source.
 *
 * Keyed by the exact source text; each entry names the locales it applies to and why. An identical
 * target that is not code-like, not a product term and not listed here fails the gate, and an entry that
 * no longer matches any identical target fails as stale.
 *
 * @var  array<string, array{locales: list<string>, reason: string}>
 */
const KUMWE_IDENTICAL_REGISTER = [
    'Status' => [
        'locales' => ['af', 'de', 'pt-BR'],
        'reason' => 'Status is the standard noun for a state in Afrikaans, German and Portuguese.',
    ],
    'Parameters' => [
        'locales' => ['af'],
        'reason' => 'Afrikaans plural of parameter is spelt the same as in English.',
    ],
    'Minimum' => [
        'locales' => ['af', 'de'],
        'reason' => 'Latin loanword used unchanged in Afrikaans and German.',
    ],
    'Maximum' => [
        'locales' => ['de'],
        'reason' => 'Latin loanword used unchanged in German.',
    ],
    'Filters' => [
        'locales' => ['af'],
        'reason' => 'Afrikaans plural of filter is spelt the same as in English.',
    ],
    'Detail' => [
        'locales' => ['af', 'de'],
        'reason' => 'Detail is the ordinary noun in Afrikaans and German.',
    ],
    'Binding' => [
        'locales' => ['af'],
        'reason' => 'Binding is the ordinary Afrikaans noun for a binding.',
    ],
    'Bindings' => [
        'locales' => ['af'],
        'reason' => 'Afrikaans plural of binding is spelt the same as in English.',
    ],
    'Media' => [
        'locales' => ['af'],
        'reason' => 'Media is the Afrikaans word for media.',
    ],
    'Boolean' => [
        'locales' => ['af'],
        'reason' => 'Names the Boolean value type after George Boole; Afrikaans technical usage keeps the name.',
    ],
    'Model' => [
        'locales' => ['af'],
        'reason' => 'Model is the Afrikaans word for model.',
    ],
    'Operator' => [
        'locales' => ['de'],
        'reason' => 'Operator is the German term for a comparison operator.',
    ],
    'Highlights' => [
        'locales' => ['de'],
        'reason' => 'Established German loanword for featured points of an article.',
    ],
    'Administrator' => [
        'locales' => ['de'],
        'reason' => 'Administrator is the German role name.',
    ],
    'Dashboard' => [
        'locales' => ['de'],
        'reason' => 'Established German term for the administrator overview screen.',
    ],
    'Dashboard · Kumwe' => [
        'locales' => ['de'],
        'reason' => 'German keeps Dashboard, followed by the product name.',
    ],
    'Dashboard · Kumwe Portal' => [
        'locales' => ['de'],
        'reason' => 'German keeps Dashboard and Portal, followed by the product name.',
    ],
    'Name' => [
        'locales' => ['de'],
        'reason' => 'Name is the German noun for name.',
    ],
    'Global' => [
        'locales' => ['de', 'es', 'pt-BR'],
        'reason' => 'Global is spelt the same in German, Spanish and Portuguese.',
    ],
    '(optional)' => [
        'locales' => ['de'],
        'reason' => 'Optional is the German adjective for optional.',
    ],
    'optional' => [
        'locales' => ['de'],
        'reason' => 'Optional is the German adjective for optional.',
    ],
    'Token' => [
        'locales' => ['de', 'es', 'pt-BR'],
        'reason' => 'Token is the established technical term for an API credential in these languages.',
    ],
    'Tokens' => [
        'locales' => ['de', 'es', 'pt-BR'],
        'reason' => 'Plural of the established technical loanword Token in German, Spanish and Portuguese.',
    ],
    'Website' => [
        'locales' => ['de'],
        'reason' => 'Website is the established German word for a web site.',
    ],
    'Platform' => [
        'locales' => ['af'],
        'reason' => 'Platform is the Afrikaans word for platform.',
    ],
    'Details' => [
        'locales' => ['de'],
        'reason' => 'Details is the German plural of Detail.',
    ],
    'Workflow' => [
        'locales' => ['de'],
        'reason' => 'Established German technical term, used consistently across the German catalogue.',
    ],
    'Workflows' => [
        'locales' => ['de'],
        'reason' => 'German plural of the established term Workflow.',
    ],
    'Portal' => [
        'locales' => ['de', 'es', 'pt-BR'],
        'reason' => 'Portal is spelt the same in German, Spanish and Portuguese.',
    ],
    'Kumwe Portal' => [
        'locales' => ['af', 'de', 'es', 'pt-BR'],
        'reason' => 'Product name; af, de, es and pt-BR spell Portal the same way or keep the product title.',
    ],
    'Position' => [
        'locales' => ['de'],
        'reason' => 'Position is the German noun for position.',
    ],
    'Definition' => [
        'locales' => ['de'],
        'reason' => 'Definition is the German noun for definition.',
    ],
    'Operation' => [
        'locales' => ['de'],
        'reason' => 'Operation is the German noun for operation.',
    ],
    'Quorum' => [
        'locales' => ['de'],
        'reason' => 'Latin term used unchanged in German.',
    ],
    'Version' => [
        'locales' => ['de'],
        'reason' => 'Version is the German noun for version.',
    ],
    'Text' => [
        'locales' => ['de'],
        'reason' => 'Text is the German noun for text.',
    ],
    'Null' => [
        'locales' => ['de', 'he', 'zh-Hans'],
        'reason' => 'Names the JSON null literal a typed value may hold; German also spells the word Null.',
    ],
    'Inline' => [
        'locales' => ['de'],
        'reason' => 'Established German typographic term for in-line presentation.',
    ],
    'Design' => [
        'locales' => ['de', 'pt-BR'],
        'reason' => 'Design is the established German and Brazilian Portuguese term.',
    ],
    'Layout' => [
        'locales' => ['de', 'pt-BR'],
        'reason' => 'Layout is the established German and Brazilian Portuguese term.',
    ],
    'Information' => [
        'locales' => ['de'],
        'reason' => 'Information is the German noun for information.',
    ],
    'System' => [
        'locales' => ['de'],
        'reason' => 'System is the German noun for system.',
    ],
    'No' => [
        'locales' => ['es'],
        'reason' => 'No is the Spanish word for no.',
    ],
    'Personal' => [
        'locales' => ['es'],
        'reason' => 'Personal is the Spanish adjective for personal.',
    ],
    'Actor' => [
        'locales' => ['es'],
        'reason' => 'Actor is the Spanish noun for actor.',
    ],
    'Roles' => [
        'locales' => ['es'],
        'reason' => 'Roles is the Spanish plural of rol.',
    ],
    'Editorial' => [
        'locales' => ['es', 'pt-BR'],
        'reason' => 'Editorial is spelt the same in Spanish and Portuguese.',
    ],
    'Decimal' => [
        'locales' => ['es', 'pt-BR'],
        'reason' => 'Decimal is spelt the same in Spanish and Portuguese.',
    ],
    'Inspector' => [
        'locales' => ['es'],
        'reason' => 'Inspector is the Spanish noun for inspector.',
    ],
    'Error' => [
        'locales' => ['es'],
        'reason' => 'Error is the Spanish noun for error.',
    ],
    'Menu' => [
        'locales' => ['pt-BR'],
        'reason' => 'Menu is the Portuguese noun for menu.',
    ],
    'Menus' => [
        'locales' => ['pt-BR'],
        'reason' => 'Menus is the Portuguese plural of menu.',
    ],
    'Menus · Kumwe' => [
        'locales' => ['pt-BR'],
        'reason' => 'Portuguese plural of menu followed by the product name.',
    ],
    'Site' => [
        'locales' => ['pt-BR'],
        'reason' => 'Site is the Brazilian Portuguese term for a website.',
    ],
    'Media · Kumwe' => [
        'locales' => ['af'],
        'reason' => 'Media is the Afrikaans word for media, followed by the product name.',
    ],
];

/**
 * Inline elements a message may carry around its own words; the same subset `t_html` renders.
 *
 * @var  list<string>
 */
const KUMWE_MESSAGE_MARKUP = ['code', 'em', 'span', 'strong'];

/**
 * Parse one ICU MessageFormat pattern into its arguments and selecting constructs.
 *
 * Apostrophe quoting follows ICU's default `DOUBLE_OPTIONAL` mode: `''` is a literal apostrophe, and a
 * single apostrophe starts a quoted literal only before `{`, `}`, `#` (inside a plural) or `|`. The
 * pattern has already been accepted by `MessageFormatter::create()`, so malformed input is not expected
 * here; the parser exists to learn what ICU will not report — which arguments a pattern names, of which
 * type, and which forms each plural and select declares.
 *
 * @param   string  $pattern  Pattern ICU has accepted.
 *
 * @return  array{arguments: array<string, array<string, true>>, selectors: list<array{name: string,
 *          type: string, keys: list<string>}>}  Argument types keyed by name, and every selecting construct.
 */
function kumwe_icu_structure(string $pattern): array
{
    $structure = ['arguments' => [], 'selectors' => []];
    $offset = 0;
    kumwe_icu_message($pattern, $offset, 0, false, $structure);

    return $structure;
}

/**
 * Walk message text until the closing brace of the enclosing option, recording every argument met.
 *
 * @param   string  $pattern    Pattern being walked.
 * @param   int     $offset     Byte offset, advanced past what was consumed.
 * @param   int     $depth      Nesting depth; zero is the top-level message.
 * @param   bool    $inPlural   Whether `#` is special here because a plural or selectordinal encloses it.
 * @param   array{arguments: array<string, array<string, true>>, selectors: list<array{name: string,
 *          type: string, keys: list<string>}>}  $structure  Accumulated structure.
 *
 * @return  void
 */
function kumwe_icu_message(string $pattern, int &$offset, int $depth, bool $inPlural, array &$structure): void
{
    $length = strlen($pattern);
    while ($offset < $length) {
        $character = $pattern[$offset];
        if ($character === "'") {
            $next = $pattern[$offset + 1] ?? '';
            if ($next === "'") {
                $offset += 2;
                continue;
            }
            if ($next === '{' || $next === '}' || $next === '|' || ($inPlural && $next === '#')) {
                $offset += 2;
                while ($offset < $length) {
                    if ($pattern[$offset] === "'") {
                        if (($pattern[$offset + 1] ?? '') === "'") {
                            $offset += 2;
                            continue;
                        }
                        $offset++;
                        break;
                    }
                    $offset++;
                }
                continue;
            }
            $offset++;
            continue;
        }
        if ($character === '{') {
            $offset++;
            kumwe_icu_argument($pattern, $offset, $depth, $structure);
            continue;
        }
        if ($character === '}' && $depth > 0) {
            return;
        }
        $offset++;
    }
}

/**
 * Read one argument after its opening brace and consume through its closing brace.
 *
 * @param   string  $pattern    Pattern being walked.
 * @param   int     $offset     Byte offset just after the opening brace, advanced past the closing one.
 * @param   int     $depth      Nesting depth of the message containing the argument.
 * @param   array{arguments: array<string, array<string, true>>, selectors: list<array{name: string,
 *          type: string, keys: list<string>}>}  $structure  Accumulated structure.
 *
 * @return  void
 */
function kumwe_icu_argument(string $pattern, int &$offset, int $depth, array &$structure): void
{
    $length = strlen($pattern);
    $skip = static function () use ($pattern, &$offset, $length): void {
        while ($offset < $length && ctype_space($pattern[$offset])) {
            $offset++;
        }
    };
    $skip();
    $name = '';
    while ($offset < $length && !ctype_space($pattern[$offset]) && !in_array($pattern[$offset], [',', '}'], true)) {
        $name .= $pattern[$offset++];
    }
    $skip();
    $type = 'simple';
    if (($pattern[$offset] ?? '') === ',') {
        $offset++;
        $skip();
        $type = '';
        while ($offset < $length && ctype_alpha($pattern[$offset])) {
            $type .= $pattern[$offset++];
        }
        $skip();
    }
    $structure['arguments'][$name][$type] = true;
    if (($pattern[$offset] ?? '') === '}') {
        $offset++;
        return;
    }
    // A comma follows: either an option list (plural, selectordinal, select, choice) or a style.
    $offset++;
    if (!in_array($type, ['plural', 'selectordinal', 'select'], true)) {
        $nesting = 0;
        while ($offset < $length) {
            $character = $pattern[$offset++];
            if ($character === '{') {
                $nesting++;
            } elseif ($character === '}') {
                if ($nesting === 0) {
                    return;
                }
                $nesting--;
            }
        }
        return;
    }
    $keys = [];
    while ($offset < $length) {
        $skip();
        if (($pattern[$offset] ?? '') === '}') {
            $offset++;
            break;
        }
        $key = '';
        while ($offset < $length && !ctype_space($pattern[$offset]) && $pattern[$offset] !== '{') {
            $key .= $pattern[$offset++];
        }
        if (str_starts_with($key, 'offset:')) {
            continue;
        }
        $skip();
        if (($pattern[$offset] ?? '') !== '{') {
            break;
        }
        $offset++;
        kumwe_icu_message($pattern, $offset, $depth + 1, $type !== 'select', $structure);
        $offset++;
        $keys[] = $key;
    }
    $structure['selectors'][] = ['name' => $name, 'type' => $type, 'keys' => $keys];
}

/**
 * Probe which plural or ordinal category the installed ICU selects for each count 0 to 1000.
 *
 * @param   string  $locale  Catalogue locale.
 * @param   string  $type    Either `plural` or `selectordinal`.
 *
 * @return  array<int, string>  Category keyed by count.
 */
function kumwe_icu_categories(string $locale, string $type): array
{
    static $cache = [];
    $key = $locale . '|' . $type;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $formatter = new MessageFormatter(
        $locale,
        '{n, ' . $type . ', zero {zero} one {one} two {two} few {few} many {many} other {other}}',
    );
    $categories = [];
    for ($count = 0; $count <= 1000; $count++) {
        $categories[$count] = (string) $formatter->format(['n' => $count]);
    }

    return $cache[$key] = $categories;
}

/**
 * Whether identical-to-source text is code-like or a product term rather than untranslated wording.
 *
 * @param   string  $text  Source text of a unit whose target equals it.
 *
 * @return  ?string  The exemption category, or null when the text is wording a translator should own.
 */
function kumwe_identical_exemption(string $text): ?string
{
    if (preg_match('/\p{L}/u', $text) !== 1) {
        return 'no letters';
    }
    if (str_contains($text, '{') || str_contains($text, '/')) {
        return 'placeholder or path';
    }
    if (preg_match_all('/\p{L}+/u', $text, $words) > 0 && $words[0] === array_map('mb_strtoupper', $words[0])) {
        return 'upper-case token';
    }
    $remainder = str_replace(KUMWE_PRODUCT_TERMS, '', $text);
    if (preg_match('/\p{L}/u', $remainder) !== 1) {
        return 'product term';
    }
    if (preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)+$/D', $text) === 1) {
        return 'machine identifier';
    }

    return null;
}

$arguments = array_slice($argv, 1);
$directory = $root . '/resources/localization/messages';
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--catalogues=')) {
        $directory = rtrim(substr($argument, strlen('--catalogues=')), '/');
        continue;
    }
    fwrite(STDERR, "Usage: php tools/verify-catalogue-quality.php [--catalogues=<directory>]\n");
    exit(64);
}

$reader = new XliffCatalogueReader();
$source = SupportedLocales::SOURCE;
$sourceUnits = $reader->readFile($directory . '/' . $source . '.xlf')->units;
$englishLocales = ['en-GB', 'en-US'];
$counts = [0, 1, 2, 3, 4, 5, 6, 7, 11, 12, 21, 22, 100, 101, 102, 111, 1000, 1000000];
$timestamp = 1758700000;
$failures = [];
$registerUsed = [];
$report = [];

foreach (SupportedLocales::VERSION_TWO as $locale) {
    $units = $locale === $source ? $sourceUnits : $reader->readFile($directory . '/' . $locale . '.xlf')->units;
    $formatted = 0;
    $pluralMessages = 0;
    $identical = 0;
    $exempt = [];
    $advisory = [];
    foreach ($units as $identifier => $unit) {
        $pattern = $locale === $source ? $unit['source'] : ($unit['target'] ?? '');
        $origin = $sourceUnits[$identifier]['source'] ?? $unit['source'];
        if (preg_match_all('/<\/?([A-Za-z][A-Za-z0-9-]*)/', $pattern, $tags) > 0) {
            $unsafe = array_values(array_diff(
                array_unique(array_map('strtolower', $tags[1])),
                KUMWE_MESSAGE_MARKUP,
            ));
            if ($unsafe !== []) {
                $failures[] = sprintf(
                    '%s %s carries <%s> markup; only %s may sit inside a message. Keep icons and structure '
                        . 'in the template.',
                    $locale,
                    $identifier,
                    implode('>, <', $unsafe),
                    implode(', ', KUMWE_MESSAGE_MARKUP),
                );
            }
        }
        if (str_starts_with($identifier, 'core.studio.shell.')) {
            preg_match_all('/\{([^{}\s]+)\}/u', $pattern, $mine);
            preg_match_all('/\{([^{}\s]+)\}/u', $origin, $theirs);
            $mineNames = array_values(array_unique($mine[1]));
            $theirNames = array_values(array_unique($theirs[1]));
            sort($mineNames);
            sort($theirNames);
            if ($mineNames !== $theirNames) {
                $failures[] = sprintf(
                    '%s %s names placeholders {%s} but its source names {%s}.',
                    $locale,
                    $identifier,
                    implode('}, {', $mineNames),
                    implode('}, {', $theirNames),
                );
            }
        } else {
            $formatter = MessageFormatter::create($locale, $pattern);
            if (!$formatter instanceof MessageFormatter) {
                $failures[] = sprintf(
                    '%s %s is refused by ICU: %s',
                    $locale,
                    $identifier,
                    intl_get_error_message(),
                );
                continue;
            }
            $structure = kumwe_icu_structure($pattern);
            $originStructure = kumwe_icu_structure($origin);
            $names = array_keys($structure['arguments']);
            $originNames = array_keys($originStructure['arguments']);
            sort($names);
            sort($originNames);
            if ($names !== $originNames) {
                $failures[] = sprintf(
                    '%s %s names arguments {%s} but its source names {%s}.',
                    $locale,
                    $identifier,
                    implode('}, {', $names),
                    implode('}, {', $originNames),
                );
            }
            $selectKeys = [];
            foreach ($structure['selectors'] as $selector) {
                if ($selector['type'] === 'select') {
                    $selectKeys[$selector['name']] = array_values(array_unique(array_merge(
                        $selectKeys[$selector['name']] ?? [],
                        $selector['keys'],
                    )));
                }
            }
            $passes = max(count($counts), ...array_values(array_map('count', $selectKeys ?: [[]])));
            for ($pass = 0; $pass < $passes; $pass++) {
                $values = [];
                foreach ($structure['arguments'] as $name => $types) {
                    if (isset($types['date']) || isset($types['time'])) {
                        $values[$name] = $timestamp;
                    } elseif (isset($types['plural']) || isset($types['selectordinal']) || isset($types['number'])) {
                        $values[$name] = $counts[$pass % count($counts)];
                    } elseif (isset($selectKeys[$name]) && $selectKeys[$name] !== []) {
                        $values[$name] = $selectKeys[$name][$pass % count($selectKeys[$name])];
                    } else {
                        $values[$name] = 'Kumwe';
                    }
                }
                $result = $formatter->format($values);
                if ($result === false) {
                    $failures[] = sprintf(
                        '%s %s cannot be formatted with %s: %s',
                        $locale,
                        $identifier,
                        json_encode($values, JSON_UNESCAPED_UNICODE),
                        $formatter->getErrorMessage(),
                    );
                    break;
                }
            }
            $formatted++;
            foreach ($structure['selectors'] as $selector) {
                if ($selector['type'] === 'select') {
                    if (!in_array('other', $selector['keys'], true)) {
                        $failures[] = sprintf(
                            '%s %s selects on {%s} without an other branch.',
                            $locale,
                            $identifier,
                            $selector['name'],
                        );
                    }
                    continue;
                }
                $pluralMessages++;
                $explicit = [];
                $declared = [];
                foreach ($selector['keys'] as $key) {
                    if (str_starts_with($key, '=')) {
                        $explicit[(int) substr($key, 1)] = true;
                    } else {
                        $declared[$key] = true;
                    }
                }
                $missing = [];
                foreach (kumwe_icu_categories($locale, $selector['type']) as $count => $category) {
                    if (!isset($explicit[$count]) && !isset($declared[$category]) && !isset($missing[$category])) {
                        $missing[$category] = $count;
                    }
                }
                foreach ($missing as $category => $count) {
                    $failures[] = sprintf(
                        '%s %s has no `%s` form for {%s}; ICU selects `%s` for %d and would render `other`.',
                        $locale,
                        $identifier,
                        $category,
                        $selector['name'],
                        $category,
                        $count,
                    );
                }
                $large = (new MessageFormatter(
                    $locale,
                    '{n, ' . $selector['type'] . ', zero {zero} one {one} two {two} few {few} many {many} '
                        . 'other {other}}',
                ))->format(['n' => 1000000]);
                if (
                    is_string($large)
                    && !isset($declared[$large])
                    && !in_array($large, kumwe_icu_categories($locale, $selector['type']), true)
                ) {
                    $advisory[$large] = ($advisory[$large] ?? 0) + 1;
                }
            }
        }
        if ($locale === $source || in_array($locale, $englishLocales, true) || $pattern !== $unit['source']) {
            continue;
        }
        $identical++;
        $category = kumwe_identical_exemption($origin);
        if ($category !== null) {
            $exempt[$category] = ($exempt[$category] ?? 0) + 1;
            continue;
        }
        $entry = KUMWE_IDENTICAL_REGISTER[$origin] ?? null;
        if ($entry !== null && in_array($locale, $entry['locales'], true)) {
            $registerUsed[$origin][$locale] = true;
            $exempt['register'] = ($exempt['register'] ?? 0) + 1;
            continue;
        }
        $failures[] = sprintf(
            '%s %s is left identical to its English source "%s"; translate it, or record why it is correct '
                . 'in tools/verify-catalogue-quality.php.',
            $locale,
            $identifier,
            $origin,
        );
    }
    ksort($exempt);
    $report[] = sprintf(
        '  %-7s %4d units, %4d ICU patterns formatted, %2d plural forms checked against {%s}; '
            . '%d identical to source (%s)%s',
        $locale,
        count($units),
        $formatted,
        $pluralMessages,
        implode(', ', array_values(array_unique(kumwe_icu_categories($locale, 'plural')))),
        $identical,
        in_array($locale, $englishLocales, true)
            ? 'English wording'
            : ($exempt === [] ? 'none' : implode(', ', array_map(
                static fn (string $key, int $count): string => $count . ' ' . $key,
                array_keys($exempt),
                $exempt,
            ))),
        $advisory === [] ? '' : sprintf(
            '; advisory: %s',
            implode(', ', array_map(
                static fn (string $category, int $count): string => sprintf(
                    '%d plural(s) render `other` where CLDR uses `%s` for exact millions',
                    $count,
                    $category,
                ),
                array_keys($advisory),
                $advisory,
            )),
        ),
    );
}

foreach (KUMWE_IDENTICAL_REGISTER as $text => $entry) {
    foreach ($entry['locales'] as $locale) {
        if (!isset($registerUsed[$text][$locale])) {
            $failures[] = sprintf(
                'The identical-wording register excuses "%s" in %s, but no %s target is identical to it; '
                    . 'remove the stale entry.',
                $text,
                $locale,
                $locale,
            );
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, sprintf(
        "The interface catalogues are not qualified (%d defect(s)):\n  - %s\n",
        count($failures),
        implode("\n  - ", $failures),
    ));
    exit(1);
}

fwrite(STDOUT, sprintf(
    "The %d interface catalogues are qualified with ICU %s:\n%s\n",
    count(SupportedLocales::VERSION_TWO),
    INTL_ICU_VERSION,
    implode("\n", $report),
));
exit(0);
