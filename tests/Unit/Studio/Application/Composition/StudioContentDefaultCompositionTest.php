<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Composition;

use DateTimeImmutable;
use Kumwe\App\BusinessSurface\Presentation\Field\SdkFieldConfigurationAdmission;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Studio\Application\Composition\StudioCompositionContributionCatalog;
use Kumwe\App\Studio\Application\Composition\StudioContentCompositionService;
use Kumwe\App\Studio\Application\Composition\StudioContentDefaultComposition;
use Kumwe\App\Studio\Application\Host\StudioArtifactAdmission;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Application\Projection\RecordAuthorizedStudioContentFieldDisclosure;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\App\Studio\Application\Rendering\StudioContentFieldBlockRenderer;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;
use Kumwe\Content\Domain\ContentTypeDefinition;
use Kumwe\Content\Domain\JsonSchemaValidator;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Pins the default composition a Content type version without an authored layout opens with (App ADR 0024).
 *
 * The derivation is pure: it reads only the projected Content model and the block lock the Blueprint carries,
 * so these cases need no database. The Page-shaped case runs the real projector over the seeded Page schema,
 * so the owner's own page is pinned here rather than in an order-dependent browser journey.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioContentDefaultComposition::class)]
#[UsesClass(ContentStudioProjector::class)]
#[UsesClass(RecordAuthorizedStudioContentFieldDisclosure::class)]
#[UsesClass(StudioArtifactAdmission::class)]
#[UsesClass(StudioCompositionContributionCatalog::class)]
#[UsesClass(StudioBlockRendererRuntime::class)]
#[UsesClass(StudioContentFieldBlockRenderer::class)]
#[UsesClass(ExtensionContributionRegistrySet::class)]
final class StudioContentDefaultCompositionTest extends TestCase
{
    /**
     * Content type the Page-shaped projection is made for.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string PAGE_TYPE_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026be730';

    /**
     * Content-field block types a fixture lock carries beside the core section.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array FIELD_TYPES = [
        'core/field-text',
        'core/field-rich-text',
        'core/field-integer',
        'core/field-decimal',
        'core/field-boolean',
        'core/field-date',
        'core/field-date-time',
        'core/field-media',
        'core/field-resource',
    ];

    /**
     * A model that exposes only the entry title composes one section holding one text block bound to it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATitleOnlyModelComposesOneSectionHoldingTheTitle(): void
    {
        $roots = StudioContentDefaultComposition::roots(self::model([self::title()]), self::locks());

        self::assertCount(1, $roots);
        $section = $roots[0];
        self::assertSame(StudioContentDefaultComposition::SECTION_ID, $section->id);
        self::assertSame('default/section', $section->id);
        self::assertSame('studio.core/section', $section->type);
        self::assertSame('1.0.0', $section->version);
        self::assertEquals(new stdClass(), $section->properties);
        self::assertEquals(new stdClass(), $section->bindings);
        self::assertEquals((object) ['mode' => 'structural'], $section->authoring);
        self::assertSame(['content'], array_keys(get_object_vars($section->slots)));
        self::assertCount(1, $section->slots->content);

        $title = $section->slots->content[0];
        self::assertSame('default/field/title', $title->id);
        self::assertSame('core/field-text', $title->type);
        self::assertSame('1.0.0', $title->version);
        self::assertEquals(new stdClass(), $title->properties);
        self::assertEquals(new stdClass(), $title->slots);
        self::assertEquals((object) ['mode' => 'content'], $title->authoring);
        self::assertSame(['value'], array_keys(get_object_vars($title->bindings)));
        $binding = $title->bindings->value;
        self::assertEquals((object) ['kind' => 'entry-field', 'fieldPath' => ['title']], $binding->source);
        self::assertSame([], $binding->transforms);
        self::assertSame('empty', $binding->onNull);
        self::assertSame('error', $binding->onError);
        self::assertFalse(property_exists($binding, 'fallback'));

        // Every empty member is a JSON object, never a JSON array, as the Blueprint node schema requires.
        self::assertSame(
            '[{"authoring":{"mode":"structural"},"bindings":{},"id":"default/section","properties":{},'
            . '"slots":{"content":[{"authoring":{"mode":"content"},"bindings":{"value":{"onError":"error",'
            . '"onNull":"empty","source":{"fieldPath":["title"],"kind":"entry-field"},"transforms":[]}},'
            . '"id":"default/field/title","properties":{},"slots":{},"type":"core/field-text","version":"1.0.0"}]},'
            . '"type":"studio.core/section","version":"1.0.0"}]',
            CanonicalJson::stringify($roots),
        );
    }

    /**
     * Scalar kinds map to their field blocks in authoring order; other kinds, the slug and hidden fields stay out.
     *
     * The second half projects the seeded Page schema, without the header logo a later migration removed,
     * through the real projector and pins the default the owner's Page opens with.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testScalarKindsMapToTheirFieldBlocksInAuthoringOrder(): void
    {
        $hidden = self::dataField('data_hidden', 'string', 21);
        $hidden->authoring->hidden = true;
        $unordered = self::dataField('data_unordered', 'string', 0);
        unset($unordered->authoring->order);
        $model = self::model([
            self::dataField('data_tags', 'collection', 19, 'many'),
            self::dataField('data_when', 'date', 15),
            self::title(),
            self::dataField('data_count', 'integer', 11),
            self::dataField('data_tone', 'enum', 17),
            $unordered,
            self::dataField('data_price', 'decimal', 12),
            self::slug(),
            self::dataField('data_flag', 'boolean', 13),
            self::dataField('data_cta', 'object', 18),
            self::dataField('data_body', 'string', 10),
            self::dataField('data_moment', 'date-time', 16),
            self::dataField('data_logo', 'media', 14),
            $hidden,
            self::dataField('data_second', 'string', 20),
            self::dataField('data_first', 'string', 20),
        ]);

        $children = self::children(StudioContentDefaultComposition::roots($model, self::locks()));

        self::assertSame(
            [
                'title' => 'core/field-text',
                'data_body' => 'core/field-text',
                'data_count' => 'core/field-integer',
                'data_price' => 'core/field-decimal',
                'data_flag' => 'core/field-boolean',
                'data_logo' => 'core/field-media',
                'data_when' => 'core/field-date',
                'data_moment' => 'core/field-date-time',
                // Fields sharing an order keep their position in the model.
                'data_second' => 'core/field-text',
                'data_first' => 'core/field-text',
                // A field without an order follows every ordered one.
                'data_unordered' => 'core/field-text',
            ],
            self::boundTypes($children),
        );
        foreach ($children as $child) {
            self::assertSame(
                StudioContentDefaultComposition::fieldNodeId($child->bindings->value->source->fieldPath[0]),
                $child->id,
            );
        }

        $page = self::pageModel();
        $pageChildren = self::children(StudioContentDefaultComposition::roots($page, self::locks()));

        self::assertSame(
            [
                'default/field/title',
                'default/field/data:body',
                'default/field/data:eyebrow',
                'default/field/data:heading',
                'default/field/data:logo',
                'default/field/data:summary',
            ],
            array_column($pageChildren, 'id'),
        );
        self::assertSame(
            [
                'title' => 'core/field-text',
                'data_body' => 'core/field-text',
                'data_eyebrow' => 'core/field-text',
                'data_heading' => 'core/field-text',
                'data_logo' => 'core/field-media',
                'data_summary' => 'core/field-text',
            ],
            self::boundTypes($pageChildren),
        );
    }

    /**
     * Only block types the lock carries are composed, at the lock's own versions.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOnlyLockedBlockTypesAreComposed(): void
    {
        $model = self::model([
            self::title(),
            self::dataField('data_count', 'integer', 10),
            self::dataField('data_body', 'string', 11),
        ]);

        $withoutInteger = array_values(array_filter(
            self::locks(),
            static fn (stdClass $lock): bool => $lock->type !== 'core/field-integer',
        ));
        self::assertSame(
            ['title' => 'core/field-text', 'data_body' => 'core/field-text'],
            self::boundTypes(self::children(StudioContentDefaultComposition::roots($model, $withoutInteger))),
        );

        $withoutSection = array_values(array_filter(
            self::locks(),
            static fn (stdClass $lock): bool => $lock->type !== 'studio.core/section',
        ));
        self::assertSame([], StudioContentDefaultComposition::roots($model, $withoutSection));
        self::assertSame([], StudioContentDefaultComposition::roots($model, []));

        $withoutFields = [self::lock('studio.core/section', '1.0.0', 'layout-section-r1')];
        self::assertSame([], StudioContentDefaultComposition::roots($model, $withoutFields));
        self::assertSame(
            [],
            StudioContentDefaultComposition::roots(
                self::model([self::slug(), self::dataField('data_cta', 'object', 10)]),
                self::locks(),
            ),
        );

        $versioned = [
            self::lock('studio.core/section', '2.0.0', 'layout-section-r9'),
            self::lock('core/field-text', '1.4.0', 'core-block-r9'),
            self::lock('core/field-integer', '1.1.0', 'core-block-r9'),
        ];
        $roots = StudioContentDefaultComposition::roots($model, $versioned);
        self::assertSame('2.0.0', $roots[0]->version);
        self::assertSame(['1.4.0', '1.1.0', '1.4.0'], array_column(self::children($roots), 'version'));
    }

    /**
     * Node identities are valid, distinct for distinct fields, and the derivation is byte-identical every time.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNodeIdentitiesAreDeterministicAndDistinct(): void
    {
        self::assertSame('default/field/data:body', StudioContentDefaultComposition::fieldNodeId('data_body'));
        self::assertSame('default/field/data-body', StudioContentDefaultComposition::fieldNodeId('data-body'));
        self::assertSame('default/field/title', StudioContentDefaultComposition::fieldNodeId('title'));

        $model = self::model([
            self::title(),
            self::dataField('data_body', 'string', 10),
            self::dataField('data-body', 'string', 11),
            self::dataField('data.body', 'string', 12),
        ]);
        $roots = StudioContentDefaultComposition::roots($model, self::locks());
        $ids = array_column(self::children($roots), 'id');

        self::assertSame(
            ['default/field/title', 'default/field/data:body', 'default/field/data-body', 'default/field/data.body'],
            $ids,
        );
        self::assertSame($ids, array_values(array_unique($ids)));
        foreach ([$roots[0]->id, ...$ids] as $id) {
            self::assertMatchesRegularExpression('#^[A-Za-z0-9][A-Za-z0-9._:/-]*$#D', $id);
        }

        $decoded = json_decode(json_encode($model, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $decoded);
        self::assertSame(
            CanonicalJson::stringify($roots),
            CanonicalJson::stringify(StudioContentDefaultComposition::roots($model, self::locks())),
        );
        self::assertSame(
            CanonicalJson::stringify($roots),
            CanonicalJson::stringify(StudioContentDefaultComposition::roots($decoded, self::locks())),
        );
    }

    /**
     * Only a stored draft with no roots is presented with the default; its coordinates and lock are kept.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPresentedReplacesOnlyAnEmptyDraft(): void
    {
        $model = self::model([self::title(), self::dataField('data_body', 'string', 10)]);
        $stored = self::blueprint($model, self::locks(), []);
        $before = CanonicalJson::stringify($stored);

        $presented = StudioContentDefaultComposition::presented($stored, $model, self::locks());

        self::assertNotSame($stored, $presented);
        self::assertSame($before, CanonicalJson::stringify($stored), 'The stored document is never changed.');
        self::assertEquals(StudioContentDefaultComposition::roots($model, self::locks()), $presented->roots);
        self::assertSame(
            ['default/field/title', 'default/field/data:body'],
            array_column(self::children($presented->roots), 'id'),
        );
        $kept = get_object_vars($presented);
        unset($kept['roots']);
        $original = get_object_vars($stored);
        unset($original['roots']);
        self::assertEquals($original, $kept);
        foreach (['id', 'version', 'revision', 'status'] as $member) {
            self::assertSame($stored->{$member}, $presented->{$member}, $member . ' is kept.');
        }

        $published = self::blueprint($model, self::locks(), [], 'published');
        self::assertSame($published, StudioContentDefaultComposition::presented($published, $model, self::locks()));

        $authored = self::blueprint($model, self::locks(), $presented->roots);
        self::assertSame($authored, StudioContentDefaultComposition::presented($authored, $model, self::locks()));

        $withoutRoots = self::blueprint($model, self::locks(), []);
        unset($withoutRoots->roots);
        self::assertSame(
            $withoutRoots,
            StudioContentDefaultComposition::presented($withoutRoots, $model, self::locks()),
        );

        // A type save stores an empty layout locking no block; the renderable locks fill in what it composes.
        $unlocked = self::blueprint($model, [], []);
        $widened = StudioContentDefaultComposition::presented($unlocked, $model, self::locks());
        self::assertEquals($presented->roots, $widened->roots);
        self::assertSame(
            ['studio.core/section', 'core/field-text'],
            array_column($widened->dependencyLock->blocks, 'type'),
        );
        self::assertSame([], $unlocked->dependencyLock->blocks, 'The stored lock is never changed.');
        self::assertSame($unlocked, StudioContentDefaultComposition::presented($unlocked, $model, []));
    }

    /**
     * The default under the live deployment lock is a schema-valid Blueprint that canonical admission accepts.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDefaultIsAnAdmissibleBlueprint(): void
    {
        $registries = new ExtensionContributionRegistrySet(
            new DeterministicCanonicalEncoder(),
            new SdkFieldConfigurationAdmission(),
        );
        $catalog = new StudioCompositionContributionCatalog(
            $registries,
            new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer()),
        );
        $locks = $catalog->project([], StudioContentCompositionService::RENDERERS)->blockLocks;
        $model = self::model([
            self::title(),
            self::dataField('data_body', 'string', 10),
            self::dataField('data_count', 'integer', 11),
            self::dataField('data_flag', 'boolean', 12),
            self::dataField('data_logo', 'media', 13),
        ]);
        $roots = StudioContentDefaultComposition::roots($model, $locks);
        self::assertSame(
            [
                'title' => 'core/field-text',
                'data_body' => 'core/field-text',
                'data_count' => 'core/field-integer',
                'data_flag' => 'core/field-boolean',
                'data_logo' => 'core/field-media',
            ],
            self::boundTypes(self::children($roots)),
        );
        $admission = new StudioArtifactAdmission(StudioDocumentSchemaRegistry::fromVendoredCorpus());

        foreach (['draft', 'published'] as $status) {
            $blueprint = self::blueprint($model, $locks, $roots, $status);
            $stored = $admission->admit('default', $blueprint);

            self::assertSame($status, $stored->status);
            self::assertSame(CanonicalJson::stringify($blueprint), $stored->canonicalDocument);
        }
    }

    /**
     * Only a draft whose handed roots are the derived default, saved without change, counts as untouched.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOnlyAnUntouchedDerivedDefaultCountsAsUntouched(): void
    {
        $model = self::model([
            self::title(),
            self::dataField('data_body', 'string', 10),
            self::dataField('data_count', 'integer', 11),
        ]);
        $default = StudioContentDefaultComposition::roots($model, self::locks());
        $handed = self::blueprint($model, self::locks(), $default);
        $roundTrip = static function (array $roots): array {
            $decoded = json_decode(json_encode($roots, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);

            return $decoded;
        };

        // The roots the browser sends back are compared canonically, so member order does not matter.
        self::assertTrue(StudioContentDefaultComposition::untouched($handed, $model, $roundTrip($default)));
        $reordered = $roundTrip($default);
        $reordered[0] = (object) array_reverse(get_object_vars($reordered[0]), true);
        self::assertTrue(StudioContentDefaultComposition::untouched($handed, $model, $reordered));

        // One node moved.
        $moved = $roundTrip($default);
        $moved[0]->slots->content = array_reverse($moved[0]->slots->content);
        self::assertFalse(StudioContentDefaultComposition::untouched($handed, $model, $moved));

        // One node removed.
        $removed = $roundTrip($default);
        array_pop($removed[0]->slots->content);
        self::assertFalse(StudioContentDefaultComposition::untouched($handed, $model, $removed));

        // An authored draft layout that is not the derived default, saved unchanged, publishes as before.
        $authoredLayout = $roundTrip($default);
        array_shift($authoredLayout[0]->slots->content);
        $authoredDraft = self::blueprint($model, self::locks(), $authoredLayout);
        self::assertFalse(
            StudioContentDefaultComposition::untouched($authoredDraft, $model, $roundTrip($authoredLayout)),
        );

        // A published layout, and the blank canvas, are never an untouched default.
        $published = self::blueprint($model, self::locks(), $default, 'published');
        self::assertFalse(StudioContentDefaultComposition::untouched($published, $model, $roundTrip($default)));
        $blank = self::blueprint($model, self::locks(), []);
        self::assertFalse(StudioContentDefaultComposition::untouched($blank, $model, []));
        self::assertFalse(StudioContentDefaultComposition::untouched($blank, $model, $roundTrip($default)));
    }

    /**
     * Build a projected Content model coordinate around the given fields.
     *
     * @param   list<stdClass>  $fields  Projected fields in model order.
     *
     * @return  stdClass  Content-model projection.
     *
     * @since   2.0.0
     */
    private static function model(array $fields): stdClass
    {
        return (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'content-model',
            'id' => 'content-model:018f22e2-7c8b-7ab0-8f3a-88e8026be731',
            'version' => '0.0.4',
            'revision' => 'content-model-r4',
            'fields' => $fields,
        ];
    }

