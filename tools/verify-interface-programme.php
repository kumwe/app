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

// Focused unit tests load the validation functions without running the repository-wide command.
if (defined('KUMWE_INTERFACE_PROGRAMME_LIBRARY_ONLY')) {
    return;
}

$root = dirname(__DIR__);
$errors = [];
$inventory = readJson($root . '/docs/interface-standard/programme/surface-inventory.json', $errors);
$catalogue = readJson($root . '/docs/interface-standard/programme/actor-task-journeys.json', $errors);
$ledger = readJson($root . '/docs/interface-standard/programme/phase-ledger.json', $errors);
$findingsRegister = readJson($root . '/docs/interface-standard/programme/findings-register.json', $errors);
$reportTemplate = readJson($root . '/docs/interface-standard/programme/verification-report-template.json', $errors);
$reports = [];
foreach (glob($root . '/docs/interface-standard/programme/reports/*.json') ?: [] as $reportPath) {
    $reports[relativePath($reportPath, $root)] = readJson($reportPath, $errors);
}

if (
    $inventory === []
    || $catalogue === []
    || $ledger === []
    || $findingsRegister === []
    || $reportTemplate === []
    || $reports === []
) {
    report($errors);
}

$surfaceIds = uniqueIds($inventory['surfaces'] ?? null, 'surface', $errors);
$actorIds = uniqueIds($catalogue['actors'] ?? null, 'actor', $errors);
$taskIds = uniqueIds($catalogue['tasks'] ?? null, 'task', $errors);
$journeyIds = uniqueIds($catalogue['journeys'] ?? null, 'journey', $errors);
$findingIds = uniqueIds($inventory['findings'] ?? null, 'finding', $errors);
$registeredFindingIds = uniqueIds($findingsRegister['findings'] ?? null, 'registered finding', $errors);
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
validateMigrationDispositions($inventory, $ledger, $errors);
validateTemplates($root, $inventory, $surfaceIds, $errors);
validateCoreRoutes($root, $inventory, $errors);
validateNavigationSources($root, $inventory, $errors);
validateCoreSurfaceDeclarations($root, $inventory, $errors);
validateExtensionManifests($root, $inventory, $errors);
validateGeneratedInstances($root, $inventory, $errors);
$findingEvidenceIds = validateFindingsRegister(
    $root,
    $findingsRegister,
    $inventory,
    $surfaceIds,
    $ownerIds,
    $phaseNumbers,
    $workItems,
    $errors,
);
$registeredFindings = normalizeFindingReferences($findingsRegister['findings'] ?? null, $errors);
$blockingFindings = normalizeBlockingFindings($findingsRegister['findings'] ?? null, $errors);
validateLedger(
    $root,
    $ledger,
    $surfaceIds,
    $ownerIds,
    $evidenceIds,
    $gateIds,
    $workItems,
    $errors,
    $blockingFindings,
    $registeredFindings,
);
$templateCheckIds = validateVerificationReport(
    $root,
    $reportTemplate,
    'verification report template',
    true,
    $surfaceIds,
    $journeyIds,
    $ownerIds,
    $phaseNumbers,
    $workItems,
    $registeredFindingIds,
    $findingEvidenceIds,
    $errors,
);
foreach ($reports as $reportPath => $report) {
    $reportCheckIds = validateVerificationReport(
        $root,
        $report,
        'verification report ' . $reportPath,
        false,
        $surfaceIds,
        $journeyIds,
        $ownerIds,
        $phaseNumbers,
        $workItems,
        $registeredFindingIds,
        $findingEvidenceIds,
        $errors,
    );
    compareSets($reportCheckIds, $templateCheckIds, 'report check', 'verification report template', $errors);
}

if ($errors !== []) {
    report($errors);
}

