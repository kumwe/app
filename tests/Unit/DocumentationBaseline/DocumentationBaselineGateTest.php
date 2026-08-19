<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\DocumentationBaseline;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Hold the documentation baseline gate to the behaviour its record claims for it.
 *
 * The gate shipped without tests and a keying defect went with it: entries were keyed on a file and a
 * bare member name, a test file may declare several classes, and so a brand new undocumented method
 * whose name already appeared in that file's entries passed silently. The build was green throughout.
 * These cases pin each promise the baseline document makes — a violation the record does not carry
 * fails, an entry that no longer matches fails, and a record that is itself malformed fails — against
 * a fixture tree of their own, so the gate is proven rather than trusted.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class DocumentationBaselineGateTest extends TestCase
{
    /**
     * Scratch tree each case scans, removed again when it finishes.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $root = '';

    /**
     * Load the verifier as a library, without letting its command line run.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!defined('KUMWE_DOCBLOCKS_LIBRARY_ONLY')) {
            define('KUMWE_DOCBLOCKS_LIBRARY_ONLY', true);
        }

        require_once dirname(__DIR__, 3) . '/tools/verify-docblocks.php';
    }

    /**
     * Create the scratch tree.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        parent::setUp();

        $root = sys_get_temp_dir() . '/kumwe-docblock-gate-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root . '/fixtures', 0o700, true));
        $this->root = $root;
    }

    /**
     * Remove the scratch tree.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $tree = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($tree as $entry) {
                $path = (string) $entry;
                is_dir($path) ? rmdir($path) : unlink($path);
            }
            rmdir($this->root);
        }

        parent::tearDown();
    }

    /**
     * A tree whose whole debt is recorded passes, and the record round-trips byte for byte.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARecordedTreePassesAndTheRecordIsReproducible(): void
    {
        $this->write('Sample.php', $this->undocumented('Sample', ['handle']));

        $document = $this->emit();
        self::assertSame(2, $document['entry_count'], 'The class and its method are both debt.');
        self::assertSame(0, $this->compare($document));
        self::assertSame($this->render($document), $this->render($this->emit()));
    }

    /**
     * A method the record does not carry fails, even where a sibling class declares the same name.
     *
     * This is the defect the gate shipped with: `Sample::handle()` and `Other::handle()` share a bare
     * name, so a record keyed on the file and the name alone excused the second for free.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMethodBorrowingASiblingClassNameStillFails(): void
    {
        $this->write('Two.php', $this->undocumented('Sample', ['handle']) . $this->undocumented('Other', []));
        $document = $this->emit();
        self::assertSame(0, $this->compare($document));

        $this->write('Two.php', $this->undocumented('Sample', ['handle']) . $this->undocumented('Other', ['handle']));

        self::assertSame(1, $this->compare($document));
    }

    /**
     * An entry that no longer matches anything fails, so the record cannot be trimmed for free.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEntryThatNoLongerMatchesMustBeDeleted(): void
    {
        $this->write('Sample.php', $this->undocumented('Sample', ['handle']));
        $document = $this->emit();

        $this->write('Sample.php', $this->documented('Sample', ['handle']));

        self::assertSame(1, $this->compare($document), 'The stale entries must be deleted.');
    }

    /**
     * Deleting an entry without documenting the member it names fails.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeletingAnEntryWithoutDoingTheWorkFails(): void
    {
        $this->write('Sample.php', $this->undocumented('Sample', ['handle']));
        $document = $this->emit();
        $document['entries'] = [$document['entries'][0]];
        $document['entry_count'] = 1;

        self::assertSame(1, $this->compare($document));
    }

    /**
     * A record that is itself malformed fails, one complaint per rule the entry breaks.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMalformedRecordFails(): void
    {
        $this->write('Sample.php', $this->undocumented('Sample', ['handle']));
        $valid = $this->emit();

        $unowned = $valid;
        $unowned['entries'][0]['owner'] = 'UNASSIGNED';
        self::assertSame(1, $this->compare($unowned), 'An exemption nobody owns is a permission.');

        $undated = $valid;
        unset($undated['entries'][0]['expires']);
        self::assertSame(1, $this->compare($undated), 'A missing expiry must not mean no expiry.');

        $malformed = $valid;
        $malformed['entries'][0]['expires'] = 'never';
        self::assertSame(1, $this->compare($malformed));

        $duplicated = $valid;
        $duplicated['entries'][] = $duplicated['entries'][0];
        $duplicated['entry_count'] = count($duplicated['entries']);
        self::assertSame(1, $this->compare($duplicated), 'One key twice is a free pass for the second.');

        $miscounted = $valid;
        $miscounted['entry_count'] = 99;
        self::assertSame(1, $this->compare($miscounted), 'The count is the burn-down number.');
    }

    /**
     * An entry past its expiry fails, so an exemption does not outlive the work that justified it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExpiredEntryFails(): void
    {
        $this->write('Sample.php', $this->undocumented('Sample', ['handle']));
        $document = $this->emit('2026-01-31');

        self::assertSame(0, $this->compare($document, '2026-01-31'), 'Valid through the recorded day.');
        self::assertSame(1, $this->compare($document, '2026-02-01'));
    }

    /**
     * Every documentation rule is recorded, not only the missing block.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRecordCoversEveryRuleAndNotOnlyTheMissingBlock(): void
    {
        $this->write('Partial.php', <<<'SOURCE'
            <?php

            declare(strict_types=1);

            namespace Kumwe\App\Tests\Fixture;

            /**
             * Documented.
             *
             * @since  2.0.0
             */
            final class Partial
            {
                /**
                 * Documented but incomplete.
                 */
                public function handle(string $name): string
                {
                    return $name;
                }
            }
            SOURCE);

        $codes = array_column($this->emit()['entries'], 'code');
        sort($codes, SORT_STRING);

        self::assertSame(['MISSING_PARAM', 'MISSING_RETURN', 'MISSING_SINCE'], $codes);
    }

    /**
     * An attribute carrying arguments must not cost a member the documentation block above it.
     *
     * An attribute sits between a doc block and the name it documents, and its arguments may hold any
     * expression — `::class`, strings, arrays — whose tokens the walk has no other reason to expect.
     * Stepping through them one at a time let a single unrecognised token drop the pending block, so a
     * fully documented class reported as undocumented and its debt was recorded as real. Stacked
     * attributes made it worse, and `#[CoversClass(Foo::class)]` is the single most common attribute in
     * this suite: the gate was quietly wrong about a large part of the tree it was hired to judge.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnAttributeWithArgumentsDoesNotSwallowTheDocumentationBlock(): void
    {
        $this->write('Attributed.php', <<<'SOURCE'
            <?php

            declare(strict_types=1);

            namespace Kumwe\App\Tests\Fixture;

            /**
             * Documented, behind attributes that carry arguments.
             *
             * @since  2.0.0
             */
            #[Covers(Alpha::class)]
            #[Covers(Beta::class)]
            #[Group('slow', 'integration')]
            final class Attributed
            {
                /**
                 * Documented, behind an attribute holding an array argument.
                 *
                 * @param   string  $name  Name to answer with.
                 *
                 * @return  string  The name it was given.
                 *
                 * @since   2.0.0
                 */
                #[Provider(['a' => [1, 2], 'b' => [3, 4]])]
                public function handle(string $name): string
                {
                    return $name;
                }
            }
            SOURCE);

        self::assertSame([], $this->emit()['entries']);
    }

    /**
     * A member that genuinely lacks a block is still caught when it sits behind the same attributes.
     *
     * Skipping an attribute must move the walk past it, not past the declaration it decorates, or the
     * fix for the swallowed block would have bought silence instead of accuracy.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSkippingAnAttributeStillLeavesAnUndocumentedMemberVisible(): void
    {
        $this->write('AttributedUndocumented.php', <<<'SOURCE'
            <?php

            declare(strict_types=1);

            namespace Kumwe\App\Tests\Fixture;

            #[Covers(Alpha::class)]
            #[Covers(Beta::class)]
            final class AttributedUndocumented
            {
                #[Provider(['a' => [1, 2]])]
                public function handle(string $name): string
                {
                    return $name;
                }
            }
            SOURCE);

        $members = array_column($this->emit()['entries'], 'member');
        sort($members, SORT_STRING);

        self::assertSame(
            ['AttributedUndocumented', 'AttributedUndocumented::handle()'],
            $members,
        );
    }

    /**
     * Write one fixture file into the scratch tree.
     *
     * @param   string  $name    File name below `fixtures/`.
     * @param   string  $source  PHP source to write.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function write(string $name, string $source): void
    {
        self::assertIsInt(file_put_contents($this->root . '/fixtures/' . $name, $source));
    }

    /**
     * Build a class with no documentation anywhere.
     *
     * @param   string        $class    Class name.
     * @param   list<string>  $methods  Method names the class declares.
     *
     * @return  string  PHP source.
     *
     * @since   2.0.0
     */
    private function undocumented(string $class, array $methods): string
    {
        $body = '';
        foreach ($methods as $method) {
            $body .= sprintf("    public function %s(): void\n    {\n    }\n", $method);
        }

        return sprintf("<?php\n\nfinal class %s\n{\n%s}\n", $class, $body);
    }

    /**
     * Build the same class with every block the standard asks for.
     *
     * @param   string        $class    Class name.
     * @param   list<string>  $methods  Method names the class declares.
     *
     * @return  string  PHP source.
     *
     * @since   2.0.0
     */
    private function documented(string $class, array $methods): string
    {
        $body = '';
        foreach ($methods as $method) {
            $body .= sprintf(
                "    /**\n     * Documented.\n     *\n     * @return  void\n     *\n"
                    . "     * @since   2.0.0\n     */\n    public function %s(): void\n    {\n    }\n",
                $method,
            );
        }

        return sprintf(
            "<?php\n\n/**\n * Documented.\n *\n * @since  2.0.0\n */\nfinal class %s\n{\n%s}\n",
            $class,
            $body,
        );
    }

    /**
     * Scan the scratch tree and emit the record it produces.
     *
     * @param   string  $expires  Expiry stamped on every entry.
     *
     * @return  array<string, mixed>  The decoded baseline document.
     *
     * @since   2.0.0
     */
    private function emit(string $expires = '2099-12-31'): array
    {
        $auditor = new \DocBlockAuditor('2.0.0', 120);
        $auditor->scan($this->root . '/fixtures');
        $json = emitDocblockBaseline(
            $auditor->violations(),
            $this->root,
            ['fixtures'],
            $expires,
            '2026-08-19',
            ['https://example.test/run'],
        );
        $document = json_decode($json, true);
        self::assertIsArray($document);

        return $document;
    }

    /**
     * Run the armed gate against the scratch tree.
     *
     * @param   array<string, mixed>  $document  Baseline document to arm it with.
     * @param   string                $today     ISO date used for expiry checks.
     *
     * @return  int  The gate's exit status.
     *
     * @since   2.0.0
     */
    private function compare(array $document, string $today = '2026-08-19'): int
    {
        $path = $this->root . '/baseline.json';
        self::assertIsInt(file_put_contents($path, $this->render($document)));
        $auditor = new \DocBlockAuditor('2.0.0', 120);
        $auditor->scan($this->root . '/fixtures');

        ob_start();
        $status = compareDocblockBaseline(
            $auditor->violations(),
            $path,
            $this->root,
            ['fixtures'],
            $today,
            true,
            false,
            0,
        );
        ob_end_clean();

        return $status;
    }

    /**
     * Render a baseline document the way the emitter renders it.
     *
     * @param   array<string, mixed>  $document  Document to render.
     *
     * @return  string  Pretty-printed JSON.
     *
     * @since   2.0.0
     */
    private function render(array $document): string
    {
        return json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
