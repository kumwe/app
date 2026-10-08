<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Composition;

use Kumwe\App\Studio\Application\Composition\StudioCompositionLockMismatch;
use Kumwe\App\Studio\Application\Composition\StudioContentComposition;
use Kumwe\App\Studio\Application\Composition\StudioContentCompositionService;
use Kumwe\App\Studio\Application\Composition\StudioPublishedBlueprintMismatch;
use Kumwe\App\Studio\Application\Composition\StudioPublishedCompositionGuard;
use Kumwe\App\Studio\Application\Host\StudioArtifactAdmission;
use Kumwe\App\Studio\Application\Host\StudioPersistenceRace;
use Kumwe\App\Studio\Domain\Artifact\StoredStudioArtifact;
use Kumwe\App\Studio\Domain\Projection\EntryCompositionOverrides;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\BuildsStudioCompositionService;
use Kumwe\App\Tests\Support\RecordingAuditRecorder;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * Proves the composition service mints, validates, pins and audits an entry's own item layout (App ADR 0025).
 *
 * The layout is a host-written Blueprint snapshot whose revision is a digest of its content, admitted and
 * checked by the real publication guard before it can be kept, stored once in its immutable history, and
 * pinned from the entry's override record by compare-and-set with one audit event per pointer move.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioContentCompositionService::class)]
#[UsesClass(StudioPublishedCompositionGuard::class)]
#[UsesClass(StudioArtifactAdmission::class)]
#[UsesClass(EntryCompositionOverrides::class)]
#[UsesClass(StudioContentComposition::class)]
final class StudioContentCompositionServiceItemLayoutTest extends TestCase
{
    use BuildsStudioCompositionService;

