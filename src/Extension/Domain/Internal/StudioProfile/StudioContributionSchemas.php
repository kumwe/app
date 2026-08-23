<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Domain\Internal\StudioProfile;

use LogicException;
use RuntimeException;
use SplObjectStorage;
use stdClass;

/**
 * The compiled registry of the pinned canonical Studio contribution schemas.
 *
 * Manifest schema 6 carries canonical Studio documents, and each one must validate against the
 * exact schemas of the pinned `@kumwe/studio-protocol` release vendored under
 * `resources/studio-contract/protocol/schemas/` behind the `composer studio:corpus` digest gate. This
 * registry loads the reference closure the six contribution kinds need — their own documents plus
 * `common` and `blueprint` — walks every schema position against the interpreter's closed keyword
 * grammar, compiles the reviewed lexical patterns the canonical schemas are allowed to carry,
 * resolves every same-document and cross-document `$ref` through the documents' root `$id` base
 * URIs, and hands out one interpreting validator per contribution kind. Runtime network retrieval
 * never happens: the registry is the in-memory whole of what a reference may reach.
 *
 * @since  2.0.0
 */
final class StudioContributionSchemas
{
    /**
     * The contribution kinds manifest schema 6 admits, each named after its schema document.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const array CONTRIBUTION_KINDS = [
        'block-definition',
        'pattern',
        'field-adapter',
        'inspector',
        'design-vocabulary',
        'migration',
    ];

    /**
     * The reference closure: every document a contribution-kind schema may reach.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array DOCUMENT_CLOSURE = [
        'block-definition',
        'pattern',
        'field-adapter',
        'inspector',
        'design-vocabulary',
        'migration',
        'blueprint',
        'common',
    ];

    /**
     * The dialect a canonical schema may declare.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string DRAFT_2020_12 = 'https://json-schema.org/draft/2020-12/schema';

    /**
     * Longest lexical pattern source the canonical schemas may carry.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int MAX_PATTERN_SOURCE_LENGTH = 500;

    /**
     * The interpreter's closed keyword set: the contributed profile plus the two canonical-schema
     * affordances, `pattern` and a document-root `$id`.
     *
     * @var    array<string, true>
     * @since  2.0.0
     */
    private const array SUPPORTED_KEYWORDS = [
        '$defs' => true, '$id' => true, '$ref' => true, '$schema' => true,
        'additionalProperties' => true, 'allOf' => true, 'anyOf' => true, 'const' => true,
        'default' => true, 'dependentRequired' => true, 'description' => true, 'else' => true,
        'enum' => true, 'examples' => true, 'exclusiveMaximum' => true, 'exclusiveMinimum' => true,
        'if' => true, 'items' => true, 'maxItems' => true, 'maxLength' => true,
        'maxProperties' => true, 'maximum' => true, 'minItems' => true, 'minLength' => true,
        'minProperties' => true, 'minimum' => true, 'multipleOf' => true, 'not' => true,
        'oneOf' => true, 'pattern' => true, 'prefixItems' => true, 'properties' => true,
        'propertyNames' => true, 'readOnly' => true, 'required' => true, 'then' => true,
        'title' => true, 'type' => true, 'uniqueItems' => true, 'writeOnly' => true,
    ];

    /**
     * One compiled validator per contribution kind, created on first use.
     *
     * @var    array<string, SchemaPropertyValidator>
     * @since  2.0.0
     */
    private array $validators = [];

    /**
     * Document roots by contribution kind.
     *
     * @var    array<string, stdClass>
     * @since  2.0.0
     */
    private array $roots = [];

    /**
     * Resolved `$ref` target per referring schema node, shared across the closure.
     *
     * @var    SplObjectStorage<stdClass, stdClass|bool>
     * @since  2.0.0
     */
    private SplObjectStorage $references;

    /**
     * Compiled PCRE per pattern-bearing schema node, shared across the closure.
     *
     * @var    SplObjectStorage<stdClass, string>
     * @since  2.0.0
     */
    private SplObjectStorage $patterns;

