<?php

/**
 * Verify that the KIS programme records are internally coherent and cover current graphical sources.
 *
 * The check is dependency-free so route, navigation, Twig, extension-manifest, generated-definition,
 * actor, journey, phase and evidence coverage remains enforceable before Composer dependencies exist.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$inventory = readJson($root . '/docs/interface-standard/programme/surface-inventory.json', $errors);
$catalogue = readJson($root . '/docs/interface-standard/programme/actor-task-journeys.json', $errors);
$ledger = readJson($root . '/docs/interface-standard/programme/phase-ledger.json', $errors);

if ($inventory === [] || $catalogue === [] || $ledger === []) {
    report($errors);
}

$surfaceIds = uniqueIds($inventory['surfaces'] ?? null, 'surface', $errors);
$actorIds = uniqueIds($catalogue['actors'] ?? null, 'actor', $errors);
$taskIds = uniqueIds($catalogue['tasks'] ?? null, 'task', $errors);
$journeyIds = uniqueIds($catalogue['journeys'] ?? null, 'journey', $errors);
$findingIds = uniqueIds($inventory['findings'] ?? null, 'finding', $errors);
$fixtureIds = uniqueIds($inventory['fixture_profiles'] ?? null, 'fixture', $errors);
$navigationIds = uniqueIds($inventory['navigation_catalog'] ?? null, 'navigation', $errors);
$templatePaths = uniqueValues($inventory['template_catalog'] ?? null, 'path', 'template path', $errors);
$ownerIds = uniqueIds($ledger['owner_roles'] ?? null, 'owner role', $errors);
$evidenceIds = uniqueIds($ledger['evidence_records'] ?? null, 'evidence', $errors);
$gateIds = uniqueIds($ledger['gates'] ?? null, 'gate', $errors);

$phases = expectList($ledger['phases'] ?? null, 'phases', $errors);
$phaseNumbers = [];
$workItems = [];
foreach ($phases as $phase) {
    if (!is_array($phase) || !is_int($phase['number'] ?? null)) {
        $errors[] = 'Every phase requires an integer number.';
        continue;
    }
    $number = $phase['number'];
    if (isset($phaseNumbers[$number])) {
        $errors[] = sprintf('Phase number %d is duplicated.', $number);
    }
    $phaseNumbers[$number] = true;
    foreach (expectList($phase['work_items'] ?? null, sprintf('phase %d work_items', $number), $errors) as $item) {
        if (!is_array($item) || !is_string($item['id'] ?? null) || $item['id'] === '') {
            $errors[] = sprintf('Phase %d has a work item without an ID.', $number);
            continue;
        }
        if (isset($workItems[$item['id']])) {
            $errors[] = sprintf('Work item %s is duplicated.', $item['id']);
        }
        $workItems[$item['id']] = $item;
    }
}
if (array_keys($phaseNumbers) !== [0, 1, 2, 3, 4, 5, 6]) {
    $numbers = array_keys($phaseNumbers);
    sort($numbers, SORT_NUMERIC);
    if ($numbers !== [0, 1, 2, 3, 4, 5, 6]) {
        $errors[] = 'The programme must contain exactly Phases 0 through 6.';
    }
}

validateCatalogue($catalogue, $actorIds, $taskIds, $journeyIds, $surfaceIds, $errors);
validateSurfaces(
    $root,
    $inventory,
    $surfaceIds,
    $actorIds,
    $taskIds,
    $journeyIds,
    $findingIds,
    $fixtureIds,
    $navigationIds,
    $templatePaths,
    $phaseNumbers,
    $ownerIds,
    $errors,
);
validateTemplates($root, $inventory, $surfaceIds, $errors);
validateCoreRoutes($root, $inventory, $errors);
validateNavigationSources($root, $inventory, $errors);
validateExtensionManifests($root, $inventory, $errors);
validateGeneratedInstances($root, $inventory, $errors);
validateLedger(
    $ledger,
    $surfaceIds,
    $ownerIds,
    $evidenceIds,
    $gateIds,
    $workItems,
    $errors,
);

if ($errors !== []) {
    report($errors);
}

printf(
    "KIS programme verified: %d surfaces, %d templates, %d navigation entries, %d generated instances, "
    . "%d actors, %d tasks, %d journeys, %d work items.\n",
    count($surfaceIds),
    count($templatePaths),
    count($navigationIds),
    count($inventory['generated_instances'] ?? []),
    count($actorIds),
    count($taskIds),
    count($journeyIds),
    count($workItems),
);

/**
 * Read one JSON programme record.
 *
 * @param   string        $path    Absolute path to decode.
 * @param   list<string>  $errors  Accumulated validation failures.
 *
 * @return  array<string, mixed>  Decoded object or an empty array after a read or syntax failure.
 *
 * @since   2.0.0
 */
