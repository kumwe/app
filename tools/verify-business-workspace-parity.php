<?php

declare(strict_types=1);

/**
 * Verifies the Phase 2 old/new interface parity contract against production sources.
 *
 * The verifier is deliberately dependency-free so it can run before Composer installation and from the
 * architecture suite. It treats the manifest as an executable inventory: old and new contracts must be
 * identical, routes must still carry their exact capability and CSRF middleware, mutation fields must match
 * the rendered form inventory, and every declared service/security invariant must retain source evidence.
 *
 * @since  2.0.0
 */
final class BusinessWorkspaceParityVerifier
{
    /**
     * Sections whose entries must carry byte-for-byte equivalent `old` and `new` documents.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const PARITY_SECTIONS = [
        'routes',
        'capabilities',
        'field_visibility',
        'actions',
        'payloads',
        'input_inventory',
        'invariants',
        'no_javascript',
    ];

    /**
     * Cache repository files read while evaluating evidence.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private array $contents = [];

    /**
     * Create a verifier rooted at one checkout.
     *
     * @param  string  $root      Absolute repository root.
     * @param  string  $manifest  Repository-relative parity manifest path.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly string $root,
        private readonly string $manifest = 'docs/interface-standard/programme/phase-2-business-workspace-parity.json',
    ) {
    }

    /**
     * Return every parity or source-binding violation without stopping at the first one.
     *
     * @return  list<string>  Empty when the manifest and production source remain aligned.
     *
     * @since   2.0.0
     */
    public function violations(): array
    {
        $violations = [];
        try {
            $document = json_decode($this->file($this->manifest), true, 128, JSON_THROW_ON_ERROR);
        } catch (Throwable $failure) {
            return [sprintf('The Phase 2 parity manifest cannot be read: %s', $failure->getMessage())];
        }
        if (!is_array($document) || array_is_list($document)) {
            return ['The Phase 2 parity manifest must be a JSON object.'];
        }
        if (($document['format'] ?? null) !== 'kumwe-kis-interface-parity-v1') {
            $violations[] = 'The parity manifest format must be kumwe-kis-interface-parity-v1.';
        }
        if (($document['manifest_version'] ?? null) !== 1 || ($document['phase'] ?? null) !== 2) {
            $violations[] = 'The parity manifest must identify manifest version 1 and Phase 2.';
        }
        $routeRegistry = $document['route_registry'] ?? null;
        if (!is_string($routeRegistry)) {
            $violations[] = 'The parity manifest route_registry is missing.';
            $routeRegistry = 'src/Kernel/ContainerFactory.php';
        }
        $surfaces = $document['surfaces'] ?? null;
        if (!is_array($surfaces) || !array_is_list($surfaces) || $surfaces === []) {
            return [...$violations, 'The parity manifest must contain at least one surface.'];
        }

        $surfaceIds = [];
        foreach ($surfaces as $surface) {
            if (!is_array($surface) || array_is_list($surface)) {
                $violations[] = 'Every parity surface must be an object.';
                continue;
            }
            $surfaceId = is_string($surface['id'] ?? null) ? $surface['id'] : '(missing surface id)';
            if (isset($surfaceIds[$surfaceId])) {
                $violations[] = sprintf('Surface %s is declared more than once.', $surfaceId);
            }
            $surfaceIds[$surfaceId] = true;
            $this->verifySurface($surfaceId, $surface, $routeRegistry, $violations);
        }
        if (
            array_keys($surfaceIds) !== [
                'core.administrator.business-definitions',
                'core.administrator.schema-plans',
            ]
        ) {
            $violations[] = 'The Phase 2 manifest must cover Business Definitions and Business Schema Plans exactly.';
        }

        return $violations;
    }