    /**
     * Compile the closure once; use {@see fromVendoredCorpus()} to construct.
     *
     * @param  array<string, stdClass>  $documents  Decoded schema documents by closure name.
     *
     * @since  2.0.0
     */
    private function __construct(array $documents)
    {
        /** @var SplObjectStorage<stdClass, stdClass|bool> $references */
        $references = new SplObjectStorage();
        /** @var SplObjectStorage<stdClass, string> $patterns */
        $patterns = new SplObjectStorage();
        $this->references = $references;
        $this->patterns = $patterns;

        $byUri = [];
        $pointers = [];
        foreach ($documents as $name => $document) {
            $baseUri = $document->{'$id'} ?? null;
            if (!is_string($baseUri) || $baseUri === '') {
                throw new RuntimeException(sprintf('Registry schema document %s must declare a root $id.', $name));
            }
            if (isset($byUri[$baseUri])) {
                throw new RuntimeException(sprintf('Schema registry declares %s more than once.', $baseUri));
            }
            $byUri[$baseUri] = $document;
            $pointers[$baseUri] = [];
        }

        $sites = [];
        foreach ($documents as $name => $document) {
            $baseUri = $document->{'$id'};
            assert(is_string($baseUri));
            $this->walkDocument($document, $baseUri, $pointers[$baseUri], $sites);
            if (in_array($name, self::CONTRIBUTION_KINDS, true)) {
                $this->roots[$name] = $document;
            }
        }

        foreach ($sites as [$node, $baseUri, $pointer, $reference]) {
            $this->references[$node] = self::resolveSite($baseUri, $pointer, $reference, $byUri, $pointers);
        }
    }

    /**
     * Load and compile the pinned reference closure from the vendored corpus.
     *
     * @param   string|null  $schemaDirectory  Corpus schema directory; the vendored default when null.
     *
     * @return  self  The compiled registry.
     *
     * @throws  RuntimeException  When a closure document is missing, unreadable, or outside the
     *          interpreter grammar.
     *
     * @since   2.0.0
     */
    public static function fromVendoredCorpus(?string $schemaDirectory = null): self
    {
        /** @var  self|null  $shared */
        static $shared = null;
        if ($schemaDirectory === null) {
            return $shared ??= self::compileVendored();
        }

        return self::compileFrom($schemaDirectory);
    }

    /**
     * Compile the registry from the default vendored location.
     *
     * @return  self  The compiled registry.
     *
     * @since   2.0.0
     */
    private static function compileVendored(): self
    {
        return self::compileFrom(dirname(__DIR__, 5) . '/resources/studio-contract/protocol/schemas');
    }

    /**
     * Compile the registry from one schema directory.
     *
     * @param   string  $schemaDirectory  Directory holding the pinned schema documents.
     *
     * @return  self  The compiled registry.
     *
     * @since   2.0.0
     */
    private static function compileFrom(string $schemaDirectory): self
    {
        $documents = [];
        foreach (self::DOCUMENT_CLOSURE as $name) {
            $path = $schemaDirectory . '/' . $name . '.schema.json';
            $decoded = is_file($path) ? json_decode((string) file_get_contents($path), false) : null;
            if (!$decoded instanceof stdClass) {
                throw new RuntimeException(sprintf('The pinned Studio schema %s is missing or unreadable.', $path));
            }
            $documents[$name] = $decoded;
        }

        return new self($documents);
    }

    /**
     * Hand out the interpreting validator for one contribution kind.
     *
     * @param   string  $kind  One of {@see self::CONTRIBUTION_KINDS}.
     *
     * @return  SchemaPropertyValidator  Validator over the kind's pinned schema document.
     *
     * @throws  LogicException  When the kind is not a canonical contribution kind.
     *
     * @since   2.0.0
     */
    public function validator(string $kind): SchemaPropertyValidator
    {
        $root = $this->roots[$kind] ?? null;
        if ($root === null) {
            throw new LogicException(sprintf('"%s" is not a canonical Studio contribution kind.', $kind));
        }

        return $this->validators[$kind] ??= new SchemaPropertyValidator($root, $this->references, $this->patterns);
    }

