<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Governance;

/**
 * Verifies the one Version 2 acceptance record and derives every summary that is read from it.
 *
 * `docs/roadmap/acceptance-record.json` holds one entry per requirement the increment accepts by merge: every
 * open finding in `docs/roadmap/findings.json`, every roadmap work package that is not yet delivered, every Gate
 * B criterion, and the few requirements the maintainer named that have no finding of their own. Each entry links
 * the requirement and its text reference to the runtime that owns it, the tests and CI jobs that prove it, the
 * workflow artifacts that carry the evidence, the decision that governs it, what is still outstanding, its state
 * and the track that owns it. Under ADR 0021 the maintainer's merge after every required check is green is the
 * sole human acceptance, so the record has to be truthful inside the pull request.
 *
 * The record is checked against the files it cites rather than trusted: every path must exist, every CI job must
 * be declared by its workflow, every uploaded artifact must be uploaded by its workflow, every reference anchor
 * must resolve, every finding must match the ledger and every package the README definitions. The derived views
 * — `docs/roadmap/acceptance-summary.md` and the generated blocks of `docs/roadmap/STATUS.md` (phase board, open
 * work by phase, Gate B criteria and ledger snapshot) — are rendered here and never written by hand, so a claim
 * cannot drift from the record that supports it.
 *
 * The class reads only committed files and needs no Composer autoloader, so the gate runs before
 * `composer install`, exactly like the rest of the governance tooling.
 *
 * @phpstan-type Phase array{id: string, name: string, gate: string, blocked_on: string,
 *     delivered_packages: list<string>, history: string, note: string, board: bool}
 * @phpstan-type Model array{record: array<string, mixed>, ledger: array<string, mixed>,
 *     phases: array<string, Phase>, entries: list<array<array-key, mixed>>, ledgerStates: array<string, string>}
 *
 * @since  2.0.0
 */