    /**
     * Build the projected entry-title field.
     *
     * @return  stdClass  Title field in projector shape.
     *
     * @since   2.0.0
     */
    private static function title(): stdClass
    {
        return self::field('title', 'string', 0, (object) ['storage' => 'entry', 'key' => 'title']);
    }

    /**
     * Build the projected entry-slug field.
     *
     * @return  stdClass  Slug field in projector shape.
     *
     * @since   2.0.0
     */
    private static function slug(): stdClass
    {
        return self::field('slug', 'string', 1, (object) ['storage' => 'entry', 'key' => 'slug']);
    }

    /**
     * Build one projected top-level Content data field.
     *
     * @param   string  $id           Projected field identifier.
     * @param   string  $kind         Projected field kind.
     * @param   int     $order        Authoring order.
     * @param   string  $cardinality  Projected cardinality.
     *
     * @return  stdClass  Data field in projector shape.
     *
     * @since   2.0.0
     */
    private static function dataField(string $id, string $kind, int $order, string $cardinality = 'one'): stdClass
    {
        return self::field(
            $id,
            $kind,
            $order,
            (object) ['storage' => 'data', 'key' => str_replace(['data_', 'data-', 'data.'], '', $id)],
            $cardinality,
        );
    }

    /**
     * Build one projected field.
     *
     * @param   string    $id           Projected field identifier.
     * @param   string    $kind         Projected field kind.
     * @param   int       $order        Authoring order.
     * @param   stdClass  $source       App source-field extension.
     * @param   string    $cardinality  Projected cardinality.
     *
     * @return  stdClass  Field in projector shape.
     *
     * @since   2.0.0
     */
    private static function field(
        string $id,
        string $kind,
        int $order,
        stdClass $source,
        string $cardinality = 'one',
    ): stdClass {
        return (object) [
            'id' => $id,
            'kind' => $kind,
            'label' => (object) ['key' => 'kumwe.test/' . strtr($id, '_', '-'), 'defaultMessage' => $id],
            'required' => false,
            'localized' => true,
            'cardinality' => $cardinality,
            'authoring' => (object) ['group' => 'content', 'order' => $order, 'width' => 'full'],
            'extensions' => (object) ['kumwe.app/source-field' => $source],
        ];
    }

