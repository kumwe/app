<?php

/**
 * Read, verify and execute the one quality contract.
 *
 * `docs/quality/contract.json` is the single definition of every check this repository runs and of the
 * lane — local, merge, nightly, release — each one runs in. Before it existed the definition was
 * scattered: `composer qa` carried a hand-assembled list, `.github/workflows/ci.yml` reassembled its own
 * sequence, and the release job carried a third and shorter one, so a check could be documented, or run
 * locally, or run at merge, without being run anywhere else. Nothing detected that.
 *
 * This tool closes both directions. `--check` fails when the manifest and the `qa` script disagree either
 * way, when a check names a workflow or a job that does not exist, when the job that is declared to carry a
 * command no longer contains it, when a check declares engines its lane does not cover, and when the
 * three-engine regression matrix Gate A exit criterion 12 is assessed on is not what the workflow executes.
 * `--run` executes a lane, so nightly and release consume the manifest instead of restating it.
 *
 * Usage:
 *   php tools/quality-contract.php --check [--contract=PATH]
 *   php tools/quality-contract.php --run --cadence=nightly|release|local|ci [--contract=PATH]
 *
 * `--contract` exists so the architecture suite can prove the check fails in the right direction against a
 * deliberately broken manifest without writing that manifest into the committed one.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$contractPath = $root . '/docs/quality/contract.json';
$mode = null;
$cadence = null;
$argumentErrors = [];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--check') {
        $mode = 'check';
        continue;
    }
    if ($argument === '--run') {
        $mode = 'run';
        continue;
    }
    if (str_starts_with($argument, '--cadence=')) {
        $cadence = substr($argument, strlen('--cadence='));
        continue;
    }
    if (str_starts_with($argument, '--contract=')) {
        $contractPath = substr($argument, strlen('--contract='));
        continue;
    }

    $argumentErrors[] = sprintf('Unknown argument %s.', $argument);
}

if ($argumentErrors !== []) {
    reportContractFailure($argumentErrors);
}

$mode ??= 'check';

if ($mode === 'run' && ($cadence === null || $cadence === '')) {
    reportContractFailure(['--run needs --cadence=local, --cadence=ci, --cadence=nightly or --cadence=release.']);
}

$contract = readContract($contractPath);

if ($mode === 'run') {
    /** @var string $cadence */
    exit(runContractLane($contract, $cadence, $root));
}

exit(checkContract($contract, $contractPath, $root));

/**
 * Read the manifest and refuse anything that is not a decodable JSON object.
 *
 * @param   string  $path  Absolute path to the manifest.
 *
 * @return  array<string, mixed>  The decoded manifest.
 *
 * @since   2.0.0
 */
