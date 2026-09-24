<?php

declare(strict_types=1);

/**
 * Verifies that every administrator and portal browser operation has a machine equivalent or a reasoned exemption.
 *
 * The record `docs/machine-contract/browser-machine-parity.json` is an executable inventory. Every browser route
 * registered under `/administrator` or `/portal` in `src/Kernel/ContainerFactory.php` appears in it exactly once,
 * each route lists the application operations it performs, and each operation is classified. An `equivalent`
 * operation names the application service method the browser handler calls and the REST operation ids, CLI
 * command actions and MCP tools that call the same method; a surface that deliberately withholds it carries a
 * reason from the record's closed surface-exemption vocabulary. A `browser-only` operation carries a reason from
 * the closed browser-only vocabulary. A `gap` is always a violation. An equivalent operation whose machine surfaces
 * authorize with less assurance than the browser — a browser step-up or password re-proof a released machine
 * contract does not carry — records an `assurance_gap`; the summary lists them and the architecture suite pins the
 * list, so the set can only change by a reviewed edit.
 *
 * The machine side is read from the contracts that are themselves gated: the current REST generation's compiled
 * OpenAPI artifact (`composer openapi:check`), the live CLI generation (`composer cli:contract`) and the live MCP
 * catalogue (`composer mcp:contract`). Routes are read from the literal route registrations so the check runs in
 * the quality lane without a configured environment; the architecture suite additionally proves the extracted set
 * equals the booted router's and that each entry names the handler the router dispatches to.
 *
 * Usage:
 *   php tools/verify-browser-machine-parity.php            verify the record against the live contracts
 *   php tools/verify-browser-machine-parity.php --summary  also print the classification counts as JSON
 *
 * @since  2.0.0
 */
final class BrowserMachineParityVerifier
{
    /**
     * Repository-relative path of the parity record.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string RECORD = 'docs/machine-contract/browser-machine-parity.json';

    /**
     * Repository-relative path of the parity record's JSON schema.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string SCHEMA = 'docs/machine-contract/browser-machine-parity.schema.json';

    /**
     * Route name of the Studio host dispatch route whose operations are the Studio port capabilities.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string STUDIO_HOST_ROUTE = 'administrator.studio.host';

    /**
     * Machine surfaces an equivalent operation must address or explicitly exempt.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const array SURFACES = ['rest', 'cli', 'mcp'];

    /**
     * Browser-only reasons that must name the machine reads exposing the same data.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array READABLE_REASONS = ['html-presentation', 'navigation'];

    /**
     * Create a verifier rooted at one checkout.
     *
     * @param  string  $root  Absolute repository root.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly string $root)
    {
    }

    /**
     * Return every violation of the committed record against the live machine contracts.
     *
     * @return  list<string>  Empty when every browser operation is equivalent or reasoned browser-only.
     *
     * @since   2.0.0
     */
    public function violations(): array
    {
        try {
            $document = $this->record();
            $surfaces = $this->surfaces();
        } catch (Throwable $failure) {
            return [sprintf('The browser-machine parity record cannot be verified: %s', $failure->getMessage())];
        }

        return [...$this->schemaViolations($document), ...$this->violationsFor($document, $surfaces)];
    }

    /**
     * Decode the committed parity record.
     *
     * @return  array<string, mixed>  Decoded record.
     *
     * @throws  JsonException  When the record is not valid JSON.
     * @throws  RuntimeException  When the record is unreadable or is not a JSON object.
     *
     * @since   2.0.0
     */
    public function record(): array
    {
        $json = @file_get_contents($this->root . '/' . self::RECORD);
        if (!is_string($json)) {
            throw new RuntimeException(sprintf('%s is unreadable.', self::RECORD));
        }
        $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($document) || array_is_list($document)) {
            throw new RuntimeException(sprintf('%s must be a JSON object.', self::RECORD));
        }

