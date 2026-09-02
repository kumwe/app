<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Governance;

/**
 * Resolves a fully qualified class name to the architecture layer `docs/architecture/layers.json` places it in.
 *
 * Two rules apply in order. An explicit `namespace_prefixes` entry wins, longest prefix first, which is how an
 * extracted package namespace or an App subtree that deviates from its folder name is classified. App-owned
 * names that no prefix covers fall back to `namespace_segments`: the first segment of the namespace path that
 * names a layer decides, exactly as `tools/verify-dependency-graph.php` enforces, so the growth gate and the
 * dependency gate never disagree about a class. Anything else is unclassifiable and refused, because a
 * namespace nothing governs is a namespace nothing checks.
 *
 * @since  2.0.0
 */
final readonly class LayerClassifier
{
    /**
     * The App namespace the segment rule is reserved for.
     *
     * @var    string
     * @since  2.0.0
     */
    public const APP_NAMESPACE = 'Kumwe\\App';

    /**
     * Layers whose code is portable behaviour rather than host composition.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const PORTABLE_LAYERS = ['shared', 'domain', 'application'];

    /**
     * Keep the validated rules.
     *
     * @param  string                       $path        Path of the layer graph, for diagnostics.
     * @param  list<string>                 $firstParty  Admitted top-level package namespaces.
     * @param  array<string, list<string>>  $layers      Layers each layer may depend on, by layer name.
     * @param  array<string, string>        $segments    Namespace segment to layer name.
     * @param  array<string, string>        $prefixes    Namespace prefix to layer name.
     *
     * @since  2.0.0
     */
    private function __construct(
        private string $path,
        private array $firstParty,
        private array $layers,
        private array $segments,
        private array $prefixes,
    ) {
    }

    /**
     * Read and validate a layer graph document.
     *
     * @param   string  $path  Absolute path of `layers.json`.
     *
     * @return  self  The classifier.
     *
     * @throws  GovernanceViolation  When the document is missing, malformed or names undeclared layers.
     *
     * @since   2.0.0
     */
    public static function fromFile(string $path): self
    {
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($bytes)) {
            throw GovernanceViolation::at($path, 'the layer graph is missing', 'restore docs/architecture/layers.json');
        }
        /** @var mixed $graph */
        $graph = json_decode($bytes, true);
        if (!is_array($graph)) {
            throw GovernanceViolation::at($path, 'the layer graph is not well-formed JSON', 'repair the document');
        }

        $layers = [];
        $declared = $graph['layers'] ?? null;
        if (!is_array($declared) || $declared === []) {
            throw GovernanceViolation::at(
                $path,
                'the layer graph must declare a non-empty "layers" object',
                'declare it',
            );
        }
        foreach ($declared as $name => $layer) {
            $allowed = is_array($layer) ? ($layer['may_depend_on'] ?? null) : null;
            if (!is_string($name) || !is_array($allowed) || !array_is_list($allowed)) {
                throw GovernanceViolation::at($path, 'every layer needs a name and a may_depend_on list', 'repair it');
            }
            $targets = [];
            foreach ($allowed as $target) {
                if (!is_string($target) || $target === '') {
                    throw GovernanceViolation::at(
                        $path,
                        sprintf('layer "%s" has a malformed dependency', $name),
                        'repair it',
                    );
                }
                $targets[] = $target;
            }
            $layers[$name] = $targets;
        }

        $firstParty = [];
        foreach (self::listOf($graph, 'first_party_namespaces', $path) as $prefix) {
            if (preg_match('/^Kumwe\\\\[A-Za-z][A-Za-z0-9]*$/D', $prefix) !== 1) {
                throw GovernanceViolation::at(
                    $path,
                    sprintf('first_party_namespaces entry "%s" is not one top-level Kumwe namespace', $prefix),
                    'declare each package as `Kumwe\\<Name>`',
                );
            }
            $firstParty[] = $prefix;
        }

        $segments = self::mapOf($graph, 'namespace_segments', $path, $layers);
        $prefixes = self::mapOf($graph, 'namespace_prefixes', $path, $layers);
        foreach (array_keys($prefixes) as $prefix) {
            if (!self::belongs($prefix, $firstParty)) {
                throw GovernanceViolation::at(
                    $path,
                    sprintf('namespace prefix "%s" belongs to no first-party namespace', $prefix),
                    'add the package to first_party_namespaces or remove the rule',
                );
            }
        }

        return new self($path, $firstParty, $layers, $segments, $prefixes);
    }

    /**
     * Resolve the layer of a fully qualified class name.
     *
     * @param   string  $fqcn  Class-like name without a leading backslash.
     *
     * @return  string  Layer name declared in the graph.
     *
     * @throws  GovernanceViolation  When no prefix rule and no App segment rule classifies the name.
     *
     * @since   2.0.0
     */
    public function classify(string $fqcn): string
    {
        $best = null;
        $bestLength = -1;
        foreach ($this->prefixes as $prefix => $layer) {
            if ($fqcn !== $prefix && !str_starts_with($fqcn, $prefix . '\\')) {
                continue;
            }
            if (strlen($prefix) > $bestLength) {
                $best = $layer;
                $bestLength = strlen($prefix);
            }
        }
        if ($best !== null) {
            return $best;
        }

        if (str_starts_with($fqcn, self::APP_NAMESPACE . '\\')) {
            $segments = explode('\\', $fqcn);
            array_pop($segments);
            foreach ($segments as $segment) {
                if (isset($this->segments[$segment])) {
                    return $this->segments[$segment];
                }
            }
        }

        throw GovernanceViolation::at(
            $this->path,
            sprintf('"%s" is classified by no namespace_prefixes rule and no App namespace segment', $fqcn),
            'add a longest-prefix rule for its namespace to namespace_prefixes',
        );
    }

    /**
     * Decide whether a name belongs to an admitted first-party namespace.
     *
     * @param   string  $fqcn  Class-like name or namespace.
     *
     * @return  bool  True when one of `first_party_namespaces` owns it.
     *
     * @since   2.0.0
     */
    public function isFirstParty(string $fqcn): bool
    {
        return self::belongs($fqcn, $this->firstParty);
    }

    /**
     * Decide whether a layer holds portable behaviour rather than host composition.
     *
     * @param   string  $layer  Layer name.
     *
     * @return  bool  True for shared, domain and application.
     *
     * @since   2.0.0
     */
    public static function isPortable(string $layer): bool
    {
        return in_array($layer, self::PORTABLE_LAYERS, true);
    }

    /**
     * The declared layer names.
     *
     * @return  list<string>  In declaration order.
     *
     * @since   2.0.0
     */
    public function layers(): array
    {
        return array_keys($this->layers);
    }

    /**
     * The admitted first-party namespaces.
     *
     * @return  list<string>  In declaration order.
     *
     * @since   2.0.0
     */
    public function firstPartyNamespaces(): array
    {
        return $this->firstParty;
    }

    /**
     * Decide whether a name sits under one of a set of namespaces, on a segment boundary.
     *
     * @param   string        $fqcn      Class-like name or namespace.
     * @param   list<string>  $prefixes  Candidate namespaces.
     *
     * @return  bool  True when one prefix equals the name or is followed by a separator.
     *
     * @since   2.0.0
     */
    private static function belongs(string $fqcn, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($fqcn === $prefix || str_starts_with($fqcn, $prefix . '\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read a list of strings from the graph document.
     *
     * @param   array<int|string, mixed>  $graph  Decoded document.
     * @param   string                    $key    Top-level key.
     * @param   string                    $path   Document path, for diagnostics.
     *
     * @return  list<string>  The entries.
     *
     * @throws  GovernanceViolation  When the key is missing or an entry is not a string.
     *
     * @since   2.0.0
     */
    private static function listOf(array $graph, string $key, string $path): array
    {
        $declared = $graph[$key] ?? null;
        if (!is_array($declared) || !array_is_list($declared) || $declared === []) {
            throw GovernanceViolation::at(
                $path,
                sprintf('the layer graph must declare a non-empty "%s" list', $key),
                'declare it',
            );
        }
        $result = [];
        foreach ($declared as $entry) {
            if (!is_string($entry)) {
                throw GovernanceViolation::at($path, sprintf('"%s" contains a non-string entry', $key), 'repair it');
            }
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Read a namespace-to-layer map from the graph document.
     *
     * @param   array<int|string, mixed>     $graph   Decoded document.
     * @param   string                       $key     Top-level key.
     * @param   string                       $path    Document path, for diagnostics.
     * @param   array<string, list<string>>  $layers  Declared layers.
     *
     * @return  array<string, string>  Namespace to layer name.
     *
     * @throws  GovernanceViolation  When the key is missing or a rule names an undeclared layer.
     *
     * @since   2.0.0
     */
    private static function mapOf(array $graph, string $key, string $path, array $layers): array
    {
        $declared = $graph[$key] ?? null;
        if (!is_array($declared)) {
            throw GovernanceViolation::at(
                $path,
                sprintf('the layer graph must declare "%s" as an object', $key),
                'declare it',
            );
        }
        $result = [];
        foreach ($declared as $namespace => $layer) {
            if (!is_string($namespace) || !is_string($layer)) {
                throw GovernanceViolation::at(
                    $path,
                    sprintf('every "%s" entry maps a namespace to a layer', $key),
                    'repair it',
                );
            }
            if (!isset($layers[$layer])) {
                throw GovernanceViolation::at(
                    $path,
                    sprintf('"%s" rule "%s" names the undeclared layer "%s"', $key, $namespace, $layer),
                    'declare the layer or correct the rule',
                );
            }
            $result[$namespace] = $layer;
        }

        return $result;
    }
}