function readJson(string $path, array &$errors): array
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        $errors[] = sprintf('Programme record %s is unreadable.', $path);
        return [];
    }
    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $errors[] = sprintf('Programme record %s is invalid JSON: %s', $path, $exception->getMessage());
        return [];
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
        $errors[] = sprintf('Programme record %s must contain a JSON object.', $path);
        return [];
    }
    return $decoded;
}

/**
 * Require a value to be a JSON-style list.
 *
 * @param   mixed         $value   Candidate list.
 * @param   string        $label   Human-readable source label.
 * @param   list<string>  $errors  Accumulated validation failures.
 *
 * @return  list<mixed>  The list or an empty list after a shape failure.
 *
 * @since   2.0.0
 */
function expectList(mixed $value, string $label, array &$errors): array
{
    if (!is_array($value) || !array_is_list($value)) {
        $errors[] = sprintf('%s must be a list.', $label);
        return [];
    }
    return $value;
}

/**
 * Build and validate a lookup from records carrying an `id` field.
 *
 * @param   mixed         $records  Candidate record list.
 * @param   string        $label    Record kind used in diagnostics.
 * @param   list<string>  $errors   Accumulated validation failures.
 *
 * @return  array<string, true>  Unique identifier lookup.
 *
 * @since   2.0.0
 */
function uniqueIds(mixed $records, string $label, array &$errors): array
{
    $result = [];
    foreach (expectList($records, $label . ' records', $errors) as $record) {
        if (!is_array($record) || !is_string($record['id'] ?? null) || $record['id'] === '') {
            $errors[] = sprintf('Every %s record requires a non-empty id.', $label);
            continue;
        }
        if (isset($result[$record['id']])) {
            $errors[] = sprintf('%s id %s is duplicated.', ucfirst($label), $record['id']);
        }
        $result[$record['id']] = true;
    }
    return $result;
}

/**
 * Build and validate a lookup from records carrying another unique string field.
 *
 * @param   mixed         $records  Candidate record list.
 * @param   string        $field    Field whose values must be unique.
 * @param   string        $label    Record kind used in diagnostics.
 * @param   list<string>  $errors   Accumulated validation failures.
 *
 * @return  array<string, true>  Unique value lookup.
 *
 * @since   2.0.0
 */
function uniqueValues(mixed $records, string $field, string $label, array &$errors): array
{
    $result = [];
    foreach (expectList($records, $label . ' records', $errors) as $record) {
        if (!is_array($record) || !is_string($record[$field] ?? null) || $record[$field] === '') {
            $errors[] = sprintf('Every %s record requires a non-empty %s.', $label, $field);
            continue;
        }
        if (isset($result[$record[$field]])) {
            $errors[] = sprintf('%s %s is duplicated.', ucfirst($label), $record[$field]);
        }
        $result[$record[$field]] = true;
    }
    return $result;
}