final readonly class AcceptanceRecord
{
    /**
     * Repository-relative path of the record.
     *
     * @var    string
     * @since  2.0.0
     */
    public const RECORD = 'docs/roadmap/acceptance-record.json';

    /**
     * Repository-relative path of the derived summary page.
     *
     * @var    string
     * @since  2.0.0
     */
    public const SUMMARY = 'docs/roadmap/acceptance-summary.md';

    /**
     * Repository-relative path of the status page whose marked blocks are generated.
     *
     * @var    string
     * @since  2.0.0
     */
    public const STATUS = 'docs/roadmap/STATUS.md';

    /**
     * Repository-relative path of the open findings ledger.
     *
     * @var    string
     * @since  2.0.0
     */
    public const LEDGER = 'docs/roadmap/findings.json';

    /**
     * Repository-relative path of the durable programme specification that defines every package.
     *
     * @var    string
     * @since  2.0.0
     */
    public const README = 'docs/roadmap/README.md';

    /**
     * Repository-relative path of the completed-work record.
     *
     * @var    string
     * @since  2.0.0
     */
    public const CHANGELOG = 'CHANGELOG.md';

    /**
     * The entry states, in the order the summaries report them.
     *
     * `delivered` is on the pull request head, out of the live indexes and cited by the changelog;
     * `pending-integration` is complete on a named agent branch that has not reached the head; `open` still has
     * outstanding work on every branch.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const STATES = ['delivered', 'pending-integration', 'open'];

    /**
     * The entry kinds the record admits.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const KINDS = ['finding', 'package', 'gate-b-criterion', 'requirement'];

    /**
     * The generated STATUS.md blocks, in document order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const STATUS_BLOCKS = ['phase-board', 'open-work', 'gate-b', 'ledger-snapshot'];

    /**
     * Words the roadmap lifecycle check refuses inside the open-work table, which the renderer must never emit.
     *
     * @var    string
     * @since  2.0.0
     */
    private const COMPLETION_MARKERS = '/\b(?:closed|complete|completed|delivered|done|finished|shipped)\b/i';

    /**
     * Bind the verifier to one repository checkout.
     *
     * @param   string  $root  Absolute path of the repository root every record path is relative to.
     *
     * @since   2.0.0
     */
    public function __construct(private string $root)
    {
    }

    /**
     * Verify the record against the ledger, the README, the changelog, the workflows and the working tree.
     *
     * @return  list<string>  One sentence per failure, naming the entry, the rule and the fix; empty when truthful.
     *
     * @since   2.0.0
     */
    public function verify(): array
    {
        $errors = [];
        $record = $this->json(self::RECORD, $errors);
        $ledger = $this->json(self::LEDGER, $errors);
        $readme = $this->text(self::README, $errors);
        // A comment is a placeholder for work still to land; only rendered text counts as a citation.
        $changelog = preg_replace('/<!--.*?-->/s', '', $this->text(self::CHANGELOG, $errors)) ?? '';
        if ($errors !== []) {
            return $errors;
        }

        foreach (['record', 'version', 'authority', 'pull_request', 'branch', 'note', 'states', 'tracks'] as $key) {
            if (!array_key_exists($key, $record)) {
                $errors[] = sprintf('The acceptance record lacks the required key "%s".', $key);
            }
        }
        foreach (['phases', 'board', 'gates', 'entries'] as $key) {
            if (!is_array($record[$key] ?? null)) {
                $errors[] = sprintf('The acceptance record must carry the list or object "%s".', $key);
            }
        }
        if (!is_int($record['pull_request'] ?? null)) {
            $errors[] = 'The acceptance record must name its pull request as an integer.';
        }
        $states = is_array($record['states'] ?? null) ? array_keys($record['states']) : [];
        if ($states !== self::STATES) {
            $errors[] = sprintf(
                'The acceptance record must describe exactly the states %s, in that order.',
                implode(', ', self::STATES),
            );
        }
        $tracks = is_array($record['tracks'] ?? null) ? array_map('strval', array_keys($record['tracks'])) : [];
        if ($tracks === []) {
            $errors[] = 'The acceptance record must describe at least one track.';
        }
        if ($errors !== []) {
            return $errors;
        }

        $packagesDefined = $this->readmePackages($readme);
        $phases = $this->phases($record, $packagesDefined, $errors);
        $this->verifyBoard($record, $phases, $errors);
        $ledgerFindings = $this->ledgerFindings($ledger, $errors);
        $entries = $this->entries($record);
        $byId = [];
        foreach ($entries as $index => $entry) {
            $id = $entry['id'] ?? null;
            if (!is_string($id) || preg_match('/^[A-Z][A-Z0-9]*(?:-[A-Z0-9]+)+$/', $id) !== 1) {
                $errors[] = sprintf('Entry %d has no well-formed identifier.', $index);
                continue;
            }
            if (isset($byId[$id])) {
                $errors[] = sprintf('Entry %s is declared twice; the record holds one entry per requirement.', $id);
                continue;
            }
            $byId[$id] = $entry;
        }

        $deliveredBefore = [];
        foreach ($phases as $phase) {
            foreach ($phase['delivered_packages'] as $package) {
                $deliveredBefore[$package] = true;
            }
        }
        $criteria = [];
        foreach ($byId as $id => $entry) {
            $this->verifyEntry($id, $entry, $tracks, $phases, $changelog, $ledgerFindings, $packagesDefined, $errors);
            foreach ($this->criteriaOf($entry) as $criterion) {
                $criteria[$criterion][] = $id;
            }
            if (isset($deliveredBefore[$id])) {
                $errors[] = sprintf(
                    'Package %s is both an entry and listed as delivered before this record in its phase.',
                    $id,
                );
            }
        }
        $this->verifyLinks($byId, $deliveredBefore, $errors);

        foreach (array_keys($ledgerFindings) as $finding) {
            if (!isset($byId[$finding])) {
                $errors[] = sprintf(
                    'Finding %s is open in %s but has no acceptance entry; add one with its runtime owner, tests, '
                    . 'workflows, decision, state, track and outstanding note.',
                    $finding,
                    self::LEDGER,
                );
            }
        }
        foreach (array_keys($packagesDefined) as $package) {
            if (!isset($byId[$package]) && !isset($deliveredBefore[$package])) {
                $errors[] = sprintf(
                    'Package %s is defined in %s but is neither an acceptance entry nor listed as delivered in its '
                    . 'phase.',
                    $package,
                    self::README,
                );
            }
        }
        for ($criterion = 1; $criterion <= 12; $criterion++) {
            $own = $byId['GB-' . $criterion] ?? null;
            if ($own === null || ($own['kind'] ?? null) !== 'gate-b-criterion') {
                $errors[] = sprintf(
                    'Gate B criterion %d has no GB-%d entry of kind gate-b-criterion.',
                    $criterion,
                    $criterion,
                );
                continue;
            }
            if (($own['state'] ?? null) !== 'delivered') {
                continue;
            }
            if (count($criteria[$criterion] ?? []) < 2) {
                $errors[] = sprintf(
                    'GB-%d is delivered although no requirement entry cites the criterion it would satisfy.',
                    $criterion,
                );
            }
            foreach ($criteria[$criterion] ?? [] as $id) {
                if (($byId[$id]['state'] ?? null) !== 'delivered') {
                    $errors[] = sprintf(
                        'GB-%d is delivered while %s, which cites that criterion, is %s.',
                        $criterion,
                        $id,
                        is_string($byId[$id]['state'] ?? null) ? $byId[$id]['state'] : 'stateless',
                    );
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Render every derived document from a verified record.
     *
     * Call only after `verify()` returned no failure: the renderer trusts the shapes the verifier proved.
     *
     * @return  array<string, string>  Repository-relative path to the complete expected contents.
     *
     * @since   2.0.0
     */
    public function derivedDocuments(): array
    {
        $ignored = [];
        $record = $this->json(self::RECORD, $ignored);
        $ledger = $this->json(self::LEDGER, $ignored);
        $status = $this->text(self::STATUS, $ignored);
        $model = $this->model($record, $ledger);
        $blocks = [
            'phase-board' => $this->renderPhaseBoard($model),
            'open-work' => $this->renderOpenWork($model),
            'gate-b' => $this->renderGateB($model),
            'ledger-snapshot' => $this->renderLedgerSnapshot($model),
        ];

        return [
            self::SUMMARY => $this->renderSummary($model),
            self::STATUS => self::replaceBlocks($status, $blocks),
        ];
    }

    /**
     * Name every derived document whose committed contents differ from what the record renders.
     *
     * @return  list<string>  One failure per stale or unmarked document; empty when every view is current.
     *
     * @since   2.0.0
     */
    public function staleDocuments(): array
    {
        $errors = [];
        $status = $this->text(self::STATUS, $errors);
        foreach (self::STATUS_BLOCKS as $block) {
            $begin = substr_count($status, self::marker($block, 'begin'));
            $end = substr_count($status, self::marker($block, 'end'));
            if ($begin !== 1 || $end !== 1) {
                $errors[] = sprintf(
                    '%s must carry the generated block "%s" exactly once between %s and %s.',
                    self::STATUS,
                    $block,
                    self::marker($block, 'begin'),
                    self::marker($block, 'end'),
                );
            }
        }
        if ($errors !== []) {
            return $errors;
        }
        foreach ($this->derivedDocuments() as $path => $expected) {
            $current = is_file($this->root . '/' . $path) ? file_get_contents($this->root . '/' . $path) : false;
            if (!is_string($current) || !hash_equals($expected, $current)) {
                $errors[] = sprintf(
                    '%s is stale against %s; run composer acceptance:summary and commit the result.',
                    $path,
                    self::RECORD,
                );
            }
        }

        return $errors;
    }

    /**
     * Write every derived document.
     *
     * @return  list<string>  Repository-relative paths whose contents changed.
     *
     * @since   2.0.0
     */
    public function writeDerivedDocuments(): array
    {
        $changed = [];
        foreach ($this->derivedDocuments() as $path => $contents) {
            $absolute = $this->root . '/' . $path;
            $current = is_file($absolute) ? file_get_contents($absolute) : false;
            if ($current === $contents) {
                continue;
            }
            file_put_contents($absolute, $contents);
            $changed[] = $path;
        }

        return $changed;
    }

    /**
     * Count the entries by state and by track for the one-line verdict.
     *
     * @return  array{entries: int, states: array<string, int>, tracks: array<string, int>}  The tallies.
     *
     * @since   2.0.0
     */
    public function counts(): array
    {
        $ignored = [];
        $record = $this->json(self::RECORD, $ignored);
        $states = array_fill_keys(self::STATES, 0);
        $tracks = [];
        foreach (is_array($record['tracks'] ?? null) ? array_keys($record['tracks']) : [] as $track) {
            $tracks[(string) $track] = 0;
        }
        $entries = $this->entries($record);
        foreach ($entries as $entry) {
            $state = is_string($entry['state'] ?? null) ? $entry['state'] : '';
            $track = is_string($entry['track'] ?? null) ? $entry['track'] : '';
            $states[$state] = ($states[$state] ?? 0) + 1;
            $tracks[$track] = ($tracks[$track] ?? 0) + 1;
        }

        return ['entries' => count($entries), 'states' => $states, 'tracks' => $tracks];
    }

    /**
     * Replace the body of every named generated block in a document.
     *
     * @param   string                 $document  Current document contents.
     * @param   array<string, string>  $blocks    Block name to rendered body, without the markers.
     *
     * @return  string  The document with each block's body replaced; unmarked blocks are left untouched.
     *
     * @since   2.0.0
     */
    public static function replaceBlocks(string $document, array $blocks): string
    {
        foreach ($blocks as $name => $body) {
            $begin = self::marker($name, 'begin');
            $end = self::marker($name, 'end');
            $start = strpos($document, $begin);
            $stop = strpos($document, $end);
            if ($start === false || $stop === false || $stop < $start) {
                continue;
            }
            $document = substr($document, 0, $start + strlen($begin)) . "\n" . rtrim($body, "\n") . "\n"
                . substr($document, $stop);
        }

        return $document;
    }

    /**
     * The HTML comment that opens or closes one generated block.
     *
     * @param   string  $block  Block name from STATUS_BLOCKS.
     * @param   string  $edge   Either `begin` or `end`.
     *
     * @return  string  The marker line, invisible in rendered Markdown.
     *
     * @since   2.0.0
     */
    public static function marker(string $block, string $edge): string
    {
        return sprintf('<!-- acceptance-record:%s:%s -->', $block, $edge);
    }

    /**
     * The anchor GitHub gives a Markdown heading.
     *
     * @param   string  $heading  Heading text without the leading hashes.
     *
     * @return  string  Lower-case slug with punctuation removed and spaces turned into hyphens.
     *
     * @since   2.0.0
     */
    public static function slug(string $heading): string
    {
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', trim($heading)) ?? $heading;
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $text) ?? $text;

        return str_replace(' ', '-', $text);
    }

    /**
     * Verify one entry's own shape, paths, workflows, reference and state rules.
     *
     * @param   string                                    $id              Entry identifier.
     * @param   array<array-key, mixed>                   $entry           Decoded entry.
     * @param   list<string>                              $tracks          Declared track identifiers.
     * @param   array<string, array<string, mixed>>       $phases          Declared phases by identifier.
     * @param   string                                    $changelog       Changelog contents.
     * @param   array<string, array<array-key, mixed>>    $ledgerFindings  Open ledger findings by identifier.
     * @param   array<string, true>                       $packages        Package identifiers the README defines.
     * @param   list<string>                              $errors          Accumulated failures.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function verifyEntry(
        string $id,
        array $entry,
        array $tracks,
        array $phases,
        string $changelog,
        array $ledgerFindings,
        array $packages,
        array &$errors,
    ): void {
        $kind = is_string($entry['kind'] ?? null) ? $entry['kind'] : '';
        $state = is_string($entry['state'] ?? null) ? $entry['state'] : '';
        if (!in_array($kind, self::KINDS, true)) {
            $errors[] = sprintf(
                'Entry %s carries the unknown kind "%s"; use one of %s.',
                $id,
                $kind,
                implode(', ', self::KINDS),
            );
            return;
        }
        if (!in_array($state, self::STATES, true)) {
            $errors[] = sprintf(
                'Entry %s carries the unknown state "%s"; use one of %s.',
                $id,
                $state,
                implode(', ', self::STATES),
            );
            return;
        }
        foreach (['requirement', 'reference', 'decision'] as $key) {
            if (!is_string($entry[$key] ?? null) || trim($entry[$key]) === '') {
                $errors[] = sprintf('Entry %s must carry a non-empty %s.', $id, $key);
            }
        }
        $track = $entry['track'] ?? null;
        if (!is_string($track) || !in_array($track, $tracks, true)) {
            $errors[] = sprintf('Entry %s names a track the record does not declare.', $id);
        }
        $lists = ['runtime_owner', 'tests', 'workflows', 'artifacts', 'branches', 'branch_evidence', 'gate_b_criteria'];
        foreach ($lists as $key) {
            if (!is_array($entry[$key] ?? null) || !array_is_list($entry[$key])) {
                $errors[] = sprintf('Entry %s must carry the list %s.', $id, $key);
                return;
            }
        }
        /** @var list<mixed> $owners */
        $owners = $entry['runtime_owner'];
        /** @var list<mixed> $tests */
        $tests = $entry['tests'];
        /** @var list<mixed> $workflows */
        $workflows = $entry['workflows'];
        /** @var list<mixed> $artifacts */
        $artifacts = $entry['artifacts'];
        /** @var list<mixed> $branches */
        $branches = $entry['branches'];
        foreach (['runtime_owner' => $owners, 'tests' => $tests] as $key => $paths) {
            foreach ($paths as $path) {
                $exists = is_string($path) && $path !== '' && !str_contains($path, '..')
                    && file_exists($this->root . '/' . $path);
                if (!$exists) {
                    $errors[] = sprintf(
                        'Entry %s names the missing %s path %s.',
                        $id,
                        $key,
                        is_string($path) ? $path : '(not a string)',
                    );
                }
            }
        }
        foreach ($workflows as $workflow) {
            $this->verifyWorkflowJob($id, $workflow, $errors);
        }
        foreach ($artifacts as $artifact) {
            $this->verifyArtifact($id, $artifact, $errors);
        }
        foreach ($branches as $branch) {
            if (!is_string($branch) || preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$#', $branch) !== 1) {
                $errors[] = sprintf('Entry %s names a malformed branch.', $id);
            }
        }
        /** @var list<mixed> $branchEvidence */
        $branchEvidence = $entry['branch_evidence'];
        foreach ($branchEvidence as $path) {
            if (!is_string($path) || $path === '' || str_contains($path, '..')) {
                $errors[] = sprintf('Entry %s carries a malformed branch_evidence path.', $id);
            }
        }
        if ($branchEvidence !== [] && $branches === []) {
            $errors[] = sprintf(
                'Entry %s lists branch_evidence without naming the branch that carries it.',
                $id,
            );
        }
        $reference = is_string($entry['reference'] ?? null) ? $entry['reference'] : '';
        if ($reference !== '') {
            $this->verifyReference($id, $reference, $errors);
        }
        foreach ($this->criteriaOf($entry) as $criterion) {
            if ($criterion < 1 || $criterion > 12) {
                $errors[] = sprintf('Entry %s cites a Gate B criterion outside 1 through 12.', $id);
            }
        }
        if (count($this->criteriaOf($entry)) !== count($entry['gate_b_criteria'])) {
            $errors[] = sprintf('Entry %s cites a Gate B criterion that is not an integer.', $id);
        }

        $phase = $entry['phase'] ?? null;
        $outstanding = $entry['outstanding'] ?? null;
        $cited = preg_match('/(?<![A-Za-z0-9-])' . preg_quote($id, '/') . '(?![A-Za-z0-9])/', $changelog) === 1;
        if ($state === 'delivered') {
            if ($outstanding !== null) {
                $errors[] = sprintf('Entry %s is delivered and may not carry an outstanding note.', $id);
            }
            if ($branches !== [] || $branchEvidence !== []) {
                $errors[] = sprintf(
                    'Entry %s is delivered on the head and may not name a pending branch or branch evidence; move '
                    . 'the landed evidence into tests, workflows and artifacts.',
                    $id,
                );
            }
            $proven = array_filter(
                $tests,
                static fn(mixed $path): bool => is_string($path) && str_starts_with($path, 'tests/'),
            );
            if ($proven === []) {
                $errors[] = sprintf('Entry %s is delivered without naming a test under tests/.', $id);
            }
            if ($workflows === []) {
                $errors[] = sprintf('Entry %s is delivered without naming the CI job that runs its proof.', $id);
            }
            if (!$cited && $kind !== 'gate-b-criterion') {
                $errors[] = sprintf('Entry %s is delivered but %s never cites it.', $id, self::CHANGELOG);
            }
        } else {
            if (!is_string($outstanding) || trim($outstanding) === '') {
                $errors[] = sprintf('Entry %s is %s and must say what is outstanding.', $id, $state);
            }
            if ($state === 'pending-integration' && $branches === []) {
                $errors[] = sprintf('Entry %s is pending integration and must name the branch that carries it.', $id);
            }
        }

        if ($kind === 'finding') {
            if (preg_match('/^(?:V2|V3|GM)-[A-Z]+-\d{2,3}$/', $id) !== 1) {
                $errors[] = sprintf('Finding entry %s does not carry a finding identifier.', $id);
            }
            $inLedger = isset($ledgerFindings[$id]);
            if ($state === 'delivered' && $inLedger) {
                $errors[] = sprintf(
                    'Entry %s is delivered but still sits in %s; delete it from the ledger in the same change.',
                    $id,
                    self::LEDGER,
                );
            }
            if ($state !== 'delivered' && !$inLedger) {
                $errors[] = sprintf('Entry %s is %s but %s no longer carries it.', $id, $state, self::LEDGER);
            }
            if ($inLedger && ($ledgerFindings[$id]['phase'] ?? null) !== $phase) {
                $errors[] = sprintf(
                    'Entry %s names phase %s, but the ledger places it in phase %s.',
                    $id,
                    self::show($phase),
                    self::show($ledgerFindings[$id]['phase'] ?? null),
                );
            }
            if (!is_array($entry['packages'] ?? null)) {
                $errors[] = sprintf('Finding entry %s must carry the list packages.', $id);
            }
        } elseif ($kind === 'package') {
            if (!isset($packages[$id])) {
                $errors[] = sprintf('Package entry %s is not defined in %s.', $id, self::README);
            }
            if ($phase !== self::packagePhase($id)) {
                $errors[] = sprintf('Package entry %s must name phase %s.', $id, self::packagePhase($id));
            }
            if (!is_array($entry['findings'] ?? null)) {
                $errors[] = sprintf('Package entry %s must carry the list findings.', $id);
            }
        } elseif ($kind === 'gate-b-criterion') {
            if (preg_match('/^GB-(\d{1,2})$/', $id, $match) !== 1 || $this->criteriaOf($entry) !== [(int) $match[1]]) {
                $errors[] = sprintf('Gate B entry %s must be named GB-n and cite exactly criterion n.', $id);
            }
            if ($phase !== null) {
                $errors[] = sprintf('Gate B entry %s belongs to no phase; set phase to null.', $id);
            }
        }
        if ($kind !== 'gate-b-criterion' && (!is_string($phase) || !isset($phases[$phase]))) {
            $errors[] = sprintf('Entry %s names a phase the record does not declare.', $id);
        }
    }

    /**
     * Verify that packages and findings name each other, and that no package is delivered ahead of its findings.
     *
     * @param   array<string, array<array-key, mixed>>  $byId             Entries by identifier.
     * @param   array<string, true>                     $deliveredBefore  Packages delivered before this record.
     * @param   list<string>                            $errors           Accumulated failures.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function verifyLinks(array $byId, array $deliveredBefore, array &$errors): void
    {
        foreach ($byId as $id => $entry) {
            $kind = $entry['kind'] ?? null;
            if ($kind === 'finding') {
                foreach (is_array($entry['packages'] ?? null) ? $entry['packages'] : [] as $package) {
                    if (!is_string($package)) {
                        continue;
                    }
                    if (isset($deliveredBefore[$package])) {
                        continue;
                    }
                    $owner = $byId[$package] ?? null;
                    $listed = is_array($owner['findings'] ?? null) ? $owner['findings'] : [];
                    if ($owner === null || ($owner['kind'] ?? null) !== 'package' || !in_array($id, $listed, true)) {
                        $errors[] = sprintf(
                            'Finding entry %s names package %s, which is not a package entry listing it back.',
                            $id,
                            $package,
                        );
                    }
                }
            }
            if ($kind !== 'package') {
                continue;
            }
            foreach (is_array($entry['findings'] ?? null) ? $entry['findings'] : [] as $finding) {
                if (!is_string($finding)) {
                    continue;
                }
                $owned = $byId[$finding] ?? null;
                $named = is_array($owned['packages'] ?? null) ? $owned['packages'] : [];
                if ($owned === null || ($owned['kind'] ?? null) !== 'finding' || !in_array($id, $named, true)) {
                    $errors[] = sprintf(
                        'Package entry %s lists finding %s, which is not a finding entry naming the package.',
                        $id,
                        $finding,
                    );
                    continue;
                }
                if (($entry['state'] ?? null) === 'delivered' && ($owned['state'] ?? null) !== 'delivered') {
                    $errors[] = sprintf('Package %s is delivered while its finding %s is not.', $id, $finding);
                }
            }
        }
    }

    /**
     * Verify that a workflow reference names a job its workflow file declares.
     *
     * @param   string        $id        Entry identifier.
     * @param   mixed         $workflow  Reference in the form `.github/workflows/<file>.yml#<job-id>`.
     * @param   list<string>  $errors    Accumulated failures.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function verifyWorkflowJob(string $id, mixed $workflow, array &$errors): void
    {
        $pattern = '#^(\.github/workflows/[A-Za-z0-9._-]+\.ya?ml)\#([A-Za-z0-9_-]+)$#';
        if (!is_string($workflow) || preg_match($pattern, $workflow, $match) !== 1) {
            $errors[] = sprintf(
                'Entry %s carries a malformed workflow reference; use .github/workflows/<file>.yml#<job-id>.',
                $id,
            );
            return;
        }
        $contents = is_file($this->root . '/' . $match[1]) ? file_get_contents($this->root . '/' . $match[1]) : false;
        if (!is_string($contents)) {
            $errors[] = sprintf('Entry %s names the missing workflow %s.', $id, $match[1]);
            return;
        }
        if (!array_key_exists($match[2], self::workflowJobs($contents))) {
            $errors[] = sprintf('Entry %s names the job %s, which %s does not declare.', $id, $match[2], $match[1]);
        }
    }

    /**
     * Verify that an artifact reference is a committed file or an artifact its workflow uploads.
     *
     * @param   string        $id        Entry identifier.
     * @param   mixed         $artifact  Repository path, or `.github/workflows/<file>.yml#<uploaded-artifact-name>`.
     * @param   list<string>  $errors    Accumulated failures.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function verifyArtifact(string $id, mixed $artifact, array &$errors): void
    {
        if (!is_string($artifact) || $artifact === '' || str_contains($artifact, '..')) {
            $errors[] = sprintf('Entry %s carries a malformed artifact reference.', $id);
            return;
        }
        $parts = explode('#', $artifact, 2);
        $file = $parts[0];
        $name = $parts[1] ?? null;
        if (!file_exists($this->root . '/' . $file)) {
            $errors[] = sprintf('Entry %s names the missing artifact source %s.', $id, $file);
            return;
        }
        if ($name === null) {
            return;
        }
        $contents = file_get_contents($this->root . '/' . $file);
        if (!is_string($contents) || !in_array($name, self::uploadedArtifacts($contents), true)) {
            $errors[] = sprintf('Entry %s names the artifact %s, which %s never uploads.', $id, $name, $file);
        }
    }

    /**
     * Verify that a text reference resolves: the file exists and its anchor is a heading or a ledger identifier.
     *
     * @param   string        $id         Entry identifier.
     * @param   string        $reference  `path`, `path#heading-slug` or `path#identifier` for a JSON document.
     * @param   list<string>  $errors     Accumulated failures.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function verifyReference(string $id, string $reference, array &$errors): void
    {
        $parts = explode('#', $reference, 2);
        $file = $parts[0];
        $anchor = $parts[1] ?? null;
        $contents = !str_contains($file, '..') && is_file($this->root . '/' . $file)
            ? file_get_contents($this->root . '/' . $file)
            : false;
        if (!is_string($contents)) {
            $errors[] = sprintf('Entry %s references the missing document %s.', $id, $file);
            return;
        }
        if ($anchor === null) {
            return;
        }
        if (str_ends_with($file, '.json')) {
            if (!str_contains($contents, sprintf('"id": "%s"', $anchor))) {
                $errors[] = sprintf('Entry %s references %s, which carries no identifier %s.', $id, $file, $anchor);
            }
            return;
        }
        if (!in_array($anchor, self::headingSlugs($contents), true)) {
            $errors[] = sprintf(
                'Entry %s references the anchor #%s, which no heading of %s produces.',
                $id,
                $anchor,
                $file,
            );
        }
    }

    /**
     * Verify the phase list and return it keyed by identifier.
     *
     * @param   array<string, mixed>  $record    Decoded record.
     * @param   array<string, true>   $packages  Package identifiers the README defines.
     * @param   list<string>          $errors    Accumulated failures.
     *
     * @return  array<string, Phase>  Declared phases by identifier, in record order.
     *
     * @since   2.0.0
     */
    private function phases(array $record, array $packages, array &$errors): array
    {
        $phases = [];
        foreach (is_array($record['phases'] ?? null) ? $record['phases'] : [] as $index => $phase) {
            $id = is_array($phase) ? ($phase['id'] ?? null) : null;
            if (!is_array($phase) || !is_string($id) || $id === '' || isset($phases[$id])) {
                $errors[] = sprintf('Phase %s is malformed or declared twice.', is_string($id) ? $id : (string) $index);
                continue;
            }
            foreach (['name', 'gate', 'blocked_on'] as $key) {
                if (!is_string($phase[$key] ?? null) || $phase[$key] === '') {
                    $errors[] = sprintf('Phase %s must carry a non-empty %s.', $id, $key);
                }
            }
            $delivered = [];
            foreach (is_array($phase['delivered_packages'] ?? null) ? $phase['delivered_packages'] : [] as $package) {
                if (!is_string($package) || !isset($packages[$package])) {
                    $errors[] = sprintf(
                        'Phase %s lists %s as delivered, which %s does not define.',
                        $id,
                        is_string($package) ? $package : '(not a string)',
                        self::README,
                    );
                    continue;
                }
                if (self::packagePhase($package) !== $id) {
                    $errors[] = sprintf(
                        'Phase %s lists %s, which belongs to phase %s.',
                        $id,
                        $package,
                        self::packagePhase($package),
                    );
                }
                $delivered[] = $package;
            }
            $phases[$id] = [
                'id' => $id,
                'name' => is_string($phase['name'] ?? null) ? $phase['name'] : '',
                'gate' => is_string($phase['gate'] ?? null) ? $phase['gate'] : '',
                'blocked_on' => is_string($phase['blocked_on'] ?? null) ? $phase['blocked_on'] : '',
                'delivered_packages' => $delivered,
                'history' => is_string($phase['history'] ?? null) ? $phase['history'] : '',
                'note' => is_string($phase['note'] ?? null) ? $phase['note'] : '',
                'board' => ($phase['board'] ?? true) !== false,
            ];
        }

        return $phases;
    }

    /**
     * Verify that the phase board order names every board phase once and both gates.
     *
     * @param   array<string, mixed>                  $record  Decoded record.
     * @param   array<string, array<string, mixed>>   $phases  Declared phases by identifier.
     * @param   list<string>                          $errors  Accumulated failures.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function verifyBoard(array $record, array $phases, array &$errors): void
    {
        $board = is_array($record['board'] ?? null) ? $record['board'] : [];
        $gates = is_array($record['gates'] ?? null) ? $record['gates'] : [];
        $expected = array_map('strval', array_keys(array_filter(
            $phases,
            static fn(array $phase): bool => ($phase['board'] ?? true) === true,
        )));
        $named = array_values(array_filter(
            $board,
            static fn(mixed $row): bool => is_string($row) && isset($phases[$row]),
        ));
        sort($expected, SORT_STRING);
        $sorted = $named;
        sort($sorted, SORT_STRING);
        if ($sorted !== $expected || count($named) !== count(array_unique($named))) {
            $errors[] = 'The board must name every board phase exactly once.';
        }
        foreach (['Gate A', 'Gate B'] as $gate) {
            if (!in_array($gate, $board, true) || !is_array($gates[$gate] ?? null)) {
                $errors[] = sprintf('The board and the gates object must both carry %s.', $gate);
            }
        }
        foreach ($board as $row) {
            if (!is_string($row) || (!isset($phases[$row]) && !isset($gates[$row]))) {
                $errors[] = 'The board names a row that is neither a phase nor a gate.';
            }
        }
        $gateA = is_array($gates['Gate A'] ?? null) ? $gates['Gate A'] : [];
        if (!is_string($gateA['state'] ?? null) || $gateA['state'] === '') {
            $errors[] = 'Gate A must carry its state on the board.';
        }
    }

    /**
     * Index the open ledger findings by identifier.
     *
     * @param   array<string, mixed>  $ledger  Decoded findings ledger.
     * @param   list<string>          $errors  Accumulated failures.
     *
     * @return  array<string, array<array-key, mixed>>  Findings by identifier.
     *
     * @since   2.0.0
     */
    private function ledgerFindings(array $ledger, array &$errors): array
    {
        $findings = [];
        foreach (is_array($ledger['findings'] ?? null) ? $ledger['findings'] : [] as $finding) {
            if (is_array($finding) && is_string($finding['id'] ?? null)) {
                $findings[$finding['id']] = $finding;
            }
        }
        if ($findings === []) {
            $errors[] = sprintf('%s carries no findings to reconcile against.', self::LEDGER);
        }

        return $findings;
    }

    /**
     * The package identifiers the README defines as work packages.
     *
     * @param   string  $readme  README contents.
     *
     * @return  array<string, true>  Package identifiers.
     *
     * @since   2.0.0
     */
    private function readmePackages(string $readme): array
    {
        preg_match_all('/^\*\*((?:P[0-9]|PE|PL|S)-[A-Z])\s+—/mu', $readme, $matches);

        return array_fill_keys($matches[1], true);
    }

    /**
     * The phase a package identifier belongs to.
     *
     * @param   string  $package  Package identifier such as `P5-A`, `PL-G` or `S-E`.
     *
     * @return  string  Phase identifier: a digit, `E`, `L` or `S`.
     *
     * @since   2.0.0
     */
    private static function packagePhase(string $package): string
    {
        $prefix = explode('-', $package, 2)[0];

        return match (true) {
            $prefix === 'S' => 'S',
            $prefix === 'PL' => 'L',
            $prefix === 'PE' => 'E',
            default => substr($prefix, 1),
        };
    }

    /**
     * The Gate B criteria an entry cites, as integers.
     *
     * @param   array<array-key, mixed>  $entry  Decoded entry.
     *
     * @return  list<int>  Cited criteria.
     *
     * @since   2.0.0
     */
    private function criteriaOf(array $entry): array
    {
        $criteria = [];
        foreach (is_array($entry['gate_b_criteria'] ?? null) ? $entry['gate_b_criteria'] : [] as $criterion) {
            if (is_int($criterion)) {
                $criteria[] = $criterion;
            }
        }

        return $criteria;
    }

    /**
     * The job identifiers and display names a workflow declares.
     *
     * @param   string  $workflow  Workflow YAML.
     *
     * @return  array<string, string>  Job identifier to its `name:` (the identifier when it has none).
     *
     * @since   2.0.0
     */
    private static function workflowJobs(string $workflow): array
    {
        $jobs = [];
        $inJobs = false;
        $current = null;
        foreach (explode("\n", $workflow) as $line) {
            if (preg_match('/^jobs:\s*$/', $line) === 1) {
                $inJobs = true;
                continue;
            }
            if (!$inJobs) {
                continue;
            }
            if (preg_match('/^\S/', $line) === 1) {
                break;
            }
            if (preg_match('/^  ([A-Za-z0-9_-]+):\s*(?:#.*)?$/', $line, $match) === 1) {
                $current = $match[1];
                $jobs[$current] = $current;
                continue;
            }
            $unnamed = $current !== null && $jobs[$current] === $current;
            if ($unnamed && preg_match('/^    name:\s*(.+?)\s*$/', $line, $match) === 1) {
                $jobs[$current] = trim($match[1], "\"'");
            }
        }

        return $jobs;
    }

    /**
     * The artifact names a workflow uploads through `actions/upload-artifact`.
     *
     * @param   string  $workflow  Workflow YAML.
     *
     * @return  list<string>  Uploaded artifact names, expressions included verbatim.
     *
     * @since   2.0.0
     */
    private static function uploadedArtifacts(string $workflow): array
    {
        $names = [];
        $lines = explode("\n", $workflow);
        foreach ($lines as $index => $line) {
            if (preg_match('#uses:\s*actions/upload-artifact@#', $line) !== 1) {
                continue;
            }
            $inWith = false;
            for ($next = $index + 1; $next < min(count($lines), $index + 25); $next++) {
                if (preg_match('/^\s*-\s/', $lines[$next]) === 1) {
                    break;
                }
                if (preg_match('/^\s*with:\s*$/', $lines[$next]) === 1) {
                    $inWith = true;
                    continue;
                }
                if ($inWith && preg_match('/^\s+name:\s*(.+?)\s*$/', $lines[$next], $match) === 1) {
                    $names[] = trim($match[1], "\"'");
                    break;
                }
            }
        }

        return $names;
    }

    /**
     * The anchors every heading of a Markdown document produces, with GitHub's duplicate suffixes.
     *
     * @param   string  $markdown  Markdown document.
     *
     * @return  list<string>  Heading anchors.
     *
     * @since   2.0.0
     */
    private static function headingSlugs(string $markdown): array
    {
        $slugs = [];
        $seen = [];
        $fenced = false;
        foreach (explode("\n", $markdown) as $line) {
            if (str_starts_with($line, '```')) {
                $fenced = !$fenced;
                continue;
            }
            if ($fenced || preg_match('/^#{1,6}\s+(.+?)\s*#*\s*$/u', $line, $match) !== 1) {
                continue;
            }
            $slug = self::slug($match[1]);
            $count = $seen[$slug] ?? 0;
            $seen[$slug] = $count + 1;
            $slugs[] = $count === 0 ? $slug : $slug . '-' . $count;
        }

        return $slugs;
    }

    /**
     * Build the normalized view model every renderer reads.
     *
     * @param   array<string, mixed>  $record  Decoded, verified record.
     * @param   array<string, mixed>  $ledger  Decoded ledger.
     *
     * @return  Model  Record, ledger, phases, entries and ledger states.
     *
     * @since   2.0.0
     */
    private function model(array $record, array $ledger): array
    {
        $ignored = [];
        $ledgerStates = [];
        foreach ($this->ledgerFindings($ledger, $ignored) as $id => $finding) {
            $ledgerStates[$id] = is_string($finding['state'] ?? null) ? $finding['state'] : '';
        }

        return [
            'record' => $record,
            'ledger' => $ledger,
            'phases' => $this->phases($record, $this->readmePackages($this->text(self::README, $ignored)), $ignored),
            'entries' => $this->entries($record),
            'ledgerStates' => $ledgerStates,
        ];
    }

    /**
     * Render the phase board rows between the board markers.
     *
     * @param   Model  $model  View model from model().
     *
     * @return  string  Markdown table.
     *
     * @since   2.0.0
     */
    private function renderPhaseBoard(array $model): string
    {
        $record = $model['record'];
        $phases = $model['phases'];
        $entries = $model['entries'];
        $ledgerStates = $model['ledgerStates'];
        /** @var array<string, array<string, mixed>> $gates */
        $gates = is_array($record['gates'] ?? null) ? $record['gates'] : [];
        $lines = ['| Phase | Gate | State | Blocked on |', '|---|---|---|---|'];
        foreach (is_array($record['board'] ?? null) ? $record['board'] : [] as $row) {
            if (!is_string($row)) {
                continue;
            }
            if (isset($gates[$row])) {
                $gate = $gates[$row];
                $state = $row === 'Gate B'
                    ? $this->gateBState($entries)
                    : (is_string($gate['state'] ?? null) ? $gate['state'] : '');
                $lines[] = sprintf(
                    '| **%s** | | **%s** | %s |',
                    $row,
                    $state,
                    is_string($gate['blocked_on'] ?? null) ? $gate['blocked_on'] : '—',
                );
                continue;
            }
            $phase = $phases[$row];
            $lines[] = sprintf(
                '| %s — %s | %s | %s | %s |',
                $row,
                self::cell($phase['name']),
                self::cell($phase['gate']),
                self::cell($this->phaseState($phase, $entries, $ledgerStates)),
                self::cell($phase['blocked_on']),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Derive one phase's board state from its entries.
     *
     * A phase with nothing outstanding reads `Delivered`; one whose every entry is untouched reads `Not started`;
     * anything else reads `In progress` and names the packages by state. No phase with open or pending work can
     * ever read as delivered, which is the rule the roadmap lifecycle check enforces from the other side.
     *
     * @param   Phase                              $phase         Declared phase.
     * @param   list<array<array-key, mixed>>      $entries       Every entry.
     * @param   array<string, string>              $ledgerStates  Ledger state by finding identifier.
     *
     * @return  string  Board state cell.
     *
     * @since   2.0.0
     */
    private function phaseState(array $phase, array $entries, array $ledgerStates): string
    {
        /** @var list<string> $deliveredBefore */
        $deliveredBefore = $phase['delivered_packages'];
        $own = array_values(array_filter(
            $entries,
            static fn(array $entry): bool => ($entry['phase'] ?? null) === $phase['id'],
        ));
        $packages = ['delivered' => $deliveredBefore, 'pending-integration' => [], 'open' => []];
        $findings = ['delivered' => [], 'pending-integration' => [], 'open' => []];
        $requirements = ['delivered' => [], 'pending-integration' => [], 'open' => []];
        $branches = [];
        $started = $deliveredBefore !== [];
        foreach ($own as $entry) {
            $id = is_string($entry['id'] ?? null) ? $entry['id'] : '';
            $state = is_string($entry['state'] ?? null) ? $entry['state'] : 'open';
            match ($entry['kind'] ?? null) {
                'package' => $packages[$state][] = $id,
                'requirement' => $requirements[$state][] = $id,
                default => $findings[$state][] = $id,
            };
            foreach (is_array($entry['branches'] ?? null) ? $entry['branches'] : [] as $branch) {
                if (is_string($branch)) {
                    $branches[$branch] = true;
                }
            }
            $moving = in_array($ledgerStates[$id] ?? '', ['in_progress', 'verified', 'conditional'], true);
            if ($state !== 'open' || $moving) {
                $started = true;
            }
        }
        $started = $started || $branches !== [];
        $outstanding = count($packages['pending-integration']) + count($packages['open'])
            + count($findings['pending-integration']) + count($findings['open'])
            + count($requirements['pending-integration']) + count($requirements['open']);
        $note = rtrim($phase['note'], '.');
        if ($outstanding === 0) {
            $history = implode('; ', array_filter(
                [$phase['history'], $note],
                static fn(string $part): bool => $part !== '',
            ));

            return 'Delivered' . ($history !== '' ? ' — ' . $history : '');
        }
        if (!$started) {
            return 'Not started' . ($note !== '' ? ' — ' . $note : '');
        }
        foreach ($packages as $group => $ids) {
            sort($ids, SORT_NATURAL);
            $packages[$group] = $ids;
        }
        $parts = [];
        if ($packages['delivered'] !== []) {
            $parts[] = self::codes($packages['delivered']) . ' delivered';
        }
        if ($packages['pending-integration'] !== []) {
            $parts[] = self::codes($packages['pending-integration']) . ' pending integration';
        }
        if ($packages['open'] !== []) {
            $parts[] = self::codes($packages['open']) . ' open';
        }
        $tally = [];
        foreach (['finding' => $findings, 'requirement' => $requirements] as $noun => $groups) {
            foreach (['pending-integration' => 'pending integration', 'open' => 'open'] as $state => $label) {
                $n = count($groups[$state]);
                if ($n > 0) {
                    $tally[] = sprintf('%d %s%s %s', $n, $noun, $n === 1 ? '' : 's', $label);
                }
            }
        }
        if ($tally !== []) {
            $parts[] = implode(', ', $tally);
        }
        if ($branches !== []) {
            ksort($branches);
            $parts[] = 'in flight on ' . self::codes(array_keys($branches));
        }

        if ($note !== '') {
            $parts[] = $note;
        }

        return 'In progress — ' . implode('; ', $parts);
    }

    /**
     * Derive Gate B's board state from its twelve criterion entries.
     *
     * @param   list<array<array-key, mixed>>  $entries  Every entry.
     *
     * @return  string  Board state cell, never an affirmative readiness claim.
     *
     * @since   2.0.0
     */
    private function gateBState(array $entries): string
    {
        $tally = array_fill_keys(self::STATES, 0);
        foreach ($entries as $entry) {
            if (($entry['kind'] ?? null) === 'gate-b-criterion' && is_string($entry['state'] ?? null)) {
                $tally[$entry['state']] = ($tally[$entry['state']] ?? 0) + 1;
            }
        }

        return sprintf(
            'Not assessed — criteria: %d delivered, %d pending integration, %d open',
            $tally['delivered'],
            $tally['pending-integration'],
            $tally['open'],
        );
    }

    /**
     * Render the open work by phase: every package and finding not yet delivered, and who carries it.
     *
     * @param   Model  $model  View model from model().
     *
     * @return  string  Markdown table whose rows carry no completion marker.
     *
     * @since   2.0.0
     */
    private function renderOpenWork(array $model): string
    {
        $phases = $model['phases'];
        $entries = $model['entries'];
        $lines = [
            '| Phase | Packages | Findings and requirements | Track | Pending integration from |',
            '|---|---|---|---|---|',
        ];
        foreach (array_map('strval', array_keys($phases)) as $phase) {
            $packages = [];
            $findings = [];
            $tracks = [];
            $branches = [];
            foreach ($entries as $entry) {
                if (($entry['phase'] ?? null) !== $phase || ($entry['state'] ?? null) === 'delivered') {
                    continue;
                }
                $id = is_string($entry['id'] ?? null) ? $entry['id'] : '';
                if (($entry['kind'] ?? null) === 'package') {
                    $packages[] = $id;
                } else {
                    $findings[] = $id;
                }
                if (is_string($entry['track'] ?? null)) {
                    $tracks[$entry['track']] = true;
                }
                if (($entry['state'] ?? null) === 'pending-integration') {
                    foreach (is_array($entry['branches'] ?? null) ? $entry['branches'] : [] as $branch) {
                        if (is_string($branch)) {
                            $branches[$branch][] = $id;
                        }
                    }
                }
            }
            if ($packages === [] && $findings === []) {
                continue;
            }
            ksort($branches);
            $pending = [];
            foreach ($branches as $branch => $ids) {
                $pending[] = sprintf('`%s`: %s', $branch, self::codes($ids));
            }
            $row = sprintf(
                '| %s | %s | %s | %s | %s |',
                $phase,
                $packages === [] ? '—' : self::codes($packages),
                $findings === [] ? '—' : self::codes($findings),
                self::codes(array_keys($tracks)),
                $pending === [] ? '—' : implode('; ', $pending),
            );
            $lines[] = preg_replace(self::COMPLETION_MARKERS, '', $row) ?? $row;
        }

        return implode("\n", $lines);
    }

    /**
     * Render the Gate B criteria table from the twelve criterion entries and the entries citing them.
     *
     * @param   Model  $model  View model from model().
     *
     * @return  string  Markdown table.
     *
     * @since   2.0.0
     */
    private function renderGateB(array $model): string
    {
        $entries = $model['entries'];
        $byCriterion = [];
        $own = [];
        foreach ($entries as $entry) {
            $id = is_string($entry['id'] ?? null) ? $entry['id'] : '';
            if (($entry['kind'] ?? null) === 'gate-b-criterion') {
                $own[(int) substr($id, 3)] = $entry;
                continue;
            }
            foreach ($this->criteriaOf($entry) as $criterion) {
                $byCriterion[$criterion][] = $entry;
            }
        }
        $lines = [
            '| # | Criterion | State | Track | Entries not yet delivered |',
            '|---|---|---|---|---|',
        ];
        for ($criterion = 1; $criterion <= 12; $criterion++) {
            $entry = $own[$criterion] ?? [];
            $waiting = [];
            foreach ($byCriterion[$criterion] ?? [] as $cited) {
                if (($cited['state'] ?? null) !== 'delivered' && is_string($cited['id'] ?? null)) {
                    $waiting[] = $cited['id'];
                }
            }
            $lines[] = sprintf(
                '| %d | %s | %s | %s | %s |',
                $criterion,
                self::cell(is_string($entry['requirement'] ?? null) ? $entry['requirement'] : ''),
                self::stateLabel($entry),
                is_string($entry['track'] ?? null) ? '`' . $entry['track'] . '`' : '—',
                $waiting === [] ? '—' : self::codes($waiting),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Render the ledger snapshot counts from the findings ledger itself.
     *
     * @param   Model  $model  View model from model().
     *
     * @return  string  Markdown counts.
     *
     * @since   2.0.0
     */
    private function renderLedgerSnapshot(array $model): string
    {
        $ledger = $model['ledger'];
        $phases = $model['phases'];
        $findings = array_values(array_filter(
            is_array($ledger['findings'] ?? null) ? $ledger['findings'] : [],
            'is_array',
        ));
        $lines = [sprintf(
            '**%d open findings** in [`findings.json`](findings.json). The ledger holds open work only.',
            count($findings),
        ), '', '| State | Count |', '|---|---|'];
        $byState = self::tally($findings, 'state');
        foreach (is_array($ledger['states'] ?? null) ? $ledger['states'] : [] as $state) {
            if (is_string($state)) {
                $lines[] = sprintf('| `%s` | %d |', $state, $byState[$state] ?? 0);
            }
        }
        $lines[] = '| `closed` | **not an allowed state** — see [`CHANGELOG.md`](../../CHANGELOG.md) |';
        $lines[] = '';
        $lines[] = '| Phase | Findings |';
        $lines[] = '|---|---|';
        $byPhase = self::tally($findings, 'phase');
        foreach (array_map('strval', array_keys($phases)) as $phase) {
            $lines[] = sprintf('| %s | %d |', $phase, $byPhase[$phase] ?? 0);
        }
        $lines[] = '';
        $lines[] = '| Gate | Findings |';
        $lines[] = '|---|---|';
        $byGate = self::tally($findings, 'gate');
        foreach (['A', 'B', 'none'] as $gate) {
            $lines[] = sprintf('| %s | %d |', $gate, $byGate[$gate] ?? 0);
        }
        $lines[] = '';
        $severity = self::tallyLine(self::tally($findings, 'severity'), ['critical', 'high', 'medium', 'low']);
        $lines[] = sprintf('By severity: %s.', $severity);
        $origin = self::tallyLine(self::tally($findings, 'origin'), ['review', 'gap-matrix', 'new']);
        $lines[] = sprintf('By origin: %s.', $origin);

        return implode("\n", $lines);
    }

    /**
     * Count ledger findings by the value of one field.
     *
     * @param   list<array<array-key, mixed>>  $findings  Ledger findings.
     * @param   string                         $key       Field to tally, such as `state` or `phase`.
     *
     * @return  array<string, int>  Count by value; an absent or empty value counts as `none`.
     *
     * @since   2.0.0
     */
    private static function tally(array $findings, string $key): array
    {
        $counts = [];
        foreach ($findings as $finding) {
            $value = $finding[$key] ?? null;
            $label = is_string($value) && $value !== '' ? $value : 'none';
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Print a tally as one sentence fragment, the named labels first and the rest alphabetically.
     *
     * @param   array<string, int>  $counts  Count by label.
     * @param   list<string>        $order   Labels printed first, in this order, even when zero.
     *
     * @return  string  Fragment such as `1 critical, 16 high, 17 medium, 9 low`.
     *
     * @since   2.0.0
     */
    private static function tallyLine(array $counts, array $order): string
    {
        $parts = [];
        foreach ($order as $key) {
            $parts[] = sprintf('%d %s', $counts[$key] ?? 0, $key);
            unset($counts[$key]);
        }
        ksort($counts);
        foreach ($counts as $key => $count) {
            $parts[] = sprintf('%d %s', $count, $key);
        }

        return implode(', ', $parts);
    }

    /**
     * Render the complete derived summary page.
     *
     * @param   Model  $model  View model from model().
     *
     * @return  string  Markdown document.
     *
     * @since   2.0.0
     */
    private function renderSummary(array $model): string
    {
        $record = $model['record'];
        $entries = $model['entries'];
        /** @var array<string, mixed> $tracks */
        $tracks = is_array($record['tracks'] ?? null) ? $record['tracks'] : [];
        /** @var array<string, mixed> $states */
        $states = is_array($record['states'] ?? null) ? $record['states'] : [];
        $lines = [
            '# Version 2 acceptance summary',
            '',
            'Generated by `composer acceptance:summary` from [`acceptance-record.json`](acceptance-record.json).',
            'Do not edit: `composer acceptance:check` fails when this page, or a generated block of',
            '[`STATUS.md`](STATUS.md), differs from the record.',
            '',
            sprintf(
                '%s. Pull request #%s on `%s`.',
                is_string($record['authority'] ?? null) ? rtrim($record['authority'], '.') : '',
                is_int($record['pull_request'] ?? null) ? (string) $record['pull_request'] : '',
                is_string($record['branch'] ?? null) ? $record['branch'] : '',
            ),
            '',
            '## States',
            '',
            '| State | Meaning | Entries |',
            '|---|---|---|',
        ];
        $counts = $this->counts();
        foreach ($states as $state => $meaning) {
            $lines[] = sprintf(
                '| `%s` | %s | %d |',
                $state,
                self::cell(is_string($meaning) ? $meaning : ''),
                $counts['states'][$state] ?? 0,
            );
        }
        $lines[] = '';
        $lines[] = '## Tracks';
        $lines[] = '';
        $header = '| Track | Scope |';
        $rule = '|---|---|';
        foreach (self::STATES as $state) {
            $header .= ' ' . $state . ' |';
            $rule .= '---|';
        }
        $lines[] = $header;
        $lines[] = $rule;
        foreach ($tracks as $track => $scope) {
            $row = sprintf('| `%s` | %s |', $track, self::cell(is_string($scope) ? $scope : ''));
            foreach (self::STATES as $state) {
                $row .= sprintf(' %d |', count(array_filter(
                    $entries,
                    static fn(array $entry): bool => ($entry['track'] ?? null) === $track
                        && ($entry['state'] ?? null) === $state,
                )));
            }
            $lines[] = $row;
        }
        $lines[] = '';
        $lines[] = '## Gate B criteria';
        $lines[] = '';
        $lines[] = $this->renderGateB($model);
        $lines[] = '';
        $lines[] = '## Requirements';
        $lines[] = '';
        $lines[] = 'Workflow cells name `file#job`; artifact cells name a committed file or `file#uploaded-artifact`.';
        $lines[] = '';
        $lines[] = '| Requirement | Kind · phase · track | State | Runtime owner | Tests | Workflows | Artifacts '
            . '| Decision | Outstanding |';
        $lines[] = '|---|---|---|---|---|---|---|---|---|';
        foreach ($entries as $entry) {
            $lines[] = sprintf(
                '| `%s` — %s<br>[reference](%s) | %s · %s · `%s` | %s | %s | %s | %s | %s | %s | %s |',
                is_string($entry['id'] ?? null) ? $entry['id'] : '',
                self::cell(is_string($entry['requirement'] ?? null) ? $entry['requirement'] : ''),
                self::link(is_string($entry['reference'] ?? null) ? $entry['reference'] : ''),
                is_string($entry['kind'] ?? null) ? $entry['kind'] : '',
                is_string($entry['phase'] ?? null) ? $entry['phase'] : '—',
                is_string($entry['track'] ?? null) ? $entry['track'] : '',
                self::stateLabel($entry),
                self::codeList($entry['runtime_owner'] ?? []),
                self::testsCell($entry),
                self::codeList(self::shortWorkflows($entry['workflows'] ?? [])),
                self::codeList(self::shortWorkflows($entry['artifacts'] ?? [])),
                self::cell(is_string($entry['decision'] ?? null) ? $entry['decision'] : ''),
                is_string($entry['outstanding'] ?? null) ? self::cell($entry['outstanding']) : '—',
            );
        }
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * The state cell of one entry, naming the branches a pending entry waits for.
     *
     * @param   array<array-key, mixed>  $entry  Decoded entry.
     *
     * @return  string  Markdown cell.
     *
     * @since   2.0.0
     */
    private static function stateLabel(array $entry): string
    {
        $state = is_string($entry['state'] ?? null) ? $entry['state'] : '—';
        $branches = array_values(array_filter(
            is_array($entry['branches'] ?? null) ? $entry['branches'] : [],
            'is_string',
        ));
        if ($branches === []) {
            return $state;
        }

        return sprintf('%s (%s)', $state, self::codes($branches));
    }

    /**
     * The tests cell of one entry: the proofs on the head, then the evidence a pending branch carries.
     *
     * @param   array<array-key, mixed>  $entry  Decoded entry.
     *
     * @return  string  Markdown cell.
     *
     * @since   2.0.0
     */
    private static function testsCell(array $entry): string
    {
        $cell = self::codeList($entry['tests'] ?? []);
        $pending = array_values(array_filter(
            is_array($entry['branch_evidence'] ?? null) ? $entry['branch_evidence'] : [],
            'is_string',
        ));
        if ($pending === []) {
            return $cell;
        }

        return ($cell === '—' ? '' : $cell . '<br>') . '*on branch:* ' . self::codeList($pending);
    }

    /**
     * The decoded entries of a record, skipping anything that is not an object.
     *
     * @param   array<string, mixed>  $record  Decoded record.
     *
     * @return  list<array<array-key, mixed>>  Entries in record order.
     *
     * @since   2.0.0
     */
    private function entries(array $record): array
    {
        return array_values(array_filter(
            is_array($record['entries'] ?? null) ? $record['entries'] : [],
            'is_array',
        ));
    }

    /**
     * Decode one committed JSON document into an object, recording a failure instead of throwing.
     *
     * @param   string        $path    Repository-relative path.
     * @param   list<string>  $errors  Accumulated failures.
     *
     * @return  array<string, mixed>  Decoded document, empty on failure.
     *
     * @since   2.0.0
     */
    private function json(string $path, array &$errors): array
    {
        $decoded = json_decode($this->text($path, $errors), true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            $errors[] = sprintf('%s is not a JSON object.', $path);
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Read one committed text document, recording a failure instead of throwing.
     *
     * @param   string        $path    Repository-relative path.
     * @param   list<string>  $errors  Accumulated failures.
     *
     * @return  string  Contents, empty on failure.
     *
     * @since   2.0.0
     */
    private function text(string $path, array &$errors): string
    {
        $contents = is_file($this->root . '/' . $path) ? file_get_contents($this->root . '/' . $path) : false;
        if (!is_string($contents)) {
            $errors[] = sprintf('%s is missing or unreadable.', $path);
            return '';
        }

        return $contents;
    }

    /**
     * Escape a value for one Markdown table cell.
     *
     * @param   string  $value  Plain text.
     *
     * @return  string  Text with pipes escaped and line breaks folded.
     *
     * @since   2.0.0
     */
    private static function cell(string $value): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $value);
    }

    /**
     * Render identifiers as a comma-separated list of inline code.
     *
     * @param   list<string>  $ids  Identifiers.
     *
     * @return  string  Markdown fragment.
     *
     * @since   2.0.0
     */
    private static function codes(array $ids): string
    {
        return implode(', ', array_map(static fn(string $id): string => '`' . $id . '`', $ids));
    }

    /**
     * Render a list of paths or references as line-separated inline code, or a dash when empty.
     *
     * @param   mixed  $items  Decoded list.
     *
     * @return  string  Markdown fragment.
     *
     * @since   2.0.0
     */
    private static function codeList(mixed $items): string
    {
        $strings = array_values(array_filter(is_array($items) ? $items : [], 'is_string'));

        return $strings === [] ? '—' : '`' . implode('`<br>`', $strings) . '`';
    }

    /**
     * Drop the shared `.github/workflows/` prefix from workflow references for a compact table cell.
     *
     * @param   mixed  $items  Decoded list of references.
     *
     * @return  list<string>  References with the prefix removed; committed file paths are unchanged.
     *
     * @since   2.0.0
     */
    private static function shortWorkflows(mixed $items): array
    {
        $short = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_string($item)) {
                $short[] = str_starts_with($item, '.github/workflows/') ? substr($item, 18) : $item;
            }
        }

        return $short;
    }

    /**
     * Turn a repository-relative reference into a link relative to `docs/roadmap/`.
     *
     * @param   string  $reference  Repository-relative path with an optional anchor.
     *
     * @return  string  Relative link target.
     *
     * @since   2.0.0
     */
    private static function link(string $reference): string
    {
        return str_starts_with($reference, 'docs/roadmap/')
            ? substr($reference, strlen('docs/roadmap/'))
            : '../../' . $reference;
    }

    /**
     * Render a decoded scalar for a failure message.
     *
     * @param   mixed  $value  Decoded value.
     *
     * @return  string  Printable form.
     *
     * @since   2.0.0
     */
    private static function show(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : 'none';
    }
}