function readContract(string $path): array
{
    if (!is_file($path)) {
        reportContractFailure([sprintf('%s is missing. It is the single definition of the quality gates.', $path)]);
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        reportContractFailure([sprintf('%s could not be read.', $path)]);
    }

    /** @var mixed $decoded */
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        reportContractFailure([sprintf('%s is not well-formed JSON: %s.', $path, json_last_error_msg())]);
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * Verify the manifest against the repository it describes.
 *
 * @param   array<string, mixed>  $contract  Decoded manifest.
 * @param   string                $path      Path the manifest was read from, for diagnostics.
 * @param   string                $root      Repository root.
 *
 * @return  int  Process exit status: zero when every binding holds.
 *
 * @since   2.0.0
 */
function checkContract(array $contract, string $path, string $root): int
{
    $errors = [];
    $cadences = stringList($contract['cadences'] ?? null, 'cadences', $errors);
    $intervals = stringList($contract['intervals'] ?? null, 'intervals', $errors);
    $owners = stringList($contract['owners'] ?? null, 'owners', $errors);

    $engines = readEngines($contract, $errors);
    $laneBindings = readLaneBindings($contract, $cadences, $root, $errors);
    $checks = readChecks($contract, $cadences, $intervals, $owners, array_keys($engines), $errors);

    verifyComposerScripts($checks, $root, $errors);
    verifyWorkflowBindings($checks, $engines, $laneBindings, $root, $errors);
    verifyEngineMatrix($engines, $root, $errors);
    verifyRegressionMatrix($contract, $engines, $root, $errors);

    if ($errors !== []) {
        reportContractFailure($errors);
    }

    $localLane = 0;
    foreach ($checks as $check) {
        if (in_array('local', $check['cadence'], true)) {
            $localLane++;
        }
    }

    fwrite(
        STDOUT,
        sprintf(
            "Kumwe quality contract verified: %d checks, %d in the local lane, %d engines, %s.\n",
            count($checks),
            $localLane,
            count($engines),
            basename($path),
        ),
    );

    return 0;
}

/**
 * Read and validate the declared engines.
 *
 * @param   array<string, mixed>  $contract  Decoded manifest.
 * @param   list<string>          $errors    Accumulated validation failures.
 *
 * @return  array<string, array{id: string, image: string, canonical_coverage: bool, name: string}>  Engines
 *          by identifier.
 *
 * @since   2.0.0
 */
function readEngines(array $contract, array &$errors): array
{
    $engines = [];
    $canonical = 0;
    /** @var mixed $declared */
    $declared = $contract['engines'] ?? null;
    if (!is_array($declared) || !array_is_list($declared) || $declared === []) {
        $errors[] = 'The manifest must declare a non-empty "engines" array.';

        return [];
    }

    foreach ($declared as $index => $engine) {
        if (!is_array($engine)) {
            $errors[] = sprintf('Engine at position %d is not an object.', $index);
            continue;
        }
        $id = $engine['id'] ?? null;
        $image = $engine['image'] ?? null;
        $name = $engine['name'] ?? null;
        $coverage = $engine['canonical_coverage'] ?? null;
        if (!is_string($id) || $id === '' || !is_string($image) || $image === '' || !is_string($name)) {
            $errors[] = sprintf('Engine at position %d needs a non-empty id, name and image.', $index);
            continue;
        }
        if (!is_bool($coverage)) {
            $errors[] = sprintf('Engine %s must declare canonical_coverage as a boolean.', $id);
            continue;
        }
        if (isset($engines[$id])) {
            $errors[] = sprintf('Engine %s is declared more than once.', $id);
            continue;
        }
        $canonical += $coverage ? 1 : 0;
        $engines[$id] = ['id' => $id, 'image' => $image, 'canonical_coverage' => $coverage, 'name' => $name];
    }

    if ($engines !== [] && $canonical !== 1) {
        $errors[] = sprintf(
            'Exactly one engine carries canonical_coverage, and %d do. Coverage is attributed on one '
            . 'canonical engine so two legs cannot each claim to be the measurement.',
            $canonical,
        );
    }

    return $engines;
}

/**
 * Read the per-lane execution bindings and prove the nightly and release entry points exist.
 *
 * @param   array<string, mixed>  $contract  Decoded manifest.
 * @param   list<string>          $cadences  Declared cadence names.
 * @param   string                $root      Repository root.
 * @param   list<string>          $errors    Accumulated validation failures.
 *
 * @return  array<string, array<string, mixed>>  Lane bindings by cadence.
 *
 * @since   2.0.0
 */
function readLaneBindings(array $contract, array $cadences, string $root, array &$errors): array
{
    /** @var mixed $declared */
    $declared = $contract['lane_bindings'] ?? null;
    if (!is_array($declared)) {
        $errors[] = 'The manifest must declare "lane_bindings" describing how each lane is entered.';

        return [];
    }

    $bindings = [];
    foreach ($cadences as $cadence) {
        /** @var mixed $binding */
        $binding = $declared[$cadence] ?? null;
        if (!is_array($binding)) {
            $errors[] = sprintf('Lane "%s" has no binding, so nothing says how that lane is entered.', $cadence);
            continue;
        }
        /** @var array<string, mixed> $binding */
        $bindings[$cadence] = $binding;

        $file = $binding['file'] ?? null;
        if (!is_string($file) || $file === '') {
            continue;
        }
        $job = $binding['job'] ?? null;
        $contains = $binding['contains'] ?? null;
        if (!is_string($job) || $job === '' || !is_string($contains) || $contains === '') {
            $errors[] = sprintf('Lane "%s" names a workflow file but no job and command to find in it.', $cadence);
            continue;
        }
        verifyJobCarries($root, $file, $job, $contains, sprintf('Lane "%s"', $cadence), $errors);
    }

    return $bindings;
}

/**
 * Read and validate every declared check.
 *
 * @param   array<string, mixed>  $contract   Decoded manifest.
 * @param   list<string>          $cadences   Declared cadence names.
 * @param   list<string>          $intervals  Declared interval names.
 * @param   list<string>          $owners     Declared owner names.
 * @param   list<string>          $engineIds  Declared engine identifiers.
 * @param   list<string>          $errors     Accumulated validation failures.
 *
 * @return  list<array{id: string, runner: string, composer_script: string|null, in_qa: bool,
 *          cadence: list<string>, engines: list<string>, workflows: array<string, array<string, mixed>>,
 *          invoked_by: string|null}>  The validated checks.
 *
 * @since   2.0.0
 */
function readChecks(
    array $contract,
    array $cadences,
    array $intervals,
    array $owners,
    array $engineIds,
    array &$errors,
): array {
    /** @var mixed $declared */
    $declared = $contract['checks'] ?? null;
    if (!is_array($declared) || !array_is_list($declared) || $declared === []) {
        $errors[] = 'The manifest must declare a non-empty "checks" array.';

        return [];
    }

    $runners = ['composer', 'npm', 'shell', 'workflow'];
    $checks = [];
    $seen = [];
    foreach ($declared as $index => $check) {
        if (!is_array($check)) {
            $errors[] = sprintf('Check at position %d is not an object.', $index);
            continue;
        }
        $id = $check['id'] ?? null;
        if (!is_string($id) || $id === '') {
            $errors[] = sprintf('Check at position %d has no identifier.', $index);
            continue;
        }
        if (isset($seen[$id])) {
            $errors[] = sprintf('Check %s is declared more than once.', $id);
            continue;
        }
        $seen[$id] = true;

        foreach (['title', 'purpose', 'command', 'artifact'] as $field) {
            $value = $check[$field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                $errors[] = sprintf('Check %s needs a non-empty "%s".', $id, $field);
            }
        }

        $owner = $check['owner'] ?? null;
        if (!is_string($owner) || !in_array($owner, $owners, true)) {
            $errors[] = sprintf('Check %s must name one owner from the manifest\'s "owners" list.', $id);
        }

        $runner = $check['runner'] ?? null;
        if (!is_string($runner) || !in_array($runner, $runners, true)) {
            $errors[] = sprintf('Check %s must declare a runner from %s.', $id, implode(', ', $runners));
            continue;
        }

        $interval = $check['maximum_interval'] ?? null;
        if (!is_string($interval) || !in_array($interval, $intervals, true)) {
            $errors[] = sprintf('Check %s must declare a maximum_interval from the manifest\'s list.', $id);
        }

        $inQa = $check['in_qa'] ?? null;
        if (!is_bool($inQa)) {
            $errors[] = sprintf('Check %s must declare in_qa as a boolean.', $id);
            continue;
        }
        if (!$inQa) {
            $reason = $check['excluded_from_qa_reason'] ?? null;
            if (!is_string($reason) || trim($reason) === '') {
                $errors[] = sprintf(
                    'Check %s is outside composer qa and must say why, so the exclusion is a decision '
                    . 'rather than an omission.',
                    $id,
                );
            }
        }

        $cadence = stringList($check['cadence'] ?? null, sprintf('%s.cadence', $id), $errors);
        if ($cadence === []) {
            $errors[] = sprintf('Check %s runs in no lane at all.', $id);
        }
        foreach ($cadence as $lane) {
            if (!in_array($lane, $cadences, true)) {
                $errors[] = sprintf('Check %s declares unknown lane "%s".', $id, $lane);
            }
        }

        $engines = stringList($check['engines'] ?? null, sprintf('%s.engines', $id), $errors);
        foreach ($engines as $engine) {
            if (!in_array($engine, $engineIds, true)) {
                $errors[] = sprintf('Check %s declares unknown engine "%s".', $id, $engine);
            }
        }

        $script = $check['composer_script'] ?? null;
        if ($runner === 'composer' && (!is_string($script) || $script === '')) {
            $errors[] = sprintf('Check %s runs through Composer and must name its script.', $id);
            $script = null;
        }
        if ($runner !== 'composer') {
            $script = is_string($script) && $script !== '' ? $script : null;
        }
        if (in_array('local', $cadence, true) && $script === null) {
            $errors[] = sprintf(
                'Check %s claims the local lane but names no Composer script, so a contributor has no way '
                . 'to run it.',
                $id,
            );
        }
        if ($inQa && $script === null) {
            $errors[] = sprintf('Check %s is in composer qa and must name the script qa calls.', $id);
        }

        /** @var mixed $workflows */
        $workflows = $check['workflows'] ?? [];
        if (!is_array($workflows)) {
            $errors[] = sprintf('Check %s must declare "workflows" as an object.', $id);
            $workflows = [];
        }
        /** @var array<string, array<string, mixed>> $workflows */

        $invokedBy = $check['invoked_by'] ?? null;

        $checks[] = [
            'id' => $id,
            'runner' => $runner,
            'composer_script' => is_string($script) ? $script : null,
            'in_qa' => $inQa,
            'cadence' => $cadence,
            'engines' => $engines,
            'workflows' => $workflows,
            'invoked_by' => is_string($invokedBy) && $invokedBy !== '' ? $invokedBy : null,
        ];
    }

    foreach ($checks as $check) {
        if ($check['invoked_by'] === null) {
            continue;
        }
        if (!isset($seen[$check['invoked_by']])) {
            $errors[] = sprintf(
                'Check %s says it is invoked by %s, which is not a declared check.',
                $check['id'],
                $check['invoked_by'],
            );
        }
    }

    return $checks;
}

/**
 * Prove every Composer script the manifest names exists, and that the qa lane matches in both directions.
 *
 * @param   list<array{id: string, runner: string, composer_script: string|null, in_qa: bool,
 *          cadence: list<string>, engines: list<string>, workflows: array<string, array<string, mixed>>,
 *          invoked_by: string|null}>  $checks  Validated checks.
 * @param   string                     $root    Repository root.
 * @param   list<string>               $errors  Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function verifyComposerScripts(array $checks, string $root, array &$errors): void
{
    $raw = file_get_contents($root . '/composer.json');
    if ($raw === false) {
        $errors[] = 'composer.json could not be read.';

        return;
    }
    /** @var mixed $composer */
    $composer = json_decode($raw, true);
    if (!is_array($composer)) {
        $errors[] = 'composer.json is not well-formed JSON.';

        return;
    }
    /** @var mixed $scripts */
    $scripts = $composer['scripts'] ?? null;
    if (!is_array($scripts)) {
        $errors[] = 'composer.json declares no scripts.';

        return;
    }

    $declared = [];
    foreach ($checks as $check) {
        $script = $check['composer_script'];
        if ($script === null) {
            continue;
        }
        if (!array_key_exists($script, $scripts)) {
            $errors[] = sprintf(
                'Check %s names Composer script "%s", which composer.json does not define.',
                $check['id'],
                $script,
            );
            continue;
        }
        if ($check['in_qa']) {
            $declared[$script] = $check['id'];
        }
    }

    /** @var mixed $qa */
    $qa = $scripts['qa'] ?? null;
    if (!is_array($qa) || !array_is_list($qa)) {
        $errors[] = 'composer.json must declare "qa" as an array of script references.';

        return;
    }

    $executed = [];
    foreach ($qa as $entry) {
        if (!is_string($entry) || !str_starts_with($entry, '@')) {
            $errors[] = sprintf('composer qa entry %s is not a script reference.', var_export($entry, true));
            continue;
        }
        $executed[substr($entry, 1)] = true;
    }

    foreach (array_keys($declared) as $script) {
        if (!isset($executed[$script])) {
            $errors[] = sprintf(
                'The contract puts "%s" in the local lane but composer qa does not run it. Add "@%s" to the '
                . 'qa script, or move the check out of the local lane with a stated reason.',
                $script,
                $script,
            );
        }
    }

    foreach (array_keys($executed) as $script) {
        if (isset($declared[$script])) {
            continue;
        }
        $errors[] = sprintf(
            'composer qa runs "@%s", which docs/quality/contract.json does not declare as a local-lane '
            . 'check. Declare it there — with an owner, a purpose, an artifact and a cadence — so the '
            . 'contract stays the one definition of what runs.',
            $script,
        );
    }
}