/**
 * Validate actors, tasks and journeys and all of their cross-references.
 *
 * @param   array<string, mixed>  $catalogue   Actor/task/journey record.
 * @param   array<string, true>   $actorIds    Known actor lookup.
 * @param   array<string, true>   $taskIds     Known task lookup.
 * @param   array<string, true>   $journeyIds  Known journey lookup.
 * @param   array<string, true>   $surfaceIds  Known surface lookup.
 * @param   list<string>          $errors      Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateCatalogue(
    array $catalogue,
    array $actorIds,
    array $taskIds,
    array $journeyIds,
    array $surfaceIds,
    array &$errors,
): void {
    foreach (expectList($catalogue['tasks'] ?? null, 'tasks', $errors) as $task) {
        if (!is_array($task) || !is_string($task['id'] ?? null)) {
            continue;
        }
        validateReferences($task['actor_ids'] ?? null, $actorIds, 'actor', 'task ' . $task['id'], $errors);
        validateReferences($task['journey_ids'] ?? null, $journeyIds, 'journey', 'task ' . $task['id'], $errors);
    }
    foreach (expectList($catalogue['journeys'] ?? null, 'journeys', $errors) as $journey) {
        if (!is_array($journey) || !is_string($journey['id'] ?? null)) {
            continue;
        }
        validateReferences($journey['actor_ids'] ?? null, $actorIds, 'actor', 'journey ' . $journey['id'], $errors);
        validateReferences(
            $journey['surface_sequence'] ?? null,
            $surfaceIds,
            'surface',
            'journey ' . $journey['id'],
            $errors,
        );
    }
    if ($taskIds === [] || $actorIds === [] || $journeyIds === []) {
        $errors[] = 'The actor/task/journey catalogue cannot be empty.';
    }
}

/**
 * Validate required surface metadata, source files and catalogue references.
 *
 * @param   string                $root           Repository root.
 * @param   array<string, mixed>  $inventory      Surface inventory.
 * @param   array<string, true>   $surfaceIds     Known surfaces.
 * @param   array<string, true>   $actorIds       Known actors.
 * @param   array<string, true>   $taskIds        Known tasks.
 * @param   array<string, true>   $journeyIds     Known journeys.
 * @param   array<string, true>   $findingIds     Known findings.
 * @param   array<string, true>   $fixtureIds     Known fixtures.
 * @param   array<string, true>   $navigationIds  Known navigation declarations.
 * @param   array<string, true>   $templatePaths  Known template dispositions.
 * @param   array<int, true>      $phaseNumbers   Known phase numbers.
 * @param   array<string, true>   $ownerIds       Known accountable roles.
 * @param   list<string>          $errors         Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateSurfaces(
    string $root,
    array $inventory,
    array $surfaceIds,
    array $actorIds,
    array $taskIds,
    array $journeyIds,
    array $findingIds,
    array $fixtureIds,
    array $navigationIds,
    array $templatePaths,
    array $phaseNumbers,
    array $ownerIds,
    array &$errors,
): void {
    $required = [
        'id', 'area', 'surface_type', 'owner', 'bounded_context', 'purpose', 'route_contracts',
        'handler_sources', 'templates', 'navigation_ids', 'capabilities', 'actor_ids', 'primary_task_id',
        'secondary_task_ids', 'journey_ids', 'current_elements', 'fixture_profile_ids', 'coverage',
        'finding_ids', 'dependencies', 'target', 'test_disposition',
    ];
    $routeNames = [];
    $surfaceNavigation = [];
    foreach (expectList($inventory['surfaces'] ?? null, 'surfaces', $errors) as $surface) {
        if (!is_array($surface) || !is_string($surface['id'] ?? null)) {
            continue;
        }
        $id = $surface['id'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $surface)) {
                $errors[] = sprintf('Surface %s is missing %s.', $id, $field);
            }
        }
        validateReferences($surface['actor_ids'] ?? null, $actorIds, 'actor', 'surface ' . $id, $errors);
        validateReferences($surface['secondary_task_ids'] ?? null, $taskIds, 'task', 'surface ' . $id, $errors);
        validateReferences($surface['journey_ids'] ?? null, $journeyIds, 'journey', 'surface ' . $id, $errors);
        validateReferences($surface['finding_ids'] ?? null, $findingIds, 'finding', 'surface ' . $id, $errors);
        validateReferences($surface['fixture_profile_ids'] ?? null, $fixtureIds, 'fixture', 'surface ' . $id, $errors);
        validateReferences($surface['navigation_ids'] ?? null, $navigationIds, 'navigation', 'surface ' . $id, $errors);
        foreach ($surface['navigation_ids'] ?? [] as $navigationId) {
            if (is_string($navigationId)) {
                $surfaceNavigation[$navigationId][] = $id;
            }
        }
        if (!is_string($surface['primary_task_id'] ?? null) || !isset($taskIds[$surface['primary_task_id']])) {
            $errors[] = sprintf('Surface %s has an unknown primary task.', $id);
        }
        $target = $surface['target'] ?? null;
        if (!is_array($target) || !is_int($target['phase'] ?? null) || !isset($phaseNumbers[$target['phase']])) {
            $errors[] = sprintf('Surface %s has no valid target phase.', $id);
        }
        foreach (expectList($surface['handler_sources'] ?? null, 'surface ' . $id . ' handler_sources', $errors) as $path) {
            requireFile($root, $path, 'handler for ' . $id, $errors);
        }
        foreach (expectList($surface['templates'] ?? null, 'surface ' . $id . ' templates', $errors) as $path) {
            if (!is_string($path) || !isset($templatePaths[$path])) {
                $errors[] = sprintf('Surface %s references an unclassified template %s.', $id, printable($path));
            }
        }
        foreach (expectList($surface['route_contracts'] ?? null, 'surface ' . $id . ' routes', $errors) as $route) {
            if (!is_array($route) || !is_string($route['name'] ?? null) || !is_string($route['path'] ?? null)) {
                $errors[] = sprintf('Surface %s has an invalid route contract.', $id);
                continue;
            }
            if (isset($routeNames[$route['name']])) {
                $errors[] = sprintf('Route contract name %s is assigned more than once.', $route['name']);
            }
            $routeNames[$route['name']] = true;
            if (expectList($route['methods'] ?? null, 'route ' . $route['name'] . ' methods', $errors) === []) {
                $errors[] = sprintf('Route contract %s has no methods.', $route['name']);
            }
        }
    }
    foreach (expectList($inventory['findings'] ?? null, 'findings', $errors) as $finding) {
        if (!is_array($finding) || !is_string($finding['id'] ?? null)) {
            continue;
        }
        validateReferences(
            $finding['surface_ids'] ?? null,
            $surfaceIds,
            'surface',
            'finding ' . $finding['id'],
            $errors,
        );
        if (!is_int($finding['target_phase'] ?? null) || !isset($phaseNumbers[$finding['target_phase']])) {
            $errors[] = sprintf('Finding %s has no valid target phase.', $finding['id']);
        }
        if (!in_array($finding['severity'] ?? null, ['P0', 'P1', 'P2', 'P3'], true)) {
            $errors[] = sprintf('Finding %s has no valid severity.', $finding['id']);
        }
        if (!is_string($finding['owner_role'] ?? null) || !isset($ownerIds[$finding['owner_role']])) {
            $errors[] = sprintf('Finding %s has an unknown owner role.', $finding['id']);
        }
    }
    foreach (expectList($inventory['navigation_catalog'] ?? null, 'navigation catalogue', $errors) as $navigation) {
        if (!is_array($navigation) || !is_string($navigation['id'] ?? null)) {
            continue;
        }
        $id = $navigation['id'];
        foreach (['area', 'path', 'icon', 'capability', 'surface_id', 'source'] as $field) {
            if (!is_string($navigation[$field] ?? null) || $navigation[$field] === '') {
                $errors[] = sprintf('Navigation %s requires a non-empty %s.', $id, $field);
            }
        }
        if (!isset($surfaceIds[$navigation['surface_id'] ?? ''])) {
            $errors[] = sprintf('Navigation %s references an unknown surface.', $id);
        }
        if (!in_array($navigation['surface_id'] ?? null, $surfaceNavigation[$id] ?? [], true)) {
            $errors[] = sprintf('Navigation %s is not linked back from its declared surface.', $id);
        }
        requireFile($root, $navigation['source'] ?? null, 'navigation source for ' . $id, $errors);
    }
}

/**
 * Compare the template catalogue with every repository Twig source.
 *
 * @param   string                $root        Repository root.
 * @param   array<string, mixed>  $inventory   Surface inventory.
 * @param   array<string, true>   $surfaceIds  Known surfaces.
 * @param   list<string>          $errors      Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateTemplates(string $root, array $inventory, array $surfaceIds, array &$errors): void
{
    $catalogued = [];
    foreach (expectList($inventory['template_catalog'] ?? null, 'template catalogue', $errors) as $template) {
        if (!is_array($template) || !is_string($template['path'] ?? null)) {
            continue;
        }
        $catalogued[$template['path']] = true;
        requireFile($root, $template['path'], 'catalogued template', $errors);
        validateReferences(
            $template['surface_ids'] ?? null,
            $surfaceIds,
            'surface',
            'template ' . $template['path'],
            $errors,
        );
    }
    $actual = twigFiles($root . '/templates', $root);
    foreach (glob($root . '/examples/extensions/*/templates', GLOB_ONLYDIR) ?: [] as $directory) {
        $actual = array_merge($actual, twigFiles($directory, $root));
    }
    $actual = array_fill_keys(array_values(array_unique($actual)), true);
    compareSets($actual, $catalogued, 'Twig source', 'template catalogue', $errors);
}