    /**
     * Verify one surface's paired documents and their bindings.
     *
     * @param   string              $surfaceId     Stable surface identifier.
     * @param   array<string,mixed> $surface       Surface manifest document.
     * @param   string              $routeRegistry Repository-relative route registry.
     * @param   list<string>        $violations    Accumulated violations, updated in place.
     *
     * @since   2.0.0
     */
    private function verifySurface(
        string $surfaceId,
        array $surface,
        string $routeRegistry,
        array &$violations,
    ): void {
        $template = $surface['template'] ?? null;
        if (!is_string($template)) {
            $violations[] = sprintf('%s has no template binding.', $surfaceId);
            return;
        }
        try {
            $templateContents = $this->file($template);
        } catch (Throwable $failure) {
            $violations[] = sprintf('%s template cannot be read: %s', $surfaceId, $failure->getMessage());
            return;
        }

        foreach (self::PARITY_SECTIONS as $section) {
            $entries = $surface[$section] ?? null;
            if (!is_array($entries) || !array_is_list($entries) || $entries === []) {
                $violations[] = sprintf('%s.%s must be a non-empty list.', $surfaceId, $section);
                continue;
            }
            $ids = [];
            foreach ($entries as $entry) {
                if (!is_array($entry) || array_is_list($entry) || !is_string($entry['id'] ?? null)) {
                    $violations[] = sprintf('%s.%s contains an entry without an id.', $surfaceId, $section);
                    continue;
                }
                $id = $entry['id'];
                if (isset($ids[$id])) {
                    $violations[] = sprintf('%s.%s repeats %s.', $surfaceId, $section, $id);
                }
                $ids[$id] = true;
                if (!array_key_exists('old', $entry) || !array_key_exists('new', $entry)) {
                    $violations[] = sprintf('%s.%s.%s must declare old and new.', $surfaceId, $section, $id);
                    continue;
                }
                if ($entry['old'] !== $entry['new']) {
                    $violations[] = sprintf('%s.%s.%s lost old/new parity.', $surfaceId, $section, $id);
                }
            }
        }

        $routes = $this->indexedPairs($surface['routes'] ?? []);
        foreach ($routes as $routeId => $route) {
            if (!is_array($route)) {
                $violations[] = sprintf('%s route %s must be an object.', $surfaceId, $routeId);
                continue;
            }
            $this->verifyRoute($surfaceId, $routeId, $route, $routeRegistry, $violations);
        }

        $capabilities = $this->indexedPairs($surface['capabilities'] ?? []);
        $capabilityCodes = [];
        foreach ($capabilities as $capabilityId => $capability) {
            if (!is_array($capability) || !is_string($capability['code'] ?? null)) {
                $violations[] = sprintf('%s capability %s has no code.', $surfaceId, $capabilityId);
                continue;
            }
            $capabilityCodes[] = $capability['code'];
        }

        $payloads = $this->indexedPairs($surface['payloads'] ?? []);
        $actions = $this->indexedPairs($surface['actions'] ?? []);
        foreach ($this->indexedPairs($surface['field_visibility'] ?? []) as $fieldId => $fieldContract) {
            if (is_array($fieldContract)) {
                $this->verifyTemplateMarkers(
                    $surfaceId,
                    'field_visibility',
                    $fieldId,
                    $fieldContract['template_markers'] ?? [],
                    $templateContents,
                    $violations,
                );
            }
        }
        foreach ($actions as $actionId => $action) {
            if (!is_array($action)) {
                $violations[] = sprintf('%s action %s must be an object.', $surfaceId, $actionId);
                continue;
            }
            $routeId = $action['route'] ?? null;
            if (!is_string($routeId) || !array_key_exists($routeId, $routes)) {
                $violations[] = sprintf('%s action %s names an unknown route.', $surfaceId, $actionId);
            }
            $actionCapabilities = $action['capabilities'] ?? null;
            if (!is_array($actionCapabilities) || $actionCapabilities === []) {
                $violations[] = sprintf('%s action %s has no capability contract.', $surfaceId, $actionId);
                $actionCapabilities = [];
            }
            foreach ($actionCapabilities as $code) {
                if (!is_string($code) || !in_array($code, $capabilityCodes, true)) {
                    $violations[] = sprintf('%s action %s names an unknown capability.', $surfaceId, $actionId);
                }
            }
            foreach ($action['conditional_capabilities'] ?? [] as $condition) {
                if (
                    !is_array($condition)
                    || !is_string($condition['code'] ?? null)
                    || !is_string($condition['when'] ?? null)
                    || !in_array($condition['code'], $capabilityCodes, true)
                ) {
                    $violations[] = sprintf(
                        '%s action %s has a malformed conditional capability.',
                        $surfaceId,
                        $actionId,
                    );
                }
            }
            if (
                is_string($routeId)
                && is_array($routes[$routeId] ?? null)
                && !in_array($routes[$routeId]['capability'] ?? null, $actionCapabilities, true)
            ) {
                $violations[] = sprintf('%s action %s omits its route capability.', $surfaceId, $actionId);
            }
            $payloadId = $action['payload'] ?? null;
            if ($payloadId !== null && (!is_string($payloadId) || !array_key_exists($payloadId, $payloads))) {
                $violations[] = sprintf('%s action %s names an unknown payload.', $surfaceId, $actionId);
            }
            $this->verifyAction($surfaceId, $actionId, $action, $templateContents, $violations);
        }

        foreach ($payloads as $payloadId => $payload) {
            if (!is_array($payload)) {
                $violations[] = sprintf('%s payload %s must be an object.', $surfaceId, $payloadId);
                continue;
            }
            if (!is_array($payload['fields'] ?? null) || $payload['fields'] === []) {
                $violations[] = sprintf('%s payload %s has no field contract.', $surfaceId, $payloadId);
            }
            if (!is_array($payload['evidence'] ?? null) || $payload['evidence'] === []) {
                $violations[] = sprintf('%s payload %s has no request-handler evidence.', $surfaceId, $payloadId);
            }
        }

        $inventories = $this->indexedPairs($surface['input_inventory'] ?? []);
        $inventory = $inventories['rendered-input-names'] ?? null;
        if (!is_array($inventory) || !array_is_list($inventory)) {
            $violations[] = sprintf('%s has no rendered-input-names inventory.', $surfaceId);
        } else {
            $expected = $inventory;
            sort($expected);
            $actual = $this->templateInputNames($templateContents);
            if ($actual !== $expected) {
                $violations[] = sprintf(
                    '%s rendered input inventory drifted. Expected %s; found %s.',
                    $surfaceId,
                    json_encode($expected, JSON_UNESCAPED_SLASHES),
                    json_encode($actual, JSON_UNESCAPED_SLASHES),
                );
            }
            foreach ($payloads as $payloadId => $payload) {
                if (!is_array($payload) || ($payload['transport'] ?? null) !== 'form') {
                    continue;
                }
                $payloadFields = $payload['fields'] ?? [];
                foreach ($payload['indexed_groups'] ?? [] as $group) {
                    if (
                        !is_array($group)
                        || !is_string($group['pattern'] ?? null)
                        || !is_int($group['limit'] ?? null)
                        || $group['limit'] < 1
                        || !is_array($group['members'] ?? null)
                    ) {
                        $violations[] = sprintf('%s payload %s has a malformed indexed group.', $surfaceId, $payloadId);
                        continue;
                    }
                    foreach ($group['members'] as $member) {
                        if (is_string($member)) {
                            $payloadFields[] = str_replace('{member}', $member, $group['pattern']);
                        }
                    }
                }
                foreach ($payloadFields as $field) {
                    if (!is_string($field) || !in_array($field, $expected, true)) {
                        $violations[] = sprintf(
                            '%s payload %s contains an input absent from the rendered inventory.',
                            $surfaceId,
                            $payloadId,
                        );
                    }
                }
            }
        }

        foreach (['capabilities', 'field_visibility', 'payloads', 'invariants', 'no_javascript'] as $section) {
            foreach ($this->indexedPairs($surface[$section] ?? []) as $id => $contract) {
                if (is_array($contract)) {
                    $this->verifyEvidence($surfaceId, $section, $id, $contract, $violations);
                }
            }
        }
        $this->verifyPostFormsCarryCsrf($surfaceId, $templateContents, $violations);
    }

