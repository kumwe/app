<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Holds the P7-C security qualification matrix to the evidence it claims.
 *
 * `docs/security/qualification-matrix.json` maps every area and sub-area of the roadmap's P7-C sentence to the
 * automated tests that prove it and the CI job that runs each one. A matrix is only worth its references, so
 * this gate fails when an area or sub-area goes missing, when a sub-area carries neither evidence nor a
 * reasoned out-of-scope entry owned by an open finding, when a referenced test file or method does not
 * exist, or when the named job does not actually run that test. The validator is also driven against
 * deliberately broken copies, so the gate is shown to refuse what it claims to refuse.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class SecurityQualificationMatrixTest extends TestCase
{
    /**
     * Matrix path relative to the repository root.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string MATRIX = 'docs/security/qualification-matrix.json';

    /**
     * Every P7-C area and the sub-areas the roadmap sentence names for it, in roadmap order.
     *
     * The Studio area is the roadmap's separate clause that P7-C covers the preview and media boundaries.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private const array CANONICAL = [
        'authentication-session' => [
            'credential-verification',
            'session-cookie-posture',
            'session-expiry-and-rotation',
            'credential-change-revocation',
            'bearer-token-binding',
            'self-service-reach',
        ],
        'request-boundaries' => ['request-forgery', 'content-policy', 'upload', 'traversal', 'server-side-request'],
        'input-bounds' => ['query', 'identifier', 'pagination', 'exhaustion'],
        'non-disclosure' => ['row', 'field', 'action', 'report', 'export', 'event', 'log'],
        'authority-proof' => ['maker-checker', 'separation-of-duty', 'step-up', 'human-proof', 'confused-deputy'],
        'idempotency' => ['conflict', 'operation-ownership'],
        'extension-trust' => ['signature', 'trust-rotation', 'revocation', 'generation-fencing'],
        'ambient-authority' => ['ambient-authority', 'out-of-process'],
        'secrets' => ['sources', 'rotation', 'redaction'],
        'audit' => ['tamper-evidence', 'archival'],
        'tenant-isolation' => ['concurrency', 'noisy-neighbour'],
        'studio-boundaries' => ['preview', 'media'],
    ];

    /**
     * The committed matrix names every roadmap area and sub-area, and its statement is the roadmap's own.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheMatrixCoversEveryP7CAreaAndSubArea(): void
    {
        $matrix = $this->matrix();
        $areas = [];
        foreach ($this->list($matrix['areas'] ?? null) as $area) {
            self::assertIsArray($area);
            $subAreas = [];
            foreach ($this->list($area['sub_areas'] ?? null) as $subArea) {
                self::assertIsArray($subArea);
                $subAreas[] = $subArea['id'] ?? null;
            }
            $areas[$area['id'] ?? ''] = $subAreas;
        }

        self::assertSame(self::CANONICAL, $areas);
        $statement = $matrix['statement'] ?? null;
        self::assertIsString($statement);
        $roadmap = (string) preg_replace('/\s+/', ' ', $this->contents('docs/roadmap/README.md'));
        self::assertStringContainsString(
            $statement,
            $roadmap,
            'The matrix statement must be the P7-C sentence exactly as the roadmap words it.',
        );
    }

    /**
     * Every sub-area is evidenced by existing tests its jobs run, or is reasoned out of scope.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryReferenceExistsRunsInItsJobAndEveryGapIsReasoned(): void
    {
        self::assertSame([], $this->violations($this->matrix()));
    }

    /**
     * The gate refuses a missing test, a missing method, a wrong job and an unreasoned gap.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateRefusesMissingEvidenceAndUnreasonedGaps(): void
    {
        $matrix = $this->matrix();
        $valid = $matrix['areas'][0]['sub_areas'][0]['evidence'][0] ?? null;
        self::assertIsArray($valid);
        $cases = [
            'a test file that does not exist' => [
                ['test' => 'tests/Integration/Security/NoSuchTest.php::testNothing'] + $valid,
                'does not exist',
            ],
            'a method the file does not declare' => [
                ['test' => 'tests/Architecture/SecurityQualificationMatrixTest.php::testNoSuchMethod'] + $valid,
                'does not declare',
            ],
            'an integration test claimed by the unit job' => [
                [
                    'test' => 'tests/Integration/Identity/SessionIdleExpiryIntegrationTest.php'
                        . '::testIdleAndAbsoluteExpiryHoldForAdministratorAndPortalSessions',
                    'job' => '.github/workflows/ci.yml#quality',
                ] + $valid,
                'does not run',
            ],
            'a test the security job never names' => [
                ['job' => '.github/workflows/security.yml#source-and-image'] + $valid,
                'does not run',
            ],
            'an undeclared job' => [['job' => '.github/workflows/ci.yml#nowhere'] + $valid, 'undeclared job'],
            'an evidence entry that proves nothing' => [['proves' => ''] + $valid, 'states nothing it proves'],
        ];
        foreach ($cases as $label => [$evidence, $expected]) {
            $broken = $matrix;
            $broken['areas'][0]['sub_areas'][0]['evidence'] = [$evidence];
            self::assertStringContainsString($expected, implode("\n", $this->violations($broken)), $label);
        }

        $gap = $matrix;
        $gap['areas'][1]['sub_areas'][2]['evidence'] = [];
        self::assertContains(
            'request-boundaries/upload has no evidence and no reasoned out_of_scope entry.',
            $this->violations($gap),
        );
        $unreasoned = $gap;
        $unreasoned['areas'][1]['sub_areas'][2]['out_of_scope'] = [
            'owner' => 'GM-NOT-01',
            'track' => 'point-5',
            'reason' => 'later',
        ];
        $violations = implode("\n", $this->violations($unreasoned));
        self::assertStringContainsString('request-boundaries/upload out_of_scope needs a reason', $violations);
        self::assertStringContainsString('owner GM-NOT-01 is not an open finding', $violations);
    }

    /**
     * The short documentation page links the matrix and names every area it covers.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDocumentationPageDescribesEveryArea(): void
    {
        $matrix = $this->matrix();
        $page = $matrix['page'] ?? null;
        self::assertIsString($page);
        $contents = $this->contents($page);
        self::assertStringContainsString('qualification-matrix.json', $contents);
        self::assertStringContainsString('SecurityQualificationMatrixTest', $contents);
        foreach ($this->list($matrix['areas'] ?? null) as $area) {
            self::assertIsArray($area);
            self::assertIsString($area['title'] ?? null);
            self::assertStringContainsString($area['title'], $contents, 'The page names every area.');
        }
    }

    /**
     * Collect every rule the matrix breaks.
     *
     * @param   array<mixed, mixed>  $matrix  Decoded matrix, possibly deliberately broken.
     *
     * @return  list<string>  One sentence per violation; empty when the matrix holds.
     *
     * @since   2.0.0
     */
    private function violations(array $matrix): array
    {
        $violations = [];
        if (($matrix['schema_version'] ?? null) !== 1 || ($matrix['package'] ?? null) !== 'P7-C') {
            $violations[] = 'The matrix must declare schema_version 1 for package P7-C.';
        }
        $jobs = $matrix['jobs'] ?? null;
        if (!is_array($jobs) || $jobs === []) {
            return [...$violations, 'The matrix declares no CI jobs.'];
        }
        $openFindings = $this->openFindings();
        $recorded = $this->contents('docs/roadmap/acceptance-record.json');
        foreach ($this->list($matrix['areas'] ?? null) as $area) {
            $areaId = is_array($area) && is_string($area['id'] ?? null) ? $area['id'] : '?';
            $subAreas = is_array($area) ? $this->list($area['sub_areas'] ?? null) : [];
            if ($subAreas === []) {
                $violations[] = sprintf('%s has no sub-areas.', $areaId);
            }
            foreach ($subAreas as $subArea) {
                $subId = is_array($subArea) && is_string($subArea['id'] ?? null) ? $subArea['id'] : '?';
                $name = $areaId . '/' . $subId;
                if (!is_array($subArea)) {
                    $violations[] = sprintf('%s is not an object.', $name);
                    continue;
                }
                foreach ($this->list($subArea['findings'] ?? []) as $finding) {
                    if (!is_string($finding) || !str_contains($recorded, '"' . $finding . '"')) {
                        $violations[] = sprintf('%s cites a finding the acceptance record does not know.', $name);
                    }
                }
                $evidence = $this->list($subArea['evidence'] ?? []);
                $outOfScope = $subArea['out_of_scope'] ?? null;
                if ($outOfScope !== null) {
                    $violations = [...$violations, ...$this->outOfScopeViolations($name, $outOfScope, $openFindings)];
                } elseif ($evidence === []) {
                    $violations[] = sprintf('%s has no evidence and no reasoned out_of_scope entry.', $name);
                }
                foreach ($evidence as $entry) {
                    $violations = [...$violations, ...$this->evidenceViolations($name, $entry, $jobs)];
                }
            }
        }

        return $violations;
    }

    /**
     * Check one out-of-scope entry: an open finding owns it and a real reason explains it.
     *
     * @param   string        $name          `area/sub-area` label.
     * @param   mixed         $outOfScope    Decoded out_of_scope value.
     * @param   list<string>  $openFindings  Identifiers in the live findings ledger.
     *
     * @return  list<string>  Violations found.
     *
     * @since   2.0.0
     */
    private function outOfScopeViolations(string $name, mixed $outOfScope, array $openFindings): array
    {
        if (!is_array($outOfScope)) {
            return [sprintf('%s out_of_scope must be an object.', $name)];
        }
        $violations = [];
        $reason = $outOfScope['reason'] ?? null;
        if (!is_string($reason) || strlen(trim($reason)) < 80) {
            $violations[] = sprintf('%s out_of_scope needs a reason of at least 80 characters.', $name);
        }
        $owner = $outOfScope['owner'] ?? null;
        if (!is_string($owner) || !in_array($owner, $openFindings, true)) {
            $violations[] = sprintf(
                '%s out_of_scope owner %s is not an open finding.',
                $name,
                is_string($owner) ? $owner : '(none)',
            );
        }
        if (!is_string($outOfScope['track'] ?? null) || $outOfScope['track'] === '') {
            $violations[] = sprintf('%s out_of_scope names no track.', $name);
        }

        return $violations;
    }

    /**
     * Check one evidence entry: the test exists, declares the method, and its job runs it.
     *
     * @param   string               $name   `area/sub-area` label.
     * @param   mixed                $entry  Decoded evidence entry.
     * @param   array<mixed, mixed>  $jobs   Declared job descriptions keyed by `workflow#job`.
     *
     * @return  list<string>  Violations found.
     *
     * @since   2.0.0
     */
    private function evidenceViolations(string $name, mixed $entry, array $jobs): array
    {
        if (!is_array($entry)) {
            return [sprintf('%s has an evidence entry that is not an object.', $name)];
        }
        $test = $entry['test'] ?? null;
        $job = $entry['job'] ?? null;
        $reference = '#^(tests/(?:Unit|Integration|Functional|Architecture)/[A-Za-z0-9/]+Test\.php)::(test\w+)$#D';
        if (!is_string($test) || preg_match($reference, $test, $parts) !== 1) {
            return [sprintf('%s names evidence that is not a tests/<suite>/...Test.php::testMethod reference.', $name)];
        }
        $violations = [];
        if (!is_string($entry['proves'] ?? null) || trim($entry['proves']) === '') {
            $violations[] = sprintf('%s evidence %s states nothing it proves.', $name, $test);
        }
        $file = dirname(__DIR__, 2) . '/' . $parts[1];
        if (!is_file($file)) {
            $violations[] = sprintf('%s evidence %s does not exist.', $name, $parts[1]);
        } elseif (preg_match('/\bpublic function ' . $parts[2] . '\(/', (string) file_get_contents($file)) !== 1) {
            $violations[] = sprintf('%s evidence %s does not declare %s().', $name, $parts[1], $parts[2]);
        }
        if (!is_string($job) || !array_key_exists($job, $jobs)) {
            $violations[] = sprintf('%s evidence %s names an undeclared job.', $name, $test);
        } elseif (!$this->jobRuns($job, $parts[1])) {
            $violations[] = sprintf('%s evidence %s: %s does not run it.', $name, $test, $job);
        }

        return $violations;
    }

    /**
     * Decide whether a workflow job actually executes one test file.
     *
     * The quality job runs the unit and architecture suites by name; the database job runs the complete
     * suite; the security job runs only the files its PHPUnit step lists. The job's own text is read each
     * time, so renaming a step or narrowing a suite in the workflow breaks the claim here as well.
     *
     * @param   string  $job   `workflow-path#job-id` reference.
     * @param   string  $file  Test file path relative to the repository root.
     *
     * @return  bool  Whether the job's text runs that file.
     *
     * @since   2.0.0
     */
    private function jobRuns(string $job, string $file): bool
    {
        [$workflow, $id] = explode('#', $job, 2) + [1 => ''];
        if (!is_file(dirname(__DIR__, 2) . '/' . $workflow)) {
            return false;
        }
        $text = $this->jobText($this->contents($workflow), $id);
        $suite = strtolower(explode('/', $file)[1] ?? '');

        return match (true) {
            $text === '' => false,
            str_contains($text, $file) => true,
            str_contains($text, '--testsuite ' . $suite) => true,
            default => preg_match('/^\s+(?:run: )?composer test\s*$/m', $text) === 1
                || preg_match('#vendor/bin/phpunit --colors=always \\\\\s*$#m', $text) === 1,
        };
    }

    /**
     * Cut one job's block out of a workflow file.
     *
     * @param   string  $workflow  Complete workflow YAML.
     * @param   string  $id        Job identifier under `jobs:`.
     *
     * @return  string  The job's lines, or an empty string when the job is not declared.
     *
     * @since   2.0.0
     */
    private function jobText(string $workflow, string $id): string
    {
        $jobs = strstr($workflow, "\njobs:\n");
        if ($jobs === false) {
            return '';
        }
        $pattern = sprintf('/^  %s:\n(.*?)(?=^  [A-Za-z0-9_-]+:\n|\z)/ms', preg_quote($id, '/'));

        return preg_match($pattern, $jobs, $match) === 1 ? $match[1] : '';
    }

    /**
     * Read the identifiers of every finding still open in the live ledger.
     *
     * @return  list<string>  Open finding identifiers.
     *
     * @since   2.0.0
     */
    private function openFindings(): array
    {
        preg_match_all('/"id":\s*"([A-Z0-9-]+)"/', $this->contents('docs/roadmap/findings.json'), $matches);

        return $matches[1];
    }

    /**
     * Decode the committed matrix.
     *
     * @return  array<mixed, mixed>  The matrix document.
     *
     * @since   2.0.0
     */
    private function matrix(): array
    {
        $decoded = json_decode($this->contents(self::MATRIX), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Treat a decoded value as a list, or as empty when it is not one.
     *
     * @param   mixed  $value  Decoded JSON value.
     *
     * @return  list<mixed>  The value's items.
     *
     * @since   2.0.0
     */
    private function list(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /**
     * Read one repository file.
     *
     * @param   string  $path  Path relative to the repository root.
     *
     * @return  string  File contents.
     *
     * @since   2.0.0
     */
    private function contents(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($contents, $path);

        return $contents;
    }
}