printf(
    "KIS programme verified: %d surfaces, %d templates, %d navigation entries, %d generated instances, "
    . "%d actors, %d tasks, %d journeys, %d work items, %d findings, %d verification reports.\n",
    count($surfaceIds),
    count($templatePaths),
    count($navigationIds),
    count($inventory['generated_instances'] ?? []),
    count($actorIds),
    count($taskIds),
    count($journeyIds),
    count($workItems),
    count($findingsRegister['findings'] ?? []),
    count($reports),
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
 * Build and validate a unique lookup from a list of non-empty strings.
 *
 * @param   mixed         $values  Candidate string list.
 * @param   string        $label   Value kind used in diagnostics.
 * @param   list<string>  $errors  Accumulated validation failures.
 *
 * @return  array<string, true>  Unique string lookup.
 *
 * @since   2.0.0
 */
function stringLookup(mixed $values, string $label, array &$errors): array
{
    $result = [];
    foreach (expectList($values, $label . ' vocabulary', $errors) as $value) {
        if (!is_string($value) || $value === '') {
            $errors[] = sprintf('Every %s vocabulary value must be a non-empty string.', $label);
            continue;
        }
        if (isset($result[$value])) {
            $errors[] = sprintf('%s vocabulary value %s is duplicated.', ucfirst($label), $value);
        }
        $result[$value] = true;
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
        'id', 'kis_runtime_disposition', 'area', 'surface_type', 'owner', 'bounded_context', 'purpose',
        'route_contracts',
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
        $runtimeDisposition = $surface['kis_runtime_disposition'] ?? null;
        if (!in_array($runtimeDisposition, ['declared', 'legacy'], true)) {
            $errors[] = sprintf('Surface %s has an invalid KIS runtime disposition.', $id);
        } elseif ($runtimeDisposition === 'declared') {
            if (!is_array($surface['kis_contract'] ?? null) || array_is_list($surface['kis_contract'])) {
                $errors[] = sprintf('Declared KIS surface %s requires a canonical kis_contract.', $id);
            }
        } elseif (array_key_exists('kis_contract', $surface)) {
            $errors[] = sprintf('Legacy KIS surface %s cannot claim a canonical kis_contract.', $id);
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
        $handlerSources = expectList(
            $surface['handler_sources'] ?? null,
            'surface ' . $id . ' handler_sources',
            $errors,
        );
        foreach ($handlerSources as $path) {
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
        if (!in_array($navigation['runtime_surface_binding'] ?? null, ['declared', 'legacy'], true)) {
            $errors[] = sprintf('Navigation %s has an invalid runtime surface binding disposition.', $id);
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
 * Prevent a completed migration phase from retaining an undeclared runtime surface or navigation link.
 *
 * @param   array<string, mixed>  $inventory  Authoritative surface and navigation inventory.
 * @param   array<string, mixed>  $ledger     Phase and programme state.
 * @param   list<string>          $errors     Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateMigrationDispositions(array $inventory, array $ledger, array &$errors): void
{
    $completePhases = [];
    foreach ($ledger['phases'] ?? [] as $phase) {
        if (is_array($phase) && is_int($phase['number'] ?? null) && ($phase['status'] ?? null) === 'complete') {
            $completePhases[$phase['number']] = true;
        }
    }
    $programmeComplete = ($ledger['programme_status'] ?? null) === 'complete';
    $surfaceDispositions = [];
    foreach ($inventory['surfaces'] ?? [] as $surface) {
        if (!is_array($surface) || !is_string($surface['id'] ?? null)) {
            continue;
        }
        $id = $surface['id'];
        $disposition = $surface['kis_runtime_disposition'] ?? null;
        $surfaceDispositions[$id] = $disposition;
        $targetPhase = $surface['target']['phase'] ?? null;
        $mustBeDeclared = $programmeComplete || (is_int($targetPhase) && isset($completePhases[$targetPhase]));
        if ($mustBeDeclared && $disposition !== 'declared') {
            $errors[] = sprintf('Completed migration scope retains legacy surface %s.', $id);
        }
        if ($disposition === 'declared' && ($surface['target']['migration_status'] ?? null) !== 'complete') {
            $errors[] = sprintf('Declared KIS surface %s must record a complete migration target.', $id);
        }
    }
    foreach ($inventory['navigation_catalog'] ?? [] as $navigation) {
        if (!is_array($navigation) || !is_string($navigation['id'] ?? null)) {
            continue;
        }
        $surfaceId = $navigation['surface_id'] ?? null;
        if (
            is_string($surfaceId)
            && ($surfaceDispositions[$surfaceId] ?? null) === 'declared'
            && ($navigation['runtime_surface_binding'] ?? null) !== 'declared'
        ) {
            $errors[] = sprintf(
                'Navigation %s must bind its declared KIS surface %s.',
                $navigation['id'],
                $surfaceId,
            );
        }
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
        $contents = file_get_contents($root . '/' . $template['path']);
        if (is_string($contents)) {
            validateTemplateSurfaceMarkers($template['path'], $contents, $surfaceIds, $errors);
        }
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
 * Reject literal template surface markers that bypass the authoritative inventory.
 *
 * Dynamic markers remain governed by their catalogue links and handler contracts. Literal IDs are exact
 * enough to bind directly, so allowing an unknown one would create a second, silently ungoverned surface
 * vocabulary in rendered HTML.
 *
 * @param   string               $path        Repository-relative template path.
 * @param   string               $contents    Complete Twig source.
 * @param   array<string, true>  $surfaceIds  Authoritative surface lookup.
 * @param   list<string>         $errors      Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateTemplateSurfaceMarkers(
    string $path,
    string $contents,
    array $surfaceIds,
    array &$errors,
): void {
    preg_match_all(
        '/data-kis-surface\s*=\s*"([A-Za-z][A-Za-z0-9._:-]*)"/D',
        $contents,
        $matches,
    );
    foreach (array_unique($matches[1] ?? []) as $surfaceId) {
        if (!isset($surfaceIds[$surfaceId])) {
            $errors[] = sprintf(
                'Template %s renders unknown literal KIS surface %s.',
                $path,
                $surfaceId,
            );
        }
    }
}

/**
 * Compare registered core graphical routes with their exact inventoried contracts.
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
    $declared = [];
    foreach ($inventory['surfaces'] ?? [] as $surface) {
        foreach ($surface['route_contracts'] ?? [] as $route) {
            if (is_string($route['source_anchor'] ?? null)) {
                $declared[] = [
                    'name' => $route['name'] ?? null,
                    'path' => $route['path'] ?? null,
                    'methods' => $route['methods'] ?? null,
                ];
            }
        }
    }
    validateCoreRouteContracts($source, $declared, $errors);
}

/**
 * Compare core navigation declarations, including KIS binding disposition, with the catalogue.
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
    $declared = [];
    foreach ($inventory['navigation_catalog'] ?? [] as $navigation) {
        if (($navigation['source'] ?? null) === 'src/Extension/Contribution/CoreExtensionContributions.php') {
            $declared[] = $navigation;
        }
    }
    validateCoreNavigationContracts($source, $declared, $errors);
}

/**
 * Compare every literal typed core KIS declaration with its complete canonical inventory contract.
 *
 * @param   string                $root       Repository root.
 * @param   array<string, mixed>  $inventory  Surface inventory.
 * @param   list<string>          $errors     Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateCoreSurfaceDeclarations(string $root, array $inventory, array &$errors): void
{
    $path = $root . '/src/Extension/Contribution/CoreExtensionContributions.php';
    $source = file_get_contents($path);
    if ($source === false) {
        $errors[] = 'Core interface-surface contribution source is unreadable.';
        return;
    }
    validateCoreSurfaceContracts($source, $inventory['surfaces'] ?? [], $errors);
}

/**
 * Compare one PHP source's registered graphical routes with exact inventory tuples.
 *
 * @param   string                       $source    PHP route-registration source.
 * @param   list<array<string, mixed>>   $declared Inventory route contracts.
 * @param   list<string>                 $errors   Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateCoreRouteContracts(string $source, array $declared, array &$errors): void
{
    $actualByName = [];
    foreach (extractCoreGraphicalRoutes($source) as $route) {
        $name = $route['name'];
        if (isset($actualByName[$name])) {
            $errors[] = sprintf('Core graphical route %s is registered more than once.', $name);
            continue;
        }
        $actualByName[$name] = $route;
    }
    $declaredByName = [];
    foreach ($declared as $route) {
        $name = $route['name'] ?? null;
        if (!is_string($name) || !is_string($route['path'] ?? null) || !is_array($route['methods'] ?? null)) {
            $errors[] = 'A core graphical inventory route has an invalid exact contract.';
            continue;
        }
        if (isset($declaredByName[$name])) {
            $errors[] = sprintf('Core graphical inventory route %s is declared more than once.', $name);
            continue;
        }
        $declaredByName[$name] = [
            'name' => $name,
            'path' => $route['path'],
            'methods' => $route['methods'],
        ];
    }
    foreach (array_diff_key($actualByName, $declaredByName) as $name => $_route) {
        $errors[] = sprintf('Core graphical route %s is absent from the surface inventory.', $name);
    }
    foreach (array_diff_key($declaredByName, $actualByName) as $name => $_route) {
        $errors[] = sprintf('Surface inventory route %s has no matching core graphical registration.', $name);
    }
    foreach (array_intersect_key($actualByName, $declaredByName) as $name => $route) {
        if (canonical($route) !== canonical($declaredByName[$name])) {
            $errors[] = sprintf('Core graphical route %s does not match its exact inventory path and methods.', $name);
        }
    }
}

/**
 * Extract exact graphical route tuples from literal and literal-foreach application registrations.
 *
 * @param   string  $source  PHP route-registration source.
 *
 * @return  list<array{name: string, path: string, methods: list<string>}>  Source route tuples.
 *
 * @since   2.0.0
 */
function extractCoreGraphicalRoutes(string $source): array
{
    $tokens = token_get_all($source);
    $contexts = literalForeachContexts($tokens);
    $routes = [];
    $excluded = [];
    foreach ($contexts as $context) {
        $excluded[] = [$context['start'], $context['end']];
        $routes = array_merge(
            $routes,
            applicationRouteCalls($tokens, $context['start'], $context['end'], $context['variables']),
        );
    }
    $routes = array_merge($routes, applicationRouteCalls($tokens, 0, count($tokens) - 1, [], $excluded));
    return $routes;
}

/**
 * Extract application route calls within one token range.
 *
 * @param   list<mixed>                                 $tokens     PHP tokens.
 * @param   int                                         $start      Inclusive token offset.
 * @param   int                                         $end        Inclusive token offset.
 * @param   array<string, mixed>                        $variables  Literal loop variables.
 * @param   list<array{0: int, 1: int}>                 $excluded   Ranges handled through loop expansion.
 *
 * @return  list<array{name: string, path: string, methods: list<string>}>  Source route tuples.
 *
 * @since   2.0.0
 */
function applicationRouteCalls(
    array $tokens,
    int $start,
    int $end,
    array $variables,
    array $excluded = [],
): array {
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
            || !in_array($methodToken[1], ['get', 'post', 'route'], true)
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
        $nameArgument = $methodToken[1] === 'route' ? 3 : 2;
        $name = evaluatePhpStringExpression($arguments[$nameArgument] ?? [], $variables);
        if (!is_string($path) || !is_string($name) || !isCoreGraphicalPath($path)) {
            $index = $close;
            continue;
        }
        if ($methodToken[1] === 'route') {
            $valid = false;
            $methods = parsePhpLiteralValue($arguments[2] ?? [], $valid);
            if (!$valid || !is_array($methods) || !array_is_list($methods)) {
                $index = $close;
                continue;
            }
        } else {
            $methods = [$methodToken[1] === 'get' ? 'GET' : 'POST'];
        }
        if (!array_is_list($methods) || array_filter($methods, 'is_string') !== $methods) {
            $index = $close;
            continue;
        }
        $routes[] = ['name' => $name, 'path' => $path, 'methods' => $methods];
        $index = $close;
    }
    return $routes;
}

/**
 * Decide whether an application path belongs to the inventoried graphical interface.
 *
 * @param   string  $path  Registered route path.
 *
 * @return  bool  True for public, administrator, or portal graphical routes.
 *
 * @since   2.0.0
 */
function isCoreGraphicalPath(string $path): bool
{
    return str_starts_with($path, '/administrator')
        || str_starts_with($path, '/portal')
        || in_array($path, ['/', '/pages/{slug}', '/{path:.+}'], true);
}

/**
 * Compare literal core navigation constructors with their exact catalogue bindings.
 *
 * @param   string                       $source    PHP contribution source.
 * @param   list<array<string, mixed>>   $declared Navigation catalogue records.
 * @param   list<string>                 $errors   Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateCoreNavigationContracts(string $source, array $declared, array &$errors): void
{
    $actualById = [];
    foreach (extractCoreNavigationDeclarations($source, $errors) as $navigation) {
        $id = $navigation['id'];
        if (isset($actualById[$id])) {
            $errors[] = sprintf('Core navigation declaration %s is repeated.', $id);
            continue;
        }
        $actualById[$id] = $navigation;
    }
    $declaredById = [];
    foreach ($declared as $navigation) {
        $id = $navigation['id'] ?? null;
        if (!is_string($id)) {
            $errors[] = 'A core navigation catalogue record has no ID.';
            continue;
        }
        $disposition = $navigation['runtime_surface_binding'] ?? null;
        if (!in_array($disposition, ['declared', 'legacy'], true)) {
            $errors[] = sprintf('Core navigation %s has no valid runtime surface binding disposition.', $id);
            continue;
        }
        $declaredById[$id] = [
            'id' => $id,
            'area' => $navigation['area'] ?? null,
            'path' => $navigation['path'] ?? null,
            'icon' => $navigation['icon'] ?? null,
            'capability' => $navigation['capability'] ?? null,
            'surface' => $disposition === 'declared' ? ($navigation['surface_id'] ?? null) : null,
        ];
    }
    foreach (array_diff_key($actualById, $declaredById) as $id => $_navigation) {
        $errors[] = sprintf('Core navigation declaration %s is absent from the navigation catalogue.', $id);
    }
    foreach (array_diff_key($declaredById, $actualById) as $id => $_navigation) {
        $errors[] = sprintf('Navigation catalogue entry %s has no matching core declaration.', $id);
    }
    foreach (array_intersect_key($actualById, $declaredById) as $id => $navigation) {
        if (canonical($navigation) !== canonical($declaredById[$id])) {
            $errors[] = sprintf('Core navigation declaration %s does not match its exact catalogue binding.', $id);
        }
    }
}

/**
 * Extract core administrator and portal navigation constructor bindings.
 *
 * @param   string        $source  PHP contribution source.
 * @param   list<string>  $errors  Accumulated validation failures.
 *
 * @return  list<array{id: string, area: string, path: string, icon: string, capability: string, surface: ?string}>
 *          Literal source declarations.
 *
 * @since   2.0.0
 */
function extractCoreNavigationDeclarations(string $source, array &$errors): array
{
    $tokens = token_get_all($source);
    $classes = [
        'AdministratorNavigationDefinition' => 'administrator',
        'PortalNavigationDefinition' => 'portal',
    ];
    $declarations = [];
    foreach (literalConstructorCalls($tokens, array_keys($classes), $errors) as $call) {
        $arguments = $call['arguments'];
        if (
            !is_string($arguments[0] ?? null)
            || !is_string($arguments[4] ?? null)
            || !is_string($arguments[5] ?? null)
            || !is_string($arguments[6] ?? null)
            || (array_key_exists(9, $arguments) && !is_string($arguments[9]) && $arguments[9] !== null)
        ) {
            $errors[] = sprintf('%s source declaration has an invalid binding shape.', $call['class']);
            continue;
        }
        $declarations[] = [
            'id' => $arguments[0],
            'area' => $classes[$call['class']],
            'path' => $arguments[4],
            'icon' => $arguments[5],
            'capability' => $arguments[6],
            'surface' => $arguments[9] ?? null,
        ];
    }
    return $declarations;
}

/**
 * Compare literal core SurfaceDefinition documents with declared and legacy inventory dispositions.
 *
 * @param   string                       $source    PHP contribution source.
 * @param   list<array<string, mixed>>   $surfaces Surface inventory records.
 * @param   list<string>                 $errors   Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateCoreSurfaceContracts(string $source, array $surfaces, array &$errors): void
{
    $expected = [];
    foreach ($surfaces as $surface) {
        $id = $surface['id'] ?? null;
        if (!is_string($id)) {
            continue;
        }
        if (!in_array($surface['owner'] ?? null, ['core', 'core-generator'], true)) {
            continue;
        }
        $disposition = $surface['kis_runtime_disposition'] ?? null;
        if ($disposition === 'declared') {
            if (!is_array($surface['kis_contract'] ?? null) || array_is_list($surface['kis_contract'])) {
                $errors[] = sprintf('Declared KIS surface %s has no canonical kis_contract.', $id);
                continue;
            }
            $expected[$id] = $surface['kis_contract'];
        } elseif ($disposition === 'legacy' && array_key_exists('kis_contract', $surface)) {
            $errors[] = sprintf('Legacy KIS surface %s carries a hidden typed contract.', $id);
        }
    }
    $actual = extractCoreSurfaceDefinitions($source, $errors);
    foreach (array_diff_key($actual, $expected) as $id => $_contract) {
        $errors[] = sprintf('Core typed surface %s is not admitted by a declared inventory disposition.', $id);
    }
    foreach (array_diff_key($expected, $actual) as $id => $_contract) {
        $errors[] = sprintf('Declared inventory surface %s has no matching core typed declaration.', $id);
    }
    foreach (array_intersect_key($actual, $expected) as $id => $contract) {
        if (canonical($contract) !== canonical($expected[$id])) {
            $errors[] = sprintf('Core typed surface %s does not match its complete canonical kis_contract.', $id);
        }
    }
}

/**
 * Extract literal SurfaceDefinition::fromArray documents keyed by their stable surface identifier.
 *
 * @param   string        $source  PHP contribution source.
 * @param   list<string>  $errors  Accumulated validation failures.
 *
 * @return  array<string, array<string, mixed>>  Canonical source contracts by surface ID.
 *
 * @since   2.0.0
 */
function extractCoreSurfaceDefinitions(string $source, array &$errors): array
{
    $tokens = token_get_all($source);
    $definitions = [];
    for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'SurfaceDefinition') {
            continue;
        }
        $operator = nextMeaningfulPhpToken($tokens, $index + 1);
        $method = $operator === null ? null : nextMeaningfulPhpToken($tokens, $operator + 1);
        $open = $method === null ? null : nextMeaningfulPhpToken($tokens, $method + 1);
        if (
            $operator === null
            || phpTokenText($tokens[$operator]) !== '::'
            || $method === null
            || !is_array($tokens[$method])
            || $tokens[$method][0] !== T_STRING
            || $tokens[$method][1] !== 'fromArray'
            || $open === null
            || phpTokenText($tokens[$open]) !== '('
        ) {
            continue;
        }
        $close = closingPhpToken($tokens, $open, '(', ')');
        if ($close === null) {
            $errors[] = 'A core SurfaceDefinition call has no closing parenthesis.';
            continue;
        }
        $arguments = splitPhpArguments(array_slice($tokens, $open + 1, $close - $open - 1));
        $valid = false;
        $document = parsePhpLiteralValue($arguments[1] ?? [], $valid);
        if (!$valid || !is_array($document) || array_is_list($document) || !is_string($document['surface'] ?? null)) {
            $errors[] = 'Every core SurfaceDefinition must use one complete literal declaration document.';
            $index = $close;
            continue;
        }
        $id = $document['surface'];
        unset($document['surface']);
        if (isset($definitions[$id])) {
            $errors[] = sprintf('Core typed surface %s is declared more than once.', $id);
        } else {
            $definitions[$id] = $document;
        }
        $index = $close;
    }
    return $definitions;
}

/**
 * Extract constructor calls whose arguments are entirely literal values.
 *
 * @param   list<mixed>          $tokens   PHP tokens.
 * @param   list<string>         $classes  Short constructor class names to collect.
 * @param   list<string>         $errors   Accumulated validation failures.
 *
 * @return  list<array{class: string, arguments: list<mixed>}>  Literal constructor calls.
 *
 * @since   2.0.0
 */
function literalConstructorCalls(array $tokens, array $classes, array &$errors): array
{
    $calls = [];
    for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_NEW) {
            continue;
        }
        $classIndex = nextMeaningfulPhpToken($tokens, $index + 1);
        $classToken = $classIndex === null ? null : ($tokens[$classIndex] ?? null);
        if (!is_array($classToken) || $classToken[0] !== T_STRING || !in_array($classToken[1], $classes, true)) {
            continue;
        }
        $open = nextMeaningfulPhpToken($tokens, $classIndex + 1);
        if ($open === null || phpTokenText($tokens[$open]) !== '(') {
            $errors[] = sprintf('%s source declaration has no argument list.', $classToken[1]);
            continue;
        }
        $close = closingPhpToken($tokens, $open, '(', ')');
        if ($close === null) {
            $errors[] = sprintf('%s source declaration has no closing parenthesis.', $classToken[1]);
            continue;
        }
        $arguments = [];
        $validCall = true;
        foreach (splitPhpArguments(array_slice($tokens, $open + 1, $close - $open - 1)) as $argumentTokens) {
            $valid = false;
            $argument = parsePhpLiteralValue($argumentTokens, $valid);
            if (!$valid) {
                $validCall = false;
                break;
            }
            $arguments[] = $argument;
        }
        if (!$validCall) {
            $errors[] = sprintf('%s source declaration must use literal constructor bindings.', $classToken[1]);
        } else {
            $calls[] = ['class' => $classToken[1], 'arguments' => $arguments];
        }
        $index = $close;
    }
    return $calls;
}

/**
 * Expand foreach statements over literal lists into token ranges and variable bindings.
 *
 * @param   list<mixed>  $tokens  PHP tokens.
 *
 * @return  list<array{start: int, end: int, variables: array<string, mixed>}>  Expanded loop contexts.
 *
 * @since   2.0.0
 */
function literalForeachContexts(array $tokens): array
{
    $contexts = [];
    for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_FOREACH) {
            continue;
        }
        $open = nextMeaningfulPhpToken($tokens, $index + 1);
        if ($open === null || phpTokenText($tokens[$open]) !== '(') {
            continue;
        }
        $close = closingPhpToken($tokens, $open, '(', ')');
        if ($close === null) {
            continue;
        }
        $inside = array_slice($tokens, $open + 1, $close - $open - 1);
        $as = topLevelTokenIndex($inside, T_AS);
        if ($as === null) {
            continue;
        }
        $valid = false;
        $iterable = parsePhpLiteralValue(array_slice($inside, 0, $as), $valid);
        if (!$valid || !is_array($iterable) || !array_is_list($iterable)) {
            continue;
        }
        $variables = foreachBindingVariables(array_slice($inside, $as + 1));
        if ($variables === []) {
            continue;
        }
        $bodyOpen = nextMeaningfulPhpToken($tokens, $close + 1);
        if ($bodyOpen === null || phpTokenText($tokens[$bodyOpen]) !== '{') {
            continue;
        }
        $bodyClose = closingPhpToken($tokens, $bodyOpen, '{', '}');
        if ($bodyClose === null) {
            continue;
        }
        foreach ($iterable as $item) {
            $bindings = [];
            if (count($variables) === 1) {
                $bindings[$variables[0]] = $item;
            } elseif (is_array($item) && array_is_list($item) && count($item) >= count($variables)) {
                foreach ($variables as $offset => $variable) {
                    $bindings[$variable] = $item[$offset];
                }
            } else {
                continue;
            }
            $contexts[] = ['start' => $bodyOpen + 1, 'end' => $bodyClose - 1, 'variables' => $bindings];
        }
        $index = $bodyClose;
    }
    return $contexts;
}

/**
 * Locate one token kind at the top level of a token slice.
 *
 * @param   list<mixed>  $tokens  PHP tokens.
 * @param   int          $kind    Token identifier to locate.
 *
 * @return  ?int  Token offset, or null when absent.
 *
 * @since   2.0.0
 */
function topLevelTokenIndex(array $tokens, int $kind): ?int
{
    $depth = ['(' => 0, '[' => 0, '{' => 0];
    foreach ($tokens as $index => $token) {
        $text = phpTokenText($token);
        if (isset($depth[$text])) {
            ++$depth[$text];
        } elseif ($text === ')') {
            --$depth['('];
        } elseif ($text === ']') {
            --$depth['['];
        } elseif ($text === '}') {
            --$depth['{'];
        } elseif (is_array($token) && $token[0] === $kind && max($depth) === 0) {
            return $index;
        }
    }
    return null;
}

/**
 * Parse a scalar or list-destructuring foreach binding.
 *
 * @param   list<mixed>  $tokens  Binding tokens after the as keyword.
 *
 * @return  list<string>  Variable names including their dollar prefix.
 *
 * @since   2.0.0
 */
function foreachBindingVariables(array $tokens): array
{
    $tokens = meaningfulPhpTokens($tokens);
    if (count($tokens) === 1 && is_array($tokens[0]) && $tokens[0][0] === T_VARIABLE) {
        return [$tokens[0][1]];
    }
    if ($tokens === [] || phpTokenText($tokens[0]) !== '[' || phpTokenText($tokens[array_key_last($tokens)]) !== ']') {
        return [];
    }
    $variables = [];
    foreach (array_slice($tokens, 1, -1) as $token) {
        if (is_array($token) && $token[0] === T_VARIABLE) {
            $variables[] = $token[1];
        } elseif (phpTokenText($token) !== ',') {
            return [];
        }
    }
    return $variables;
}

/**
 * Split a token slice at top-level commas.
 *
 * @param   list<mixed>  $tokens  Argument-list tokens without their outer parentheses.
 *
 * @return  list<list<mixed>>  Argument token slices.
 *
 * @since   2.0.0
 */
function splitPhpArguments(array $tokens): array
{
    $arguments = [];
    $current = [];
    $depth = ['(' => 0, '[' => 0, '{' => 0];
    foreach ($tokens as $token) {
        $text = phpTokenText($token);
        if ($text === ',' && max($depth) === 0) {
            if (meaningfulPhpTokens($current) !== []) {
                $arguments[] = $current;
            }
            $current = [];
            continue;
        }
        $current[] = $token;
        if (isset($depth[$text])) {
            ++$depth[$text];
        } elseif ($text === ')') {
            --$depth['('];
        } elseif ($text === ']') {
            --$depth['['];
        } elseif ($text === '}') {
            --$depth['{'];
        }
    }
    if (meaningfulPhpTokens($current) !== []) {
        $arguments[] = $current;
    }
    return $arguments;
}

/**
 * Parse one PHP literal composed only of scalars and nested short arrays.
 *
 * @param   list<mixed>  $tokens  Literal expression tokens.
 * @param   bool         $valid   Set true only when the complete expression is supported.
 *
 * @return  mixed  Parsed literal value, or null when invalid.
 *
 * @since   2.0.0
 */
function parsePhpLiteralValue(array $tokens, bool &$valid): mixed
{
    $tokens = meaningfulPhpTokens($tokens);
    $index = 0;
    $valid = $tokens !== [];
    $value = parsePhpLiteralNode($tokens, $index, $valid);
    if (!$valid || $index !== count($tokens)) {
        $valid = false;
        return null;
    }
    return $value;
}

/**
 * Parse one recursive PHP literal node.
 *
 * @param   list<mixed>  $tokens  Meaningful PHP tokens.
 * @param   int          $index   Current token offset, advanced on success.
 * @param   bool         $valid   Mutable parser validity.
 *
 * @return  mixed  Parsed scalar or array value.
 *
 * @since   2.0.0
 */
function parsePhpLiteralNode(array $tokens, int &$index, bool &$valid): mixed
{
    $token = $tokens[$index] ?? null;
    if ($token === '[') {
        ++$index;
        $result = [];
        while ($valid && ($tokens[$index] ?? null) !== ']') {
            $first = parsePhpLiteralNode($tokens, $index, $valid);
            if (!$valid) {
                return null;
            }
            $next = $tokens[$index] ?? null;
            if (is_array($next) && $next[0] === T_DOUBLE_ARROW) {
                ++$index;
                $value = parsePhpLiteralNode($tokens, $index, $valid);
                if (!$valid || (!is_int($first) && !is_string($first))) {
                    $valid = false;
                    return null;
                }
                $result[$first] = $value;
            } else {
                $result[] = $first;
            }
            if (($tokens[$index] ?? null) === ',') {
                ++$index;
                if (($tokens[$index] ?? null) === ']') {
                    break;
                }
            } elseif (($tokens[$index] ?? null) !== ']') {
                $valid = false;
                return null;
            }
        }
        if (($tokens[$index] ?? null) !== ']') {
            $valid = false;
            return null;
        }
        ++$index;
        return $result;
    }
    if (!is_array($token)) {
        $valid = false;
        return null;
    }
    ++$index;
    if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
        $value = decodePhpString($token[1]);
        while (($tokens[$index] ?? null) === '.') {
            $next = $tokens[$index + 1] ?? null;
            if (!is_array($next) || $next[0] !== T_CONSTANT_ENCAPSED_STRING) {
                $valid = false;
                return null;
            }
            $value .= decodePhpString($next[1]);
            $index += 2;
        }
        return $value;
    }
    if ($token[0] === T_LNUMBER) {
        return (int) str_replace('_', '', $token[1]);
    }
    if ($token[0] === T_DNUMBER) {
        return (float) str_replace('_', '', $token[1]);
    }
    if ($token[0] === T_STRING) {
        return match (strtolower($token[1])) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => invalidatePhpLiteral($valid),
        };
    }
    $valid = false;
    return null;
}