    /**
     * Verify one administrator route retains its method, handler, capability, and CSRF boundary.
     *
     * @param   string               $surfaceId     Surface identifier.
     * @param   string               $routeId       Manifest route identifier.
     * @param   array<string,mixed>  $route         Current route contract.
     * @param   string               $registry      Route registry path.
     * @param   list<string>         $violations    Accumulated violations.
     *
     * @since   2.0.0
     */
    private function verifyRoute(
        string $surfaceId,
        string $routeId,
        array $route,
        string $registry,
        array &$violations,
    ): void {
        foreach (['method', 'path', 'name', 'handler', 'handler_file', 'capability'] as $key) {
            if (!is_string($route[$key] ?? null) || $route[$key] === '') {
                $violations[] = sprintf('%s route %s has no %s.', $surfaceId, $routeId, $key);
                return;
            }
        }
        $method = strtolower($route['method']);
        $handler = $route['handler'];
        $csrf = ($route['csrf'] ?? null) === true;
        $middleware = $csrf
            ? sprintf('[AdministratorCsrfMiddleware::class,%s::class]', $handler)
            : sprintf('%s::class', $handler);
        $expected = sprintf(
            "self::administratorRoute(\$application->%s('%s',%s,'%s',),'%s');",
            $method,
            $route['path'],
            $middleware,
            $route['name'],
            $route['capability'],
        );
        $actual = preg_replace('/\s+/', '', $this->file($registry)) ?? '';
        if (!str_contains($actual, $expected)) {
            $violations[] = sprintf('%s route %s no longer matches the route registry.', $surfaceId, $routeId);
        }
        try {
            $handlerContents = $this->file($route['handler_file']);
            if (!str_contains($handlerContents, 'class ' . $handler)) {
                $violations[] = sprintf(
                    '%s route %s handler file does not declare %s.',
                    $surfaceId,
                    $routeId,
                    $handler,
                );
            }
        } catch (Throwable $failure) {
            $violations[] = sprintf(
                '%s route %s handler cannot be read: %s',
                $surfaceId,
                $routeId,
                $failure->getMessage(),
            );
        }
    }