/**
 * Prove every declared workflow binding resolves to a job that still carries the command.
 *
 * @param   list<array{id: string, runner: string, composer_script: string|null, in_qa: bool,
 *          cadence: list<string>, engines: list<string>, workflows: array<string, array<string, mixed>>,
 *          invoked_by: string|null}>  $checks  Validated checks.
 * @param   array<string, array{id: string, image: string, canonical_coverage: bool, name: string}>
 *          $engines  Declared engines by identifier.
 * @param   array<string, array<string, mixed>>  $laneBindings  Lane bindings by cadence.
 * @param   string                               $root          Repository root.
 * @param   list<string>                         $errors        Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function verifyWorkflowBindings(
    array $checks,
    array $engines,
    array $laneBindings,
    string $root,
    array &$errors,
): void {
    foreach ($checks as $check) {
        foreach ($check['cadence'] as $lane) {
            if ($lane === 'local') {
                continue;
            }
            /** @var mixed $binding */
            $binding = $check['workflows'][$lane] ?? null;
            if (!is_array($binding)) {
                $laneBinding = $laneBindings[$lane] ?? null;
                if ($lane !== 'ci' && is_array($laneBinding) && isset($laneBinding['file'])) {
                    continue;
                }
                $errors[] = sprintf(
                    'Check %s claims the %s lane but names no workflow and job for it, and that lane has no '
                    . 'manifest runner to carry it.',
                    $check['id'],
                    $lane,
                );
                continue;
            }
            $file = $binding['file'] ?? null;
            $job = $binding['job'] ?? null;
            $contains = $binding['contains'] ?? null;
            if (
                !is_string($file) || $file === '' || !is_string($job) || $job === ''
                || !is_string($contains) || $contains === ''
            ) {
                $errors[] = sprintf('Check %s has an incomplete %s workflow binding.', $check['id'], $lane);
                continue;
            }
            $span = verifyJobCarries(
                $root,
                $file,
                $job,
                $contains,
                sprintf('Check %s (%s lane)', $check['id'], $lane),
                $errors,
            );
            if ($span === null || $check['engines'] === []) {
                continue;
            }
            $scope = engineScopeFor($root, $file, $span);
            foreach ($check['engines'] as $engineId) {
                $engine = $engines[$engineId] ?? null;
                if ($engine === null || str_contains($scope, $engine['image'])) {
                    continue;
                }
                $errors[] = sprintf(
                    'Check %s declares engine %s, but %s job "%s" never names image %s. A check that claims '
                    . 'an engine has to run on it.',
                    $check['id'],
                    $engineId,
                    $file,
                    $job,
                    $engine['image'],
                );
            }
        }
    }
}