    /**
     * Build one exact block lock.
     *
     * @param   string  $type      Block type.
     * @param   string  $version   Locked version.
     * @param   string  $revision  Locked revision.
     *
     * @return  stdClass  Lock entry.
     *
     * @since   2.0.0
     */
    private static function lock(string $type, string $version, string $revision): stdClass
    {
        return (object) ['type' => $type, 'version' => $version, 'revision' => $revision];
    }

    /**
     * Build the lock of the core section and every Content-field block.
     *
     * @return  list<stdClass>  Exact block locks.
     *
     * @since   2.0.0
     */
    private static function locks(): array
    {
        $locks = [self::lock('studio.core/section', '1.0.0', 'layout-section-r1')];
        foreach (self::FIELD_TYPES as $type) {
            $locks[] = self::lock($type, '1.0.0', 'core-block-r2');
        }

        return $locks;
    }

    /**
     * Build a provisioned-shape Blueprint around one model, lock and roots.
     *
     * @param   stdClass        $model   Projected model the Blueprint locks.
     * @param   list<stdClass>  $locks   Exact block locks.
     * @param   list<stdClass>  $roots   Blueprint roots.
     * @param   string          $status  Blueprint lifecycle status.
     *
     * @return  stdClass  Blueprint document.
     *
     * @since   2.0.0
     */
    private static function blueprint(stdClass $model, array $locks, array $roots, string $status = 'draft'): stdClass
    {
        return (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'blueprint',
            'id' => 'content-blueprint:018f22e2-7c8b-7ab0-8f3a-88e8026be731',
            'version' => '1.0.0',
            'revision' => 'initial-' . str_repeat('b', 64),
            'owner' => (object) ['id' => 'kumwe.app/content', 'version' => '2.0.0'],
            'status' => $status,
            'label' => (object) ['key' => 'kumwe.app/content-blueprint', 'defaultMessage' => 'Content composition'],
            'model' => (object) ['id' => $model->id, 'version' => $model->version, 'revision' => $model->revision],
            'dependencyLock' => (object) [
                'theme' => (object) ['id' => 'core.theme/site', 'version' => '1.0.0', 'revision' => 'theme-r1'],
                'blocks' => $locks,
            ],
            'roots' => $roots,
        ];
    }

