<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversNothing]
/**
 * Holds the immutable-correction rule of ADR 0003 so no write path in `src/` can quietly bypass it.
 *
 * A definition may declare that entering a workflow state closes the record; after that, every mutation
 * of its fields and owned lines refuses with the stable `BusinessRecordImmutable` error, and correction
 * happens through a new record carrying a `reversal` link. That rule is only worth anything if it is
 * structural: stored record state must keep exactly one door, and behind that door every mutating write
 * site must be classified against the guard — guarded, guarded by its one caller, or exempt for a reason
 * the decision record states. A write site this test has never heard of fails the build until somebody
 * classifies it, which is precisely the enforcement the ADR demands.
 *
 * @since  2.0.0
 */
final class ImmutableRecordBoundaryTest extends TestCase
{
    /**
     * The one application service every business-record mutation flows through.
     *
     * @var    string
     * @since  2.0.0
     */
    private const SERVICE = 'src/BusinessRecord/Application/BusinessRecordService.php';

    /**
     * Classes permitted to hold the write repository, which is the only door onto stored record state.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const WRITERS = [
        'src/BusinessRecord/Application/BusinessRecordService.php',
        'src/BusinessRecord/Application/BusinessRecordWriteRepository.php',
        'src/BusinessRecord/Infrastructure/Persistence/DoctrineBusinessRecordWriteRepository.php',
    ];

    /**
     * Declaring, implementing or holding the write repository, as opposed to naming it in prose.
     *
     * @var    string
     * @since  2.0.0
     */
    private const HOLDS_WRITER = '/(?:implements|interface)\\s+BusinessRecordWriteRepository\\b'
        . '|BusinessRecordWriteRepository\\s+\\$/';

    /**
     * A call that rewrites stored record state through the write repository.
     *
     * @var    string
     * @since  2.0.0
     */
    private const MUTATING_CALL =
        '/\\$this->writes->(?:insert|update|hardDelete|relate|unrelate|reorder|writeOwnedLines)\\(/';

