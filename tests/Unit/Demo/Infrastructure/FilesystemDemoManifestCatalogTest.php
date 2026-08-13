<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Demo\Infrastructure;

use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\CMS\Demo\Infrastructure\FilesystemDemoManifestCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the shipped content and business demo manifests as one coherent release contract.
 *
 * @since  2.0.0
 */
#[CoversClass(FilesystemDemoManifestCatalog::class)]
#[UsesClass(CanonicalDefinitionJson::class)]
final class FilesystemDemoManifestCatalogTest extends TestCase
{
    /**
     * Documented fields every page of one layout must author, keyed by content-type identity.
     *
     * The core Page type demands the four hero fields; each document-driven layout demands the
     * fields its public template cannot render without. A page failing this list would install
     * fine and then present an empty surface, so the release contract refuses it here.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private const array REQUIRED_PAGE_FIELDS = [
        '018f22e2-7c8b-7ab0-8f3a-88e8026bb402' => ['eyebrow', 'heading', 'summary', 'body'],
        '018f22e2-7c8b-7ab0-8f3a-88e8026bb410' => ['heading', 'body', 'sections'],
        '018f22e2-7c8b-7ab0-8f3a-88e8026bb411' => ['heading', 'body', 'steps'],
        '018f22e2-7c8b-7ab0-8f3a-88e8026bb412' => ['heading', 'entries'],
        '018f22e2-7c8b-7ab0-8f3a-88e8026bb413' => ['heading', 'items'],
        '018f22e2-7c8b-7ab0-8f3a-88e8026bb414' => ['heading', 'features'],
        '018f22e2-7c8b-7ab0-8f3a-88e8026bb415' => ['heading', 'body'],
    ];

    /**
     * Proves the default documentation profile is a complete linked guide rather than a landing-page stub.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentationProfileProvidesTwentyEightLinkedGuides(): void
    {
        $loaded = $this->catalog()->content('documentation');
        $manifest = $loaded['manifest'];
        $content = $this->list($manifest['content'] ?? null, 'documentation content');
        $menus = $this->list($manifest['menus'] ?? null, 'documentation menus');

        self::assertSame('kumwe.demo-content/v1', $manifest['format'] ?? null);
        self::assertSame('documentation', $manifest['profile'] ?? null);
        self::assertSame(4, $manifest['version'] ?? null);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $loaded['checksum']);
        self::assertCount(28, $content);
        self::assertCount(1, $menus);

        $reservedSystemRoutes = [
            'administrator',
            'api',
            'assets',
            'health',
            'mcp',
            'media',
            'pages',
        ];
        $pages = [];
        $pageIds = [];
        $layoutsUsed = [];
        foreach ($content as $candidate) {
            $page = $this->map($candidate, 'documentation page');
            $fixtureKey = $this->string($page, 'fixture_key');
            $resourceId = $this->string($page, 'resource_id');
            $typeId = $this->string($page, 'content_type_id');
            self::assertNotContains($this->string($page, 'slug'), $reservedSystemRoutes);
            self::assertArrayNotHasKey($fixtureKey, $pages);
            self::assertArrayNotHasKey($resourceId, $pageIds);
            self::assertArrayHasKey($typeId, self::REQUIRED_PAGE_FIELDS);
            self::assertIsInt($page['content_type_version'] ?? null);
            self::assertSame('published', $page['workflow_state_key'] ?? null);
            $data = $this->map($page['data'] ?? null, 'page data');
            foreach (self::REQUIRED_PAGE_FIELDS[$typeId] as $requiredField) {
                self::assertArrayHasKey($requiredField, $data);
            }
            foreach (['primary_action', 'secondary_action'] as $optionalAction) {
                if (!array_key_exists($optionalAction, $data)) {
                    continue;
                }
                $action = $this->map($data[$optionalAction], sprintf('%s action', $optionalAction));
                $this->string($action, 'label');
                $this->string($action, 'url');
            }
            $pages[$fixtureKey] = $page;
            $pageIds[$resourceId] = true;
            $layoutsUsed[$typeId] = true;
        }

        self::assertCount(
            count(self::REQUIRED_PAGE_FIELDS),
            $layoutsUsed,
            'Every shipped layout must be demonstrated by at least one page.',
        );

        $settings = $this->map($manifest['settings'] ?? null, 'documentation settings');
        self::assertSame('page.welcome', $settings['homepage_content_fixture_key'] ?? null);
        self::assertSame($pages['page.welcome']['resource_id'], $settings['homepage_content_id'] ?? null);
        self::assertSame('Welcome to Kumwe', $pages['page.welcome']['title'] ?? null);

        $menu = $this->map($menus[0], 'documentation menu');
        $items = $this->list($menu['items'] ?? null, 'documentation menu items');
        self::assertSame('main', $menu['handle'] ?? null);
        self::assertCount(29, $items);
        $seen = [];
        $externalTargets = [];
        foreach ($items as $candidate) {
            $item = $this->map($candidate, 'documentation menu item');
            $fixtureKey = $this->string($item, 'fixture_key');
            if (($item['target_type'] ?? null) === 'url') {
                $target = $this->string($item, 'target_url');
                self::assertStringStartsWith('https://', $target);
                self::assertNull($item['content_fixture_key'] ?? null);
                $externalTargets[] = $target;
                self::assertArrayNotHasKey($fixtureKey, $seen);
                $seen[$fixtureKey] = $item;
                continue;
            }
            $contentKey = $this->string($item, 'content_fixture_key');
            self::assertArrayHasKey($contentKey, $pages);
            self::assertSame($pages[$contentKey]['resource_id'], $item['content_id'] ?? null);
            self::assertSame('content', $item['target_type'] ?? null);
            $parent = $item['parent_fixture_key'] ?? null;
            if ($parent !== null) {
                self::assertIsString($parent);
                self::assertArrayHasKey($parent, $seen, 'Menu children must follow their parent.');
                self::assertNull(
                    $seen[$parent]['parent_fixture_key'] ?? null,
                    'Documentation menus stay one level deep.',
                );
            }
            self::assertArrayNotHasKey($fixtureKey, $seen);
            $seen[$fixtureKey] = $item;
        }
        self::assertSame(
            ['https://github.com/kumwe/cms'],
            $externalTargets,
            'The navigation links back to the project repository.',
        );
    }

    /**
     * Proves the selectable placeholder remains byte-addressable by its previously released stable identities.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPlaceholderProfileRetainsTheReleasedFixtureIdentities(): void
    {
        $manifest = $this->catalog()->content('placeholder')['manifest'];
        $content = $this->list($manifest['content'] ?? null, 'placeholder content');
        $menus = $this->list($manifest['menus'] ?? null, 'placeholder menus');
        $page = $this->map($content[0] ?? null, 'placeholder page');
        $revision = $this->map($page['revision'] ?? null, 'placeholder revision');
        $menu = $this->map($menus[0] ?? null, 'placeholder menu');
        $items = $this->list($menu['items'] ?? null, 'placeholder menu items');

        self::assertSame('placeholder', $manifest['profile'] ?? null);
        self::assertCount(1, $content);
        self::assertSame('Welcome to Kumwe', $page['title'] ?? null);
        self::assertSame('00000000-0000-7000-8000-000000001001', $page['resource_id'] ?? null);
        self::assertSame('00000000-0000-7000-8000-000000001002', $revision['resource_id'] ?? null);
        self::assertSame('00000000-0000-7000-8000-000000001101', $menu['resource_id'] ?? null);
        self::assertSame(
            [
                '00000000-0000-7000-8000-000000001102',
                '00000000-0000-7000-8000-000000001103',
                '00000000-0000-7000-8000-000000001104',
                '00000000-0000-7000-8000-000000001105',
            ],
            array_map(
                fn (mixed $item): string => $this->string($this->map($item, 'placeholder item'), 'resource_id'),
                $items,
            ),
        );
    }

    /**
     * Proves a blank selection retains the required site and primary-menu infrastructure without sample entries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBlankProfileContainsNoExampleEntriesOrMenuItems(): void
    {
        $manifest = $this->catalog()->content('blank')['manifest'];
        $menus = $this->list($manifest['menus'] ?? null, 'blank menus');
        $menu = $this->map($menus[0] ?? null, 'blank menu');
        $settings = $this->map($manifest['settings'] ?? null, 'blank settings');

        self::assertSame('blank', $manifest['profile'] ?? null);
        self::assertSame([], $manifest['content'] ?? null);
        self::assertCount(1, $menus);
        self::assertSame('main', $menu['handle'] ?? null);
        self::assertSame([], $menu['items'] ?? null);
        self::assertNull($settings['homepage_content_fixture_key'] ?? null);
        self::assertNull($settings['homepage_content_id'] ?? null);
    }

    /**
     * Proves the VDM manifest declares a complete, referentially valid business graph and workflow program.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testVdmBusinessProfileHasCompleteReferencesAndExpectedCounts(): void
    {
        $loaded = $this->catalog()->vdmBusiness();
        $manifest = $loaded['manifest'];
        $order = $this->list($manifest['installation_order'] ?? null, 'definition installation order');
        $documents = $this->map($manifest['definition_documents'] ?? null, 'definition documents');
        $recordsDocument = $this->map($manifest['records_document'] ?? null, 'record document');
        $records = $this->list($recordsDocument['records'] ?? null, 'records');
        $relations = $this->list($recordsDocument['relations'] ?? null, 'relations');
        $actions = $this->list($recordsDocument['actions'] ?? null, 'actions');
        $archives = $this->list($recordsDocument['archives'] ?? null, 'archives');
        $expected = $this->map($manifest['expected'] ?? null, 'profile expected counts');

        self::assertSame('kumwe.demo-business-profile/v1', $manifest['format'] ?? null);
        self::assertSame('vdm', $manifest['profile'] ?? null);
        self::assertSame(4, $manifest['version'] ?? null);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $loaded['checksum']);
        self::assertCount(12, $order);
        self::assertSame(count($order), $expected['definition_count'] ?? null);
        self::assertSame(count($records), $expected['record_count'] ?? null);
        self::assertSame(count($relations), $expected['relation_count'] ?? null);
        self::assertSame(count($actions), $expected['action_count'] ?? null);
        self::assertSame(count($archives), $expected['archive_count'] ?? null);

        $definitions = [];
        $definitionKeys = [];
        foreach ($order as $candidate) {
            $entry = $this->map($candidate, 'definition installation entry');
            $fixtureKey = $this->string($entry, 'fixture_key');
            foreach ($this->list($entry['depends_on'] ?? null, 'definition dependencies') as $dependency) {
                self::assertIsString($dependency);
                self::assertArrayHasKey(
                    $dependency,
                    $definitionKeys,
                    'Definition dependencies must be installed first.',
                );
            }
            $definition = $this->map($documents[$fixtureKey] ?? null, 'definition document');
            self::assertSame($entry['id'] ?? null, $definition['id'] ?? null);
            self::assertSame($entry['handle'] ?? null, $definition['handle'] ?? null);
            self::assertSame(['type' => 'site', 'identifier' => 'default'], $definition['owner'] ?? null);
            $definitions[$this->string($definition, 'handle')] = $definition;
            $definitionKeys[$fixtureKey] = true;
        }

        $recordIndex = [];
        foreach ($records as $candidate) {
            $record = $this->map($candidate, 'record declaration');
            $recordId = $this->string($record, 'record_id');
            $definition = $this->string($record, 'definition');
            self::assertArrayNotHasKey($recordId, $recordIndex);
            self::assertArrayHasKey($definition, $definitions);
            $recordIndex[$recordId] = $record;
        }
        $lineIndex = [];
        foreach ($relations as $candidate) {
            $relation = $this->map($candidate, 'relationship declaration');
            $sourceId = $this->string($relation, 'source_record_id');
            $targetId = $this->string($relation, 'target_record_id');
            self::assertArrayHasKey($sourceId, $recordIndex);
            self::assertSame($recordIndex[$sourceId]['definition'], $relation['definition'] ?? null);
            $definition = $definitions[$this->string($relation, 'definition')];
            $relationship = $this->memberByHandle(
                $this->list($definition['relationships'] ?? null, 'definition relationships'),
                $this->string($relation, 'relationship'),
            );
            if (($relationship['kind'] ?? null) === 'owned_line_collection') {
                self::assertArrayNotHasKey($targetId, $recordIndex);
                self::assertArrayNotHasKey($targetId, $lineIndex);
                self::assertArrayHasKey($this->string($relationship, 'target'), $definitions);
                $this->map($relation['target_values'] ?? null, 'owned line values');
                $lineIndex[$targetId] = true;
                continue;
            }
            self::assertArrayHasKey($targetId, $recordIndex);
            self::assertSame($recordIndex[$targetId]['definition'], $relationship['target'] ?? null);
        }
        foreach ($actions as $candidate) {
            $action = $this->map($candidate, 'action declaration');
            $recordId = $this->string($action, 'record_id');
            $definitionHandle = $this->string($action, 'definition');
            self::assertArrayHasKey($recordId, $recordIndex);
            self::assertSame($recordIndex[$recordId]['definition'], $definitionHandle);
            $this->memberByHandle(
                $this->list($definitions[$definitionHandle]['actions'] ?? null, 'definition actions'),
                $this->string($action, 'action'),
            );
        }
        foreach ($archives as $candidate) {
            $archive = $this->map($candidate, 'archive declaration');
            $recordId = $this->string($archive, 'record_id');
            self::assertArrayHasKey($recordId, $recordIndex);
            self::assertSame($recordIndex[$recordId]['definition'], $archive['definition'] ?? null);
        }
    }

    /**
     * Proves financial fixtures use exact strings and fictional contact data without credential-shaped keys.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testVdmRecordsContainExactFictionalNonSecretValues(): void
    {
        $manifest = $this->catalog()->vdmBusiness()['manifest'];
        $documents = $this->map($manifest['definition_documents'] ?? null, 'definition documents');
        $recordDocument = $this->map($manifest['records_document'] ?? null, 'record document');
        $records = $this->list($recordDocument['records'] ?? null, 'records');
        $source = $this->map($manifest['source_context'] ?? null, 'source context');

        self::assertSame(
            'Every client, role, address, request, engagement, and work entry is fictional.',
            $source['fixture_privacy'] ?? null,
        );
        foreach ($records as $candidate) {
            $record = $this->map($candidate, 'record declaration');
            $values = $this->map($record['values'] ?? null, 'record values');
            foreach (array_keys($values) as $key) {
                self::assertDoesNotMatchRegularExpression(
                    '/(?:password|passwd|secret|credential|api[_-]?key|access[_-]?token|private[_-]?key)/i',
                    $key,
                );
            }
            foreach ($values as $value) {
                if (!is_string($value)) {
                    continue;
                }
                self::assertStringNotContainsString('-----BEGIN ', $value);
            }

            $definitionHandle = $this->string($record, 'definition');
            $definition = null;
            foreach ($documents as $candidateDefinition) {
                $mapped = $this->map($candidateDefinition, 'definition document');
                if (($mapped['handle'] ?? null) === $definitionHandle) {
                    $definition = $mapped;
                    break;
                }
            }
            self::assertIsArray($definition);
            foreach ($this->list($definition['fields'] ?? null, 'definition fields') as $candidateField) {
                $field = $this->map($candidateField, 'definition field');
                $type = $field['type'] ?? null;
                if (!in_array($type, ['core.decimal', 'core.money', 'core.quantity'], true)) {
                    continue;
                }
                $handle = $this->string($field, 'handle');
                if (!array_key_exists($handle, $values) || $values[$handle] === null) {
                    continue;
                }
                $exactValue = $values[$handle];
                if ($type !== 'core.decimal') {
                    $measurement = $this->map($exactValue, sprintf('%s measurement', $handle));
                    $exactValue = $measurement['amount'] ?? null;
                    self::assertTrue(
                        isset($measurement['currency']) || isset($measurement['unit']),
                        sprintf('%s must identify its currency or unit.', $handle),
                    );
                }
                self::assertIsString($exactValue, sprintf('%s must retain an exact amount string.', $handle));
                self::assertMatchesRegularExpression('/^-?[0-9]+(?:\.[0-9]+)?$/D', $exactValue);
            }
        }

        $encoded = json_encode($recordDocument, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('@', $encoded);
        preg_match_all('/[A-Z0-9._%+-]+@([A-Z0-9.-]+)/i', $encoded, $emailMatches);
        self::assertNotEmpty($emailMatches[1]);
        foreach ($emailMatches[1] as $domain) {
            self::assertStringEndsWith('.example', $domain);
        }
    }

    /**
     * Proves the content selector is a closed vocabulary before any filesystem path is constructed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnknownContentProfileIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported');

        $this->catalog()->content('../vdm');
    }

    /**
     * Proves discovery reports exactly the released profile vocabulary without a hard-coded list.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDiscoveryReportsTheReleasedProfileVocabulary(): void
    {
        self::assertSame(['blank', 'documentation', 'placeholder'], $this->catalog()->contentProfiles());
        self::assertSame(['vdm'], $this->catalog()->businessProfiles());
    }

    /**
     * Proves the generic business loader answers the released VDM dataset byte for byte.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGenericBusinessLoaderMatchesTheVdmShortcut(): void
    {
        self::assertSame($this->catalog()->vdmBusiness(), $this->catalog()->business('vdm'));
    }

    /**
     * Proves an undiscovered business profile is refused before any filesystem path is constructed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnknownBusinessProfileIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported');

        $this->catalog()->business('../vdm');
    }

    /**
     * Proves the released access manifest declares a complete fictional cast without any credential.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testVdmAccessManifestDeclaresTheDemonstrationCast(): void
    {
        $loaded = $this->catalog()->access('vdm');
        $manifest = $loaded['manifest'];

        self::assertSame('kumwe.demo-access/v1', $manifest['format'] ?? null);
        self::assertSame('vdm', $manifest['profile'] ?? null);
        self::assertSame(3, $manifest['version'] ?? null);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $loaded['checksum']);
        $roles = $this->list($manifest['roles'] ?? null, 'roles');
        $staff = $this->list($manifest['staff'] ?? null, 'staff');
        $organizations = $this->list($manifest['organizations'] ?? null, 'organizations');
        self::assertCount(6, $roles);
        self::assertCount(5, $staff);
        self::assertCount(6, $organizations);
        $capabilitiesByRole = [];
        foreach ($roles as $role) {
            $entry = $this->map($role, 'role');
            if (($entry['area'] ?? null) === 'administrator') {
                self::assertContains(
                    'administrator.access',
                    $this->list($entry['capabilities'] ?? null, 'role capabilities'),
                    'Every administrator-area demo role must be able to open the administrator front door.',
                );
            }
            $capabilitiesByRole[$this->string($entry, 'handle')] =
                $this->list($entry['capabilities'] ?? null, 'role capabilities');
        }
        self::assertContains(
            'business.security.manage',
            $capabilitiesByRole['vdm-system-administrator'] ?? [],
            'The business-security screen must be demonstrable by the system administrator.',
        );
        $memberCounts = [];
        foreach ($organizations as $organization) {
            $entry = $this->map($organization, 'organization');
            $identifier = $this->string($entry, 'identifier');
            $memberCounts[$identifier] = count($this->list($entry['members'] ?? null, 'members'));
        }
        ksort($memberCounts);
        self::assertSame(
            [
                'desert-bloom' => 1,
                'erongo-tours' => 1,
                'kalahari-health' => 2,
                'namib-learning' => 1,
                'okavango-logistics' => 2,
                'zambezi-farms' => 2,
            ],
            $memberCounts,
            'Several organizations demonstrate multiple members while others demonstrate single membership.',
        );
        $encoded = json_encode($manifest, JSON_THROW_ON_ERROR);
        self::assertDoesNotMatchRegularExpression(
            '/"(?:password|passwd|secret|credential|api[_-]?key|access[_-]?token|private[_-]?key)"/i',
            $encoded,
        );
    }

    /**
     * Proves an access manifest identity outside the reserved example zone is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAccessManifestRefusesAddressesOutsideTheExampleZone(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported');

        $this->catalog()->access('../vdm');
    }

    /**
     * Build the production manifest catalog against the repository root.
     *
     * @return  FilesystemDemoManifestCatalog
     *
     * @since   2.0.0
     */
    private function catalog(): FilesystemDemoManifestCatalog
    {
        return new FilesystemDemoManifestCatalog(dirname(__DIR__, 4));
    }