/**
 * Mark a PHP literal parse invalid while yielding a null expression value.
 *
 * @param   bool  $valid  Mutable parser validity.
 *
 * @return  null
 *
 * @since   2.0.0
 */
function invalidatePhpLiteral(bool &$valid): null
{
    $valid = false;
    return null;
}

/**
 * Resolve a concatenated literal/foreach-variable PHP string expression.
 *
 * @param   list<mixed>            $tokens     Expression tokens.
 * @param   array<string, mixed>   $variables  Literal values keyed by variable token text.
 *
 * @return  ?string  Resolved string, or null for a dynamic expression.
 *
 * @since   2.0.0
 */
function evaluatePhpStringExpression(array $tokens, array $variables): ?string
{
    $tokens = meaningfulPhpTokens($tokens);
    if ($tokens === []) {
        return null;
    }
    $value = '';
    $expectValue = true;
    foreach ($tokens as $token) {
        if ($expectValue) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $part = decodePhpString($token[1]);
            } elseif (
                is_array($token)
                && $token[0] === T_VARIABLE
                && is_string($variables[$token[1]] ?? null)
            ) {
                $part = $variables[$token[1]];
            } else {
                return null;
            }
            $value .= $part;
        } elseif (phpTokenText($token) !== '.') {
            return null;
        }
        $expectValue = !$expectValue;
    }
    return $expectValue ? null : $value;
}

/**
 * Remove whitespace and comments from a PHP token slice.
 *
 * @param   list<mixed>  $tokens  Raw PHP tokens.
 *
 * @return  list<mixed>  Meaningful tokens with stable numeric keys.
 *
 * @since   2.0.0
 */