/**
 * Compare graphical path literals in the composition root with inventoried source anchors.
 *
 * @param   string                $root       Repository root.
 * @param   array<string, mixed>  $inventory  Surface inventory.
 * @param   list<string>          $errors     Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateCoreRoutes(string $root, array $inventory, array &$errors): void
{
    $sourcePath = $root . '/src/Kernel/ContainerFactory.php';
    $source = file_get_contents($sourcePath);
    if ($source === false) {
        $errors[] = 'ContainerFactory route source is unreadable.';
        return;
    }
    $actual = [];
    foreach (token_get_all($source) as $token) {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $value = decodePhpString($token[1]);
        if (
            str_starts_with($value, '/administrator')
            || str_starts_with($value, '/portal')
            || in_array($value, ['/', '/pages/{slug}', '/{path:.+}'], true)
        ) {
            $actual[$value] = true;
        }
    }
    $declared = [];
    foreach ($inventory['surfaces'] as $surface) {
        foreach ($surface['route_contracts'] as $route) {
            if (is_string($route['source_anchor'] ?? null)) {
                $declared[$route['source_anchor']] = true;
            }
        }
    }
    compareSets($actual, $declared, 'core graphical route anchor', 'surface inventory', $errors);
}

/**
 * Compare core and shipped-extension navigation declarations with the catalogue.
 *
 * @param   string                $root       Repository root.
 * @param   array<string, mixed>  $inventory  Surface inventory.
 * @param   list<string>          $errors     Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateNavigationSources(string $root, array $inventory, array &$errors): void
{
    $path = $root . '/src/Extension/Contribution/CoreExtensionContributions.php';
    $source = file_get_contents($path);
    if ($source === false) {
        $errors[] = 'Core navigation contribution source is unreadable.';
        return;
    }
    $actual = [];
    foreach (['AdministratorNavigationDefinition', 'PortalNavigationDefinition'] as $class) {
        preg_match_all('/new\s+' . $class . "\\(\\s*'([^']+)'/m", $source, $matches);
        foreach ($matches[1] ?? [] as $id) {
            $actual[$id] = true;
        }
    }
    $declared = [];
    foreach ($inventory['navigation_catalog'] as $navigation) {
        if (($navigation['source'] ?? null) === 'src/Extension/Contribution/CoreExtensionContributions.php') {
            $declared[$navigation['id']] = true;
        }
    }
    compareSets($actual, $declared, 'core navigation declaration', 'navigation catalogue', $errors);
}

/**
 * Verify each shipped extension route and navigation declaration has a programme disposition.
 *
 * @param   string                $root       Repository root.
 * @param   array<string, mixed>  $inventory  Surface inventory.
 * @param   list<string>          $errors     Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateExtensionManifests(string $root, array $inventory, array &$errors): void
{
    $declaredRoutes = [];
    foreach ($inventory['surfaces'] as $surface) {
        foreach ($surface['route_contracts'] as $route) {
            if (is_string($route['source_manifest'] ?? null) && is_string($route['declaration_name'] ?? null)) {
                $declaredRoutes[$route['source_manifest'] . '|' . $route['declaration_name']] = true;
            }
        }
    }
    $declaredNavigation = [];
    foreach ($inventory['navigation_catalog'] as $navigation) {
        if (str_starts_with((string) ($navigation['source'] ?? ''), 'examples/extensions/')) {
            $declaredNavigation[$navigation['source'] . '|' . $navigation['id']] = true;
        }
    }
    $actualRoutes = [];
    $actualNavigation = [];
    foreach (glob($root . '/examples/extensions/*/kumwe.json') ?: [] as $manifestPath) {
        $relative = relativePath($manifestPath, $root);
        $manifest = readJson($manifestPath, $errors);
        foreach (['administrator', 'portal'] as $area) {
            $areaData = $manifest['contributions'][$area] ?? [];
            if (!is_array($areaData)) {
                continue;
            }
            foreach ($areaData['routes'] ?? [] as $route) {
                if (is_array($route) && is_string($route['name'] ?? null)) {
                    $actualRoutes[$relative . '|' . $route['name']] = true;
                }
            }
            foreach ($areaData['navigation'] ?? [] as $navigation) {
                if (is_array($navigation) && is_string($navigation['id'] ?? null)) {
                    $actualNavigation[$relative . '|' . $navigation['id']] = true;
                }
            }
        }
    }
    compareSets($actualRoutes, $declaredRoutes, 'extension graphical route', 'surface inventory', $errors);
    compareSets($actualNavigation, $declaredNavigation, 'extension navigation', 'navigation catalogue', $errors);
}