    /**
     * Verify one action remains connected to its UI affordance and application-service calls.
     *
     * @param   string               $surfaceId       Surface identifier.
     * @param   string               $actionId        Action identifier.
     * @param   array<string,mixed>  $action          Current action contract.
     * @param   string               $template        Surface template contents.
     * @param   list<string>         $violations      Accumulated violations.
     *
     * @since   2.0.0
     */
    private function verifyAction(
        string $surfaceId,
        string $actionId,
        array $action,
        string $template,
        array &$violations,
    ): void {
        $this->verifyTemplateMarkers(
            $surfaceId,
            'actions',
            $actionId,
            $action['ui_markers'] ?? [],
            $template,
            $violations,
        );
        $handler = $action['handler_file'] ?? null;
        if (!is_string($handler)) {
            $violations[] = sprintf('%s action %s has no handler_file.', $surfaceId, $actionId);
            return;
        }
        try {
            $contents = $this->file($handler);
        } catch (Throwable $failure) {
            $violations[] = sprintf(
                '%s action %s handler cannot be read: %s',
                $surfaceId,
                $actionId,
                $failure->getMessage(),
            );
            return;
        }
        foreach ($action['service_calls'] ?? [] as $call) {
            if (!is_string($call) || !str_contains($contents, $call)) {
                $violations[] = sprintf('%s action %s lost service call %s.', $surfaceId, $actionId, (string) $call);
            }
        }
        $this->verifyEvidence($surfaceId, 'actions', $actionId, $action, $violations);
    }

    /**
     * Verify one contract remains connected to its rendered affordance or field group.
     *
     * @param   string        $surfaceId  Surface identifier.
     * @param   string        $section    Manifest section.
     * @param   string        $id         Contract identifier.
     * @param   mixed         $markers    Required literal template markers.
     * @param   string        $template   Surface template contents.
     * @param   list<string>  $violations Accumulated violations.
     *
     * @since   2.0.0
     */
    private function verifyTemplateMarkers(
        string $surfaceId,
        string $section,
        string $id,
        mixed $markers,
        string $template,
        array &$violations,
    ): void {
        if (!is_array($markers) || $markers === []) {
            $violations[] = sprintf('%s.%s.%s has no template markers.', $surfaceId, $section, $id);
            return;
        }
        foreach ($markers as $marker) {
            if (!is_string($marker) || !str_contains($template, $marker)) {
                $violations[] = sprintf(
                    '%s.%s.%s lost template marker %s.',
                    $surfaceId,
                    $section,
                    $id,
                    (string) $marker,
                );
            }
        }
    }

    /**
     * Verify affirmative and forbidden source evidence on a contract document.
     *
     * @param   string               $surfaceId   Surface identifier.
     * @param   string               $section     Manifest section.
     * @param   string               $id          Contract identifier.
     * @param   array<string,mixed>  $contract    Current contract document.
     * @param   list<string>         $violations  Accumulated violations.
     *
     * @since   2.0.0
     */
    private function verifyEvidence(
        string $surfaceId,
        string $section,
        string $id,
        array $contract,
        array &$violations,
    ): void {
        foreach (['evidence' => true, 'forbidden' => false] as $key => $mustExist) {
            foreach ($contract[$key] ?? [] as $evidence) {
                if (
                    !is_array($evidence)
                    || !is_string($evidence['path'] ?? null)
                    || !is_string($evidence['contains'] ?? null)
                ) {
                    $violations[] = sprintf('%s.%s.%s has malformed %s evidence.', $surfaceId, $section, $id, $key);
                    continue;
                }
                try {
                    $present = str_contains($this->file($evidence['path']), $evidence['contains']);
                } catch (Throwable $failure) {
                    $violations[] = sprintf('%s.%s.%s evidence cannot be read.', $surfaceId, $section, $id);
                    continue;
                }
                if ($present !== $mustExist) {
                    $violations[] = sprintf(
                        '%s.%s.%s %s evidence %s in %s.',
                        $surfaceId,
                        $section,
                        $id,
                        $mustExist ? 'lost' : 'introduced forbidden',
                        $evidence['contains'],
                        $evidence['path'],
                    );
                }
            }
        }
    }