    /**
     * Require a decoded JSON value to be an object-shaped array.
     *
     * @param   mixed   $value  Candidate decoded JSON value.
     * @param   string  $name   Diagnostic noun used if the value is malformed.
     *
     * @return  array<string, mixed>  Validated object-shaped value.
     *
     * @since   2.0.0
     */
    private function map(mixed $value, string $name): array
    {
        self::assertIsArray($value, sprintf('%s must be an object.', $name));
        self::assertFalse(array_is_list($value), sprintf('%s must be an object.', $name));

        return $value;
    }

    /**
     * Require a decoded JSON value to be a list.
     *
     * @param   mixed   $value  Candidate decoded JSON value.
     * @param   string  $name   Diagnostic noun used if the value is malformed.
     *
     * @return  list<mixed>  Validated list value.
     *
     * @since   2.0.0
     */
    private function list(mixed $value, string $name): array
    {
        self::assertIsArray($value, sprintf('%s must be a list.', $name));
        self::assertTrue(array_is_list($value), sprintf('%s must be a list.', $name));

        return $value;
    }

    /**
     * Read one required non-empty string from a decoded JSON object.
     *
     * @param   array<string, mixed>  $document  Object that owns the field.
     * @param   string                $key       Required field name.
     *
     * @return  non-empty-string  Validated field value.
     *
     * @since   2.0.0
     */
    private function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        self::assertIsString($value);
        self::assertNotSame('', $value);

        return $value;
    }

    /**
     * Find one definition member by its stable handle and fail with useful context when it is absent.
     *
     * @param   list<mixed>  $members  Definition members to search.
     * @param   string       $handle   Exact handle expected in the collection.
     *
     * @return  array<string, mixed>  Matching decoded definition member.
     *
     * @since   2.0.0
     */
    private function memberByHandle(array $members, string $handle): array
    {
        foreach ($members as $candidate) {
            $member = $this->map($candidate, 'definition member');
            if (($member['handle'] ?? null) === $handle) {
                return $member;
            }
        }

        self::fail(sprintf('Definition member %s is missing.', $handle));
    }
}