/**
 * Verify every shipped administrator or portal business-definition exposure and view.
 *
 * @param   string                $root       Repository root.
 * @param   array<string, mixed>  $inventory  Surface inventory.
 * @param   list<string>          $errors     Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateGeneratedInstances(string $root, array $inventory, array &$errors): void
{
    $expected = [];
    foreach ($inventory['generated_instances'] ?? [] as $instance) {
        if (!is_array($instance) || !is_string($instance['source'] ?? null) || !is_string($instance['handle'] ?? null)) {
            $errors[] = 'Every generated instance requires source and handle.';
            continue;
        }
        $expected[$instance['source'] . '|' . $instance['handle']] = canonical($instance);
    }
    $actual = [];
    foreach (glob($root . '/examples/extensions/*/kumwe.json') ?: [] as $path) {
        $manifest = readJson($path, $errors);
        foreach ($manifest['contributions']['business']['definitions'] ?? [] as $definition) {
            if (is_array($definition)) {
                $instance = generatedInstance(relativePath($path, $root), $definition);
                $actual[$instance['source'] . '|' . $instance['handle']] = canonical($instance);
            }
        }
    }
    foreach (glob($root . '/resources/demo/business/*/definitions/*.json') ?: [] as $path) {
        $definition = readJson($path, $errors);
        if ($definition !== []) {
            $instance = generatedInstance(relativePath($path, $root), $definition);
            $actual[$instance['source'] . '|' . $instance['handle']] = canonical($instance);
        }
    }
    compareSets($actual, $expected, 'generated definition exposure', 'generated instance inventory', $errors, true);
}