function meaningfulPhpTokens(array $tokens): array
{
    return array_values(array_filter(
        $tokens,
        static fn (mixed $token): bool => !is_array($token)
            || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
}

/**
 * Find the next non-whitespace, non-comment PHP token.
 *
 * @param   list<mixed>  $tokens  PHP tokens.
 * @param   int          $start   First candidate offset.
 *
 * @return  ?int  Token offset, or null when none remains.
 *
 * @since   2.0.0
 */
function nextMeaningfulPhpToken(array $tokens, int $start): ?int
{
    for ($index = $start, $count = count($tokens); $index < $count; ++$index) {
        $token = $tokens[$index];
        if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            return $index;
        }
    }
    return null;
}

/**
 * Find a balanced closing punctuation token.
 *
 * @param   list<mixed>  $tokens  PHP tokens.
 * @param   int          $open    Opening token offset.
 * @param   string       $left    Opening punctuation.
 * @param   string       $right   Closing punctuation.
 *
 * @return  ?int  Closing token offset, or null when unbalanced.
 *
 * @since   2.0.0
 */
function closingPhpToken(array $tokens, int $open, string $left, string $right): ?int
{
    $depth = 0;
    for ($index = $open, $count = count($tokens); $index < $count; ++$index) {
        $text = phpTokenText($tokens[$index]);
        if ($text === $left) {
            ++$depth;
        } elseif ($text === $right && --$depth === 0) {
            return $index;
        }
    }
    return null;
}

/**
 * Return one token's source text.
 *
 * @param   mixed  $token  PHP array token or punctuation string.
 *
 * @return  string  Token source text.
 *
 * @since   2.0.0
 */
function phpTokenText(mixed $token): string
{
    return is_array($token) ? $token[1] : (is_string($token) ? $token : '');
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
    $inventorySurfaces = [];
    $inventorySurfacesByManifest = [];
    $declaredInventorySurfacesByManifest = [];
    $declaredRoutes = [];
    $inventoryRoutes = [];
    foreach ($inventory['surfaces'] as $surface) {
        if (!is_array($surface) || !is_string($surface['id'] ?? null)) {
            continue;
        }
        $inventorySurfaces[$surface['id']] = $surface;
        foreach ($surface['route_contracts'] as $route) {
            if (is_string($route['source_manifest'] ?? null) && is_string($route['declaration_name'] ?? null)) {
                $routeKey = $route['source_manifest'] . '|' . $route['declaration_name'];
                $declaredRoutes[$routeKey] = true;
                $inventoryRoutes[$routeKey] = $route;
                $inventorySurfacesByManifest[$route['source_manifest']][$surface['id']] = true;
                if (($surface['kis_runtime_disposition'] ?? null) === 'declared') {
                    $declaredInventorySurfacesByManifest[$route['source_manifest']][$surface['id']] = true;
                }
            }
        }
    }
    $declaredNavigation = [];
    $inventoryNavigation = [];
    foreach ($inventory['navigation_catalog'] as $navigation) {
        if (str_starts_with((string) ($navigation['source'] ?? ''), 'examples/extensions/')) {
            $declaredNavigation[$navigation['source'] . '|' . $navigation['id']] = true;
            $inventoryNavigation[$navigation['source'] . '|' . $navigation['id']] = $navigation;
        }
    }
    $actualRoutes = [];
    $actualNavigation = [];
    foreach (glob($root . '/examples/extensions/*/kumwe.json') ?: [] as $manifestPath) {
        $relative = relativePath($manifestPath, $root);
        $manifest = readJson($manifestPath, $errors);
        $extension = $manifest['name'] ?? null;
        $manifestSurfaces = [];
        foreach ($manifest['contributions']['interface']['surfaces'] ?? [] as $surface) {
            if (!is_array($surface) || !is_string($surface['surface'] ?? null)) {
                $errors[] = sprintf('Extension manifest %s has an interface surface without an ID.', $relative);
                continue;
            }
            $surfaceId = $surface['surface'];
            if (isset($manifestSurfaces[$surfaceId])) {
                $errors[] = sprintf('Extension manifest %s repeats interface surface %s.', $relative, $surfaceId);
                continue;
            }
            $manifestSurfaces[$surfaceId] = true;
            $inventorySurface = $inventorySurfaces[$surfaceId] ?? null;
            if (!is_array($inventorySurface)) {
                $errors[] = sprintf(
                    'Extension interface surface %s from %s is absent from the surface inventory.',
                    $surfaceId,
                    $relative,
                );
                continue;
            }
            if (!isset($inventorySurfacesByManifest[$relative][$surfaceId])) {
                $errors[] = sprintf(
                    'Extension interface surface %s is not source-bound to %s in the surface inventory.',
                    $surfaceId,
                    $relative,
                );
            }
            if (($inventorySurface['kis_runtime_disposition'] ?? null) !== 'declared') {
                $errors[] = sprintf(
                    'Extension interface surface %s from %s must have a declared inventory disposition.',
                    $surfaceId,
                    $relative,
                );
            }
            $manifestContract = $surface;
            unset($manifestContract['surface']);
            if (canonical($manifestContract) !== canonical($inventorySurface['kis_contract'] ?? null)) {
                $errors[] = sprintf(
                    'Extension interface surface %s does not match its complete canonical kis_contract.',
                    $surfaceId,
                );
            }
            $manifestCapabilities = expectList(
                $surface['capabilities'] ?? null,
                'extension surface ' . $surfaceId . ' capabilities',
                $errors,
            );
            $inventoryCapabilities = expectList(
                $inventorySurface['capabilities'] ?? null,
                'inventory surface ' . $surfaceId . ' capabilities',
                $errors,
            );
            sort($manifestCapabilities, SORT_STRING);
            sort($inventoryCapabilities, SORT_STRING);
            if ($manifestCapabilities !== $inventoryCapabilities) {
                $errors[] = sprintf(
                    'Extension surface %s capabilities %s do not match inventory capabilities %s.',
                    $surfaceId,
                    printable($manifestCapabilities),
                    printable($inventoryCapabilities),
                );
            }
        }
        compareSets(
            $manifestSurfaces,
            $declaredInventorySurfacesByManifest[$relative] ?? [],
            'declared extension interface surface',
            $relative . ' surface inventory',
            $errors,
        );
        foreach (['administrator', 'portal'] as $area) {
            $areaData = $manifest['contributions'][$area] ?? [];
            if (!is_array($areaData)) {
                continue;
            }
            foreach ($areaData['routes'] ?? [] as $route) {
                if (is_array($route) && is_string($route['name'] ?? null)) {
                    $routeKey = $relative . '|' . $route['name'];
                    $actualRoutes[$routeKey] = true;
                    validateExtensionMountedPath(
                        $area,
                        $extension,
                        $route['path'] ?? null,
                        $inventoryRoutes[$routeKey]['path'] ?? null,
                        'Extension graphical route ' . $routeKey,
                        $errors,
                    );
                }
            }
            foreach ($areaData['navigation'] ?? [] as $navigation) {
                if (is_array($navigation) && is_string($navigation['id'] ?? null)) {
                    $navigationKey = $relative . '|' . $navigation['id'];
                    $actualNavigation[$navigationKey] = true;
                    $surfaceId = $navigation['surface'] ?? null;
                    $inventoryEntry = $inventoryNavigation[$navigationKey] ?? null;
                    validateExtensionMountedPath(
                        $area,
                        $extension,
                        $navigation['path'] ?? null,
                        $inventoryEntry['path'] ?? null,
                        'Extension navigation ' . $navigationKey,
                        $errors,
                    );
                    if (is_string($surfaceId) && $surfaceId !== '') {
                        if (!isset($manifestSurfaces[$surfaceId])) {
                            $errors[] = sprintf(
                                'Extension navigation %s references undeclared interface surface %s.',
                                $navigationKey,
                                $surfaceId,
                            );
                        }
                        if (($inventoryEntry['surface_id'] ?? null) !== $surfaceId) {
                            $errors[] = sprintf(
                                'Extension navigation %s surface %s does not match inventory surface %s.',
                                $navigationKey,
                                $surfaceId,
                                printable($inventoryEntry['surface_id'] ?? null),
                            );
                        }
                    } elseif ($manifestSurfaces !== []) {
                        $errors[] = sprintf(
                            'Extension navigation %s omits its interface surface reference.',
                            $navigationKey,
                        );
                    }
                }
            }
        }
    }
    compareSets($actualRoutes, $declaredRoutes, 'extension graphical route', 'surface inventory', $errors);
    compareSets($actualNavigation, $declaredNavigation, 'extension navigation', 'navigation catalogue', $errors);
}

/**
 * Reconcile an extension's relative graphical declaration with its exact mounted inventory path.
 *
 * @param   string        $area           Administrator or portal contribution area.
 * @param   mixed         $extension      Manifest package identifier.
 * @param   mixed         $declaredPath   Owner-relative route or navigation path.
 * @param   mixed         $inventoryPath  Absolute path recorded by the programme inventory.
 * @param   string        $label          Human-readable declaration identifier.
 * @param   list<string>  $errors         Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateExtensionMountedPath(
    string $area,
    mixed $extension,
    mixed $declaredPath,
    mixed $inventoryPath,
    string $label,
    array &$errors,
): void {
    if (
        !in_array($area, ['administrator', 'portal'], true)
        || !is_string($extension)
        || $extension === ''
        || !is_string($declaredPath)
        || $declaredPath === ''
    ) {
        $errors[] = sprintf('%s cannot resolve its mounted path from the extension manifest.', $label);
        return;
    }

    $prefix = $area === 'administrator' ? '/administrator/extensions/' : '/portal/extensions/';
    $expected = $prefix . $extension . ($declaredPath === '/' ? '' : $declaredPath);
    if ($inventoryPath !== $expected) {
        $errors[] = sprintf(
            '%s inventory path %s does not match mounted path %s.',
            $label,
            printable($inventoryPath),
            $expected,
        );
    }
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
        if (
            !is_array($instance)
            || !is_string($instance['source'] ?? null)
            || !is_string($instance['handle'] ?? null)
        ) {
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
 * Normalize authoritative finding targets for governed waiver validation.
 *
 * @param   mixed         $records  Candidate findings-register records.
 * @param   list<string>  $errors   Accumulated validation failures.
 *
 * @return  array<string, array<string, mixed>>  Finding metadata keyed by stable ID.
 *
 * @since   2.0.0
 */
function normalizeFindingReferences(mixed $records, array &$errors): array
{
    $result = [];
    foreach (expectList($records, 'findings for waiver governance', $errors) as $finding) {
        if (!is_array($finding) || !is_string($finding['id'] ?? null) || $finding['id'] === '') {
            continue;
        }
        $phaseNumbers = [];
        foreach ($finding['phase_numbers'] ?? [] as $phaseNumber) {
            if (is_int($phaseNumber)) {
                $phaseNumbers[$phaseNumber] = true;
            }
        }
        $workItemIds = [];
        foreach ($finding['work_item_ids'] ?? [] as $workItemId) {
            if (is_string($workItemId)) {
                $workItemIds[$workItemId] = true;
            }
        }
        $result[$finding['id']] = [
            'severity' => $finding['severity'] ?? null,
            'phase_numbers' => $phaseNumbers,
            'work_item_ids' => $workItemIds,
        ];
    }
    return $result;
}

/**
 * Normalize unresolved P0/P1 findings for deterministic completion checks.
 *
 * Resolved and superseded findings remain in the authoritative register for history, but do not block a
 * completion decision.
 *
 * @param   mixed         $records  Candidate findings-register records.
 * @param   list<string>  $errors   Accumulated validation failures.
 *
 * @return  array<string, array<string, mixed>>  Blocking findings keyed by stable ID.
 *
 * @since   2.0.0
 */
function normalizeBlockingFindings(mixed $records, array &$errors): array
{
    $result = [];
    foreach (expectList($records, 'findings for completion', $errors) as $finding) {
        if (
            !is_array($finding)
            || !is_string($finding['id'] ?? null)
            || !in_array($finding['severity'] ?? null, ['P0', 'P1'], true)
            || in_array($finding['status'] ?? null, ['resolved', 'superseded'], true)
        ) {
            continue;
        }
        $phaseNumbers = [];
        foreach ($finding['phase_numbers'] ?? [] as $phaseNumber) {
            if (is_int($phaseNumber)) {
                $phaseNumbers[$phaseNumber] = true;
            }
        }
        $workItemIds = [];
        foreach ($finding['work_item_ids'] ?? [] as $workItemId) {
            if (is_string($workItemId)) {
                $workItemIds[$workItemId] = true;
            }
        }
        $result[$finding['id']] = [
            'phase_numbers' => $phaseNumbers,
            'work_item_ids' => $workItemIds,
        ];
    }
    return $result;
}

/**
 * Validate work items, gates, evidence, ownership, history and completion rules.
 *
 * @param   string                              $root         Repository root.
 * @param   array<string, mixed>                $ledger       Programme ledger.
 * @param   array<string, true>                 $surfaceIds   Known surfaces.
 * @param   array<string, true>                 $ownerIds     Known owner roles.
 * @param   array<string, true>                 $evidenceIds  Known evidence records.
 * @param   array<string, true>                 $gateIds      Known gates.
 * @param   array<string, array<string, mixed>> $workItems    Work items keyed by ID.
 * @param   list<string>                        $errors       Accumulated validation failures.
 * @param   array<string, array<string, mixed>> $findings     Normalized unresolved P0/P1 findings.
 * @param   array<string, array<string, mixed>> $registered   Authoritative finding targets.
 * @param   ?string                             $asOf         Injected UTC verification time for deterministic tests.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateLedger(
    string $root,
    array $ledger,
    array $surfaceIds,
    array $ownerIds,
    array $evidenceIds,
    array $gateIds,
    array $workItems,
    array &$errors,
    array $findings = [],
    array $registered = [],
    ?string $asOf = null,
): void {
    $asOf ??= gmdate('Y-m-d\TH:i:s\Z');
    if (!isUtcTimestamp($asOf)) {
        $errors[] = 'Programme verification as-of time must be a UTC ISO-8601 timestamp.';
        $asOf = gmdate('Y-m-d\TH:i:s\Z');
    }
    $statuses = array_fill_keys(expectList($ledger['status_vocabulary'] ?? null, 'status vocabulary', $errors), true);
    $currentFocus = [];
    foreach (expectList($ledger['current_focus'] ?? [], 'current focus', $errors) as $phaseNumber) {
        if (is_int($phaseNumber) && $phaseNumber >= 0 && $phaseNumber <= 6) {
            $currentFocus[$phaseNumber] = true;
        } else {
            $errors[] = sprintf('Current focus contains invalid phase %s.', printable($phaseNumber));
        }
    }
    $phaseStatuses = [];
    foreach ($ledger['phases'] ?? [] as $phase) {
        if (is_array($phase) && is_int($phase['number'] ?? null) && is_string($phase['status'] ?? null)) {
            $phaseStatuses[$phase['number']] = $phase['status'];
        }
    }
    $evidenceRules = $ledger['evidence_rules'] ?? null;
    if (!is_array($evidenceRules) || array_is_list($evidenceRules)) {
        $errors[] = 'Evidence rules must be an object.';
        $evidenceRules = [];
    }
    $evidenceTypes = stringLookup($evidenceRules['types'] ?? null, 'evidence type', $errors);
    $evidenceStatuses = stringLookup($evidenceRules['statuses'] ?? null, 'evidence status', $errors);
    $requiredEvidenceFields = stringLookup(
        $evidenceRules['required_fields'] ?? null,
        'required evidence field',
        $errors,
    );
    $mandatoryEvidenceFields = [
        'id', 'type', 'status', 'producer_role', 'source_revision', 'environment', 'method', 'result',
        'artifact_paths', 'supports',
    ];
    foreach ($mandatoryEvidenceFields as $field) {
        if (!isset($requiredEvidenceFields[$field])) {
            $errors[] = sprintf('Evidence rules must require field %s.', $field);
        }
    }
    $acceptedStatus = $evidenceRules['accepted_status'] ?? null;
    if (!is_string($acceptedStatus) || $acceptedStatus === '') {
        $errors[] = 'Evidence rules accepted_status must be a non-empty string.';
        $acceptedStatus = 'accepted';
    }
    if (!isset($evidenceStatuses[$acceptedStatus])) {
        $errors[] = 'Evidence rules accepted_status must belong to the evidence status vocabulary.';
    }

    $acceptedEvidence = [];
    $acceptedEvidenceByType = [];
    $acceptedEvidenceTypes = [];
    $evidenceSupports = [];
    $evidenceSupersedes = [];
    $allTargets = $gateIds + array_fill_keys(array_keys($workItems), true);
    $evidenceRecords = expectList($ledger['evidence_records'] ?? null, 'evidence records', $errors);
    foreach ($evidenceRecords as $evidence) {
        if (!is_array($evidence) || !is_string($evidence['id'] ?? null) || $evidence['id'] === '') {
            continue;
        }
        $evidenceId = $evidence['id'];
        foreach (array_keys($requiredEvidenceFields) as $field) {
            if (!array_key_exists($field, $evidence)) {
                $errors[] = sprintf('Evidence %s is missing required field %s.', $evidenceId, $field);
            }
        }
        $type = $evidence['type'] ?? null;
        if (!is_string($type) || !isset($evidenceTypes[$type])) {
            $errors[] = sprintf('Evidence %s has an unknown type.', $evidenceId);
        }
        $status = $evidence['status'] ?? null;
        if (!is_string($status) || !isset($evidenceStatuses[$status])) {
            $errors[] = sprintf(
                'Evidence %s has unknown status %s.',
                $evidenceId,
                printable($status),
            );
        }
        if (!isset($ownerIds[$evidence['producer_role'] ?? ''])) {
            $errors[] = sprintf('Evidence %s has an unknown producer role.', $evidenceId);
        }
        $sourceRevision = $evidence['source_revision'] ?? null;
        $sourceRevisionVerified = isCommitRevision($sourceRevision);
        if (!$sourceRevisionVerified) {
            $errors[] = sprintf('Evidence %s requires a 40-character source revision.', $evidenceId);
        }
        if ($status === $acceptedStatus && $sourceRevisionVerified && !gitCommitExists($root, $sourceRevision)) {
            $errors[] = sprintf(
                'Accepted evidence %s source revision %s is not a verifiable repository commit.',
                $evidenceId,
                $sourceRevision,
            );
            $sourceRevisionVerified = false;
        }
        foreach (['environment', 'method', 'result'] as $field) {
            if (!is_string($evidence[$field] ?? null) || trim($evidence[$field]) === '') {
                $errors[] = sprintf('Evidence %s requires a non-empty %s.', $evidenceId, $field);
            }
        }
        $artifactPaths = expectList(
            $evidence['artifact_paths'] ?? null,
            'evidence ' . $evidenceId . ' artifact_paths',
            $errors,
        );
        if ($artifactPaths === []) {
            $errors[] = sprintf('Evidence %s requires at least one artifact path.', $evidenceId);
        }
        foreach ($artifactPaths as $path) {
            requireFile($root, $path, 'evidence artifact', $errors);
        }
        $supports = expectList($evidence['supports'] ?? null, 'evidence ' . $evidenceId . ' supports', $errors);
        if ($supports === []) {
            $errors[] = sprintf('Evidence %s must support at least one work item or gate.', $evidenceId);
        }
        validateReferences($supports, $allTargets, 'work item or gate', 'evidence ' . $evidenceId, $errors);
        foreach ($supports as $target) {
            if (is_string($target) && isset($allTargets[$target])) {
                $evidenceSupports[$evidenceId][$target] = true;
            }
        }
        $supersedes = $evidence['supersedes'] ?? null;
        if ($supersedes !== null) {
            if (!is_string($supersedes) || !isset($evidenceIds[$supersedes]) || $supersedes === $evidenceId) {
                $errors[] = sprintf('Evidence %s has an invalid supersedes reference.', $evidenceId);
            } else {
                $evidenceSupersedes[$evidenceId] = $supersedes;
            }
        }
        if (
            $status === $acceptedStatus
            && $sourceRevisionVerified
            && is_string($type)
            && isset($evidenceTypes[$type])
        ) {
            $acceptedEvidence[$evidenceId] = true;
            $acceptedEvidenceByType[$type][$evidenceId] = true;
            $acceptedEvidenceTypes[$evidenceId] = $type;
        }
    }
    $supersededBy = [];
    foreach ($evidenceSupersedes as $evidenceId => $supersededId) {
        $visited = [$evidenceId => true];
        $cursor = $supersededId;
        while (isset($evidenceSupersedes[$cursor])) {
            if (isset($visited[$cursor])) {
                $errors[] = sprintf('Evidence %s has a cyclic supersedes chain.', $evidenceId);
                break;
            }
            $visited[$cursor] = true;
            $cursor = $evidenceSupersedes[$cursor];
        }
        if (!isset($acceptedEvidence[$evidenceId])) {
            continue;
        }
        if (isset($supersededBy[$supersededId])) {
            $errors[] = sprintf(
                'Accepted evidence %s is superseded by multiple records.',
                $supersededId,
            );
        }
        $supersededBy[$supersededId] = $evidenceId;
    }
    foreach ($supersededBy as $supersededId => $evidenceId) {
        unset($acceptedEvidence[$supersededId]);
        $supersededType = $acceptedEvidenceTypes[$supersededId] ?? null;
        if (is_string($supersededType)) {
            unset($acceptedEvidenceByType[$supersededType][$supersededId]);
        }
    }

    $governedWaivers = [];
    foreach ($workItems as $id => $item) {
        validateStatusRecord($item, 'work item ' . $id, $statuses, $evidenceIds, $errors);
        validateOwnerRecord($item, 'work item ' . $id, $ownerIds, $errors);
        validateReferences($item['surface_ids'] ?? null, $surfaceIds, 'surface', 'work item ' . $id, $errors);
        validateReferences($item['evidence_ids'] ?? null, $evidenceIds, 'evidence', 'work item ' . $id, $errors);
        validateEvidenceTypeRequirements(
            $item['evidence_required'] ?? null,
            $evidenceTypes,
            'work item ' . $id,
            $errors,
        );
        $prerequisites = expectList(
            $item['prerequisites'] ?? null,
            'work item ' . $id . ' prerequisites',
            $errors,
        );
        foreach ($prerequisites as $required) {
            if (!is_string($required) || (!isset($workItems[$required]) && !isset($gateIds[$required]))) {
                $errors[] = sprintf('Work item %s has unknown prerequisite %s.', $id, printable($required));
            }
        }
        if (($item['status'] ?? null) === 'waived') {
            $governedWaivers[$id] = validateGovernedWaiver(
                $item,
                $id,
                $statuses,
                $ownerIds,
                $acceptedEvidence,
                $evidenceSupports,
                $registered,
                $asOf,
                $currentFocus,
                $phaseStatuses,
                $errors,
            );
        }
        if (($item['status'] ?? null) === 'superseded') {
            validateSupersededRecord($item, $id, $workItems, 'work item', $statuses, $errors);
        }
    }
    foreach ($ledger['gates'] ?? [] as $gate) {
        if (!is_array($gate) || !is_string($gate['id'] ?? null)) {
            continue;
        }
        $id = $gate['id'];
        validateStatusRecord($gate, 'gate ' . $id, $statuses, $evidenceIds, $errors);
        validateOwnerRecord($gate, 'gate ' . $id, $ownerIds, $errors);
        validateReferences($gate['evidence_ids'] ?? null, $evidenceIds, 'evidence', 'gate ' . $id, $errors);
        validateEvidenceTypeRequirements(
            $gate['required_evidence_types'] ?? null,
            $evidenceTypes,
            'gate ' . $id,
            $errors,
        );
        foreach (expectList($gate['prerequisites'] ?? null, 'gate ' . $id . ' prerequisites', $errors) as $required) {
            if (!is_string($required) || !isset($gateIds[$required])) {
                $errors[] = sprintf('Gate %s has unknown prerequisite %s.', $id, printable($required));
            }
        }
        if (($gate['status'] ?? null) === 'waived') {
            $errors[] = sprintf('Gate %s cannot be waived.', $id);
        }
        if (($gate['status'] ?? null) === 'superseded') {
            validateSupersededRecord($gate, $id, $ledger['gates'] ?? [], 'gate', $statuses, $errors);
        }
    }

    $gatesById = [];
    foreach ($ledger['gates'] ?? [] as $gate) {
        if (is_array($gate) && is_string($gate['id'] ?? null)) {
            $gatesById[$gate['id']] = $gate;
        }
    }
    $gateScopes = [];
    foreach ($ledger['phases'] ?? [] as $phase) {
        if (!is_array($phase) || !is_int($phase['number'] ?? null)) {
            continue;
        }
        $phaseWorkItems = [];
        foreach ($phase['work_items'] ?? [] as $item) {
            if (is_array($item) && is_string($item['id'] ?? null)) {
                $phaseWorkItems[$item['id']] = true;
            }
        }
        foreach ($phase['exit_gates'] ?? [] as $gateId) {
            if (!is_string($gateId) || !isset($gateIds[$gateId])) {
                continue;
            }
            $gateScopes[$gateId]['phase_numbers'][$phase['number']] = true;
            $gateScopes[$gateId]['work_item_ids'] = ($gateScopes[$gateId]['work_item_ids'] ?? []) + $phaseWorkItems;
        }
    }
    foreach ($ledger['phases'] ?? [] as $phase) {
        if (!is_array($phase) || !is_int($phase['number'] ?? null)) {
            continue;
        }
        $phaseNumber = $phase['number'];
        $phaseStatus = $phase['status'] ?? null;
        if (!is_string($phaseStatus) || !isset($statuses[$phaseStatus])) {
            $errors[] = sprintf('Phase %d has an unknown status.', $phaseNumber);
        }
        validateReferences(
            $phase['entry_gates'] ?? null,
            $gateIds,
            'gate',
            'phase ' . $phaseNumber . ' entry',
            $errors,
        );
        validateReferences(
            $phase['exit_gates'] ?? null,
            $gateIds,
            'gate',
            'phase ' . $phaseNumber . ' exit',
            $errors,
        );
        if ($phaseStatus !== 'complete') {
            continue;
        }
        foreach ($phase['work_items'] ?? [] as $item) {
            $workItemId = is_array($item) ? ($item['id'] ?? null) : null;
            if (
                is_string($workItemId)
                && !prerequisiteSatisfiesCompletion(
                    $workItemId,
                    $workItems,
                    $gatesById,
                    $governedWaivers,
                    $statuses,
                    [],
                )
            ) {
                $errors[] = sprintf('Complete phase %d has incomplete work item %s.', $phaseNumber, $workItemId);
            }
        }
        foreach ($phase['exit_gates'] ?? [] as $gateId) {
            if (is_string($gateId) && ($gatesById[$gateId]['status'] ?? null) !== 'complete') {
                $errors[] = sprintf('Complete phase %d has incomplete exit gate %s.', $phaseNumber, $gateId);
            }
        }
    }
    foreach ($workItems as $id => $item) {
        if (($item['status'] ?? null) !== 'complete') {
            continue;
        }
        requireAcceptedEvidence($item['evidence_ids'] ?? [], $acceptedEvidence, 'work item ' . $id, $errors);
        requireAcceptedEvidenceTypes(
            $item['evidence_ids'] ?? [],
            $item['evidence_required'] ?? [],
            $acceptedEvidenceByType,
            $evidenceSupports,
            $id,
            'work item ' . $id,
            $errors,
        );
        requireCompletePrerequisites(
            $item['prerequisites'] ?? [],
            $workItems,
            $gatesById,
            $governedWaivers,
            $statuses,
            'work item ' . $id,
            $errors,
        );
        requireNoBlockingFindingsForWorkItem($id, $findings, $errors);
    }
    foreach ($gatesById as $id => $gate) {
        if (($gate['status'] ?? null) === 'complete') {
            requireAcceptedEvidence($gate['evidence_ids'] ?? [], $acceptedEvidence, 'gate ' . $id, $errors);
            requireAcceptedEvidenceTypes(
                $gate['evidence_ids'] ?? [],
                $gate['required_evidence_types'] ?? [],
                $acceptedEvidenceByType,
                $evidenceSupports,
                $id,
                'gate ' . $id,
                $errors,
            );
            requireCompletePrerequisites(
                $gate['prerequisites'] ?? [],
                [],
                $gatesById,
                [],
                $statuses,
                'gate ' . $id,
                $errors,
            );
            requireNoBlockingFindingsForGate(
                $id,
                $gateScopes[$id] ?? ['phase_numbers' => [], 'work_item_ids' => []],
                $findings,
                $errors,
            );
        }
    }
}

/**
 * Validate one work item or gate's required evidence-type list.
 *
 * @param   mixed                $requirements  Candidate evidence-type list.
 * @param   array<string, true>  $knownTypes    Evidence-type vocabulary.
 * @param   string               $label         Work item or gate label.
 * @param   list<string>         $errors        Accumulated validation failures.
 *
 * @return  array<string, true>  Valid, unique required types.
 *
 * @since   2.0.0
 */
function validateEvidenceTypeRequirements(
    mixed $requirements,
    array $knownTypes,
    string $label,
    array &$errors,
): array {
    $result = [];
    foreach (expectList($requirements, $label . ' evidence type requirements', $errors) as $type) {
        if (!is_string($type) || !isset($knownTypes[$type])) {
            $errors[] = sprintf('%s requires unknown evidence type %s.', ucfirst($label), printable($type));
            continue;
        }
        if (isset($result[$type])) {
            $errors[] = sprintf('%s repeats required evidence type %s.', ucfirst($label), $type);
        }
        $result[$type] = true;
    }
    return $result;
}

/**
 * Validate the governance record that permits a P2/P3 work item to be waived.
 *
 * @param   array<string, mixed>                 $item                Waived work item.
 * @param   string                               $id                  Work item identifier.
 * @param   array<string, true>                  $statuses            Ledger status vocabulary.
 * @param   array<string, true>                  $ownerIds            Known accountable roles.
 * @param   array<string, true>                  $acceptedEvidence    Accepted evidence IDs.
 * @param   array<string, array<string, true>>   $evidenceSupports    Targets declared by evidence ID.
 * @param   array<string, array<string, mixed>>  $registeredFindings  Authoritative finding targets.
 * @param   string                               $asOf                UTC verification time.
 * @param   array<int, true>                     $currentFocus        Phases currently in active scope.
 * @param   array<int, string>                   $phaseStatuses       Current phase statuses.
 * @param   list<string>                         $errors              Accumulated validation failures.
 *
 * @return  bool  True only when the waiver can satisfy a completed item's prerequisite.
 *
 * @since   2.0.0
 */
function validateGovernedWaiver(
    array $item,
    string $id,
    array $statuses,
    array $ownerIds,
    array $acceptedEvidence,
    array $evidenceSupports,
    array $registeredFindings,
    string $asOf,
    array $currentFocus,
    array $phaseStatuses,
    array &$errors,
): bool {
    $valid = true;
    if (!isset($statuses['waived']) || !in_array($item['severity'] ?? null, ['P2', 'P3'], true)) {
        $errors[] = sprintf('Waived work item %s requires a waivable P2/P3 severity.', $id);
        $valid = false;
    }
    $waiver = $item['waiver'] ?? null;
    if (!is_array($waiver) || array_is_list($waiver)) {
        $errors[] = sprintf('Waived work item %s requires a waiver record.', $id);
        return false;
    }
    foreach (['rationale', 'compensating_control'] as $field) {
        if (!is_string($waiver[$field] ?? null) || trim($waiver[$field]) === '') {
            $errors[] = sprintf('Waiver for work item %s requires a non-empty %s.', $id, $field);
            $valid = false;
        }
    }
    $findingId = $waiver['finding_id'] ?? null;
    $finding = is_string($findingId) ? ($registeredFindings[$findingId] ?? null) : null;
    if (!is_string($findingId) || !is_array($finding)) {
        $errors[] = sprintf('Waiver for work item %s references unknown finding %s.', $id, printable($findingId));
        $valid = false;
    } else {
        if (!in_array($finding['severity'] ?? null, ['P2', 'P3'], true)) {
            $errors[] = sprintf('Waiver for work item %s references non-waivable finding %s.', $id, $findingId);
            $valid = false;
        }
        if (($finding['work_item_ids'] ?? []) !== [] && !isset($finding['work_item_ids'][$id])) {
            $errors[] = sprintf('Waiver finding %s is not linked to work item %s.', $findingId, $id);
            $valid = false;
        }
        if (
            preg_match('/^P(\d+)-/', $id, $phaseMatch) === 1
            && ($finding['phase_numbers'] ?? []) !== []
            && !isset($finding['phase_numbers'][(int) $phaseMatch[1]])
        ) {
            $errors[] = sprintf('Waiver finding %s does not apply to Phase %d.', $findingId, (int) $phaseMatch[1]);
            $valid = false;
        }
    }
    if (!is_string($waiver['owner_role'] ?? null) || !isset($ownerIds[$waiver['owner_role']])) {
        $errors[] = sprintf('Waiver for work item %s has an unknown owner role.', $id);
        $valid = false;
    }
    $hasExpiryField = array_key_exists('expires_at', $waiver);
    $hasTargetPhaseField = array_key_exists('target_phase', $waiver);
    $hasExpiry = $hasExpiryField && isUtcTimestamp($waiver['expires_at']);
    $hasTargetPhase = $hasTargetPhaseField
        && is_int($waiver['target_phase'])
        && $waiver['target_phase'] >= 0
        && $waiver['target_phase'] <= 6;
    if (!$hasExpiryField && !$hasTargetPhaseField) {
        $errors[] = sprintf('Waiver for work item %s requires a UTC expiry or Phase 0-6 target.', $id);
        $valid = false;
    }
    if ($hasExpiryField && !$hasExpiry) {
        $errors[] = sprintf('Waiver for work item %s has an invalid UTC expiry.', $id);
        $valid = false;
    }
    if ($hasExpiry && $waiver['expires_at'] <= $asOf) {
        $errors[] = sprintf('Waiver for work item %s expired at %s.', $id, $waiver['expires_at']);
        $valid = false;
    }
    if ($hasTargetPhaseField && !$hasTargetPhase) {
        $errors[] = sprintf('Waiver for work item %s has an invalid target phase.', $id);
        $valid = false;
    }
    if ($hasTargetPhase) {
        $targetPhase = $waiver['target_phase'];
        $highestFocus = $currentFocus === [] ? -1 : max(array_keys($currentFocus));
        if ($targetPhase <= $highestFocus || ($phaseStatuses[$targetPhase] ?? null) !== 'planned') {
            $errors[] = sprintf('Waiver for work item %s expired on entry to Phase %d.', $id, $targetPhase);
            $valid = false;
        }
    }
    $waiverEvidence = expectList($waiver['evidence_ids'] ?? null, 'waiver for work item ' . $id . ' evidence', $errors);
    if ($waiverEvidence === []) {
        $errors[] = sprintf('Waiver for work item %s requires accepted compensating evidence.', $id);
        $valid = false;
    }
    foreach ($waiverEvidence as $evidenceId) {
        if (!is_string($evidenceId) || !isset($acceptedEvidence[$evidenceId])) {
            $errors[] = sprintf(
                'Waiver for work item %s references unaccepted evidence %s.',
                $id,
                printable($evidenceId),
            );
            $valid = false;
            continue;
        }
        if (!isset($evidenceSupports[$evidenceId][$id])) {
            $errors[] = sprintf('Waiver evidence %s does not support work item %s.', $evidenceId, $id);
            $valid = false;
        }
    }
    return $valid;
}

/**
 * Validate an explicit, acyclic replacement chain for a superseded work item or gate.
 *
 * @param   array<string, mixed>  $record    Superseded record.
 * @param   string                $id        Superseded identifier.
 * @param   array<mixed>          $records   Records of the same kind.
 * @param   string                $kind      `work item` or `gate`.
 * @param   array<string, true>   $statuses  Ledger status vocabulary.
 * @param   list<string>          $errors    Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateSupersededRecord(
    array $record,
    string $id,
    array $records,
    string $kind,
    array $statuses,
    array &$errors,
): void {
    if (!isset($statuses['superseded'])) {
        $errors[] = sprintf('Superseded %s %s is not supported by the status vocabulary.', $kind, $id);
        return;
    }
    $byId = [];
    foreach ($records as $candidateId => $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $candidateId = is_string($candidateId) ? $candidateId : ($candidate['id'] ?? null);
        if (is_string($candidateId) && $candidateId !== '') {
            $byId[$candidateId] = $candidate;
        }
    }
    $replacement = $record['superseded_by'] ?? null;
    if (!is_string($replacement) || !isset($byId[$replacement]) || $replacement === $id) {
        $errors[] = sprintf('Superseded %s %s requires a different known superseded_by record.', $kind, $id);
        return;
    }
    $visited = [$id => true];
    while (isset($byId[$replacement]) && ($byId[$replacement]['status'] ?? null) === 'superseded') {
        if (isset($visited[$replacement])) {
            $errors[] = sprintf('Superseded %s %s has a cyclic replacement chain.', $kind, $id);
            return;
        }
        $visited[$replacement] = true;
        $replacement = $byId[$replacement]['superseded_by'] ?? null;
        if (!is_string($replacement) || !isset($byId[$replacement])) {
            $errors[] = sprintf('Superseded %s %s has an unresolved replacement chain.', $kind, $id);
            return;
        }
    }
}

/**
 * Require accepted evidence of every declared type, with reciprocal target support.
 *
 * @param   mixed                                      $references              Evidence IDs on the record.
 * @param   mixed                                      $requirements            Required evidence types.
 * @param   array<string, array<string, true>>         $acceptedEvidenceByType  Accepted evidence IDs by type.
 * @param   array<string, array<string, true>>         $evidenceSupports        Targets declared by evidence ID.
 * @param   string                                     $targetId                Work item or gate identifier.
 * @param   string                                     $label                   Work item or gate label.
 * @param   list<string>                               $errors                  Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function requireAcceptedEvidenceTypes(
    mixed $references,
    mixed $requirements,
    array $acceptedEvidenceByType,
    array $evidenceSupports,
    string $targetId,
    string $label,
    array &$errors,
): void {
    $references = expectList($references, $label . ' evidence', $errors);
    foreach ($references as $evidenceId) {
        if (
            is_string($evidenceId)
            && isset($evidenceSupports[$evidenceId])
            && !isset($evidenceSupports[$evidenceId][$targetId])
        ) {
            $errors[] = sprintf(
                'Completed %s evidence %s does not declare support for %s.',
                $label,
                $evidenceId,
                $targetId,
            );
        }
    }
    foreach (expectList($requirements, $label . ' evidence requirements', $errors) as $type) {
        if (!is_string($type)) {
            continue;
        }
        $covered = false;
        foreach ($references as $evidenceId) {
            if (
                is_string($evidenceId)
                && isset($acceptedEvidenceByType[$type][$evidenceId])
                && isset($evidenceSupports[$evidenceId][$targetId])
            ) {
                $covered = true;
                break;
            }
        }
        if (!$covered) {
            $errors[] = sprintf('Completed %s lacks accepted %s evidence.', $label, $type);
        }
    }
}

/**
 * Require all prerequisites of a completed record to be complete or explicitly governed replacements.
 *
 * @param   mixed                                  $references        Prerequisite IDs.
 * @param   array<string, array<string, mixed>>     $workItems        Work-item lookup.
 * @param   array<string, array<string, mixed>>     $gates            Gate lookup.
 * @param   array<string, bool>                     $governedWaivers  Valid work-item waiver lookup.
 * @param   array<string, true>                     $statuses          Ledger status vocabulary.
 * @param   string                                  $label             Completed record label.
 * @param   list<string>                            $errors            Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function requireCompletePrerequisites(
    mixed $references,
    array $workItems,
    array $gates,
    array $governedWaivers,
    array $statuses,
    string $label,
    array &$errors,
): void {
    foreach (expectList($references, $label . ' prerequisites', $errors) as $prerequisiteId) {
        if (!is_string($prerequisiteId)) {
            continue;
        }
        if (!prerequisiteSatisfiesCompletion(
            $prerequisiteId,
            $workItems,
            $gates,
            $governedWaivers,
            $statuses,
            [],
        )) {
            $errors[] = sprintf('Completed %s has incomplete prerequisite %s.', $label, $prerequisiteId);
        }
    }
}

/**
 * Resolve one prerequisite through governed waiver or supersession records.
 *
 * @param   string                                 $id                 Prerequisite identifier.
 * @param   array<string, array<string, mixed>>    $workItems          Work-item lookup.
 * @param   array<string, array<string, mixed>>    $gates              Gate lookup.
 * @param   array<string, bool>                    $governedWaivers    Valid work-item waiver lookup.
 * @param   array<string, true>                    $statuses            Ledger status vocabulary.
 * @param   array<string, true>                    $visited             Supersession-cycle guard.
 *
 * @return  bool  True when the prerequisite is complete or an explicitly governed equivalent.
 *
 * @since   2.0.0
 */
function prerequisiteSatisfiesCompletion(
    string $id,
    array $workItems,
    array $gates,
    array $governedWaivers,
    array $statuses,
    array $visited,
): bool {
    if (isset($visited[$id])) {
        return false;
    }
    $visited[$id] = true;
    $isWorkItem = isset($workItems[$id]);
    $record = $workItems[$id] ?? $gates[$id] ?? null;
    if (!is_array($record)) {
        return false;
    }
    $status = $record['status'] ?? null;
    if ($status === 'complete') {
        return true;
    }
    if ($status === 'waived') {
        return $isWorkItem && isset($statuses['waived']) && ($governedWaivers[$id] ?? false);
    }
    if ($status !== 'superseded' || !isset($statuses['superseded'])) {
        return false;
    }
    $replacement = $record['superseded_by'] ?? null;
    return is_string($replacement) && prerequisiteSatisfiesCompletion(
        $replacement,
        $workItems,
        $gates,
        $governedWaivers,
        $statuses,
        $visited,
    );
}

/**
 * Reject completion while a linked P0/P1 finding remains unresolved.
 *
 * @param   string                               $workItemId  Completed work-item identifier.
 * @param   array<string, array<string, mixed>>  $findings    Normalized blocking findings.
 * @param   list<string>                         $errors      Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function requireNoBlockingFindingsForWorkItem(string $workItemId, array $findings, array &$errors): void
{
    foreach ($findings as $findingId => $finding) {
        if (isset($finding['work_item_ids'][$workItemId])) {
            $errors[] = sprintf(
                'Completed work item %s is blocked by unresolved finding %s.',
                $workItemId,
                $findingId,
            );
        }
    }
}

/**
 * Reject gate completion while a P0/P1 finding applies to its exit phase or work items.
 *
 * @param   string                               $gateId    Completed gate identifier.
 * @param   array<string, mixed>                 $scope     Exit phases and their work-item IDs.
 * @param   array<string, array<string, mixed>>  $findings  Normalized blocking findings.
 * @param   list<string>                         $errors    Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function requireNoBlockingFindingsForGate(
    string $gateId,
    array $scope,
    array $findings,
    array &$errors,
): void {
    foreach ($findings as $findingId => $finding) {
        $phaseMatch = array_intersect_key(
            $finding['phase_numbers'] ?? [],
            $scope['phase_numbers'] ?? [],
        ) !== [];
        $workItemMatch = array_intersect_key(
            $finding['work_item_ids'] ?? [],
            $scope['work_item_ids'] ?? [],
        ) !== [];
        if ($phaseMatch || $workItemMatch) {
            $errors[] = sprintf('Completed gate %s is blocked by unresolved finding %s.', $gateId, $findingId);
        }
    }
}

/**
 * Keep environment limitations auditable while allowing verified CI evidence to close them.
 *
 * An environment limitation starts as an open, surface-neutral blocker. It may only resolve
 * after retaining the original blocked evidence and adding passed evidence from a supported
 * qualification environment. This preserves the diagnostic history without making local tool
 * availability a permanent programme dead end.
 *
 * @param   array<string, mixed>  $finding            Finding record.
 * @param   string                $id                 Finding identifier.
 * @param   bool                  $hasBlockedEvidence Whether the limitation is evidenced.
 * @param   bool                  $hasPassedEvidence  Whether a supported environment verified it.
 * @param   list<string>          $errors             Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateEnvironmentFindingLifecycle(
    array $finding,
    string $id,
    bool $hasBlockedEvidence,
    bool $hasPassedEvidence,
    array &$errors,
): void {
    $isOpenLimitation = ($finding['status'] ?? null) === 'open'
        && ($finding['disposition'] ?? null) === 'environment_blocked'
        && $hasBlockedEvidence;
    $isVerifiedResolution = ($finding['status'] ?? null) === 'resolved'
        && ($finding['disposition'] ?? null) === 'verified_fixed'
        && $hasBlockedEvidence
        && $hasPassedEvidence;

    if (($finding['surface_ids'] ?? null) !== [] || (!$isOpenLimitation && !$isVerifiedResolution)) {
        $errors[] = sprintf(
            'Environment finding %s must be surface-neutral and either remain blocked or retain blocked evidence '
                . 'with passed qualification evidence when resolved.',
            $id,
        );
    }
}

/**
 * Validate the deduplicated findings register and return its embedded evidence identifiers.
 *
 * @param   string                                  $root          Repository root.
 * @param   array<string, mixed>                    $register      Findings register.
 * @param   array<string, mixed>                    $inventory     Surface inventory.
 * @param   array<string, true>                     $surfaceIds    Known surfaces.
 * @param   array<string, true>                     $ownerIds      Known accountable roles.
 * @param   array<int, true>                        $phaseNumbers  Known phases.
 * @param   array<string, array<string, mixed>>     $workItems     Known work items.
 * @param   list<string>                            $errors        Accumulated validation failures.
 *
 * @return  array<string, true>  Unique embedded finding-evidence identifiers.
 *
 * @since   2.0.0
 */
function validateFindingsRegister(
    string $root,
    array $register,
    array $inventory,
    array $surfaceIds,
    array $ownerIds,
    array $phaseNumbers,
    array $workItems,
    array &$errors,
): array {
    if (($register['schema_version'] ?? null) !== 1) {
        $errors[] = 'The findings register schema_version must be 1.';
    }
    $categories = stringLookup($register['category_vocabulary'] ?? null, 'finding category', $errors);
    $statuses = stringLookup($register['status_vocabulary'] ?? null, 'finding status', $errors);
    $dispositions = stringLookup($register['disposition_vocabulary'] ?? null, 'finding disposition', $errors);
    $evidenceKinds = stringLookup($register['evidence_kind_vocabulary'] ?? null, 'finding evidence kind', $errors);
    $evidenceStatuses = stringLookup(
        $register['evidence_status_vocabulary'] ?? null,
        'finding evidence status',
        $errors,
    );
    $records = expectList($register['findings'] ?? null, 'registered findings', $errors);
    $recordsById = [];
    $fingerprints = [];
    $evidenceIds = [];
    $evidenceIdsByFinding = [];
    $passedEvidenceByFinding = [];
    foreach ($records as $finding) {
        if (!is_array($finding) || !is_string($finding['id'] ?? null) || $finding['id'] === '') {
            $errors[] = 'Every registered finding requires a non-empty ID.';
            continue;
        }
        $id = $finding['id'];
        $recordsById[$id] = $finding;
        foreach (
            [
                'fingerprint', 'category', 'summary', 'severity', 'status', 'disposition', 'owner_role',
                'detected_at', 'reproduction', 'suggested_correction', 'confidence',
            ] as $field
        ) {
            if (!is_string($finding[$field] ?? null) || $finding[$field] === '') {
                $errors[] = sprintf('Finding %s requires a non-empty %s.', $id, $field);
            }
        }
        $fingerprint = $finding['fingerprint'] ?? null;
        if (is_string($fingerprint)) {
            if (isset($fingerprints[$fingerprint])) {
                $errors[] = sprintf('Finding fingerprint %s is duplicated.', $fingerprint);
            }
            $fingerprints[$fingerprint] = true;
        }
        if (!isset($categories[$finding['category'] ?? ''])) {
            $errors[] = sprintf('Finding %s has an unknown category.', $id);
        }
        if (!isset($statuses[$finding['status'] ?? ''])) {
            $errors[] = sprintf('Finding %s has an unknown status.', $id);
        }
        if (!isset($dispositions[$finding['disposition'] ?? ''])) {
            $errors[] = sprintf('Finding %s has an unknown disposition.', $id);
        }
        if (!in_array($finding['severity'] ?? null, ['P0', 'P1', 'P2', 'P3'], true)) {
            $errors[] = sprintf('Finding %s has an unknown severity.', $id);
        }
        if (!isset($ownerIds[$finding['owner_role'] ?? ''])) {
            $errors[] = sprintf('Finding %s has an unknown owner role.', $id);
        }
        if (!is_string($finding['detected_at'] ?? null) || !isUtcTimestamp($finding['detected_at'])) {
            $errors[] = sprintf('Finding %s detected_at must be a UTC ISO-8601 timestamp.', $id);
        }
        validatePhaseReferences($finding['phase_numbers'] ?? null, $phaseNumbers, 'finding ' . $id, $errors);
        validateReferences($finding['surface_ids'] ?? null, $surfaceIds, 'surface', 'finding ' . $id, $errors);
        validateReferences($finding['work_item_ids'] ?? null, $workItems, 'work item', 'finding ' . $id, $errors);
        $hasBlockedEvidence = false;
        $hasObservedSource = false;
        $hasPassedEvidence = false;
        foreach (expectList($finding['evidence'] ?? null, 'finding ' . $id . ' evidence', $errors) as $evidence) {
            if (!is_array($evidence) || !is_string($evidence['id'] ?? null) || $evidence['id'] === '') {
                $errors[] = sprintf('Finding %s has evidence without an ID.', $id);
                continue;
            }
            $evidenceId = $evidence['id'];
            if (isset($evidenceIds[$evidenceId])) {
                $errors[] = sprintf('Finding evidence ID %s is duplicated.', $evidenceId);
            }
            $evidenceIds[$evidenceId] = true;
            $evidenceIdsByFinding[$id][$evidenceId] = true;
            foreach (['kind', 'status', 'source_revision', 'method', 'result'] as $field) {
                if (!is_string($evidence[$field] ?? null) || $evidence[$field] === '') {
                    $errors[] = sprintf('Finding evidence %s requires a non-empty %s.', $evidenceId, $field);
                }
            }
            if (!isset($evidenceKinds[$evidence['kind'] ?? ''])) {
                $errors[] = sprintf('Finding evidence %s has an unknown kind.', $evidenceId);
            }
            if (!isset($evidenceStatuses[$evidence['status'] ?? ''])) {
                $errors[] = sprintf('Finding evidence %s has an unknown status.', $evidenceId);
            }
            if (!isCommitRevision($evidence['source_revision'] ?? null)) {
                $errors[] = sprintf('Finding evidence %s requires a 40-character source revision.', $evidenceId);
            }
            foreach (expectList($evidence['artifact_paths'] ?? null, 'evidence artifact paths', $errors) as $path) {
                requireFile($root, $path, 'finding evidence artifact', $errors);
            }
            if (($evidence['status'] ?? null) === 'blocked') {
                $hasBlockedEvidence = true;
            }
            if (($evidence['kind'] ?? null) === 'source' && ($evidence['status'] ?? null) === 'observed') {
                $hasObservedSource = true;
            }
            if (($evidence['status'] ?? null) === 'passed') {
                $hasPassedEvidence = true;
                $passedEvidenceByFinding[$id][$evidenceId] = true;
            }
        }
        if (($finding['category'] ?? null) === 'environment_limitation') {
            validateEnvironmentFindingLifecycle(
                $finding,
                $id,
                $hasBlockedEvidence,
                $hasPassedEvidence,
                $errors,
            );
        }
        if (($finding['disposition'] ?? null) === 'fixed_pending_verification') {
            if (($finding['status'] ?? null) !== 'in_review' || !$hasObservedSource || !$hasBlockedEvidence) {
                $errors[] = sprintf(
                    'Finding %s fixed_pending_verification requires in_review, observed source, and blocked evidence.',
                    $id,
                );
            }
        }
    }
    foreach ($recordsById as $id => $finding) {
        validateReferences(
            $finding['related_finding_ids'] ?? null,
            $recordsById,
            'finding',
            'finding ' . $id,
            $errors,
        );
        $duplicateOf = $finding['duplicate_of'] ?? null;
        if (($finding['disposition'] ?? null) === 'duplicate') {
            if (!is_string($duplicateOf) || !isset($recordsById[$duplicateOf]) || $duplicateOf === $id) {
                $errors[] = sprintf('Duplicate finding %s must reference another registered finding.', $id);
            }
        } elseif ($duplicateOf !== null) {
            $errors[] = sprintf('Non-duplicate finding %s must have a null duplicate_of.', $id);
        }
        validateFindingStatusHistory(
            $finding,
            $id,
            $statuses,
            $evidenceIdsByFinding[$id] ?? [],
            $passedEvidenceByFinding[$id] ?? [],
            $errors,
        );
        foreach ($finding['evidence'] ?? [] as $evidence) {
            if (is_array($evidence)) {
                validateReferences(
                    $evidence['blocker_finding_ids'] ?? null,
                    $recordsById,
                    'finding',
                    'evidence ' . ($evidence['id'] ?? 'unknown'),
                    $errors,
                );
            }
        }
    }
    $inventoryFindings = [];
    foreach ($inventory['findings'] ?? [] as $finding) {
        if (is_array($finding) && is_string($finding['id'] ?? null)) {
            $inventoryFindings[$finding['id']] = $finding;
        }
    }
    foreach ($inventoryFindings as $id => $finding) {
        $registered = $recordsById[$id] ?? null;
        if (!is_array($registered)) {
            $errors[] = sprintf('Inventory finding %s is absent from the findings register.', $id);
            continue;
        }
        foreach (['severity', 'owner_role'] as $field) {
            if (($registered[$field] ?? null) !== ($finding[$field] ?? null)) {
                $errors[] = sprintf('Registered finding %s %s does not match the surface inventory.', $id, $field);
            }
        }
        if (canonical($registered['surface_ids'] ?? []) !== canonical($finding['surface_ids'] ?? [])) {
            $errors[] = sprintf('Registered finding %s surfaces do not match the surface inventory.', $id);
        }
        if (!in_array($finding['target_phase'] ?? null, $registered['phase_numbers'] ?? [], true)) {
            $errors[] = sprintf('Registered finding %s does not retain its inventory target phase.', $id);
        }
    }
    foreach ($recordsById as $id => $finding) {
        if (($finding['category'] ?? null) !== 'environment_limitation' && !isset($inventoryFindings[$id])) {
            $errors[] = sprintf('Product finding %s is absent from the surface inventory.', $id);
        }
    }
    return $evidenceIds;
}

/**
 * Validate an append-only finding status history and terminal resolution proof.
 *
 * @param   array<string, mixed>  $finding         Finding record.
 * @param   string                $id              Stable finding identifier.
 * @param   array<string, true>   $statuses        Finding status vocabulary.
 * @param   array<string, true>   $evidenceIds     Evidence owned by this finding.
 * @param   array<string, true>   $passedEvidence  Passed evidence owned by this finding.
 * @param   list<string>          $errors          Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateFindingStatusHistory(
    array $finding,
    string $id,
    array $statuses,
    array $evidenceIds,
    array $passedEvidence,
    array &$errors,
): void {
    $history = expectList($finding['status_history'] ?? null, 'finding ' . $id . ' status_history', $errors);
    $previousTo = null;
    foreach ($history as $index => $entry) {
        if (!is_array($entry) || array_is_list($entry)) {
            $errors[] = sprintf('Finding %s history entry %d must be an object.', $id, $index + 1);
            continue;
        }
        foreach (['at', 'from', 'to', 'reason', 'evidence_ids'] as $field) {
            if (!array_key_exists($field, $entry)) {
                $errors[] = sprintf('Finding %s history entry %d is missing %s.', $id, $index + 1, $field);
            }
        }
        if (!isUtcTimestamp($entry['at'] ?? null)) {
            $errors[] = sprintf('Finding %s history entry %d requires a UTC timestamp.', $id, $index + 1);
        }
        $from = $entry['from'] ?? null;
        $to = $entry['to'] ?? null;
        if ($from !== null && (!is_string($from) || !isset($statuses[$from]))) {
            $errors[] = sprintf('Finding %s history entry %d has an unknown from status.', $id, $index + 1);
        }
        if (!is_string($to) || !isset($statuses[$to])) {
            $errors[] = sprintf('Finding %s history entry %d has an unknown to status.', $id, $index + 1);
        }
        if ($index === 0 && ($from !== null || $to !== 'open')) {
            $errors[] = sprintf('Finding %s history must start with null to open.', $id);
        }
        if ($index > 0 && $from !== $previousTo) {
            $errors[] = sprintf('Finding %s history entry %d does not continue the prior status.', $id, $index + 1);
        }
        if ($from !== null && $from === $to) {
            $errors[] = sprintf('Finding %s history entry %d is a no-op transition.', $id, $index + 1);
        }
        if (!is_string($entry['reason'] ?? null) || trim($entry['reason']) === '') {
            $errors[] = sprintf('Finding %s history entry %d requires a reason.', $id, $index + 1);
        }
        validateReferences(
            $entry['evidence_ids'] ?? null,
            $evidenceIds,
            'finding evidence',
            'finding ' . $id . ' history entry ' . ($index + 1),
            $errors,
        );
        $previousTo = is_string($to) ? $to : null;
    }
    $last = $history === [] ? null : $history[array_key_last($history)];
    if (!is_array($last) || ($last['to'] ?? null) !== ($finding['status'] ?? null)) {
        $errors[] = sprintf('Finding %s status does not match its latest history entry.', $id);
    }
    if (($finding['status'] ?? null) !== 'resolved') {
        return;
    }
    if (($finding['disposition'] ?? null) !== 'verified_fixed') {
        $errors[] = sprintf('Resolved finding %s requires verified_fixed disposition.', $id);
    }
    $hasTerminalPass = false;
    foreach (is_array($last) ? ($last['evidence_ids'] ?? []) : [] as $evidenceId) {
        if (is_string($evidenceId) && isset($passedEvidence[$evidenceId])) {
            $hasTerminalPass = true;
            break;
        }
    }
    if (!$hasTerminalPass) {
        $errors[] = sprintf('Resolved finding %s requires passed evidence on its terminal transition.', $id);
    }
}

/**
 * Validate one report/template against programme references and return its canonical check IDs.
 *
 * @param   string                                  $root                Repository root.
 * @param   array<string, mixed>                    $report              Verification report or template.
 * @param   string                                  $label               Diagnostic label.
 * @param   bool                                    $template            Whether placeholders are permitted.
 * @param   array<string, true>                     $surfaceIds          Known surfaces.
 * @param   array<string, true>                     $journeyIds          Known journeys.
 * @param   array<string, true>                     $ownerIds            Known accountable roles.
 * @param   array<int, true>                        $phaseNumbers        Known phases.
 * @param   array<string, array<string, mixed>>     $workItems           Known work items.
 * @param   array<string, true>                     $findingIds          Registered findings.
 * @param   array<string, true>                     $findingEvidenceIds  Registered finding evidence.
 * @param   list<string>                            $errors              Accumulated validation failures.
 *
 * @return  array<string, true>  Check identifiers declared by this report.
 *
 * @since   2.0.0
 */
function validateVerificationReport(
    string $root,
    array $report,
    string $label,
    bool $template,
    array $surfaceIds,
    array $journeyIds,
    array $ownerIds,
    array $phaseNumbers,
    array $workItems,
    array $findingIds,
    array $findingEvidenceIds,
    array &$errors,
): array {
    if (($report['schema_version'] ?? null) !== 1 || ($report['template_version'] ?? null) !== 1) {
        $errors[] = sprintf('%s schema_version and template_version must both be 1.', ucfirst($label));
    }
    foreach (
        [
            'report_id', 'report_kind', 'state', 'overall_status', 'branch', 'base_revision', 'source_revision',
            'prepared_at',
        ]
        as $field
    ) {
        if (!is_string($report[$field] ?? null) || $report[$field] === '') {
            $errors[] = sprintf('%s requires a non-empty %s.', ucfirst($label), $field);
        }
    }
    if ($template && ($report['report_kind'] ?? null) !== 'template') {
        $errors[] = 'The verification report template must use report_kind template.';
    }
    if (!$template && !in_array($report['report_kind'] ?? null, ['pull_request', 'phase_checkpoint'], true)) {
        $errors[] = sprintf('%s has an unsupported report_kind.', ucfirst($label));
    }
    if (!in_array($report['state'] ?? null, ['draft', 'in_review', 'accepted', 'superseded'], true)) {
        $errors[] = sprintf('%s has an unsupported state.', ucfirst($label));
    }
    if (!in_array($report['overall_status'] ?? null, ['not_run', 'passed', 'blocked', 'failed'], true)) {
        $errors[] = sprintf('%s has an unsupported overall_status.', ucfirst($label));
    }
    if (!$template) {
        if (
            !isCommitRevision($report['base_revision'] ?? null)
            || !isCommitRevision($report['source_revision'] ?? null)
        ) {
            $errors[] = sprintf('%s requires 40-character base and source revisions.', ucfirst($label));
        }
        if (!isUtcTimestamp($report['prepared_at'] ?? null)) {
            $errors[] = sprintf('%s prepared_at must be a UTC ISO-8601 timestamp.', ucfirst($label));
        }
        if (containsPlaceholder($report)) {
            $errors[] = sprintf('%s contains an unresolved template placeholder.', ucfirst($label));
        }
    }
    if (!isset($ownerIds[$report['prepared_by_role'] ?? ''])) {
        $errors[] = sprintf('%s has an unknown prepared_by_role.', ucfirst($label));
    }
    $scope = $report['scope'] ?? null;
    if (!is_array($scope)) {
        $errors[] = sprintf('%s requires a scope object.', ucfirst($label));
        $scope = [];
    }
    validatePhaseReferences($scope['phase_numbers'] ?? null, $phaseNumbers, $label . ' scope', $errors);
    validateReferences($scope['work_item_ids'] ?? null, $workItems, 'work item', $label . ' scope', $errors);
    $scopePhases = [];
    foreach ($scope['phase_numbers'] ?? [] as $phaseNumber) {
        if (is_int($phaseNumber) && isset($phaseNumbers[$phaseNumber])) {
            $scopePhases[$phaseNumber] = true;
        }
    }
    $scopeWorkItems = [];
    foreach ($scope['work_item_ids'] ?? [] as $workItemId) {
        if (is_string($workItemId) && isset($workItems[$workItemId])) {
            if (isset($scopeWorkItems[$workItemId])) {
                $errors[] = sprintf('%s scope repeats work item %s.', ucfirst($label), $workItemId);
            }
            $scopeWorkItems[$workItemId] = true;
        }
    }
    if (!$template && ($report['report_kind'] ?? null) === 'phase_checkpoint') {
        $expectedWorkItems = [];
        foreach (array_keys($workItems) as $workItemId) {
            if (preg_match('/^P(\d+)-/', $workItemId, $phaseMatch) === 1 && isset($scopePhases[(int) $phaseMatch[1]])) {
                $expectedWorkItems[$workItemId] = true;
            }
        }
        compareSets(
            $expectedWorkItems,
            $scopeWorkItems,
            'phase checkpoint work item',
            'verification report scope',
            $errors,
        );
    }
    validateReferences($scope['surface_ids'] ?? null, $surfaceIds, 'surface', $label . ' scope', $errors);
    validateReferences($scope['journey_ids'] ?? null, $journeyIds, 'journey', $label . ' scope', $errors);
    foreach (expectList($scope['changed_paths'] ?? null, $label . ' changed_paths', $errors) as $path) {
        if (!$template) {
            requirePath($root, $path, $label . ' changed path', $errors);
        }
    }
    foreach (expectList($scope['included_revisions'] ?? null, $label . ' included revisions', $errors) as $revision) {
        if (!$template && !isCommitRevision($revision)) {
            $errors[] = sprintf('%s has an invalid included revision %s.', ucfirst($label), printable($revision));
        }
    }
    if (!is_bool($scope['working_tree_included'] ?? null)) {
        $errors[] = sprintf('%s scope requires a working_tree_included boolean.', ucfirst($label));
    }
    $kis = $report['kis'] ?? null;
    if (!is_array($kis) || !is_string($kis['version'] ?? null) || $kis['version'] === '') {
        $errors[] = sprintf('%s requires KIS version metadata.', ucfirst($label));
        $kis = [];
    }
    foreach (expectList($kis['normative_document_paths'] ?? null, $label . ' KIS documents', $errors) as $path) {
        if (!$template) {
            requireFile($root, $path, $label . ' KIS document', $errors);
        }
    }
    foreach (['decision_ids', 'deviation_ids'] as $field) {
        expectList($kis[$field] ?? null, $label . ' ' . $field, $errors);
    }
    $behavior = $report['behavior_changes'] ?? null;
    if (!is_array($behavior)) {
        $errors[] = sprintf('%s requires behavior_changes.', ucfirst($label));
        $behavior = [];
    }
    foreach (['routes', 'capabilities', 'fields', 'actions', 'payloads', 'states'] as $field) {
        expectList($behavior[$field] ?? null, $label . ' behavior ' . $field, $errors);
    }
    if (!is_string($behavior['notes'] ?? null) || $behavior['notes'] === '') {
        $errors[] = sprintf('%s requires behavior-change notes.', ucfirst($label));
    }
    $parity = $report['parity'] ?? null;
    if (!is_array($parity) || !in_array(
        $parity['status'] ?? null,
        ['not_run', 'passed', 'blocked', 'failed', 'not_applicable'],
        true,
    )) {
        $errors[] = sprintf('%s has invalid parity status.', ucfirst($label));
        $parity = [];
    }
    foreach (expectList($parity['manifest_paths'] ?? null, $label . ' parity manifests', $errors) as $path) {
        if (!$template) {
            requireFile($root, $path, $label . ' parity manifest', $errors);
        }
    }
    $checks = uniqueIds($report['check_matrix'] ?? null, $label . ' check', $errors);
    $hasBlockedCheck = false;
    $hasIncompleteRequiredCheck = false;
    $checkedWorkItems = [];
    foreach (expectList($report['check_matrix'] ?? null, $label . ' check matrix', $errors) as $check) {
        if (!is_array($check) || !is_string($check['id'] ?? null)) {
            continue;
        }
        $id = $check['id'];
        $requiredCheckFields = [
            'category', 'requirement', 'applicability', 'status', 'command', 'environment', 'result_summary',
        ];
        foreach ($requiredCheckFields as $field) {
            if (!is_string($check[$field] ?? null) || $check[$field] === '') {
                $errors[] = sprintf('%s check %s requires a non-empty %s.', ucfirst($label), $id, $field);
            }
        }
        if (!in_array($check['applicability'] ?? null, ['required', 'conditional', 'not_applicable'], true)) {
            $errors[] = sprintf('%s check %s has invalid applicability.', ucfirst($label), $id);
        }
        if (!in_array($check['status'] ?? null, ['not_run', 'passed', 'blocked', 'failed', 'not_applicable'], true)) {
            $errors[] = sprintf('%s check %s has invalid status.', ucfirst($label), $id);
        }
        validatePhaseReferences($check['phase_numbers'] ?? null, $phaseNumbers, $label . ' check ' . $id, $errors);
        validateReferences(
            $check['work_item_ids'] ?? null,
            $workItems,
            'work item',
            $label . ' check ' . $id,
            $errors,
        );
        foreach ($check['work_item_ids'] ?? [] as $workItemId) {
            if (is_string($workItemId)) {
                $checkedWorkItems[$workItemId] = true;
            }
        }
        validateReferences(
            $check['blocker_finding_ids'] ?? null,
            $findingIds,
            'finding',
            $label . ' check ' . $id,
            $errors,
        );
        validateReferences(
            $check['evidence_ids'] ?? null,
            $findingEvidenceIds,
            'finding evidence',
            $label . ' check ' . $id,
            $errors,
        );
        foreach (expectList($check['artifact_paths'] ?? null, $label . ' check artifact paths', $errors) as $path) {
            if (!$template) {
                requireFile($root, $path, $label . ' check artifact', $errors);
            }
        }
        if (($check['status'] ?? null) === 'blocked') {
            $hasBlockedCheck = true;
            if (($check['blocker_finding_ids'] ?? []) === []) {
                $errors[] = sprintf('%s blocked check %s requires a blocker finding.', ucfirst($label), $id);
            }
        }
        if (
            ($check['applicability'] ?? null) === 'required'
            && !in_array($check['status'] ?? null, ['passed', 'not_applicable'], true)
        ) {
            $hasIncompleteRequiredCheck = true;
        }
        if ($template && ($check['status'] ?? null) !== 'not_run') {
            $errors[] = sprintf('Verification template check %s must start not_run.', $id);
        }
    }
    if (!$template && ($report['overall_status'] ?? null) === 'passed' && $hasIncompleteRequiredCheck) {
        $errors[] = sprintf('%s cannot pass while a required check is incomplete.', ucfirst($label));
    }
    if (!$template && ($report['overall_status'] ?? null) === 'blocked' && !$hasBlockedCheck) {
        $errors[] = sprintf('%s is blocked without a blocked check.', ucfirst($label));
    }
    if (!$template) {
        foreach (array_diff_key($scopeWorkItems, $checkedWorkItems) as $workItemId => $_present) {
            $errors[] = sprintf('%s scope work item %s is absent from the check matrix.', ucfirst($label), $workItemId);
        }
    }
    $impact = $report['impact'] ?? null;
    if (!is_array($impact)) {
        $errors[] = sprintf('%s requires an impact object.', ucfirst($label));
        $impact = [];
    }
    $parityKinds = [
        'security', 'accessibility', 'customization', 'templates', 'extensions', 'database', 'deployment',
    ];
    foreach ($parityKinds as $kind) {
        $entry = $impact[$kind] ?? null;
        if (
            !is_array($entry)
            || !in_array($entry['status'] ?? null, ['not_evaluated', 'unaffected', 'affected', 'blocked'], true)
            || !is_string($entry['summary'] ?? null)
            || $entry['summary'] === ''
        ) {
            $errors[] = sprintf('%s requires a valid %s impact disposition.', ucfirst($label), $kind);
        }
    }
    $reportFindings = $report['findings'] ?? null;
    if (!is_array($reportFindings)) {
        $errors[] = sprintf('%s requires a findings object.', ucfirst($label));
        $reportFindings = [];
    }
    if (($reportFindings['register_path'] ?? null) !== 'docs/interface-standard/programme/findings-register.json') {
        $errors[] = sprintf('%s must reference the canonical findings register.', ucfirst($label));
    }
    foreach (['finding_ids', 'new_finding_ids', 'resolved_finding_ids'] as $field) {
        validateReferences(
            $reportFindings[$field] ?? null,
            $findingIds,
            'finding',
            $label . ' ' . $field,
            $errors,
        );
    }
    $recovery = $report['recovery'] ?? null;
    if (
        !is_array($recovery)
        || !is_bool($recovery['required'] ?? null)
        || !is_bool($recovery['verified'] ?? null)
        || !is_string($recovery['strategy'] ?? null)
        || $recovery['strategy'] === ''
        || !is_string($recovery['command_or_procedure'] ?? null)
        || $recovery['command_or_procedure'] === ''
    ) {
        $errors[] = sprintf('%s requires a complete recovery disposition.', ucfirst($label));
        $recovery = [];
    }
    foreach (expectList($recovery['artifact_paths'] ?? null, $label . ' recovery artifacts', $errors) as $path) {
        if (!$template) {
            requireFile($root, $path, $label . ' recovery artifact', $errors);
        }
    }
    foreach (expectList($report['residual_risks'] ?? null, $label . ' residual risks', $errors) as $risk) {
        if (
            !is_array($risk)
            || !in_array($risk['severity'] ?? null, ['P0', 'P1', 'P2', 'P3'], true)
            || !is_string($risk['summary'] ?? null)
            || $risk['summary'] === ''
            || !isset($ownerIds[$risk['owner_role'] ?? ''])
        ) {
            $errors[] = sprintf('%s has an invalid residual risk.', ucfirst($label));
            continue;
        }
        validateReferences($risk['finding_ids'] ?? null, $findingIds, 'finding', $label . ' residual risk', $errors);
    }
    $signoff = $report['signoff'] ?? null;
    if (!is_array($signoff)) {
        $errors[] = sprintf('%s requires signoff metadata.', ucfirst($label));
        $signoff = [];
    }
    if (!in_array($signoff['merge_recommendation'] ?? null, ['hold', 'conditional', 'merge'], true)) {
        $errors[] = sprintf('%s has an invalid merge recommendation.', ucfirst($label));
    }
    validateReferences(
        $signoff['reviewer_roles'] ?? null,
        $ownerIds,
        'owner role',
        $label . ' signoff',
        $errors,
    );
    if (
        !$template
        && ($signoff['merge_recommendation'] ?? null) === 'merge'
        && ($report['overall_status'] ?? null) !== 'passed'
    ) {
        $errors[] = sprintf('%s cannot recommend merge unless overall_status is passed.', ucfirst($label));
    }
    return $checks;
}

/**
 * Validate one record's current status and append-only status-history tail.
 *
 * @param   array<string, mixed>  $record    Work item or gate.
 * @param   string                $label     Diagnostic label.
 * @param   array<string, true>   $statuses  Allowed status lookup.
 * @param   array<string, true>   $evidence  Known evidence lookup.
 * @param   list<string>          $errors    Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validateStatusRecord(
    array $record,
    string $label,
    array $statuses,
    array $evidence,
    array &$errors,
): void {
    $status = $record['status'] ?? null;
    if (!is_string($status) || !isset($statuses[$status])) {
        $errors[] = sprintf('%s has an unknown status.', ucfirst($label));
    }
    $history = expectList($record['status_history'] ?? null, $label . ' status_history', $errors);
    $previousTo = null;
    foreach ($history as $index => $entry) {
        if (!is_array($entry) || array_is_list($entry)) {
            $errors[] = sprintf('%s history entry %d must be an object.', ucfirst($label), $index + 1);
            continue;
        }
        foreach (['from', 'to', 'reason', 'evidence_ids'] as $field) {
            if (!array_key_exists($field, $entry)) {
                $errors[] = sprintf(
                    '%s history entry %d is missing required field %s.',
                    ucfirst($label),
                    $index + 1,
                    $field,
                );
            }
        }
        $hasDate = array_key_exists('date', $entry);
        $hasTimestamp = array_key_exists('at', $entry);
        if (
            $hasDate === $hasTimestamp
            || ($hasDate && !isDate($entry['date']))
            || ($hasTimestamp && !isUtcTimestamp($entry['at']))
        ) {
            $errors[] = sprintf(
                '%s history entry %d requires exactly one valid date or UTC timestamp.',
                ucfirst($label),
                $index + 1,
            );
        }
        $from = $entry['from'] ?? null;
        $to = $entry['to'] ?? null;
        if ($from !== null && (!is_string($from) || !isset($statuses[$from]))) {
            $errors[] = sprintf('%s history entry %d has an unknown from status.', ucfirst($label), $index + 1);
        }
        if (!is_string($to) || !isset($statuses[$to])) {
            $errors[] = sprintf('%s history entry %d has an unknown to status.', ucfirst($label), $index + 1);
        }
        if (
            $index === 0
            && (($from === null && $to !== 'planned') || ($from !== null && $from !== 'planned'))
        ) {
            $errors[] = sprintf('%s history must start with null to planned or from planned.', ucfirst($label));
        }
        if ($index > 0 && $from !== $previousTo) {
            $errors[] = sprintf('%s history entry %d does not continue the prior status.', ucfirst($label), $index + 1);
        }
        if ($from !== null && $from === $to) {
            $errors[] = sprintf('%s history entry %d is a no-op transition.', ucfirst($label), $index + 1);
        }
        if (!is_string($entry['reason'] ?? null) || trim($entry['reason']) === '') {
            $errors[] = sprintf('%s history entry %d requires a reason.', ucfirst($label), $index + 1);
        }
        validateReferences(
            $entry['evidence_ids'] ?? null,
            $evidence,
            'evidence',
            $label . ' history entry ' . ($index + 1),
            $errors,
        );
        $previousTo = is_string($to) ? $to : null;
    }
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
                $errors[] = sprintf(
                    '%s %s does not match its %s metadata.',
                    ucfirst($actualLabel),
                    $key,
                    $declaredLabel,
                );
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
 * Validate a list of integer phase references.
 *
 * @param   mixed              $references  Candidate phase-number list.
 * @param   array<int, true>   $known       Known phase-number lookup.
 * @param   string             $owner       Referencing record label.
 * @param   list<string>       $errors      Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function validatePhaseReferences(mixed $references, array $known, string $owner, array &$errors): void
{
    $seen = [];
    foreach (expectList($references, $owner . ' phase references', $errors) as $reference) {
        if (!is_int($reference) || !isset($known[$reference])) {
            $errors[] = sprintf('%s references unknown phase %s.', ucfirst($owner), printable($reference));
            continue;
        }
        if (isset($seen[$reference])) {
            $errors[] = sprintf('%s repeats phase %d.', ucfirst($owner), $reference);
        }
        $seen[$reference] = true;
    }
}

/**
 * Determine whether a value is a complete lowercase Git object identifier.
 *
 * @param   mixed  $value  Candidate revision.
 *
 * @return  bool  True for a 40-character hexadecimal revision.
 *
 * @since   2.0.0
 */
function isCommitRevision(mixed $value): bool
{
    return is_string($value) && preg_match('/^[0-9a-f]{40}$/D', $value) === 1;
}

/**
 * Determine whether an object identifier resolves to a commit in this repository.
 *
 * Native runtimes ask Git for the identifier's exact object type without invoking a shell. Sandboxed runtimes
 * fall back to loose-object headers and non-delta pack entry types. A shallow clone can therefore accept
 * only commits actually present at its boundary, while an export or unsupported packed representation
 * fails closed instead of treating a syntactically valid hash, blob, tree, or tag as evidence.
 *
 * @param   string  $root      Repository root.
 * @param   string  $revision  Full lowercase object identifier.
 *
 * @return  bool  True only when the object is locally verifiable.
 *
 * @since   2.0.0
 */
function gitCommitExists(string $root, string $revision): bool
{
    if (!isCommitRevision($revision)) {
        return false;
    }
    $nativeResult = gitCatFileCommitExists($root, $revision);
    if (is_bool($nativeResult)) {
        return $nativeResult;
    }
    $gitMetadata = $root . '/.git';
    if (is_dir($gitMetadata)) {
        $gitDirectory = $gitMetadata;
    } elseif (is_file($gitMetadata)) {
        $pointer = trim((string) file_get_contents($gitMetadata));
        if (!str_starts_with($pointer, 'gitdir: ')) {
            return false;
        }
        $gitDirectory = substr($pointer, 8);
        if (!str_starts_with($gitDirectory, '/')) {
            $gitDirectory = $root . '/' . $gitDirectory;
        }
    } else {
        return false;
    }
    $commonDirectory = $gitDirectory;
    if (is_file($gitDirectory . '/commondir')) {
        $commonDirectory = trim((string) file_get_contents($gitDirectory . '/commondir'));
        if (!str_starts_with($commonDirectory, '/')) {
            $commonDirectory = $gitDirectory . '/' . $commonDirectory;
        }
    }
    $pending = [$commonDirectory . '/objects'];
    $seen = [];
    while ($pending !== []) {
        $objectDirectory = array_pop($pending);
        if (!is_string($objectDirectory) || isset($seen[$objectDirectory]) || !is_dir($objectDirectory)) {
            continue;
        }
        $seen[$objectDirectory] = true;
        $loosePath = $objectDirectory . '/' . substr($revision, 0, 2) . '/' . substr($revision, 2);
        if (is_file($loosePath)) {
            $compressed = file_get_contents($loosePath);
            $decoded = is_string($compressed) ? @gzuncompress($compressed) : false;
            if (is_string($decoded) && preg_match('/^commit \d+\x00/sD', $decoded) === 1) {
                return true;
            }
        }
        foreach (glob($objectDirectory . '/pack/*.idx') ?: [] as $indexPath) {
            if (packedGitObjectType($indexPath, $revision) === 1) {
                return true;
            }
        }
        $alternates = @file($objectDirectory . '/info/alternates', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach (is_array($alternates) ? $alternates : [] as $alternate) {
            $pending[] = str_starts_with($alternate, '/') ? $alternate : $objectDirectory . '/' . $alternate;
        }
    }
    return false;
}

/**
 * Ask Git to verify a commit when process execution is available.
 *
 * @param   string  $root      Repository root.
 * @param   string  $revision  Full lowercase object identifier.
 *
 * @return  ?bool  Verification result, or null when process execution is unavailable.
 *
 * @since   2.0.0
 */
function gitCatFileCommitExists(string $root, string $revision): ?bool
{
    if (!function_exists('proc_open')) {
        return null;
    }
    $pipes = [];
    $process = @proc_open(
        ['git', '-C', $root, 'cat-file', '-t', $revision],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        return null;
    }
    fclose($pipes[0]);
    $objectType = trim((string) stream_get_contents($pipes[1]));
    $error = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode === 126 || $exitCode === 127 || str_contains($error, 'not found')) {
        return null;
    }
    return $exitCode === 0 && $objectType === 'commit';
}

/**
 * Resolve the non-delta object type recorded by one Git pack index and pack entry.
 *
 * @param   string  $path      Pack index path.
 * @param   string  $revision  Full lowercase object identifier.
 *
 * @return  ?int  Git pack type number, or null when absent, delta-compressed, or unverifiable.
 *
 * @since   2.0.0
 */
function packedGitObjectType(string $path, string $revision): ?int
{
    $index = file_get_contents($path);
    $needle = hex2bin($revision);
    if (!is_string($index) || !is_string($needle)) {
        return null;
    }
    $versioned = substr($index, 0, 4) === "\xfftOc";
    if ($versioned) {
        $version = unpack('Nversion', substr($index, 4, 4));
        if (!is_array($version) || !in_array($version['version'] ?? null, [2, 3], true)) {
            return null;
        }
        $fanoutOffset = 8;
        $namesOffset = $fanoutOffset + 1024;
        $stride = 20;
    } else {
        $fanoutOffset = 0;
        $namesOffset = 1024 + 4;
        $stride = 24;
    }
    $firstByte = ord($needle[0]);
    $lower = $firstByte === 0 ? 0 : unpack('Ncount', substr($index, $fanoutOffset + (($firstByte - 1) * 4), 4));
    $upper = unpack('Ncount', substr($index, $fanoutOffset + ($firstByte * 4), 4));
    $low = is_array($lower) ? (int) $lower['count'] : 0;
    $high = is_array($upper) ? (int) $upper['count'] - 1 : -1;
    $match = null;
    while ($low <= $high) {
        $middle = intdiv($low + $high, 2);
        $candidateOffset = $namesOffset + ($middle * $stride);
        $candidate = substr($index, $candidateOffset, 20);
        $comparison = strcmp($candidate, $needle);
        if ($comparison === 0) {
            $match = $middle;
            break;
        }
        if ($comparison < 0) {
            $low = $middle + 1;
        } else {
            $high = $middle - 1;
        }
    }
    if (!is_int($match)) {
        return null;
    }
    if ($versioned) {
        $countValue = unpack('Ncount', substr($index, $fanoutOffset + (255 * 4), 4));
        $count = is_array($countValue) ? (int) $countValue['count'] : 0;
        $offsetTable = $namesOffset + ($count * 20) + ($count * 4);
        $offsetValue = unpack('Noffset', substr($index, $offsetTable + ($match * 4), 4));
        $offset = is_array($offsetValue) ? (int) $offsetValue['offset'] : -1;
        if (($offset & 0x80000000) !== 0) {
            $largeIndex = $offset & 0x7fffffff;
            $largeTable = $offsetTable + ($count * 4);
            $largeValue = unpack('Joffset', substr($index, $largeTable + ($largeIndex * 8), 8));
            $offset = is_array($largeValue) ? (int) $largeValue['offset'] : -1;
        }
    } else {
        $offsetValue = unpack('Noffset', substr($index, 1024 + ($match * 24), 4));
        $offset = is_array($offsetValue) ? (int) $offsetValue['offset'] : -1;
    }
    if ($offset < 12) {
        return null;
    }
    $pack = fopen(substr($path, 0, -4) . '.pack', 'rb');
    if (!is_resource($pack) || fseek($pack, $offset) !== 0) {
        if (is_resource($pack)) {
            fclose($pack);
        }
        return null;
    }
    $header = fread($pack, 1);
    fclose($pack);
    if (!is_string($header) || strlen($header) !== 1) {
        return null;
    }
    $type = (ord($header) >> 4) & 7;
    return in_array($type, [1, 2, 3, 4], true) ? $type : null;
}

/**
 * Determine whether a value is a valid calendar date.
 *
 * @param   mixed  $value  Candidate YYYY-MM-DD date.
 *
 * @return  bool  True for a real Gregorian calendar date.
 *
 * @since   2.0.0
 */
function isDate(mixed $value): bool
{
    if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) !== 1) {
        return false;
    }
    return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
}