    /**
     * Entry that keeps its own layout in every scenario.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ENTRY_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026be9a1';

    /**
     * Another entry of the same type.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string OTHER_ENTRY_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026be9a2';

    /**
     * The host writes every member of an item layout except its roots, names the type Blueprint it was made
     * from, and derives its revision from its content, so equal input always yields the same revision while a
     * different base, order or lock set yields another.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnItemLayoutIsHostWrittenAndContentAddressed(): void
    {
        $service = $this->compositionService(new RecordingAuditRecorder());
        $type = $this->provisioned($service);
        $base = self::reference($type->blueprint);
        $roots = self::moved($type->blueprint->document()->roots);
        $locks = self::usedLocks($type->blueprint->document());
        $admitted = $type->blueprint->document()->dependencyLock->blocks;

        $layout = self::admit($service, self::ENTRY_ID, $roots, $locks, $admitted, $base);
        $document = $layout->document();

        self::assertSame('content-item-blueprint:' . self::ENTRY_ID, $layout->id);
        self::assertSame(
            StudioContentCompositionService::itemBlueprintId(strtoupper(self::ENTRY_ID)),
            $layout->id,
        );
        self::assertSame('1.0.0', $layout->version);
        self::assertSame('blueprint', $layout->kind);
        self::assertSame('published', $layout->status);
        self::assertSame(SiteContext::DEFAULT, $layout->siteIdentifier);
        self::assertMatchesRegularExpression('/^item-[0-9a-f]{64}$/D', $layout->revision);
        self::assertSame(
            ['contractVersion', 'dependencyLock', 'extensions', 'id', 'kind', 'label', 'model', 'owner', 'revision',
                'roots', 'status', 'version'],
            self::sortedKeys($document),
        );
        self::assertEquals((object) ['id' => 'kumwe.app/content', 'version' => '2.0.0'], $document->owner);
        self::assertSame('kumwe.app/content-item-blueprint', $document->label->key);
        self::assertEquals($type->blueprint->document()->model, $document->model);
        self::assertEquals($type->blueprint->document()->dependencyLock->theme, $document->dependencyLock->theme);
        self::assertSame(
            CanonicalJson::stringify($locks),
            CanonicalJson::stringify($document->dependencyLock->blocks),
        );
        self::assertSame(CanonicalJson::stringify($roots), CanonicalJson::stringify($document->roots));
        self::assertEquals(
            (object) ['base' => $base],
            $document->extensions->{StudioContentCompositionService::ITEM_EXTENSION},
        );

        $again = $service->admitItemLayout(
            self::context(),
            strtoupper(self::ENTRY_ID),
            self::$compositionTypeId,
            4,
            self::moved($type->blueprint->document()->roots),
            $locks,
            $admitted,
            clone $base,
        );
        self::assertSame($layout->revision, $again->revision);
        self::assertSame($layout->canonicalDocument, $again->canonicalDocument);

        $otherBase = clone $base;
        $otherBase->revision = 'authored-' . str_repeat('b', 64);
        $variants = [
            'base' => [$roots, $locks, $otherBase],
            'roots' => [$type->blueprint->document()->roots, $locks, $base],
            'locks' => [$roots, $admitted, $base],
        ];
        $revisions = [$layout->revision];
        foreach ($variants as $label => [$variantRoots, $variantLocks, $variantBase]) {
            $revisions[] = $service->admitItemLayout(
                self::context(),
                self::ENTRY_ID,
                self::$compositionTypeId,
                4,
                $variantRoots,
                $variantLocks,
                $admitted,
                $variantBase,
            )->revision;
            self::assertSame(count($revisions), count(array_unique($revisions)), $label);
        }
        $other = $service->admitItemLayout(
            self::context(),
            self::OTHER_ENTRY_ID,
            self::$compositionTypeId,
            4,
            $roots,
            $locks,
            $admitted,
            $base,
        );
        self::assertSame('content-item-blueprint:' . self::OTHER_ENTRY_ID, $other->id);
        self::assertNotSame($layout->revision, $other->revision);
    }

    /**
     * A block lock the deployment does not render at identical coordinates, a base without an exact
     * coordinate, and a layout the publication guard would refuse publicly are all refused before anything is
     * kept.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnlockedIncompleteAndIncompatibleItemLayoutsAreRefused(): void
    {
        $service = $this->compositionService(new RecordingAuditRecorder());
        $type = $this->provisioned($service);
        $base = self::reference($type->blueprint);
        $roots = self::moved($type->blueprint->document()->roots);
        $locks = self::usedLocks($type->blueprint->document());
        $admitted = $type->blueprint->document()->dependencyLock->blocks;

        $stale = array_map(static fn (stdClass $lock): stdClass => clone $lock, $locks);
        $stale[0]->revision = 'sha256:' . str_repeat('0', 64);
        $nameless = array_map(static fn (stdClass $lock): stdClass => clone $lock, $locks);
        unset($nameless[0]->type);
        foreach (['stale lock' => $stale, 'unnamed lock' => $nameless] as $label => $candidate) {
            try {
                self::admit($service, self::ENTRY_ID, $roots, $candidate, $admitted, $base);
                self::fail(sprintf('The %s was admitted.', $label));
            } catch (StudioCompositionLockMismatch) {
                self::assertSame([], $this->compositionItemArtifacts, $label);
            }
        }

        $baseless = clone $base;
        unset($baseless->revision);
        try {
            self::admit($service, self::ENTRY_ID, $roots, $locks, $admitted, $baseless);
            self::fail('An item layout without an exact base coordinate was admitted.');
        } catch (RuntimeException $refused) {
            self::assertSame('The item layout coordinates are invalid.', $refused->getMessage());
        }

        $unbound = self::moved($type->blueprint->document()->roots);
        $unbound[0]->slots->content[0]->bindings->value->source->fieldPath = ['data_missing'];
        try {
            self::admit($service, self::ENTRY_ID, $unbound, $locks, $admitted, $base);
            self::fail('An item layout bound to a field the model lacks was admitted.');
        } catch (StudioPublishedBlueprintMismatch) {
            self::assertSame([], $this->compositionItemArtifacts);
            self::assertNull($this->compositionOverrides);
        }
    }

    /**
     * Keeping a layout stores its revision once and pins it; returning to a stored revision re-pins it
     * without storing; an unchanged pointer writes nothing; clearing moves the pointer to null; every move is a
     * compare-and-set on the override revision that never rewrites override values, and each move records one
     * audit event without document bytes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testKeepingAnItemLayoutStoresOnceRepinsAndClearsByCompareAndSet(): void
    {
        $audit = new RecordingAuditRecorder();
        $service = $this->compositionService($audit);
        $type = $this->provisioned($service);
        $base = self::reference($type->blueprint);
        $document = $type->blueprint->document();
        $locks = self::usedLocks($document);
        $admitted = $document->dependencyLock->blocks;
        $x = self::admit($service, self::ENTRY_ID, self::moved($document->roots), $locks, $admitted, $base);
        $y = self::admit($service, self::ENTRY_ID, self::trimmed($document->roots), $locks, $admitted, $base);
        $site = SiteContext::default();
        $this->compositionOverrides = new EntryCompositionOverrides(
            $site,
            self::ENTRY_ID,
            (object) ['hero/main' => (object) ['tone' => 'quiet']],
            5,
        );
        $values = $this->compositionOverrides->canonical();
        $audit->events = [];

        $service->keepItemLayout(self::context(), self::ENTRY_ID, 7, 4, $x, $this->compositionOverrides, 'kept');
        self::assertSame(
            [$x->revision],
            array_map(static fn (StoredStudioArtifact $a): string => $a->revision, $this->compositionItemArtifacts),
        );
        self::assertSame($x->revision, $this->compositionOverrides->itemBlueprintRevision);
        self::assertSame(6, $this->compositionOverrides->revision);
        self::assertSame($values, $this->compositionOverrides->canonical());
        self::assertSame(['studio.composition.item-layout'], $audit->actions());
        $event = $audit->events[0];
        self::assertSame('content_entry', $event->subjectType());
        self::assertSame(self::ENTRY_ID, $event->subjectId());
        self::assertSame('success', $event->outcome());
        self::assertSame([
            'base_blueprint_revision' => $base->revision,
            'blueprint_identity_digest' => hash('sha256', 'content-item-blueprint:' . self::ENTRY_ID),
            'blueprint_revision' => $x->revision,
            'content_type_version' => 4,
            'entry_version' => 7,
            'override_revision' => 6,
            'reason' => 'kept',
            'site_identifier' => SiteContext::DEFAULT,
        ], $event->metadata());
        self::assertStringNotContainsString('roots', $event->metadataAsJson());
        self::assertStringNotContainsString('default/field', $event->metadataAsJson());

        $service->keepItemLayout(self::context(), self::ENTRY_ID, 8, 4, $y, $this->compositionOverrides, 'kept');
        $service->keepItemLayout(self::context(), self::ENTRY_ID, 9, 4, $x, $this->compositionOverrides, 'kept');
        self::assertCount(2, $this->compositionItemArtifacts, 'A revisited layout is re-pinned, not stored.');
        self::assertSame($x->revision, $this->compositionOverrides->itemBlueprintRevision);
        self::assertSame(8, $this->compositionOverrides->revision);

        $service->keepItemLayout(self::context(), self::ENTRY_ID, 10, 4, $x, $this->compositionOverrides, 'kept');
        self::assertSame(8, $this->compositionOverrides->revision, 'An unchanged pointer is not moved.');
        self::assertCount(3, $audit->events);

        $current = $this->compositionOverrides;
        $service->keepItemLayout(self::context(), self::ENTRY_ID, 11, 4, null, $current, 'inherited');
        self::assertNull($this->compositionOverrides->itemBlueprintRevision);
        self::assertSame(9, $this->compositionOverrides->revision);
        self::assertSame($values, $this->compositionOverrides->canonical());
        self::assertNull($audit->events[3]->metadata()['blueprint_revision']);
        self::assertNull($audit->events[3]->metadata()['base_blueprint_revision']);
        self::assertSame('inherited', $audit->events[3]->metadata()['reason']);
        self::assertCount(2, $this->compositionItemArtifacts, 'Clearing keeps every stored revision.');

        $this->compositionOverrides = null;
        $service->keepItemLayout(self::context(), self::OTHER_ENTRY_ID, 1, 4, $service->admitItemLayout(
            self::context(),
            self::OTHER_ENTRY_ID,
            self::$compositionTypeId,
            4,
            self::moved($document->roots),
            $locks,
            $admitted,
            $base,
        ), null, 'kept');
        self::assertSame(1, $this->compositionOverrides->revision);
        self::assertSame('{}', $this->compositionOverrides->canonical());
    }

    /**
     * A pointer or Blueprint head that moved concurrently is a persistence race, and a layout, record or reason
     * that does not fit the entry is a programming error refused before any write.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRacesAndForeignInputsAreRefusedBeforeAnAuditedMove(): void
    {
        $audit = new RecordingAuditRecorder();
        $service = $this->compositionService($audit);
        $type = $this->provisioned($service);
        $document = $type->blueprint->document();
        $x = $service->admitItemLayout(
            self::context(),
            self::ENTRY_ID,
            self::$compositionTypeId,
            4,
            self::moved($document->roots),
            self::usedLocks($document),
            $document->dependencyLock->blocks,
            self::reference($type->blueprint),
        );
        $site = SiteContext::default();
        $stale = new EntryCompositionOverrides($site, self::ENTRY_ID, new stdClass(), 3);
        $this->compositionOverrides = new EntryCompositionOverrides($site, self::ENTRY_ID, new stdClass(), 4);
        $audit->events = [];

        $this->compositionItemHeadMoved = true;
        try {
            $service->keepItemLayout(self::context(), self::ENTRY_ID, 2, 4, $x, $this->compositionOverrides, 'kept');
            self::fail('A moved item Blueprint head accepted another revision.');
        } catch (StudioPersistenceRace) {
            self::assertSame([], $this->compositionItemArtifacts);
            self::assertSame(4, $this->compositionOverrides->revision);
        }
        $this->compositionItemHeadMoved = false;
        try {
            $service->keepItemLayout(self::context(), self::ENTRY_ID, 2, 4, $x, $stale, 'kept');
            self::fail('A stale override revision moved the pointer.');
        } catch (StudioPersistenceRace) {
            // The joined transaction rolls the stored revision back with the entry write; only the pointer is
            // observable here, and it did not move.
            self::assertNull($this->compositionOverrides->itemBlueprintRevision);
            self::assertSame(4, $this->compositionOverrides->revision);
        }

        $foreignRecord = new EntryCompositionOverrides($site, self::OTHER_ENTRY_ID, new stdClass(), 4);
        $foreignSite = new EntryCompositionOverrides(
            SiteContext::fromString('publisher-namibia'),
            self::ENTRY_ID,
            new stdClass(),
            4,
        );
        $cases = [
            'kept without a layout' => [self::ENTRY_ID, null, $this->compositionOverrides, 'kept'],
            'cleared with a layout' => [self::ENTRY_ID, $x, $this->compositionOverrides, 'inherited'],
            'unknown reason' => [self::ENTRY_ID, null, $this->compositionOverrides, 'reset'],
            'another entry layout' => [self::OTHER_ENTRY_ID, $x, null, 'kept'],
            'another entry record' => [self::ENTRY_ID, $x, $foreignRecord, 'kept'],
            'another site record' => [self::ENTRY_ID, $x, $foreignSite, 'kept'],
        ];
        foreach ($cases as $label => [$entryId, $layout, $current, $reason]) {
            try {
                $service->keepItemLayout(self::context(), $entryId, 2, 4, $layout, $current, $reason);
                self::fail(sprintf('The item layout save with %s was accepted.', $label));
            } catch (LogicException $refused) {
                self::assertNotSame('', $refused->getMessage(), $label);
            }
        }
        self::assertSame([], $audit->events);
    }

    /**
     * Reading an entry's item layout loads the exact pinned revision, answers null without a pointer, for a
     * layout made for another type version and for a layout locked to a theme that is no longer published, and
     * refuses a missing revision and another site's or entry's record.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheEditorReadLoadsTheExactPinAndDetachesAnotherTypeVersion(): void
    {
        $service = $this->compositionService(new RecordingAuditRecorder());
        $type = $this->provisioned($service);
        $document = $type->blueprint->document();
        $x = $service->admitItemLayout(
            self::context(),
            self::ENTRY_ID,
            self::$compositionTypeId,
            4,
            self::moved($document->roots),
            self::usedLocks($document),
            $document->dependencyLock->blocks,
            self::reference($type->blueprint),
        );
        $site = SiteContext::default();
        $service->keepItemLayout(self::context(), self::ENTRY_ID, 1, 4, $x, null, 'kept');
        $pinned = $this->compositionOverrides;
        self::assertInstanceOf(EntryCompositionOverrides::class, $pinned);

        self::assertNull($service->itemLayout(
            self::context(),
            self::$compositionTypeId,
            4,
            new EntryCompositionOverrides($site, self::ENTRY_ID, new stdClass(), 1),
        ));
        $loaded = $service->itemLayout(self::context(), self::$compositionTypeId, 4, $pinned);
        self::assertNotNull($loaded);
        self::assertSame($x->canonicalDocument, $loaded->canonicalDocument);

        $earlier = $x->document();
        $earlier->model->version = '0.0.3';
        $earlier->model->revision = 'content-type-v3';
        $earlier->revision = 'item-' . str_repeat('c', 64);
        $this->compositionItemArtifacts[] = (new StudioArtifactAdmission(
            StudioDocumentSchemaRegistry::fromVendoredCorpus(),
        ))->admit(SiteContext::DEFAULT, $earlier);
        $detached = new EntryCompositionOverrides($site, self::ENTRY_ID, new stdClass(), 2, $earlier->revision);
        self::assertNull($service->itemLayout(self::context(), self::$compositionTypeId, 4, $detached));

        $foreign = SiteContext::fromString('publisher-namibia');
        $missing = 'item-' . str_repeat('d', 64);
        $refusals = [
            'missing revision' => new EntryCompositionOverrides($site, self::ENTRY_ID, new stdClass(), 2, $missing),
            'another site' => new EntryCompositionOverrides($foreign, self::ENTRY_ID, new stdClass(), 2, $x->revision),
            'another entry' => new EntryCompositionOverrides($site, self::OTHER_ENTRY_ID, (object) [], 2, $x->revision),
        ];
        foreach ($refusals as $label => $overrides) {
            try {
                $service->itemLayout(self::context(), self::$compositionTypeId, 4, $overrides);
                self::fail(sprintf('The item layout read with %s was accepted.', $label));
            } catch (RuntimeException $refused) {
                self::assertNotSame('', $refused->getMessage(), $label);
            }
        }

        $this->retheme();
        self::assertNull(
            $service->itemLayout(self::context(), self::$compositionTypeId, 4, $pinned),
            'A layout locked to a theme that is no longer published is kept but not used.',
        );
        self::assertCount(1, array_filter(
            $this->compositionItemArtifacts,
            static fn (StoredStudioArtifact $artifact): bool => $artifact->revision === $x->revision,
        ));
    }

    /**
     * Provision the fixture type's composition, whose draft composes the default derived from its model.
     *
     * @param   StudioContentCompositionService  $service  Service under test.
     *
     * @return  StudioContentComposition  The provisioned type composition.
     *
     * @since   2.0.0
     */
    private function provisioned(StudioContentCompositionService $service): StudioContentComposition
    {
        $composition = $service->provision(
            self::context(),
            self::$compositionTypeId,
            4,
            StudioContentCompositionService::RENDERERS,
        );
        self::assertCount(1, $composition->blueprint->document()->roots);

        return $composition;
    }