/**
 * Decide which text a check's engine claims are proved against.
 *
 * A job that carries its own matrix is judged on that matrix, so a claim cannot be satisfied by an engine
 * another job happens to run. A job that delegates to a reusable workflow is judged on the workflow it
 * calls, because that is where the engines it claims actually appear. Anything else falls back to the whole
 * file, which is the weakest of the three and is why the first two exist.
 *
 * @param   string  $root  Repository root.
 * @param   string  $file  Workflow path relative to the root.
 * @param   string  $span  Text of the job that made the claim.
 *
 * @return  string  The text the engine images are looked for in.
 *
 * @since   2.0.0
 */
function engineScopeFor(string $root, string $file, string $span): string
{
    if (str_contains($span, 'matrix:')) {
        return $span;
    }

    if (preg_match('#uses:\s*\./(\.github/workflows/[A-Za-z0-9._-]+)#', $span, $matched) === 1) {
        $called = @file_get_contents($root . '/' . $matched[1]);
        if (is_string($called)) {
            return $called;
        }
    }

    $contents = @file_get_contents($root . '/' . $file);

    return is_string($contents) ? $contents : $span;
}

/**
 * Prove the merge matrix carries every declared engine and marks exactly the canonical one for coverage.
 *
 * @param   array<string, array{id: string, image: string, canonical_coverage: bool, name: string}>
 *          $engines  Declared engines by identifier.
 * @param   string        $root    Repository root.
 * @param   list<string>  $errors  Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function verifyEngineMatrix(array $engines, string $root, array &$errors): void
{
    $span = jobSpan($root, '.github/workflows/ci.yml', 'database', $errors);
    if ($span === null) {
        return;
    }

    foreach ($engines as $engine) {
        $block = matrixEntryFor($span, $engine['image']);
        if ($block === null) {
            $errors[] = sprintf(
                'The ci.yml database matrix has no entry for %s (%s), so the engine is declared and never run.',
                $engine['name'],
                $engine['image'],
            );
            continue;
        }
        $marked = str_contains($block, "coverage: 'true'");
        if ($marked === $engine['canonical_coverage']) {
            continue;
        }
        $errors[] = $engine['canonical_coverage']
            ? sprintf(
                'The contract makes %s the canonical coverage engine, but its ci.yml matrix entry does not '
                . "carry coverage: 'true'.",
                $engine['name'],
            )
            : sprintf(
                "The ci.yml matrix entry for %s carries coverage: 'true' while the contract names another "
                . 'engine as canonical. Two legs cannot both be the measurement.',
                $engine['name'],
            );
    }
}

/**
 * Prove the three-engine regression matrix Gate A exit criterion 12 is assessed on is what CI executes.
 *
 * @param   array<string, mixed>  $contract  Decoded manifest.
 * @param   array<string, array{id: string, image: string, canonical_coverage: bool, name: string}>
 *          $engines  Declared engines by identifier.
 * @param   string        $root    Repository root.
 * @param   list<string>  $errors  Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function verifyRegressionMatrix(array $contract, array $engines, string $root, array &$errors): void
{
    /** @var mixed $matrix */
    $matrix = $contract['regression_matrix'] ?? null;
    if (!is_array($matrix)) {
        $errors[] = 'The manifest must declare "regression_matrix": it is what makes "nothing regressed on '
            . 'three engines" an assessable statement rather than an opinion.';

        return;
    }

    $workflow = $matrix['workflow'] ?? null;
    $job = $matrix['job'] ?? null;
    if (!is_string($workflow) || $workflow === '' || !is_string($job) || $job === '') {
        $errors[] = 'The regression matrix must name the workflow and job that executes it.';

        return;
    }

    $span = jobSpan($root, $workflow, $job, $errors);
    if ($span === null) {
        return;
    }

    foreach (stringList($matrix['engines'] ?? null, 'regression_matrix.engines', $errors) as $engineId) {
        $engine = $engines[$engineId] ?? null;
        if ($engine === null) {
            $errors[] = sprintf('The regression matrix names unknown engine "%s".', $engineId);
            continue;
        }
        if (str_contains($span, $engine['image'])) {
            continue;
        }
        $errors[] = sprintf(
            'The regression matrix claims %s, which %s job "%s" does not run.',
            $engine['name'],
            $workflow,
            $job,
        );
    }

    foreach (stringList($matrix['full_suite_commands'] ?? null, 'full_suite_commands', $errors) as $command) {
        if (str_contains($span, $command)) {
            continue;
        }
        $errors[] = sprintf(
            'The regression matrix says the complete suite runs through "%s", which %s job "%s" no longer '
            . 'contains.',
            trim($command),
            $workflow,
            $job,
        );
    }

    $suites = stringList($matrix['suites'] ?? null, 'regression_matrix.suites', $errors);
    $phpunit = @file_get_contents($root . '/phpunit.xml.dist');
    if (!is_string($phpunit)) {
        $errors[] = 'phpunit.xml.dist could not be read, so the declared suites cannot be resolved.';

        return;
    }
    foreach ($suites as $suite) {
        if (str_contains($phpunit, sprintf('<testsuite name="%s">', $suite))) {
            continue;
        }
        $errors[] = sprintf('The regression matrix names suite "%s", which phpunit.xml.dist does not define.', $suite);
    }
}