/**
 * Determine whether a value is a normalized UTC timestamp.
 *
 * @param   mixed  $value  Candidate timestamp.
 *
 * @return  bool  True for second-precision UTC ISO-8601 text.
 *
 * @since   2.0.0
 */
function isUtcTimestamp(mixed $value): bool
{
    if (
        !is_string($value)
        || preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z$/D', $value, $parts) !== 1
    ) {
        return false;
    }
    return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        && (int) $parts[4] <= 23
        && (int) $parts[5] <= 59
        && (int) $parts[6] <= 59;
}

/**
 * Recursively identify unresolved angle-bracket placeholders in a completed report.
 *
 * @param   mixed  $value  Report value to inspect.
 *
 * @return  bool  True when a string contains an unresolved template marker.
 *
 * @since   2.0.0
 */
function containsPlaceholder(mixed $value): bool
{
    if (is_string($value)) {
        return preg_match('/<[^<>]+>/', $value) === 1;
    }
    if (!is_array($value)) {
        return false;
    }
    foreach ($value as $item) {
        if (containsPlaceholder($item)) {
            return true;
        }
    }
    return false;
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
 * Require one repository-relative file or directory to exist.
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
function requirePath(string $root, mixed $path, string $label, array &$errors): void
{
    if (!is_string($path) || $path === '' || !file_exists($root . '/' . $path)) {
        $errors[] = sprintf('%s %s does not exist.', ucfirst($label), printable($path));
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