    /**
     * Project the seeded Page schema, without its removed header logo, through the real projector.
     *
     * @return  stdClass  Schema-valid Page content-model projection.
     *
     * @since   2.0.0
     */
    private static function pageModel(): stdClass
    {
        $richText = static fn (string $title, string $description): array => [
            'type' => 'string',
            'title' => $title,
            'description' => $description,
            'maxLength' => 50_000,
        ];
        $action = static fn (string $title): array => [
            'type' => 'object',
            'title' => $title,
            'properties' => [
                'label' => ['type' => 'string', 'title' => 'Label', 'maxLength' => 120],
                'url' => ['type' => 'string', 'format' => 'uri-reference', 'title' => 'URL'],
            ],
            'additionalProperties' => false,
        ];
        $section = static fn (string $title): array => [
            'type' => 'object',
            'title' => $title,
            'properties' => [
                'anchor' => [
                    'type' => 'string',
                    'title' => 'Section anchor',
                    'pattern' => '^[A-Za-z][A-Za-z0-9._:-]{0,190}$',
                    'description' => 'The fragment used by a #section menu link.',
                ],
                'heading' => ['type' => 'string', 'title' => 'Heading', 'maxLength' => 255],
                'body' => $richText('Body', 'Rich text displayed in this anchored section.'),
            ],
            'additionalProperties' => false,
        ];
        $now = new DateTimeImmutable('2026-10-07T12:00:00+00:00');
        $definition = new ContentTypeDefinition(
            self::PAGE_TYPE_ID,
            SiteContext::default(),
            'page',
            'Page',
            ContentService::CORE_WORKFLOW_ID,
            1,
            [
                'type' => 'object',
                'properties' => [
                    'logo' => [
                        'type' => 'string',
                        'format' => 'uri-reference',
                        'x-kumwe-field' => 'media',
                        'title' => 'Page logo',
                        'description' => 'Choose a reusable image from Media.',
                    ],
                    'eyebrow' => ['type' => 'string', 'title' => 'Eyebrow', 'maxLength' => 160],
                    'heading' => ['type' => 'string', 'title' => 'Hero heading', 'maxLength' => 255],
                    'summary' => ['type' => 'string', 'title' => 'Hero summary', 'maxLength' => 1_000],
                    'primary_action' => $action('Primary action'),
                    'secondary_action' => $action('Secondary action'),
                    'body' => $richText('Page body', 'The main page content.'),
                    'capabilities' => $section('Capabilities section'),
                    'platform' => $section('Platform section'),
                ],
                'required' => ['body'],
                'additionalProperties' => false,
            ],
            2,
            $now,
            $now,
        );

        return (new ContentStudioProjector(
            StudioDocumentSchemaRegistry::fromVendoredCorpus(),
            new RecordAuthorizedStudioContentFieldDisclosure(),
            new JsonSchemaValidator(),
        ))->contentModel(AuthorizationContext::human(['content.read']), $definition);
    }

    /**
     * Return the field blocks the single derived section holds.
     *
     * @param   list<stdClass>  $roots  Derived roots.
     *
     * @return  list<stdClass>  Section children.
     *
     * @since   2.0.0
     */
    private static function children(array $roots): array
    {
        self::assertCount(1, $roots);
        $children = $roots[0]->slots->content;
        self::assertIsArray($children);

        return $children;
    }

    /**
     * Map each field block's bound field identifier to its block type, in composition order.
     *
     * @param   list<stdClass>  $children  Field blocks.
     *
     * @return  array<string, string>  Block type keyed by bound field identifier.
     *
     * @since   2.0.0
     */
    private static function boundTypes(array $children): array
    {
        $types = [];
        foreach ($children as $child) {
            $types[$child->bindings->value->source->fieldPath[0]] = $child->type;
        }

        return $types;
    }
}
