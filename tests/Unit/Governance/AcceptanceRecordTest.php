<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Governance;

use Kumwe\App\Tools\Governance\AcceptanceRecord;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Holds `tools/Governance/AcceptanceRecord.php` to the rules that keep the one acceptance record truthful.
 *
 * The committed record must verify and every view derived from it must be current. Every refusal is then taken
 * against a scratch repository built in the system temporary directory: a ledger finding with no entry, a delivered
 * finding still in the ledger or absent from the changelog, a missing path, an undeclared CI job, an artifact no
 * workflow uploads, an unresolvable reference, a package the README does not define or that has no entry, a
 * package delivered ahead of its finding, a missing or prematurely delivered Gate B criterion, a pending entry with
 * no branch and a stale generated block. The renderer is held to the lifecycle rule from the other side: no phase
 * with outstanding work reads as delivered and the open-work table carries no completion marker.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class AcceptanceRecordTest extends TestCase
{
    /**
     * Scratch repository roots created by the current test.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $scratch = [];

    /**
     * Load the governance classes once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/Governance/bootstrap.php';
    }

    /**
     * Remove every scratch repository the test created.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach ($this->scratch as $root) {
            GovernanceFixture::remove($root);
        }
        $this->scratch = [];
    }

    /**
     * The committed record verifies against the repository and its summary page and STATUS.md blocks are current.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCommittedRecordIsTruthfulAndItsViewsAreCurrent(): void
    {
        $acceptance = new AcceptanceRecord(GovernanceFixture::repositoryRoot());

        self::assertSame([], $acceptance->verify());
        self::assertSame([], $acceptance->staleDocuments());
        $counts = $acceptance->counts();
        self::assertGreaterThan(0, $counts['entries']);
        self::assertSame($counts['entries'], array_sum($counts['states']));
        self::assertSame($counts['entries'], array_sum($counts['tracks']));
    }

    /**
     * A clean scratch repository verifies, renders every view once and is current after the write.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACleanRecordVerifiesAndRendersEveryView(): void
    {
        $root = $this->fixture();
        $acceptance = new AcceptanceRecord($root);

        self::assertSame([], $acceptance->verify());
        self::assertNotSame([], $acceptance->staleDocuments());
        self::assertSame(
            ['docs/roadmap/acceptance-summary.md', 'docs/roadmap/STATUS.md'],
            $acceptance->writeDerivedDocuments(),
        );
        self::assertSame([], $acceptance->staleDocuments());
        self::assertSame([], $acceptance->writeDerivedDocuments());

        $status = (string) file_get_contents($root . '/docs/roadmap/STATUS.md');
        self::assertStringContainsString(
            '| 5 — Enterprise scale | B | In progress — `P5-A`, `P5-C` delivered; `P5-B` pending integration; '
            . '1 finding pending integration; in flight on `agent/scale` | — |',
            $status,
        );
        self::assertStringContainsString(
            '| 5 | `P5-B` | `V2-SCL-002` | `pr-152` | `agent/scale`: `P5-B`, `V2-SCL-002` |',
            $status,
        );
        self::assertStringContainsString('| **Gate B** | | **Not assessed — criteria: 0 delivered', $status);
        self::assertStringContainsString('**1 open findings**', $status);
        self::assertStringContainsString('Hand-written line that must survive.', $status);

        $summary = (string) file_get_contents($root . '/docs/roadmap/acceptance-summary.md');
        self::assertStringContainsString('`V2-SCL-001` — Unrelated writes overlap.', $summary);
        self::assertStringContainsString('pending-integration (`agent/scale`)', $summary);
        self::assertStringContainsString('*on branch:* `tests/Integration/SequencerTest.php`', $summary);
        self::assertStringContainsString('`ci.yml#database`', $summary);
    }

    /**
     * An open finding the ledger carries must have an entry, and an entry for an open finding must be in the ledger.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLedgerFindingsAndFindingEntriesMustMatch(): void
    {
        $root = $this->fixture(ledger: static function (array $ledger): array {
            $ledger['findings'][] = ['id' => 'V2-SCL-009', 'state' => 'open', 'phase' => '5'];

            return $ledger;
        });
        $this->assertRefused(
            $root,
            'Finding V2-SCL-009 is open in docs/roadmap/findings.json but has no acceptance entry',
        );

        $root = $this->fixture(ledger: static function (array $ledger): array {
            $ledger['findings'] = [['id' => 'V2-SCL-001', 'state' => 'open', 'phase' => '5']];

            return $ledger;
        });
        $this->assertRefused($root, 'Entry V2-SCL-001 is delivered but still sits in docs/roadmap/findings.json');
        $this->assertRefused($root, 'Entry V2-SCL-002 is pending-integration but docs/roadmap/findings.json no longer');
    }

    /**
     * A delivered entry must be cited by the changelog, name a test under tests/ and a CI job, and carry no note.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADeliveredEntryMustBeCitedProvenAndClosed(): void
    {
        $root = $this->fixture(
            record: static function (array $record): array {
                $record['entries'][0]['tests'] = ['src/Fence.php'];
                $record['entries'][0]['workflows'] = [];
                $record['entries'][0]['outstanding'] = 'Still something to do.';
                $record['entries'][0]['branches'] = ['agent/scale'];

                return $record;
            },
            changelog: "# Changelog\n\n- Nothing cites the delivered package.\n",
        );

        $this->assertRefused($root, 'Entry P5-A is delivered but CHANGELOG.md never cites it.');
        $this->assertRefused($root, 'Entry P5-A is delivered without naming a test under tests/.');
        $this->assertRefused($root, 'Entry P5-A is delivered without naming the CI job that runs its proof.');
        $this->assertRefused($root, 'Entry P5-A is delivered and may not carry an outstanding note.');
        $this->assertRefused($root, 'Entry P5-A is delivered on the head and may not name a pending branch');
    }

    /**
     * Every runtime owner and test path must exist, every CI job must be declared and every artifact uploaded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPathsJobsAndArtifactsMustExist(): void
    {
        $root = $this->fixture(record: static function (array $record): array {
            $record['entries'][0]['runtime_owner'] = ['src/Missing.php'];
            $record['entries'][0]['tests'][] = 'tests/Missing.php';
            $record['entries'][0]['workflows'] = [
                '.github/workflows/ci.yml#nowhere',
                '.github/workflows/absent.yml#database',
                'ci.yml database',
            ];
            $record['entries'][0]['artifacts'] = [
                '.github/workflows/ci.yml#never-uploaded',
                'docs/missing-evidence.json',
            ];

            return $record;
        });

        $this->assertRefused($root, 'Entry P5-A names the missing runtime_owner path src/Missing.php.');
        $this->assertRefused($root, 'Entry P5-A names the missing tests path tests/Missing.php.');
        $this->assertRefused(
            $root,
            'Entry P5-A names the job nowhere, which .github/workflows/ci.yml does not declare.',
        );
        $this->assertRefused($root, 'Entry P5-A names the missing workflow .github/workflows/absent.yml.');
        $this->assertRefused($root, 'Entry P5-A carries a malformed workflow reference');
        $this->assertRefused(
            $root,
            'Entry P5-A names the artifact never-uploaded, which .github/workflows/ci.yml never',
        );
        $this->assertRefused($root, 'Entry P5-A names the missing artifact source docs/missing-evidence.json.');
    }

    /**
     * A text reference must resolve to an existing document and to a heading or ledger identifier inside it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAReferenceMustResolve(): void
    {
        $root = $this->fixture(record: static function (array $record): array {
            $record['entries'][0]['reference'] = 'docs/roadmap/README.md#phase-9--nowhere';
            $record['entries'][2]['reference'] = 'docs/roadmap/findings.json#V2-SCL-404';
            $record['entries'][3]['reference'] = 'docs/absent.md';

            return $record;
        });

        $this->assertRefused($root, 'Entry P5-A references the anchor #phase-9--nowhere, which no heading of');
        $this->assertRefused(
            $root,
            'Entry P5-B references docs/roadmap/findings.json, which carries no identifier V2-SCL-404.',
        );
        $this->assertRefused($root, 'Entry V2-SCL-002 references the missing document docs/absent.md.');
    }

    /**
     * A package must be defined by the README, and every README package must be an entry or delivered before.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPackagesMustMatchTheReadmeDefinitions(): void
    {
        $root = $this->fixture(readme: static fn(string $readme): string => str_replace(
            "**P5-A — Fence.**",
            "**P5-A — Fence.**\n\n**P5-D — Undeclared by the record.**",
            str_replace('**P5-B — Sequencer.**', 'The sequencer package lost its definition.', $readme),
        ));

        $this->assertRefused($root, 'Package entry P5-B is not defined in docs/roadmap/README.md.');
        $this->assertRefused(
            $root,
            'Package P5-D is defined in docs/roadmap/README.md but is neither an acceptance entry',
        );
    }

    /**
     * Packages and findings name each other, and a package is never delivered while one of its findings is not.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPackageIsNeverDeliveredAheadOfItsFindings(): void
    {
        $root = $this->fixture(record: static function (array $record): array {
            $record['entries'][1]['state'] = 'pending-integration';
            $record['entries'][1]['branches'] = ['agent/scale'];
            $record['entries'][1]['outstanding'] = 'Awaiting the scale branch.';
            $record['entries'][3]['packages'] = ['P5-A'];

            return $record;
        });

        $this->assertRefused($root, 'Package P5-A is delivered while its finding V2-SCL-001 is not.');
        $this->assertRefused(
            $root,
            'Finding entry V2-SCL-002 names package P5-A, which is not a package entry listing it back.',
        );
        $this->assertRefused(
            $root,
            'Package entry P5-B lists finding V2-SCL-002, which is not a finding entry naming the package.',
        );
    }

    /**
     * Every Gate B criterion has its own entry, and a criterion is not delivered while an entry citing it is open.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGateBCriteriaMustBeCompleteAndHonest(): void
    {
        $root = $this->fixture(record: static function (array $record): array {
            $record['entries'] = array_values(array_filter(
                $record['entries'],
                static fn(array $entry): bool => $entry['id'] !== 'GB-12',
            ));
            foreach ($record['entries'] as $index => $entry) {
                if ($entry['id'] === 'GB-3') {
                    $record['entries'][$index]['state'] = 'delivered';
                    $record['entries'][$index]['outstanding'] = null;
                }
            }

            return $record;
        });

        $this->assertRefused($root, 'Gate B criterion 12 has no GB-12 entry of kind gate-b-criterion.');
        $this->assertRefused(
            $root,
            'GB-3 is delivered while P5-B, which cites that criterion, is pending-integration.',
        );
    }

    /**
     * A pending entry names its branch, an open entry says what is outstanding, and branch evidence needs a branch.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPendingAndOpenEntriesSayWhatRemains(): void
    {
        $root = $this->fixture(record: static function (array $record): array {
            $record['entries'][2]['branches'] = [];
            $record['entries'][3]['state'] = 'open';
            $record['entries'][3]['outstanding'] = ' ';
            $record['entries'][4]['track'] = 'somewhere-else';
            $record['entries'][4]['kind'] = 'wish';

            return $record;
        });

        $this->assertRefused($root, 'Entry P5-B is pending integration and must name the branch that carries it.');
        $this->assertRefused($root, 'Entry P5-B lists branch_evidence without naming the branch that carries it.');
        $this->assertRefused($root, 'Entry V2-SCL-002 is open and must say what is outstanding.');
        $this->assertRefused($root, 'Entry GB-1 carries the unknown kind "wish"');
    }

    /**
     * A generated block that is missing, duplicated or edited by hand is reported until the record regenerates it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStaleOrUnmarkedBlocksAreReported(): void
    {
        $root = $this->fixture();
        $acceptance = new AcceptanceRecord($root);
        $acceptance->writeDerivedDocuments();
        $status = $root . '/docs/roadmap/STATUS.md';

        $edited = str_replace('| 5 | `P5-B`', '| 5 | `P5-B` edited by hand', (string) file_get_contents($status));
        file_put_contents($status, $edited);
        self::assertSame(
            ['docs/roadmap/STATUS.md is stale against docs/roadmap/acceptance-record.json; run composer '
                . 'acceptance:summary and commit the result.'],
            $acceptance->staleDocuments(),
        );

        $unmarked = str_replace(AcceptanceRecord::marker('gate-b', 'end'), '', (string) file_get_contents($status));
        file_put_contents($status, $unmarked);
        self::assertStringContainsString('"gate-b" exactly once', implode("\n", $acceptance->staleDocuments()));
    }

    /**
     * No phase with open or pending work reads as delivered, a phase with none does, and the open-work table carries
     * no completion marker even when a record note would supply one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheBoardNeverCallsOutstandingWorkDelivered(): void
    {
        $root = $this->fixture(record: static function (array $record): array {
            $record['phases'][0]['name'] = 'Enterprise scale';
            $record['phases'][] = [
                'id' => '6',
                'name' => 'Continuity',
                'gate' => 'B',
                'blocked_on' => '—',
                'delivered_packages' => ['P6-A'],
                'history' => 'every package complete',
            ];
            $record['board'] = ['5', '6', 'Gate A', 'Gate B'];

            return $record;
        }, readme: static fn(string $readme): string => $readme
            . "\n### Phase 6 — Continuity\n\n**P6-A — Done.**\n");
        $acceptance = new AcceptanceRecord($root);
        self::assertSame([], $acceptance->verify());
        $acceptance->writeDerivedDocuments();
        $status = (string) file_get_contents($root . '/docs/roadmap/STATUS.md');

        self::assertStringContainsString(
            '| 6 — Continuity | B | Delivered — every package complete | — |',
            $status,
        );
        self::assertStringNotContainsString('| 5 — Enterprise scale | B | Delivered', $status);
        $open = substr($status, (int) strpos($status, AcceptanceRecord::marker('open-work', 'begin')));
        $open = substr($open, 0, (int) strpos($open, AcceptanceRecord::marker('open-work', 'end')));
        self::assertDoesNotMatchRegularExpression(
            '/\b(?:closed|complete|completed|delivered|done|finished|shipped)\b/i',
            $open,
        );
    }

    /**
     * Heading anchors follow GitHub's rule: punctuation removed, spaces become hyphens, case folded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHeadingSlugsMatchGithubAnchors(): void
    {
        self::assertSame(
            'phase-5--enterprise-scale-engineering',
            AcceptanceRecord::slug('Phase 5 — Enterprise scale engineering'),
        );
        self::assertSame('8-the-two-gates', AcceptanceRecord::slug('8. The two gates'));
        self::assertSame('baseline-health-at-7a83c295', AcceptanceRecord::slug('Baseline health at `7a83c295`'));
        self::assertSame(
            'gate-b--version-2-enterprise-release',
            AcceptanceRecord::slug('Gate B — Version 2 enterprise release'),
        );
    }

    /**
     * Assert that verifying one scratch repository reports a failure containing the given text.
     *
     * @param   string  $root      Scratch repository root.
     * @param   string  $expected  Text one failure must contain.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertRefused(string $root, string $expected): void
    {
        $errors = (new AcceptanceRecord($root))->verify();
        foreach ($errors as $error) {
            if (str_contains($error, $expected)) {
                self::assertStringContainsString($expected, $error);

                return;
            }
        }
        self::fail(sprintf("No failure contained \"%s\". Failures:\n%s", $expected, implode("\n", $errors)));
    }

    /**
     * Build a clean scratch repository, letting a test mutate the record, ledger, README or changelog first.
     *
     * @param   (callable(array<string, mixed>): array<string, mixed>)|null  $record     Record mutation.
     * @param   (callable(array<string, mixed>): array<string, mixed>)|null  $ledger     Ledger mutation.
     * @param   (callable(string): string)|null                             $readme     README mutation.
     * @param   string|null                                                 $changelog  Replacement changelog.
     *
     * @return  string  Absolute path of the scratch repository root.
     *
     * @since   2.0.0
     */
    private function fixture(
        ?callable $record = null,
        ?callable $ledger = null,
        ?callable $readme = null,
        ?string $changelog = null,
    ): string {
        $root = sys_get_temp_dir() . '/kumwe-acceptance-' . bin2hex(random_bytes(8));
        $this->scratch[] = $root;
        $readmeText = "# Roadmap\n\n### Phase 5 — Enterprise scale engineering\n\n**P5-A — Fence.**\n\n"
            . "**P5-B — Sequencer.**\n\n**P5-C — Fan-out.**\n\n### Gate B — Version 2 enterprise release\n";
        $statusText = "# Programme status\n\nHand-written line that must survive.\n\n## Phase board\n\n";
        foreach (AcceptanceRecord::STATUS_BLOCKS as $block) {
            $statusText .= AcceptanceRecord::marker($block, 'begin') . "\n" . AcceptanceRecord::marker($block, 'end')
                . "\n\n";
        }
        $workflow = "name: CI\non:\n  push:\njobs:\n  database:\n    name: PHP 8.5 / \${{ matrix.database.name }}\n"
            . "    steps:\n      - name: Upload coverage\n        uses: actions/upload-artifact@v4\n        with:\n"
            . "          name: coverage-report\n          path: build/coverage\n";
        $files = [
            'docs/roadmap/README.md' => $readme === null ? $readmeText : $readme($readmeText),
            'docs/roadmap/STATUS.md' => $statusText,
            'CHANGELOG.md' => $changelog
                ?? "# Changelog\n\n- The fence stops serializing writes (`P5-A`, `V2-SCL-001`).\n",
            '.github/workflows/ci.yml' => $workflow,
            'src/Fence.php' => "<?php\n",
            'tests/Integration/FenceTest.php' => "<?php\n",
            'docs/roadmap/findings.json' => $this->encode(
                $ledger === null ? $this->ledger() : $ledger($this->ledger()),
            ),
            'docs/roadmap/acceptance-record.json' => $this->encode(
                $record === null ? $this->record() : $record($this->record()),
            ),
        ];
        foreach ($files as $path => $contents) {
            $absolute = $root . '/' . $path;
            if (!is_dir(dirname($absolute))) {
                mkdir(dirname($absolute), 0o700, true);
            }
            file_put_contents($absolute, $contents);
        }

        return $root;
    }

    /**
     * The clean scratch ledger: one open finding.
     *
     * @return  array<string, mixed>  Decoded ledger.
     *
     * @since   2.0.0
     */
    private function ledger(): array
    {
        return [
            'states' => ['open', 'in_progress', 'conditional'],
            'findings' => [
                [
                    'id' => 'V2-SCL-002',
                    'state' => 'in_progress',
                    'phase' => '5',
                    'gate' => 'B',
                    'severity' => 'high',
                    'origin' => 'review',
                ],
            ],
        ];
    }

    /**
     * The clean scratch record: a delivered package and finding, a pending pair and the twelve Gate B criteria.
     *
     * @return  array<string, mixed>  Decoded record.
     *
     * @since   2.0.0
     */
    private function record(): array
    {
        $entry = static fn(string $id, string $kind, ?string $phase, string $state, array $extra = []): array
            => array_merge([
                'id' => $id,
                'kind' => $kind,
                'phase' => $phase,
                'requirement' => 'Unrelated writes overlap.',
                'reference' => 'docs/roadmap/README.md#phase-5--enterprise-scale-engineering',
                'runtime_owner' => ['src/Fence.php'],
                'tests' => ['tests/Integration/FenceTest.php'],
                'workflows' => ['.github/workflows/ci.yml#database'],
                'artifacts' => ['.github/workflows/ci.yml#coverage-report'],
                'decision' => 'ADR 0021',
                'state' => $state,
                'track' => 'pr-152',
                'branches' => $state === 'pending-integration' ? ['agent/scale'] : [],
                'branch_evidence' => $state === 'pending-integration' ? ['tests/Integration/SequencerTest.php'] : [],
                'outstanding' => $state === 'delivered' ? null : 'Flips when agent/scale lands.',
                'gate_b_criteria' => [3],
            ], $extra);
        $entries = [
            $entry('P5-A', 'package', '5', 'delivered', ['findings' => ['V2-SCL-001']]),
            $entry('V2-SCL-001', 'finding', '5', 'delivered', ['packages' => ['P5-A']]),
            $entry('P5-B', 'package', '5', 'pending-integration', ['findings' => ['V2-SCL-002']]),
            $entry('V2-SCL-002', 'finding', '5', 'pending-integration', [
                'packages' => ['P5-B'],
                'reference' => 'docs/roadmap/findings.json#V2-SCL-002',
            ]),
        ];
        for ($criterion = 1; $criterion <= 12; $criterion++) {
            $entries[] = $entry('GB-' . $criterion, 'gate-b-criterion', null, 'open', [
                'reference' => 'docs/roadmap/README.md#gate-b--version-2-enterprise-release',
                'gate_b_criteria' => [$criterion],
                'outstanding' => 'Assessed at the release candidate.',
            ]);
        }

        return [
            'record' => 'kumwe-version-2-acceptance-record',
            'version' => '2.0.0',
            'authority' => 'ADR 0021',
            'pull_request' => 152,
            'branch' => 'platform/v2-runtime-completion',
            'note' => 'Scratch record.',
            'states' => [
                'delivered' => 'On the head.',
                'pending-integration' => 'On a branch.',
                'open' => 'Outstanding.',
            ],
            'tracks' => ['pr-152' => 'This pull request.'],
            'board' => ['5', 'Gate A', 'Gate B'],
            'gates' => [
                'Gate A' => ['state' => 'Passed — 13/13 executable criteria met', 'blocked_on' => '—'],
                'Gate B' => ['blocked_on' => 'Phase 5'],
            ],
            'phases' => [
                [
                    'id' => '5',
                    'name' => 'Enterprise scale',
                    'gate' => 'B',
                    'blocked_on' => '—',
                    'delivered_packages' => ['P5-C'],
                ],
            ],
            'entries' => $entries,
        ];
    }

    /**
     * Encode one scratch JSON document the way the committed records are written.
     *
     * @param   array<string, mixed>  $document  Decoded document.
     *
     * @return  string  Pretty-printed JSON with a trailing newline.
     *
     * @since   2.0.0
     */
    private function encode(array $document): string
    {
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

        return json_encode($document, $flags) . "\n";
    }
}