/**
 * Prove a workflow job exists and still carries a command, returning that job's text.
 *
 * @param   string        $root      Repository root.
 * @param   string        $file      Workflow path relative to the root.
 * @param   string        $job       Job key expected in the workflow.
 * @param   string        $contains  Text the job must still carry.
 * @param   string        $subject   Diagnostic prefix naming what made the claim.
 * @param   list<string>  $errors    Accumulated validation failures.
 *
 * @return  string|null  The job's text, or null when the file, the job or the command is missing.
 *
 * @since   2.0.0
 */
function verifyJobCarries(
    string $root,
    string $file,
    string $job,
    string $contains,
    string $subject,
    array &$errors,
): ?string {
    $span = jobSpan($root, $file, $job, $errors, $subject);
    if ($span === null) {
        return null;
    }
    if (str_contains($span, $contains)) {
        return $span;
    }

    $errors[] = sprintf(
        '%s says %s job "%s" runs "%s", and it does not. Either the workflow stopped running the check or '
        . 'the contract stopped describing it; both are the drift this gate exists to catch.',
        $subject,
        $file,
        $job,
        trim($contains),
    );

    return null;
}

/**
 * Return the text of one job in a workflow file.
 *
 * @param   string        $root     Repository root.
 * @param   string        $file     Workflow path relative to the root.
 * @param   string        $job      Job key to extract.
 * @param   list<string>  $errors   Accumulated validation failures.
 * @param   string        $subject  Diagnostic prefix naming what made the claim.
 *
 * @return  string|null  The job's text, or null when the file or the job is missing.
 *
 * @since   2.0.0
 */