        /** @var array<string, mixed> $document */
        return $document;
    }

    /**
     * Validate the record against its committed JSON schema.
     *
     * @param   array<string, mixed>  $document  Decoded parity record.
     *
     * @return  list<string>  Schema violations as `<json-pointer>: <rule>`.
     *
     * @since   2.0.0
     */
    public function schemaViolations(array $document): array
    {
        require_once $this->root . '/tools/Governance/bootstrap.php';
        try {
            $violations = (new \Kumwe\App\Tools\Governance\SchemaValidator())->validate(
                $document,
                $this->root . '/' . self::SCHEMA,
            );
        } catch (Throwable $failure) {
            return [sprintf('The parity schema cannot be applied: %s', $failure->getMessage())];
        }

        return array_map(
            static fn (string $violation): string => sprintf('%s does not match its schema: %s', self::RECORD, $violation),
            $violations,
        );
    }

    /**
     * Collect the live browser routes and machine operations the record is checked against.
     *
     * @return  array{
     *            routes: list<array{name: string, path: string, methods: list<string>}>,
     *            rest: list<string>,
     *            cli: array<string, array{invoke: bool, actions: list<string>}>,
     *            mcp: list<string>,
     *            studio: list<string>
     *          }  Browser routes, REST operation ids, CLI commands, MCP tool names and Studio host operations.
     *
     * @throws  RuntimeException  When a contract source is unreadable.
     *
     * @since   2.0.0
     */
    public function surfaces(): array
    {
        require_once $this->root . '/vendor/autoload.php';
        $source = @file_get_contents($this->root . '/src/Kernel/ContainerFactory.php');
        if (!is_string($source)) {
            throw new RuntimeException('The route table in src/Kernel/ContainerFactory.php is unreadable.');
        }
        $mcp = [];
        foreach ((new \Kumwe\App\Infrastructure\Mcp\McpCapabilityCatalog())->tools() as $tool) {
            $mcp[] = $tool['name'];
        }
        $studio = [];
        foreach (
            [
                ...\Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority::HOST_CAPABILITIES,
                ...\Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority::AUTHORING_CAPABILITIES,
            ] as $capability
        ) {
            if (str_starts_with($capability, 'studio.operation/')) {
                $studio[] = $capability;
            }
        }

        return [
            'routes' => self::browserRoutes($source),
            'rest' => $this->restOperations(),
            'cli' => $this->cliCommands(),
            'mcp' => $mcp,
            'studio' => array_values(array_unique($studio)),
        ];
    }

    /**
     * Check one decoded record against one set of live surfaces.
     *
     * The comparison is pure so the architecture suite can prove each refusal with a mutated record or
     * surface set rather than trusting that the check is capable of failing.
     *
     * @param   array<string, mixed>  $document  Decoded parity record.
     * @param   array{
     *            routes: list<array{name: string, path: string, methods: list<string>}>,
     *            rest: list<string>,
     *            cli: array<string, array{invoke: bool, actions: list<string>}>,
     *            mcp: list<string>,
     *            studio: list<string>
     *          }  $surfaces  Live browser routes and machine operations.
     *
     * @return  list<string>  Every violation, in record order.
     *
     * @since   2.0.0
     */
    public function violationsFor(array $document, array $surfaces): array
    {
        $violations = [];
        $browserReasons = self::stringKeys($document['browser_only_reasons'] ?? null);
        $exemptionReasons = self::stringKeys($document['surface_exemption_reasons'] ?? null);
        $live = [];
        foreach ($surfaces['routes'] as $route) {
            if (isset($live[$route['name']])) {
                $violations[] = sprintf('Browser route %s is registered more than once.', $route['name']);
            }
            $live[$route['name']] = $route;
        }
        $recorded = [];
        $routes = $document['routes'] ?? null;
        foreach (is_array($routes) ? $routes : [] as $index => $route) {
            if (!is_array($route) || !is_string($route['name'] ?? null)) {
                $violations[] = sprintf('Parity route entry %s has no route name.', (string) $index);
                continue;
            }
            $name = $route['name'];
            if (isset($recorded[$name])) {
                $violations[] = sprintf('Parity route %s is recorded more than once.', $name);
                continue;
            }
            $recorded[$name] = true;
            $registered = $live[$name] ?? null;
            if ($registered === null) {
                $violations[] = sprintf(
                    'Parity route %s is stale: no browser route of that name is registered; remove the entry.',
                    $name,
                );
                continue;
            }
            if (($route['path'] ?? null) !== $registered['path'] || ($route['methods'] ?? null) !== $registered['methods']) {
                $violations[] = sprintf(
                    'Parity route %s records %s %s but the route table registers %s %s.',
                    $name,
                    implode(',', is_array($route['methods'] ?? null) ? $route['methods'] : []),
                    is_string($route['path'] ?? null) ? $route['path'] : '?',
                    implode(',', $registered['methods']),
                    $registered['path'],
                );
            }
            $operations = $route['operations'] ?? null;
            if (!is_array($operations) || $operations === []) {
                $violations[] = sprintf('Parity route %s lists no operation.', $name);
                continue;
            }
            $seen = [];
            foreach ($operations as $operation) {
                if (!is_array($operation) || !is_string($operation['operation'] ?? null)) {
                    $violations[] = sprintf('Parity route %s has an operation without a name.', $name);
                    continue;
                }
                $label = $name . ' ' . $operation['operation'];
                if (isset($seen[$operation['operation']])) {
                    $violations[] = sprintf('Parity operation %s is recorded more than once.', $label);
                }
                $seen[$operation['operation']] = true;
                array_push(
                    $violations,
                    ...$this->operationViolations($label, $operation, $surfaces, $browserReasons, $exemptionReasons),
                );
            }
            if ($name === self::STUDIO_HOST_ROUTE) {
                $recordedStudio = array_keys($seen);
                sort($recordedStudio, SORT_STRING);
                $liveStudio = $surfaces['studio'];
                sort($liveStudio, SORT_STRING);
                foreach (array_diff($liveStudio, $recordedStudio) as $missing) {
                    $violations[] = sprintf(
                        'Studio host operation %s is served but has no parity entry under %s.',
                        $missing,
                        self::STUDIO_HOST_ROUTE,
                    );
                }
                foreach (array_diff($recordedStudio, $liveStudio) as $stale) {
                    $violations[] = sprintf(
                        'Parity operation %s %s is stale: the Studio host no longer serves it.',
                        self::STUDIO_HOST_ROUTE,
                        $stale,
                    );
                }
            }
        }
        foreach (array_diff_key($live, $recorded) as $name => $route) {
            $violations[] = sprintf(
                'Browser route %s (%s %s) has no parity entry: record its operations as equivalent, naming their '
                . 'REST, CLI and MCP counterparts, or as browser-only with a reason.',
                $name,
                implode(',', $route['methods']),
                $route['path'],
            );
        }

        return $violations;
    }

    /**
     * Count the recorded operations by classification, and browser-only operations by reason.
     *
     * @param   array<string, mixed>  $document  Decoded parity record.
     *
     * @return  array{
     *            routes: int,
     *            operations: int,
     *            classifications: array<string, int>,
     *            browser_only_reasons: array<string, int>,
     *            surface_exemptions: array<string, int>,
     *            assurance_gaps: list<string>
     *          }  Classification counts, and every equivalent operation whose machine surfaces authorize with less
     *          assurance than the browser, as `route operation`.
     *
     * @since   2.0.0
     */
    public static function summary(array $document): array
    {
        $summary = [
            'routes' => 0,
            'operations' => 0,
            'classifications' => ['equivalent' => 0, 'browser-only' => 0, 'gap' => 0],
            'browser_only_reasons' => [],
            'surface_exemptions' => [],
            'assurance_gaps' => [],
        ];
        $routes = $document['routes'] ?? null;
        foreach (is_array($routes) ? $routes : [] as $route) {
            ++$summary['routes'];
            $operations = is_array($route) ? ($route['operations'] ?? null) : null;
            foreach (is_array($operations) ? $operations : [] as $operation) {
                if (!is_array($operation) || !is_string($operation['classification'] ?? null)) {
                    continue;
                }
                ++$summary['operations'];
                $classification = $operation['classification'];
                if (is_array($operation['assurance_gap'] ?? null) && is_array($route)) {
                    $summary['assurance_gaps'][] = $route['name'] . ' ' . $operation['operation'];
                }
                $summary['classifications'][$classification] = ($summary['classifications'][$classification] ?? 0) + 1;
                if ($classification === 'browser-only' && is_string($operation['reason'] ?? null)) {
                    $reason = $operation['reason'];
                    $summary['browser_only_reasons'][$reason] = ($summary['browser_only_reasons'][$reason] ?? 0) + 1;
                }
                $exemptions = $operation['exemptions'] ?? null;
                foreach (is_array($exemptions) ? $exemptions : [] as $exemption) {
                    if (is_array($exemption) && is_string($exemption['reason'] ?? null)) {
                        $reason = $exemption['reason'];
                        $summary['surface_exemptions'][$reason] = ($summary['surface_exemptions'][$reason] ?? 0) + 1;
                    }
                }
            }
        }
        ksort($summary['browser_only_reasons'], SORT_STRING);
        ksort($summary['surface_exemptions'], SORT_STRING);

        return $summary;
    }

    /**
     * Extract every administrator and portal route tuple from the literal route registrations.
     *
     * Literal calls and literal `foreach` expansions of `get`, `post`, `put`, `patch`, `delete` and `route`
     * are read with the interface programme's token helpers, so both gates read the route table one way.
     *
     * @param   string  $source  PHP route-registration source.
     *
     * @return  list<array{name: string, path: string, methods: list<string>}>  Browser route tuples, sorted
     *          by name.
     *
     * @since   2.0.0
     */
    public static function browserRoutes(string $source): array
    {
        if (!defined('KUMWE_INTERFACE_PROGRAMME_LIBRARY_ONLY')) {
            define('KUMWE_INTERFACE_PROGRAMME_LIBRARY_ONLY', true);
        }
        require_once dirname(__DIR__) . '/tools/verify-interface-programme.php';
        $tokens = token_get_all($source);
        $routes = [];
        $excluded = [];
        foreach (literalForeachContexts($tokens) as $context) {
            $excluded[] = [$context['start'], $context['end']];
            array_push($routes, ...self::routeCalls($tokens, $context['start'], $context['end'], $context['variables']));
        }
        array_push($routes, ...self::routeCalls($tokens, 0, count($tokens) - 1, [], $excluded));
        usort($routes, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

        return $routes;
    }

    /**
     * Extract application route calls within one token range.
     *
     * @param   list<mixed>                  $tokens     PHP tokens.
     * @param   int                          $start      Inclusive token offset.
     * @param   int                          $end        Inclusive token offset.
     * @param   array<string, mixed>         $variables  Literal loop variables.
     * @param   list<array{0: int, 1: int}>  $excluded   Ranges handled through loop expansion.
     *
     * @return  list<array{name: string, path: string, methods: list<string>}>  Browser route tuples.
     *
     * @since   2.0.0
     */
    private static function routeCalls(
        array $tokens,
        int $start,
        int $end,
        array $variables,
        array $excluded = [],
    ): array {
        $verbs = ['get' => 'GET', 'post' => 'POST', 'put' => 'PUT', 'patch' => 'PATCH', 'delete' => 'DELETE'];
        $routes = [];
        for ($index = $start; $index <= $end; ++$index) {
            foreach ($excluded as [$excludedStart, $excludedEnd]) {
                if ($index >= $excludedStart && $index <= $excludedEnd) {
                    $index = $excludedEnd;
                    continue 2;
                }
            }
            $token = $tokens[$index] ?? null;
            if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$application') {
                continue;
            }
            $operator = nextMeaningfulPhpToken($tokens, $index + 1);
            $methodIndex = $operator === null ? null : nextMeaningfulPhpToken($tokens, $operator + 1);
            $open = $methodIndex === null ? null : nextMeaningfulPhpToken($tokens, $methodIndex + 1);
            $methodToken = $methodIndex === null ? null : ($tokens[$methodIndex] ?? null);
            if (
                $operator === null
                || phpTokenText($tokens[$operator]) !== '->'
                || !is_array($methodToken)
                || $methodToken[0] !== T_STRING
                || (!isset($verbs[$methodToken[1]]) && $methodToken[1] !== 'route')
                || $open === null
                || phpTokenText($tokens[$open]) !== '('
            ) {
                continue;
            }
            $close = closingPhpToken($tokens, $open, '(', ')');
            if ($close === null) {
                continue;
            }
            $arguments = splitPhpArguments(array_slice($tokens, $open + 1, $close - $open - 1));
            $path = evaluatePhpStringExpression($arguments[0] ?? [], $variables);
            $name = evaluatePhpStringExpression($arguments[$methodToken[1] === 'route' ? 3 : 2] ?? [], $variables);
            $index = $close;
            if (
                !is_string($path)
                || !is_string($name)
                || !(str_starts_with($path, '/administrator') || str_starts_with($path, '/portal'))
            ) {
                continue;
            }
            if ($methodToken[1] === 'route') {
                $valid = false;
                $methods = parsePhpLiteralValue($arguments[2] ?? [], $valid);
                if (!$valid || !is_array($methods) || !array_is_list($methods)) {
                    continue;
                }
                $methods = array_values(array_filter($methods, 'is_string'));
            } else {
                $methods = [$verbs[$methodToken[1]]];
            }
            $routes[] = ['name' => $name, 'path' => $path, 'methods' => $methods];
        }

        return $routes;
    }

    /**
     * Check one recorded operation's classification, references and reasons.
     *
     * @param   string                $label             Route name and operation name for messages.
     * @param   array<mixed>          $operation         Recorded operation.
     * @param   array{
     *            routes: list<array{name: string, path: string, methods: list<string>}>,
     *            rest: list<string>,
     *            cli: array<string, array{invoke: bool, actions: list<string>}>,
     *            mcp: list<string>,
     *            studio: list<string>
     *          }  $surfaces  Live browser routes and machine operations.
     * @param   list<string>          $browserReasons    Closed browser-only reason vocabulary.
     * @param   list<string>          $exemptionReasons  Closed surface-exemption reason vocabulary.
     *
     * @return  list<string>  Violations for this operation.
     *
     * @since   2.0.0
     */
    private function operationViolations(
        string $label,
        array $operation,
        array $surfaces,
        array $browserReasons,
        array $exemptionReasons,
    ): array {
        $violations = [];
        $classification = $operation['classification'] ?? null;
        if ($classification === 'gap') {
            return [sprintf(
                'Browser operation %s has neither a machine equivalent nor a reasoned exemption (recorded gap: %s).',
                $label,
                is_string($operation['note'] ?? null) ? $operation['note'] : 'no note',
            )];
        }
        if ($classification === 'browser-only') {
            $reason = $operation['reason'] ?? null;
            if (!is_string($reason) || !in_array($reason, $browserReasons, true)) {
                $violations[] = sprintf(
                    'Browser-only operation %s has no reason from the browser_only_reasons vocabulary.',
                    $label,
                );
            }
            if (!is_string($operation['note'] ?? null) || trim($operation['note']) === '') {
                $violations[] = sprintf('Browser-only operation %s does not state why it stays in the browser.', $label);
            }
            $readable = $operation['machine_readable_via'] ?? null;
            if (in_array($reason, self::READABLE_REASONS, true) && !is_array($readable)) {
                $violations[] = sprintf(
                    'Browser-only operation %s presents data but names no machine_readable_via operations.',
                    $label,
                );
            }
            if (is_array($readable)) {
                foreach (self::SURFACES as $surface) {
                    foreach (self::references($readable[$surface] ?? []) as $reference) {
                        array_push($violations, ...$this->referenceViolations($label, $surface, $reference, $surfaces));
                    }
                }
            }

            return $violations;
        }
        if ($classification !== 'equivalent') {
            return [sprintf('Browser operation %s has no classification of equivalent, browser-only or gap.', $label)];
        }
        $service = $operation['service'] ?? null;
        if (!is_string($service) || !self::serviceExists($service)) {
            $violations[] = sprintf(
                'Equivalent operation %s names service %s, which is not an existing Class::method.',
                $label,
                is_string($service) ? $service : '(none)',
            );
        }
        $exemptions = $operation['exemptions'] ?? [];
        $exemptions = is_array($exemptions) ? $exemptions : [];
        foreach (self::SURFACES as $surface) {
            $references = self::references($operation[$surface] ?? []);
            $exemption = $exemptions[$surface] ?? null;
            if ($references === [] && $exemption === null) {
                $violations[] = sprintf(
                    'Equivalent operation %s has no %s operation and no reasoned %s exemption.',
                    $label,
                    strtoupper($surface),
                    strtoupper($surface),
                );
            }
            if ($references !== [] && $exemption !== null) {
                $violations[] = sprintf(
                    'Equivalent operation %s both names %s operations and exempts %s.',
                    $label,
                    strtoupper($surface),
                    strtoupper($surface),
                );
            }
            if ($exemption !== null) {
                $reason = is_array($exemption) ? ($exemption['reason'] ?? null) : null;
                if (!is_string($reason) || !in_array($reason, $exemptionReasons, true)) {
                    $violations[] = sprintf(
                        'Equivalent operation %s exempts %s without a reason from the surface_exemption_reasons '
                        . 'vocabulary.',
                        $label,
                        strtoupper($surface),
                    );
                }
            }
            foreach ($references as $reference) {
                array_push($violations, ...$this->referenceViolations($label, $surface, $reference, $surfaces));
            }
        }

        return $violations;
    }

    /**
     * Check that one named machine operation exists on its surface.
     *
     * @param   string  $label      Route name and operation name for messages.
     * @param   string  $surface    `rest`, `cli` or `mcp`.
     * @param   string  $reference  Operation id, `command [action]`, or tool name.
     * @param   array{
     *            routes: list<array{name: string, path: string, methods: list<string>}>,
     *            rest: list<string>,
     *            cli: array<string, array{invoke: bool, actions: list<string>}>,
     *            mcp: list<string>,
     *            studio: list<string>
     *          }  $surfaces  Live browser routes and machine operations.
     *
     * @return  list<string>  One violation when the reference names nothing, otherwise none.
     *
     * @since   2.0.0
     */
    private function referenceViolations(string $label, string $surface, string $reference, array $surfaces): array
    {
        $exists = match ($surface) {
            'rest' => in_array($reference, $surfaces['rest'], true),
            'mcp' => in_array($reference, $surfaces['mcp'], true),
            default => self::cliExists($reference, $surfaces['cli']),
        };
        if ($exists) {
            return [];
        }

        return [sprintf(
            'Parity operation %s names %s operation "%s", which the current %s contract does not declare.',
            $label,
            strtoupper($surface),
            $reference,
            strtoupper($surface),
        )];
    }

    /**
     * Decide whether a `command` or `command action` reference names a live CLI action.
     *
     * @param   string                                                 $reference  CLI reference.
     * @param   array<string, array{invoke: bool, actions: list<string>}>  $commands   Live CLI commands.
     *
     * @return  bool  True when the command exists and the action is declared, or the command takes no action.
     *
     * @since   2.0.0
     */
    private static function cliExists(string $reference, array $commands): bool
    {
        $parts = explode(' ', $reference);
        $command = $commands[$parts[0]] ?? null;
        if ($command === null || count($parts) > 2) {
            return false;
        }
        if (count($parts) === 1) {
            return $command['invoke'];
        }

        return !$command['invoke'] && in_array($parts[1], $command['actions'], true);
    }

    /**
     * Read the operation ids the current REST generation's compiled artifact declares.
     *
     * @return  list<string>  Operation ids.
     *
     * @throws  JsonException  When the ledger or artifact is not valid JSON.
     * @throws  RuntimeException  When the ledger names no readable current artifact.
     *
     * @since   2.0.0
     */
    private function restOperations(): array
    {
        $ledger = json_decode(
            (string) @file_get_contents($this->root . '/api/openapi/generations.json'),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        $artifact = null;
        foreach (is_array($ledger) && is_array($ledger['generations'] ?? null) ? $ledger['generations'] : [] as $row) {
            if (is_array($row) && ($row['generation'] ?? null) === ($ledger['current'] ?? null)) {
                $artifact = $row['artifact'] ?? null;
            }
        }
        if (!is_string($artifact) || str_contains($artifact, '..')) {
            throw new RuntimeException('The REST generation ledger names no current artifact.');
        }
        $json = @file_get_contents($this->root . '/' . $artifact);
        if (!is_string($json)) {
            throw new RuntimeException(sprintf('The current REST artifact %s is unreadable.', $artifact));
        }
        $openApi = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $operations = [];
        $paths = is_array($openApi) ? ($openApi['paths'] ?? null) : null;
        foreach (is_array($paths) ? $paths : [] as $item) {
            foreach (is_array($item) ? $item : [] as $operation) {
                if (is_array($operation) && is_string($operation['operationId'] ?? null)) {
                    $operations[] = $operation['operationId'];
                }
            }
        }

        return $operations;
    }

    /**
     * Read the live CLI generation's commands and actions.
     *
     * @return  array<string, array{invoke: bool, actions: list<string>}>  Commands keyed by name.
     *
     * @throws  JsonException  When the contract is not valid JSON.
     *
     * @since   2.0.0
     */
    private function cliCommands(): array
    {
        $contract = json_decode(
            \Kumwe\App\Delivery\Console\Contract\CliV2MachineContract::json(),
            true,
            128,
            JSON_THROW_ON_ERROR,
        );
        $commands = [];
        $declared = is_array($contract) ? ($contract['commands'] ?? null) : null;
        foreach (is_array($declared) ? $declared : [] as $command) {
            if (!is_array($command) || !is_string($command['name'] ?? null)) {
                continue;
            }
            $actions = [];
            foreach (is_array($command['actions'] ?? null) ? $command['actions'] : [] as $action) {
                if (is_array($action) && is_string($action['name'] ?? null)) {
                    $actions[] = $action['name'];
                }
            }
            $commands[$command['name']] = ['invoke' => ($command['action_argument'] ?? false) !== true, 'actions' => $actions];
        }

        return $commands;
    }

    /**
     * Decide whether a `Class::method` reference names an existing method.
     *
     * @param   string  $service  Fully qualified class name, `::`, method name.
     *
     * @return  bool  True when the class or interface exists and declares the method.
     *
     * @since   2.0.0
     */
    private static function serviceExists(string $service): bool
    {
        $parts = explode('::', $service);
        if (count($parts) !== 2) {
            return false;
        }
        [$class, $method] = $parts;

        return (class_exists($class) || interface_exists($class)) && method_exists($class, $method);
    }

    /**
     * Read one list of string references, ignoring anything else the schema already refuses.
     *
     * @param   mixed  $value  Recorded reference list.
     *
     * @return  list<string>  String references.
     *
     * @since   2.0.0
     */
    private static function references(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * Read the keys of one reason vocabulary object.
     *
     * @param   mixed  $value  Recorded vocabulary object.
     *
     * @return  list<string>  Reason codes.
     *
     * @since   2.0.0
     */
    private static function stringKeys(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter(array_keys($value), 'is_string')) : [];
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $arguments = array_slice($argv, 1);
    if ($arguments !== [] && $arguments !== ['--summary']) {
        fwrite(STDERR, "Usage: php tools/verify-browser-machine-parity.php [--summary]\n");
        exit(64);
    }
    $verifier = new BrowserMachineParityVerifier(dirname(__DIR__));
    $violations = $verifier->violations();
    if ($violations !== []) {
        fwrite(STDERR, implode(PHP_EOL, $violations) . PHP_EOL);
        exit(1);
    }
    $summary = BrowserMachineParityVerifier::summary($verifier->record());
    if ($arguments === ['--summary']) {
        fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
    }
    fwrite(STDOUT, sprintf(
        "Browser-machine parity verified: %d browser routes, %d operations (%d equivalent, %d browser-only); "
        . "%d equivalent operation(s) carry a recorded assurance gap pending a maintainer decision.\n",
        $summary['routes'],
        $summary['operations'],
        $summary['classifications']['equivalent'],
        $summary['classifications']['browser-only'],
        count($summary['assurance_gaps']),
    ));
}