    /**
     * Admit one item layout for the fixture type at version four.
     *
     * @param   StudioContentCompositionService  $service        Service under test.
     * @param   string                           $entryId        Entry that keeps the layout.
     * @param   list<mixed>                      $roots          Authored roots.
     * @param   list<stdClass>                   $locks          Locks of the composed blocks.
     * @param   list<stdClass>                   $admittedLocks  Every lock the deployment renders.
     * @param   stdClass                         $base           Type Blueprint reference the layout was made from.
     *
     * @return  StoredStudioArtifact  Admitted candidate item layout.
     *
     * @since   2.0.0
     */
    private static function admit(
        StudioContentCompositionService $service,
        string $entryId,
        array $roots,
        array $locks,
        array $admittedLocks,
        stdClass $base,
    ): StoredStudioArtifact {
        return $service->admitItemLayout(
            self::context(),
            $entryId,
            self::$compositionTypeId,
            4,
            $roots,
            $locks,
            $admittedLocks,
            $base,
        );
    }

    /**
     * The read authority every scenario acts with.
     *
     * @return  ExecutionContext  Authorized Content reader on the default site.
     *
     * @since   2.0.0
     */
    private static function context(): ExecutionContext
    {
        return AuthorizationContext::human(['content.read']);
    }

    /**
     * The exact `{id, version, revision}` reference of one stored Blueprint.
     *
     * @param   StoredStudioArtifact  $artifact  Stored type Blueprint.
     *
     * @return  stdClass  Exact reference.
     *
     * @since   2.0.0
     */
    private static function reference(StoredStudioArtifact $artifact): stdClass
    {
        return (object) ['id' => $artifact->id, 'version' => $artifact->version, 'revision' => $artifact->revision];
    }

