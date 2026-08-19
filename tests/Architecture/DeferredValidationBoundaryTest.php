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
 * Holds the half of accept-and-reconcile that an extension is never allowed to choose.
 *
 * Decision D14 accepts that stock and pricing cannot be authoritative at capture time, so an extension
 * may accept a document and settle those afterwards. It does not accept that anything else may be
 * deferred, and the difference has to be structural rather than advisory: an extension that could defer
 * authorization would be an extension that could write a record for an actor who was refused one.
 *
 * `docs/business-runtime.md` states the split as a table an extension author reads. This test is the
 * mechanical half — it proves the non-deferrable column has no bypass path, so a write path added later
 * cannot quietly acquire one.
 *
 * @since  2.0.0
 */
final class DeferredValidationBoundaryTest extends TestCase
{
    /**
     * Every mutation entry point on the record service, with the capability it must demand first.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const MUTATIONS = [
        'create' => 'business.record.create',
        'update' => 'business.record.update',
        'archive' => 'business.record.archive',
        'delete' => 'business.record.delete',
        'restore' => 'business.record.restore',
        'relate' => 'business.record.relate',
        'unrelate' => 'business.record.relate',
        'reorder' => 'business.record.relate',
    ];

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
     * Composition wiring names the port by class constant and is not a holder; what matters is who can
     * call it, which is a declaration, an implementation, or an injected dependency of that type.
     *
     * @var    string
     * @since  2.0.0
     */
    private const HOLDS_WRITER = '/(?:implements|interface)\\s+BusinessRecordWriteRepository\\b'
        . '|BusinessRecordWriteRepository\\s+\\$/';

    /**
     * No mutation entry point can reach its effect without first demanding its capability.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAuthorizationIsDemandedByEveryMutationEntryPoint(): void
    {
        $service = self::source('src/BusinessRecord/Application/BusinessRecordService.php');
        foreach (self::MUTATIONS as $method => $capability) {
            $offset = strpos($service, 'public function ' . $method . '(');
            self::assertIsInt($offset, 'The record service no longer declares ' . $method . '().');
            $head = substr($service, $offset, 400);
            self::assertStringContainsString(
                "\$this->authorize(\$command->context, '" . $capability . "')",
                $head,
                $method . '() must demand ' . $capability . ' before anything else it does.',
            );
        }
        $document = strpos($service, 'public function writeDocument(');
        self::assertIsInt($document);
        $head = substr($service, $document, 500);
        self::assertStringContainsString("'business.record.create' : 'business.record.update'", $head);
        self::assertStringContainsString("\$this->authorize(\$command->context, 'business.record.relate')", $head);
    }

    /**
     * Row and field policy is planned for every operation, and the plan is what the repositories receive.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRowAndFieldPolicyHasNoDeferredPath(): void
    {
        $service = self::source('src/BusinessRecord/Application/BusinessRecordService.php');

        self::assertGreaterThanOrEqual(
            count(self::MUTATIONS),
            substr_count($service, '$this->recordAccess->plan('),
            'Every mutation must plan row and field policy rather than deferring it to a later reconciliation.',
        );
        self::assertStringNotContainsString(
            'skipPolicy',
            $service,
            'The record service must offer no way to skip policy for a deferred validation.',
        );
        self::assertStringNotContainsString('deferValidation', $service);
        self::assertStringNotContainsString('withoutAuthorization', $service);
    }

    /**
     * Definition-shape validity is evaluated at command time, with no rule an extension can defer.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDefinitionShapeValidityIsEvaluatedAtCommandTime(): void
    {
        $validator = self::source('src/BusinessRecord/Application/RecordRuleValidator.php');
        $service = self::source('src/BusinessRecord/Application/BusinessRecordService.php');

        foreach (['deferred', 'defer(', 'reconcileLater', 'skipRules'] as $escape) {
            self::assertStringNotContainsString(
                $escape,
                $validator,
                'The rule validator must offer no way to defer a declared rule to reconciliation.',
            );
        }
        self::assertStringContainsString('$this->rules->', $service);
    }

    /**
     * Stored record state has exactly one door, so no second write path can be added beside the guarded one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStoredRecordStateHasOneGuardedWritePath(): void
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
            'A second path onto stored record state would be a path around authorization, policy and rules.',
        );
    }

    /**
     * The deferrable and non-deferrable split is written down where an extension author will find it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheSplitIsStatedInTheExtensionFacingContract(): void
    {
        $runtime = self::source('docs/business-runtime.md');

        self::assertStringContainsString('Never deferrable', $runtime);
        self::assertStringContainsString('Deferrable to reconciliation', $runtime);
        self::assertStringContainsString('DeferredValidationBoundaryTest', $runtime);
        foreach (['Authorization', 'policy', 'Definition-shape validity', 'idempotency claim'] as $required) {
            self::assertStringContainsString($required, $runtime);
        }
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