function jobSpan(string $root, string $file, string $job, array &$errors, string $subject = 'The contract'): ?string
{
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        $errors[] = sprintf('%s names workflow %s, which does not exist.', $subject, $file);

        return null;
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        $errors[] = sprintf('%s could not be read.', $file);

        return null;
    }

    $lines = explode("\n", $contents);
    $inJobs = false;
    $start = null;
    $end = count($lines);
    foreach ($lines as $index => $line) {
        if ($line === 'jobs:') {
            $inJobs = true;
            continue;
        }
        if (!$inJobs) {
            continue;
        }
        if ($line !== '' && !str_starts_with($line, ' ') && !str_starts_with($line, '#')) {
            if ($start !== null) {
                $end = $index;
                break;
            }
            $inJobs = false;
            continue;
        }
        if (preg_match('/^  ([A-Za-z0-9_-]+):\s*$/', $line, $matched) !== 1) {
            continue;
        }
        if ($start !== null) {
            $end = $index;
            break;
        }
        if ($matched[1] === $job) {
            $start = $index;
        }
    }

    if ($start === null) {
        $errors[] = sprintf('%s names job "%s" in %s, which declares no such job.', $subject, $job, $file);

        return null;
    }

    return implode("\n", array_slice($lines, $start, $end - $start));
}