    /**
     * Copy the default roots with the section's last field block moved to the top.
     *
     * @param   array<mixed>  $roots  Default composition roots.
     *
     * @return  list<mixed>  Moved roots.
     *
     * @since   2.0.0
     */
    private static function moved(array $roots): array
    {
        $copy = self::copied($roots);
        $section = $copy[0];
        self::assertInstanceOf(stdClass::class, $section);
        $children = $section->slots->content;
        $section->slots->content = [array_pop($children), ...$children];

        return $copy;
    }

    /**
     * Copy the default roots keeping only the section's first field block.
     *
     * @param   array<mixed>  $roots  Default composition roots.
     *
     * @return  list<mixed>  Trimmed roots.
     *
     * @since   2.0.0
     */
    private static function trimmed(array $roots): array
    {
        $copy = self::copied($roots);
        $section = $copy[0];
        self::assertInstanceOf(stdClass::class, $section);
        $section->slots->content = [$section->slots->content[0]];

        return $copy;
    }

    /**
     * Deep-copy one node list through canonical JSON.
     *
     * @param   array<mixed>  $roots  Node list to copy.
     *
     * @return  list<mixed>  Independent copy.
     *
     * @since   2.0.0
     */
    private static function copied(array $roots): array
    {
        $copy = json_decode(CanonicalJson::stringify($roots), false, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($copy);
        self::assertTrue(array_is_list($copy));

        return $copy;
    }

    /**
     * The type Blueprint's locks of exactly the block types its default composes.
     *
     * @param   stdClass  $document  Type Blueprint document.
     *
     * @return  list<stdClass>  Locks of the section and field block types.
     *
     * @since   2.0.0
     */
    private static function usedLocks(stdClass $document): array
    {
        return array_values(array_filter(
            $document->dependencyLock->blocks,
            static fn (stdClass $lock): bool
                => in_array($lock->type, ['studio.core/section', 'core/field-text'], true),
        ));
    }

    /**
     * The member names of one document, sorted.
     *
     * @param   stdClass  $document  Document to inspect.
     *
     * @return  list<string>  Sorted member names.
     *
     * @since   2.0.0
     */
    private static function sortedKeys(stdClass $document): array
    {
        $keys = array_keys(get_object_vars($document));
        sort($keys);

        return $keys;
    }
}