/**
 * Validate work items, gates, evidence, ownership, history and completion rules.
 *
 * @param   array<string, mixed>                $ledger       Programme ledger.
 * @param   array<string, true>                 $surfaceIds   Known surfaces.
 * @param   array<string, true>                 $ownerIds     Known owner roles.
 * @param   array<string, true>                 $evidenceIds  Known evidence records.
 * @param   array<string, true>                 $gateIds      Known gates.
 * @param   array<string, array<string, mixed>> $workItems    Work items keyed by ID.
 * @param   list<string>                        $errors       Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateLedger(
    array $ledger,
    array $surfaceIds,
    array $ownerIds,
    array $evidenceIds,
    array $gateIds,
    array $workItems,
    array &$errors,
): void {
    $statuses = array_fill_keys(expectList($ledger['status_vocabulary'] ?? null, 'status vocabulary', $errors), true);
    $acceptedEvidence = [];
    foreach ($ledger['evidence_records'] ?? [] as $evidence) {
        if (is_array($evidence) && is_string($evidence['id'] ?? null) && ($evidence['status'] ?? null) === 'accepted') {
            $acceptedEvidence[$evidence['id']] = true;
        }
    }
    $allTargets = $gateIds + array_fill_keys(array_keys($workItems), true);
    foreach ($ledger['evidence_records'] ?? [] as $evidence) {
        if (!is_array($evidence) || !is_string($evidence['id'] ?? null)) {
            continue;
        }
        validateReferences($evidence['supports'] ?? null, $allTargets, 'work item or gate', 'evidence ' . $evidence['id'], $errors);
        if (!isset($ownerIds[$evidence['producer_role'] ?? ''])) {
            $errors[] = sprintf('Evidence %s has an unknown producer role.', $evidence['id']);
        }
    }
    foreach ($workItems as $id => $item) {
        validateStatusRecord($item, 'work item ' . $id, $statuses, $errors);
        validateOwnerRecord($item, 'work item ' . $id, $ownerIds, $errors);
        validateReferences($item['surface_ids'] ?? null, $surfaceIds, 'surface', 'work item ' . $id, $errors);
        validateReferences($item['evidence_ids'] ?? null, $evidenceIds, 'evidence', 'work item ' . $id, $errors);
        foreach (expectList($item['prerequisites'] ?? null, 'work item ' . $id . ' prerequisites', $errors) as $required) {
            if (!is_string($required) || (!isset($workItems[$required]) && !isset($gateIds[$required]))) {
                $errors[] = sprintf('Work item %s has unknown prerequisite %s.', $id, printable($required));
            }
        }
        if (($item['status'] ?? null) === 'complete') {
            requireAcceptedEvidence($item['evidence_ids'] ?? [], $acceptedEvidence, 'work item ' . $id, $errors);
        }
        if (($item['status'] ?? null) === 'waived') {
            if (!in_array($item['severity'] ?? null, ['P2', 'P3'], true) || !is_array($item['waiver'] ?? null)) {
                $errors[] = sprintf('Waived work item %s requires P2/P3 severity and a waiver record.', $id);
            }
        }
    }
    foreach ($ledger['gates'] ?? [] as $gate) {
        if (!is_array($gate) || !is_string($gate['id'] ?? null)) {
            continue;
        }
        $id = $gate['id'];
        validateStatusRecord($gate, 'gate ' . $id, $statuses, $errors);
        validateOwnerRecord($gate, 'gate ' . $id, $ownerIds, $errors);
        validateReferences($gate['evidence_ids'] ?? null, $evidenceIds, 'evidence', 'gate ' . $id, $errors);
        foreach (expectList($gate['prerequisites'] ?? null, 'gate ' . $id . ' prerequisites', $errors) as $required) {
            if (!is_string($required) || !isset($gateIds[$required])) {
                $errors[] = sprintf('Gate %s has unknown prerequisite %s.', $id, printable($required));
            }
        }
        if (($gate['status'] ?? null) === 'complete') {
            requireAcceptedEvidence($gate['evidence_ids'] ?? [], $acceptedEvidence, 'gate ' . $id, $errors);
        }
    }
}

/**
 * Validate one record's current status and append-only status-history tail.
 *
 * @param   array<string, mixed>  $record    Work item or gate.
 * @param   string                $label     Diagnostic label.
 * @param   array<string, true>   $statuses  Allowed status lookup.
 * @param   list<string>          $errors    Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateStatusRecord(array $record, string $label, array $statuses, array &$errors): void
{
    $status = $record['status'] ?? null;
    if (!is_string($status) || !isset($statuses[$status])) {
        $errors[] = sprintf('%s has an unknown status.', ucfirst($label));
    }
    $history = expectList($record['status_history'] ?? null, $label . ' status_history', $errors);
    $last = $history === [] ? null : $history[array_key_last($history)];
    if (!is_array($last) || ($last['to'] ?? null) !== $status) {
        $errors[] = sprintf('%s status does not match its latest history entry.', ucfirst($label));
    }
}

/**
 * Validate accountable and supporting role references.
 *
 * @param   array<string, mixed> $record    Work item or gate.
 * @param   string               $label     Diagnostic label.
 * @param   array<string, true>  $ownerIds  Known role lookup.
 * @param   list<string>         $errors    Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateOwnerRecord(array $record, string $label, array $ownerIds, array &$errors): void
{
    if (!is_string($record['owner_role'] ?? null) || !isset($ownerIds[$record['owner_role']])) {
        $errors[] = sprintf('%s has an unknown owner role.', ucfirst($label));
    }
    if (array_key_exists('supporting_roles', $record)) {
        validateReferences($record['supporting_roles'], $ownerIds, 'owner role', $label, $errors);
    }
}

/**
 * Require every named evidence record to be accepted.
 *
 * @param   mixed                $references        Evidence references.
 * @param   array<string, true>  $acceptedEvidence  Accepted evidence lookup.
 * @param   string               $label             Diagnostic label.
 * @param   list<string>         $errors            Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function requireAcceptedEvidence(mixed $references, array $acceptedEvidence, string $label, array &$errors): void
{
    $references = expectList($references, $label . ' evidence', $errors);
    if ($references === []) {
        $errors[] = sprintf('Completed %s has no evidence.', $label);
    }
    foreach ($references as $reference) {
        if (!is_string($reference) || !isset($acceptedEvidence[$reference])) {
            $errors[] = sprintf('Completed %s references unaccepted evidence %s.', $label, printable($reference));
        }
    }
}

/**
 * Validate a list of identifiers against a known lookup.
 *
 * @param   mixed                $references  Candidate identifier list.
 * @param   array<string, true>  $known       Known identifier lookup.
 * @param   string               $kind        Referenced record kind.
 * @param   string               $owner       Referencing record label.
 * @param   list<string>         $errors      Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateReferences(mixed $references, array $known, string $kind, string $owner, array &$errors): void
{
    foreach (expectList($references, $owner . ' ' . $kind . ' references', $errors) as $reference) {
        if (!is_string($reference) || !isset($known[$reference])) {
            $errors[] = sprintf('%s references unknown %s %s.', ucfirst($owner), $kind, printable($reference));
        }
    }
}

/**
 * Build one normalized generated-instance record from a shipped definition.
 *
 * @param   string                $source      Repository-relative definition source.
 * @param   array<string, mixed>  $definition  Decoded definition.
 *
 * @return  array<string, mixed>  Normalized source, owner, areas and view exposures.
 *
 * @since   2.0.0
 */