/**
 * Return the matrix entry that names an image, so the entry's own options can be read.
 *
 * @param   string  $span   Text of the job carrying the matrix.
 * @param   string  $image  Container image identifying the entry.
 *
 * @return  string|null  The entry's text, or null when no entry names the image.
 *
 * @since   2.0.0
 */
function matrixEntryFor(string $span, string $image): ?string
{
    $lines = explode("\n", $span);
    $entries = [];
    $current = null;
    foreach ($lines as $line) {
        if (preg_match('/^\s*- name: /', $line) === 1) {
            if ($current !== null) {
                $entries[] = $current;
            }
            $current = $line;
            continue;
        }
        if ($current !== null) {
            $current .= "\n" . $line;
        }
    }
    if ($current !== null) {
        $entries[] = $current;
    }

    foreach ($entries as $entry) {
        if (preg_match('/^\s*image: ' . preg_quote($image, '/') . '\s*$/m', $entry) === 1) {
            return $entry;
        }
    }

    return null;
}

/**
 * Execute every check a lane declares, in manifest order.
 *
 * @param   array<string, mixed>  $contract  Decoded manifest.
 * @param   string                $cadence   Lane to execute.
 * @param   string                $root      Repository root.
 *
 * @return  int  Process exit status: zero when every executed check passed.
 *
 * @since   2.0.0
 */