    /**
     * Every service method allowed to reach the write repository, and why the guard admits it.
     *
     * `guarded` methods call `assertRecordMutable()` in their own body before writing. `caller-guarded`
     * methods are private helpers reachable only from a method that already guarded the record.
     * `exempt` methods are the two openings the decision record makes deliberately: `create` writes a
     * record that starts in the workflow's initial state, which `WorkflowBinding` refuses to declare
     * immutable, and `action` performs a declared transition, because immutability freezes content and
     * not the state machine. `createDocumentHeader` is the create branch of the document command and
     * `amendDocumentHeader` its amend branch, which `applyDocument` guards before dispatching.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const CLASSIFIED_WRITE_SITES = [
        'update' => 'guarded',
        'relate' => 'guarded',
        'unrelate' => 'guarded',
        'reorder' => 'guarded',
        'applyDocument' => 'guarded',
        'lifecycle' => 'guarded',
        'clearInboundSetNull' => 'guarded',
        'createDocumentHeader' => 'caller-guarded',
        'amendDocumentHeader' => 'caller-guarded',
        'create' => 'exempt',
        'action' => 'exempt',
    ];

    /**
     * Stored record state keeps exactly one door, so the guard behind it fences every path in the tree.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStoredRecordStateStillHasExactlyOneDoor(): void
    {
        $root = dirname(__DIR__, 2);
        $holders = [];
        $source = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src', RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($source as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);
            if (preg_match(self::HOLDS_WRITER, $contents) === 1) {
                $holders[] = str_replace($root . '/', '', $file->getPathname());
            }
        }
        sort($holders, SORT_STRING);
        $writers = self::WRITERS;
        sort($writers, SORT_STRING);

        self::assertSame(
            $writers,
            $holders,
            'A second door onto stored record state would be a door around the immutability guard.',
        );
    }

    /**
     * Every mutating write site in the record service is classified against the immutability guard.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryMutatingWriteSiteIsClassifiedAgainstTheGuard(): void
    {
        $methods = self::methods(self::source(self::SERVICE));
        $unclassified = [];
        foreach ($methods as $name => $body) {
            if (preg_match(self::MUTATING_CALL, $body) !== 1) {
                continue;
            }
            $classification = self::CLASSIFIED_WRITE_SITES[$name] ?? null;
            if ($classification === null) {
                $unclassified[] = $name;
                continue;
            }
            if ($classification === 'guarded') {
                self::assertStringContainsString(
                    '$this->assertRecordMutable(',
                    $body,
                    $name . '() writes record state and must pass the loaded record through the guard first.',
                );
            }
        }

        self::assertSame(
            [],
            $unclassified,
            'A write site the immutability boundary has never classified must not ship: '
            . implode(', ', $unclassified) . '. Guard it, or record here why the decision record exempts it.',
        );
        foreach (array_keys(self::CLASSIFIED_WRITE_SITES) as $name) {
            self::assertArrayHasKey(
                $name,
                $methods,
                'Classified write site ' . $name . '() no longer exists; retire its classification with it.',
            );
        }
    }

    /**
     * The document command guards the amend branch before either header helper can write.
     *
     * The two header helpers hold write calls but no guard of their own, so their safety is their one
     * caller: `applyDocument()` must guard the loaded header before dispatching to the amend helper, and
     * nothing else in the tree may call either helper.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDocumentHelpersAreReachableOnlyThroughTheGuardedCommand(): void
    {
        $service = self::source(self::SERVICE);
        $methods = self::methods($service);
        $apply = $methods['applyDocument'] ?? '';

        $guard = strpos($apply, '$this->assertRecordMutable(');
        $amend = strpos($apply, '$this->amendDocumentHeader(');
        self::assertIsInt($guard, 'applyDocument() must guard the amended header.');
        self::assertIsInt($amend, 'applyDocument() no longer dispatches to amendDocumentHeader().');
        self::assertLessThan($amend, $guard, 'The guard must run before the amend helper can write.');
        foreach (['createDocumentHeader', 'amendDocumentHeader'] as $helper) {
            self::assertSame(
                2,
                substr_count($service, $helper . '('),
                $helper . '() must have exactly one call site, inside applyDocument(), plus its declaration.',
            );
        }
    }

    /**
     * The two exemptions stay exactly as the decision record states them, and no wider.
     *
     * A created record cannot be born closed, because the workflow binding refuses to declare the
     * initial state immutable; and a workflow transition stays open on a closed record, because that is
     * how an approved document still becomes a delivered one while its content stays frozen. The delete
     * lifecycle is the one lifecycle operation the guard skips, named by the ADR as unchanged.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheExemptionsAreExactlyTheOnesTheDecisionRecordMakes(): void
    {
        $binding = self::source('src/BusinessDefinition/Domain/WorkflowBinding.php');
        self::assertStringContainsString(
            '|| $state === $initialState',
            $binding,
            'The workflow binding must refuse to declare the initial state immutable, or create() needs a guard.',
        );

        $methods = self::methods(self::source(self::SERVICE));
        self::assertStringNotContainsString(
            '$this->assertRecordMutable(',
            $methods['action'] ?? '',
            'Immutability freezes fields and lines, never the state machine; action() must stay open.',
        );
        self::assertStringContainsString(
            "if (\$operation !== 'delete') {",
            $methods['lifecycle'] ?? '',
            'The audited delete lifecycle is the one lifecycle operation the ADR leaves open.',
        );
    }

    /**
     * The refusal is the stable named error the ADR demands, not a policy denial and not a new family.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGuardRefusesWithTheStableNamedError(): void
    {
        $methods = self::methods(self::source(self::SERVICE));
        self::assertStringContainsString(
            'throw new BusinessRecordImmutable(',
            $methods['assertRecordMutable'] ?? '',
            'The guard must refuse with the named error, so every surface reports the same failure.',
        );

        $exception = self::source('src/BusinessRecord/Application/Exception/BusinessRecordImmutable.php');
        self::assertStringContainsString(
            'final class BusinessRecordImmutable extends BusinessRecordException',
            $exception,
        );
        self::assertStringContainsString(
            "'business_record.immutable'",
            $exception,
            'The stable code is released API; renaming it would break every caller branching on it.',
        );
    }

    /**
     * Split one class source into its method bodies, keyed by method name.
     *
     * The split is positional: each body runs from its declaration to the next declaration, which is
     * exactly what the classification assertions need — whether a guard call appears between a method's
     * opening and the next method's opening.
     *
     * @param   string  $source  Complete class file bytes.
     *
     * @return  array<string, string>  Method name mapped to its source slice.
     *
     * @since   2.0.0
     */
    private static function methods(string $source): array
    {
        $count = preg_match_all(
            '/^    (?:public|private|protected) (?:static )?function (\w+)\(/m',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE,
        );
        self::assertIsInt($count);
        self::assertGreaterThan(0, $count, 'The class source no longer declares methods where expected.');
        $methods = [];
        foreach ($matches[1] as $index => [$name, $offset]) {
            $end = $matches[0][$index + 1][1] ?? strlen($source);
            $methods[$name] = substr($source, (int) $matches[0][$index][1], $end - (int) $matches[0][$index][1]);
        }

        return $methods;
    }

    /**
     * Read one repository file the boundary assertions inspect directly.
     *
     * @param   string  $path  Repository-relative path.
     *
     * @return  string  Complete file bytes.
     *
     * @since   2.0.0
     */
    private static function source(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($contents, 'Could not read ' . $path . '.');

        return $contents;
    }
}