    /**
     * Walk one document: closed keywords, operand grammar, schema positions, patterns, ref sites.
     *
     * @param   stdClass                                                   $document  Document root.
     * @param   string                                                     $baseUri   The document's `$id`.
     * @param   array<string, true>                                        $pointers  Escaped pointers of
     *          every schema position, filled during the walk.
     * @param   list<array{0: stdClass, 1: string, 2: string, 3: string}>  $sites     Reference sites:
     *          node, base URI, pointer and raw reference.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function walkDocument(stdClass $document, string $baseUri, array &$pointers, array &$sites): void
    {
        $seen = new SplObjectStorage();
        $walkSchema = function (
            mixed $value,
            string $pointer
        ) use (
            &$walkSchema,
            &$pointers,
            &$sites,
            $baseUri,
            $seen,
        ): void {
            $location = $baseUri . '#' . $pointer;
            if (is_bool($value)) {
                $pointers[$pointer] = true;

                return;
            }
            if (!$value instanceof stdClass) {
                throw new RuntimeException($location . ' must be a plain JSON Schema object.');
            }
            if ($seen->offsetExists($value)) {
                throw new RuntimeException($location . ' reuses or cycles a schema object.');
            }
            $seen->offsetSet($value, true);
            $pointers[$pointer] = true;

            foreach (get_object_vars($value) as $keyword => $operand) {
                $keyword = (string) $keyword;
                $keywordLocation = $location . '/' . $keyword;
                if (!isset(self::SUPPORTED_KEYWORDS[$keyword])) {
                    throw new RuntimeException(sprintf(
                        '%s uses keyword "%s", which the Studio schema interpreter does not support.',
                        $keywordLocation,
                        $keyword,
                    ));
                }
                switch ($keyword) {
                    case '$id':
                        if ($pointer !== '') {
                            throw new RuntimeException($keywordLocation . ' may only appear at the document root.');
                        }
                        break;
                    case '$schema':
                        if ($operand !== self::DRAFT_2020_12) {
                            throw new RuntimeException($keywordLocation . ' must declare JSON Schema Draft 2020-12.');
                        }
                        break;
                    case '$ref':
                        if (!is_string($operand) || mb_strlen($operand, 'UTF-8') > self::MAX_PATTERN_SOURCE_LENGTH) {
                            throw new RuntimeException($keywordLocation . ' must be a bounded reference string.');
                        }
                        $sites[] = [$value, $baseUri, $pointer, $operand];
                        break;
                    case '$defs':
                    case 'properties':
                        if (!$operand instanceof stdClass) {
                            throw new RuntimeException($keywordLocation . ' must be an object of schemas.');
                        }
                        foreach (get_object_vars($operand) as $name => $member) {
                            $walkSchema($member, $pointer . '/' . $keyword . '/' . self::escapeToken((string) $name));
                        }
                        break;
                    case 'additionalProperties':
                    case 'else':
                    case 'if':
                    case 'items':
                    case 'not':
                    case 'propertyNames':
                    case 'then':
                        $walkSchema($operand, $pointer . '/' . $keyword);
                        break;
                    case 'allOf':
                    case 'anyOf':
                    case 'oneOf':
                    case 'prefixItems':
                        if (!is_array($operand) || !array_is_list($operand) || $operand === []) {
                            throw new RuntimeException(
                                $keywordLocation . ' must be a dense, non-empty array of schemas.',
                            );
                        }
                        foreach ($operand as $index => $member) {
                            $walkSchema($member, $pointer . '/' . $keyword . '/' . $index);
                        }
                        break;
                    case 'pattern':
                        $this->patterns[$value] = self::compilePattern($operand, $keywordLocation);
                        break;
                    default:
                        break;
                }
            }
        };
        $walkSchema($document, '');
    }

    /**
     * Compile one reviewed lexical pattern into a bounded Unicode PCRE.
     *
     * @param   mixed   $operand   Pattern source.
     * @param   string  $location  Location for a failure message.
     *
     * @return  string  The delimited PCRE.
     *
     * @throws  RuntimeException  When the source is unbounded or not a valid expression.
     *
     * @since   2.0.0
     */
    private static function compilePattern(mixed $operand, string $location): string
    {
        if (!is_string($operand) || mb_strlen($operand, 'UTF-8') > self::MAX_PATTERN_SOURCE_LENGTH) {
            throw new RuntimeException(sprintf(
                '%s must be a lexical pattern of at most %d characters.',
                $location,
                self::MAX_PATTERN_SOURCE_LENGTH,
            ));
        }
        // ECMAScript spells a Unicode escape \uXXXX or \u{...}; PCRE spells it \x{...}. Translate
        // only real escapes — an even run of backslashes before the "u" is literal text.
        $translated = (string) preg_replace_callback(
            '/(?<backslashes>\\\\+)u(?<code>\{[0-9A-Fa-f]{1,6}\}|[0-9A-Fa-f]{4})/',
            static function (array $match): string {
                $slashes = strlen($match['backslashes']);
                if ($slashes % 2 === 0) {
                    return $match[0];
                }

                return substr($match['backslashes'], 0, $slashes - 1)
                    . '\\x{' . trim($match['code'], '{}') . '}';
            },
            $operand,
        );
        $compiled = '~' . str_replace('~', '\~', $translated) . '~u';
        if (@preg_match($compiled, '') === false) {
            throw new RuntimeException($location . ' is not a valid Unicode regular expression.');
        }

        return $compiled;
    }