function generatedInstance(string $source, array $definition): array
{
    $areas = [];
    if (($definition['administrator_exposure'] ?? false) === true) {
        $areas[] = 'administrator';
    }
    if (($definition['portal_exposure'] ?? false) === true) {
        $areas[] = 'portal';
    }
    $views = [];
    foreach ($definition['views'] ?? [] as $view) {
        if (!is_array($view)) {
            continue;
        }
        $viewAreas = [];
        if (($definition['administrator_exposure'] ?? false) === true && ($view['administrator'] ?? true) !== false) {
            $viewAreas[] = 'administrator';
        }
        if (($definition['portal_exposure'] ?? false) === true && ($view['portal'] ?? false) === true) {
            $viewAreas[] = 'portal';
        }
        $views[] = [
            'handle' => $view['handle'] ?? null,
            'kind' => $view['kind'] ?? null,
            'areas' => $viewAreas,
        ];
    }
    return [
        'source' => $source,
        'handle' => $definition['handle'] ?? null,
        'owner' => $definition['owner'] ?? null,
        'areas' => $areas,
        'views' => $views,
    ];
}

/**
 * Canonicalize a nested array for deterministic source comparison.
 *
 * @param   mixed  $value  Value to canonicalize.
 *
 * @return  string  Stable JSON representation.
 *
 * @since   2.0.0
 */