function runContractLane(array $contract, string $cadence, string $root): int
{
    $errors = [];
    $cadences = stringList($contract['cadences'] ?? null, 'cadences', $errors);
    if (!in_array($cadence, $cadences, true)) {
        reportContractFailure([sprintf('Lane "%s" is not declared in the contract.', $cadence)]);
    }

    /** @var mixed $checks */
    $checks = $contract['checks'] ?? null;
    if (!is_array($checks)) {
        reportContractFailure(['The contract declares no checks to run.']);
    }

    $failed = [];
    $ran = 0;
    foreach ($checks as $check) {
        if (!is_array($check)) {
            continue;
        }
        $id = $check['id'] ?? '';
        $lanes = $check['cadence'] ?? null;
        if (!is_string($id) || !is_array($lanes) || !in_array($cadence, $lanes, true)) {
            continue;
        }
        if (isset($check['invoked_by'])) {
            continue;
        }
        $runner = $check['runner'] ?? '';
        if ($runner === 'workflow') {
            fwrite(STDOUT, sprintf("- %s is a workflow and is not executable here.\n", $id));
            continue;
        }
        $script = $check['composer_script'] ?? null;
        $command = $runner === 'composer' && is_string($script)
            ? sprintf('composer %s', $script)
            : (string) ($check['command'] ?? '');
        if ($command === '') {
            continue;
        }

        fwrite(STDOUT, sprintf("\n== %s: %s\n", $cadence, $command));
        $status = 0;
        passthru(sprintf('cd %s && %s', escapeshellarg($root), $command), $status);
        $ran++;
        if ($status !== 0) {
            $failed[] = sprintf('%s (%s) exited %d', $id, $command, $status);
        }
    }

    if ($failed !== []) {
        fwrite(STDERR, sprintf("\nKumwe quality contract lane \"%s\" failed:\n", $cadence));
        foreach ($failed as $failure) {
            fwrite(STDERR, ' - ' . $failure . "\n");
        }

        return 1;
    }

    fwrite(STDOUT, sprintf("\nKumwe quality contract lane \"%s\" passed: %d checks executed.\n", $cadence, $ran));

    return 0;
}

/**
 * Require a value to be a JSON array of non-empty strings.
 *
 * @param   mixed         $value   Candidate value.
 * @param   string        $label   Diagnostic label naming the member.
 * @param   list<string>  $errors  Accumulated validation failures.
 *
 * @return  list<string>  The strings, or an empty list when the value was the wrong shape.
 *
 * @since   2.0.0
 */
function stringList(mixed $value, string $label, array &$errors): array
{
    if (!is_array($value) || !array_is_list($value)) {
        $errors[] = sprintf('Contract member "%s" must be a JSON array.', $label);

        return [];
    }

    $strings = [];
    foreach ($value as $entry) {
        if (!is_string($entry) || $entry === '') {
            $errors[] = sprintf('Every entry in "%s" must be a non-empty string.', $label);
            continue;
        }
        $strings[] = $entry;
    }

    return $strings;
}

/**
 * Print every failure and terminate with a non-zero status.
 *
 * @param   list<string>  $errors  Validation failures.
 *
 * @return  never
 *
 * @since   2.0.0
 */
function reportContractFailure(array $errors): never
{
    $errors = array_values(array_unique($errors));
    fwrite(STDERR, "Kumwe quality contract verification failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, ' - ' . $error . "\n");
    }
    exit(1);
}