    /**
     * Resolve one reference site against the registry.
     *
     * @param   string                              $baseUri    Referring document's base URI.
     * @param   string                              $pointer    Referring schema position.
     * @param   string                              $reference  Raw reference.
     * @param   array<string, stdClass>             $byUri      Registry documents by `$id`.
     * @param   array<string, array<string, true>>  $pointers   Schema positions per document.
     *
     * @return  stdClass|bool  The target subschema.
     *
     * @throws  RuntimeException  When the reference leaves the registry or misses a schema position.
     *
     * @since   2.0.0
     */
    private static function resolveSite(
        string $baseUri,
        string $pointer,
        string $reference,
        array $byUri,
        array $pointers,
    ): stdClass|bool {
        $location = $baseUri . '#' . $pointer . '/$ref';
        $hashIndex = strpos($reference, '#');
        $uriPart = $hashIndex === false ? $reference : substr($reference, 0, $hashIndex);
        $fragment = $hashIndex === false ? '' : substr($reference, $hashIndex + 1);

        if ($uriPart === '') {
            $targetUri = $baseUri;
        } elseif (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $uriPart) === 1) {
            $targetUri = $uriPart;
        } else {
            if (str_starts_with($uriPart, '/') || preg_match('~(?:^|/)\.\.?(?:/|$)~', $uriPart) === 1) {
                throw new RuntimeException($location . ' must stay within the schema registry root.');
            }
            $targetUri = substr($baseUri, 0, (int) strrpos($baseUri, '/') + 1) . $uriPart;
        }
        $target = $byUri[$targetUri] ?? null;
        if ($target === null) {
            throw new RuntimeException(
                sprintf('%s references %s, which is not in the registry.', $location, $targetUri),
            );
        }

        if ($fragment !== '' && !str_starts_with($fragment, '/')) {
            throw new RuntimeException($location . ' must use a JSON Pointer fragment.');
        }
        $tokens = [];
        if ($fragment !== '') {
            foreach (explode('/', substr($fragment, 1)) as $token) {
                if (preg_match('/~(?![01])|~$/', $token) === 1) {
                    throw new RuntimeException($location . ' is not a valid JSON Pointer reference.');
                }
                $tokens[] = str_replace(['~1', '~0'], ['/', '~'], $token);
            }
        }
        $canonical = '';
        foreach ($tokens as $token) {
            $canonical .= '/' . self::escapeToken($token);
        }
        if ($canonical !== '' && !isset($pointers[$targetUri][$canonical])) {
            throw new RuntimeException($location . ' does not reference a schema position.');
        }

        $current = $target;
        foreach ($tokens as $token) {
            if (is_array($current) && preg_match('/^\d+$/', $token) === 1 && array_key_exists((int) $token, $current)) {
                $current = $current[(int) $token];
            } elseif ($current instanceof stdClass && property_exists($current, $token)) {
                $current = $current->{$token};
            } else {
                throw new RuntimeException($location . ' does not resolve to a schema.');
            }
        }
        if (is_bool($current) || $current instanceof stdClass) {
            return $current;
        }

        throw new RuntimeException($location . ' does not resolve to a schema.');
    }

    /**
     * Escape one JSON Pointer token.
     *
     * @param   string  $token  Raw member name.
     *
     * @return  string  The token with `~` and `/` escaped.
     *
     * @since   2.0.0
     */
    private static function escapeToken(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }
}