function canonical(mixed $value): string
{
    if (is_array($value)) {
        if (array_is_list($value)) {
            $value = array_map(static fn (mixed $item): mixed => canonicalValue($item), $value);
        } else {
            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                $value[$key] = canonicalValue($item);
            }
        }
    }
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/**
 * Canonicalize one nested value without encoding it yet.
 *
 * @param   mixed  $value  Value to normalize.
 *
 * @return  mixed  Recursively key-sorted value.
 *
 * @since   2.0.0
 */
function canonicalValue(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map(static fn (mixed $item): mixed => canonicalValue($item), $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = canonicalValue($item);
    }
    return $value;
}

/**
 * List every Twig file below one directory relative to the repository.
 *
 * @param   string  $directory  Directory to traverse.
 * @param   string  $root       Repository root removed from paths.
 *
 * @return  list<string>  Sorted repository-relative Twig paths.
 *
 * @since   2.0.0
 */
function twigFiles(string $directory, string $root): array
{
    if (!is_dir($directory)) {
        return [];
    }
    $paths = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'twig') {
            $paths[] = relativePath($file->getPathname(), $root);
        }
    }
    sort($paths, SORT_STRING);
    return $paths;
}

/**
 * Compare two keyed sets and optionally compare their canonical values.
 *
 * @param   array<string, mixed>  $actual         Source-derived set.
 * @param   array<string, mixed>  $declared       Programme-derived set.
 * @param   string                $actualLabel     Source record label.
 * @param   string                $declaredLabel   Programme record label.
 * @param   list<string>          $errors          Accumulated validation failures.
 * @param   bool                  $compareValues   Whether matching keys must carry identical values.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function compareSets(
    array $actual,
    array $declared,
    string $actualLabel,
    string $declaredLabel,
    array &$errors,
    bool $compareValues = false,
): void {
    foreach (array_diff_key($actual, $declared) as $key => $_value) {
        $errors[] = sprintf('%s %s is absent from the %s.', ucfirst($actualLabel), $key, $declaredLabel);
    }
    foreach (array_diff_key($declared, $actual) as $key => $_value) {
        $errors[] = sprintf('%s %s has no matching %s.', ucfirst($declaredLabel), $key, $actualLabel);
    }
    if ($compareValues) {
        foreach (array_intersect_key($actual, $declared) as $key => $value) {
            if ($value !== $declared[$key]) {
                $errors[] = sprintf('%s %s does not match its %s metadata.', ucfirst($actualLabel), $key, $declaredLabel);
            }
        }
    }
}

/**
 * Decode a quoted PHP string token used for a route literal.
 *
 * @param   string  $literal  Complete token text including quotes.
 *
 * @return  string  Decoded literal value.
 *
 * @since   2.0.0
 */
function decodePhpString(string $literal): string
{
    $quote = $literal[0] ?? '';
    $value = substr($literal, 1, -1);
    return $quote === "'"
        ? str_replace(["\\\\", "\\'"], ["\\", "'"], $value)
        : stripcslashes($value);
}

/**
 * Require one repository-relative file to exist.
 *
 * @param   string        $root    Repository root.
 * @param   mixed         $path    Candidate relative path.
 * @param   string        $label   Diagnostic label.
 * @param   list<string>  $errors  Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function requireFile(string $root, mixed $path, string $label, array &$errors): void
{
    if (!is_string($path) || $path === '' || !is_file($root . '/' . $path)) {
        $errors[] = sprintf('%s file %s does not exist.', ucfirst($label), printable($path));
    }
}

/**
 * Convert an absolute repository path to a forward-slash relative path.
 *
 * @param   string  $path  Absolute path.
 * @param   string  $root  Repository root.
 *
 * @return  string  Repository-relative path.
 *
 * @since   2.0.0
 */
function relativePath(string $path, string $root): string
{
    return str_replace('\\', '/', substr($path, strlen(rtrim($root, '/')) + 1));
}

/**
 * Render a diagnostic value without warnings for non-string input.
 *
 * @param   mixed  $value  Value to display.
 *
 * @return  string  Compact diagnostic representation.
 *
 * @since   2.0.0
 */
function printable(mixed $value): string
{
    if (is_string($value)) {
        return $value;
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES) ?: get_debug_type($value);
}

/**
 * Print all failures and terminate with a non-zero status.
 *
 * @param   list<string>  $errors  Validation failures.
 *
 * @return  never
 *
 * @since   2.0.0
 */
function report(array $errors): never
{
    $errors = array_values(array_unique($errors));
    sort($errors, SORT_STRING);
    fwrite(STDERR, "KIS programme verification failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, ' - ' . $error . "\n");
    }
    exit(1);
}