    /**
     * Extract normalized form input names, expanding bounded Twig row-key loops.
     *
     * @param   string  $template  Twig template contents.
     *
     * @return  list<string>  Sorted unique names, with dynamic row numbers represented by `{index}`.
     *
     * @since   2.0.0
     */
    private function templateInputNames(string $template): array
    {
        $expansions = ['key' => [], 'condition' => []];
        preg_match_all(
            '/\{%\s*for\s+(key|condition),\s*label\s+in\s+\{([^}]*)\}\s*%\}/',
            $template,
            $loops,
            PREG_SET_ORDER,
        );
        foreach ($loops as $loop) {
            preg_match_all("/'([^']+)'\s*:/", $loop[2], $keys);
            $expansions[$loop[1]] = array_values(array_unique([...$expansions[$loop[1]], ...$keys[1]]));
        }

        preg_match_all('/\bname="([^"]+)"/', $template, $matches);
        $names = [];
        foreach ($matches[1] as $rawName) {
            $variants = [$rawName];
            foreach ($expansions as $variable => $values) {
                $needle = '{{ ' . $variable . ' }}';
                if (!str_contains($rawName, $needle)) {
                    continue;
                }
                $variants = array_map(
                    static fn (string $value): string => str_replace($needle, $value, $rawName),
                    $values,
                );
            }
            foreach ($variants as $name) {
                $names[] = preg_replace(
                    '/\{\{\s*(?:loop\.index0|field_index)\s*\}\}|__INDEX__/',
                    '{index}',
                    $name,
                ) ?? $name;
            }
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Require every rendered POST form to carry the administrator CSRF token field.
     *
     * @param   string        $surfaceId   Surface identifier.
     * @param   string        $template    Twig template contents.
     * @param   list<string>  $violations  Accumulated violations.
     *
     * @since   2.0.0
     */
    private function verifyPostFormsCarryCsrf(string $surfaceId, string $template, array &$violations): void
    {
        preg_match_all('/<form\b[^>]*method="post"[^>]*>(.*?)<\/form>/si', $template, $forms);
        if ($forms[1] === []) {
            $violations[] = sprintf('%s renders no POST forms.', $surfaceId);
            return;
        }
        foreach ($forms[1] as $index => $form) {
            if (!str_contains($form, 'name="_csrf"')) {
                $violations[] = sprintf('%s POST form %d has no CSRF field.', $surfaceId, $index + 1);
            }
        }
    }

    /**
     * Index the current side of a paired manifest section by entry identifier.
     *
     * @param   mixed  $entries  Manifest section.
     *
     * @return  array<string,mixed>  Current contracts keyed by id.
     *
     * @since   2.0.0
     */
    private function indexedPairs(mixed $entries): array
    {
        if (!is_array($entries) || !array_is_list($entries)) {
            return [];
        }
        $indexed = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null) && array_key_exists('new', $entry)) {
                $indexed[$entry['id']] = $entry['new'];
            }
        }

        return $indexed;
    }

    /**
     * Read a repository-relative file without permitting manifest paths to escape the checkout.
     *
     * @param   string  $path  Repository-relative path.
     *
     * @return  string  File contents.
     *
     * @throws  RuntimeException  When the path is unsafe, missing, or unreadable.
     *
     * @since   2.0.0
     */
    private function file(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
            throw new RuntimeException(sprintf('Unsafe repository path: %s', $path));
        }
        if (isset($this->contents[$path])) {
            return $this->contents[$path];
        }
        $contents = file_get_contents($this->root . '/' . $path);
        if (!is_string($contents)) {
            throw new RuntimeException(sprintf('Repository file cannot be read: %s', $path));
        }
        $this->contents[$path] = $contents;

        return $contents;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $verifier = new BusinessWorkspaceParityVerifier(dirname(__DIR__));
    $violations = $verifier->violations();
    if ($violations !== []) {
        fwrite(STDERR, implode(PHP_EOL, $violations) . PHP_EOL);
        exit(1);
    }
    fwrite(STDOUT, "Phase 2 business-workspace parity verified.\n");
}
