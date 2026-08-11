<?php

declare(strict_types=1);

namespace Kumwe\CMS\Demo\Infrastructure;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\CMS\Content\Application\ContentNotFound;
use Kumwe\CMS\Content\Application\ContentRecord;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Navigation\Application\MenuItemRecord;
use Kumwe\CMS\Navigation\Application\MenuRecord;
use Kumwe\CMS\Navigation\Application\NavigationService;
use Kumwe\CMS\Site\Application\SiteSettings;
use Kumwe\CMS\Demo\Infrastructure\Persistence\DoctrineDemoProfileLedger;
use RuntimeException;

/**
 * Reconciles the selected managed-site example through Kumwe's canonical application services.
 *
 * Fixture identifiers are hints for adopting the released legacy placeholder; new records keep the UUIDs
 * minted by their application services and are mapped in the provenance ledger. A release updates an asset
 * only while its current canonical state still matches what the previous release applied. Once an operator
 * edits a page, menu item, or settings document, reconciliation leaves it alone and reports the divergence.
 *
 * @since  2.0.0
 */
final readonly class DemoContentProfileInstaller
{
    /** @var string Dataset key persisted independently from the VDM business example. @since 2.0.0 */
    public const string DATASET = 'site-content';

    /**
     * Bind the content reconciler to the public application services and its restart ledger.
     *
     * @param  ContentService                   $content     Canonical page mutation service.
     * @param  NavigationService                $navigation  Canonical menu-tree mutation service.
     * @param  SiteSettings                     $settings    Canonical settings document service.
     * @param  DoctrineDemoProfileLedger        $ledger      Stable fixture mapping and divergence baseline.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentService $content,
        private NavigationService $navigation,
        private SiteSettings $settings,
        private DoctrineDemoProfileLedger $ledger,
    ) {
    }

    /**
     * Apply one validated content manifest and return concise operator diagnostics.
     *
     * @param   ExecutionContext      $context      Purpose-bound profile-installer context.
     * @param   array<string, mixed>  $manifest     Selected content manifest.
     * @param   array<string, mixed>  $placeholder  Legacy placeholder manifest used only as an adoption sentinel.
     *
     * @return  list<string>  Applied, removed, and deliberately preserved resource summaries.
     *
     * @since   2.0.0
     */
    public function install(ExecutionContext $context, array $manifest, array $placeholder): array
    {
        $messages = [];
        $pages = $this->pageIndex($manifest);
        $baselinePages = $this->pageIndex($placeholder);
        $pageIds = [];
        foreach ($pages as $fixtureKey => $page) {
            $record = $this->reconcilePage($context, $fixtureKey, $page, $baselinePages[$fixtureKey] ?? null);
            if ($record === null) {
                $messages[] = sprintf('Preserved customized demo page %s.', $fixtureKey);
                continue;
            }
            $pageIds[$fixtureKey] = $record->entry->id();
        }
        $this->retirePages($context, $pages, $baselinePages, $messages);

        $menus = $this->menus($manifest);
        $baselineMenus = $this->menus($placeholder);
        foreach ($menus as $menu) {
            $this->reconcileMenu(
                $context,
                $menu,
                $this->menuByFixture($baselineMenus, $this->fixtureKey($menu)),
                $pageIds,
                $messages,
            );
        }
        if ($menus === []) {
            throw new RuntimeException('A site-content profile must retain the managed primary menu.');
        }
        $this->reconcileSettings($context, $manifest, $placeholder, $pageIds, $messages);

        return $messages;
    }

    /**
     * Reconcile one page, creating it through the service or updating only an untouched prior fixture.
     *
     * @param   ExecutionContext       $context     Profile installer context.
     * @param   string                 $fixtureKey  Stable fixture key.
     * @param   array<string, mixed>   $page        Desired page declaration.
     * @param   ?array<string, mixed>  $baseline    Legacy adoption sentinel for this fixture.
     *
     * @return  ?ContentRecord  Reconciled record, or null when a customized record was preserved.
     *
     * @since   2.0.0
     */
    private function reconcilePage(
        ExecutionContext $context,
        string $fixtureKey,
        array $page,
        ?array $baseline,
    ): ?ContentRecord {
        $desired = $this->pageState($page);
        $desiredChecksum = CanonicalDefinitionJson::checksum($desired);
        $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, $fixtureKey);
        $preferredId = $this->requiredString($page, 'resource_id');
        $record = is_string($asset['resource_id'] ?? null)
            ? $this->findPage($context, $asset['resource_id'], true)
            : $this->content->publishedById($preferredId, $context->site());
        if ($record === null) {
            $record = $this->publishNewPage($context, $page);
        } else {
            if ($record->deletedAt !== null) {
                $record = $this->content->restore($context, $record->entry->id(), $record->entry->version());
            }
            $current = $this->pageRecordState($record);
            $currentChecksum = CanonicalDefinitionJson::checksum($current);
            $baselineChecksum = $baseline === null
                ? null
                : CanonicalDefinitionJson::checksum($this->pageState($baseline));
            $lastApplied = is_string($asset['last_applied_checksum'] ?? null)
                ? $asset['last_applied_checksum']
                : null;
            if (
                $currentChecksum !== $desiredChecksum
                && $currentChecksum !== $lastApplied
                && $currentChecksum !== $baselineChecksum
            ) {
                return null;
            }
            if ($currentChecksum !== $desiredChecksum) {
                $record = $this->content->update(
                    $context,
                    $record->entry->id(),
                    $record->entry->version(),
                    $this->requiredString($page, 'title'),
                    $this->requiredString($page, 'slug'),
                    $this->requiredMap($page, 'data'),
                );
            }
        }
        $this->ledger->recordAsset(
            $context->site()->identifier(),
            self::DATASET,
            $fixtureKey,
            'content',
            $record->entry->id(),
            $desiredChecksum,
            $record->entry->version(),
            $desired,
        );

        return $record;
    }

    /**
     * Create and publish one new Page through its persisted editorial workflow.
     *
     * @param   ExecutionContext      $context  Profile installer context.
     * @param   array<string, mixed>  $page     Desired page declaration.
     *
     * @return  ContentRecord  Published page after draft and review revisions were captured.
     *
     * @since   2.0.0
     */
    private function publishNewPage(ExecutionContext $context, array $page): ContentRecord
    {
        $record = $this->content->create(
            $context,
            $this->requiredString($page, 'title'),
            $this->requiredString($page, 'slug'),
            $this->requiredMap($page, 'data'),
            contentTypeIdentifier: $this->requiredString($page, 'content_type_id'),
        );
        if ($record->entry->statusKey() !== 'published') {
            $record = $this->content->transition(
                $context,
                $record->entry->id(),
                $record->entry->version(),
                'review',
            );
            $record = $this->content->transition(
                $context,
                $record->entry->id(),
                $record->entry->version(),
                'published',
            );
        }

        return $record;
    }

    /**
     * Trash untouched pages removed by the selected profile while preserving every customized page.
     *
     * @param   ExecutionContext                        $context        Profile installer context.
     * @param   array<string, array<string, mixed>>     $target         Desired page index.
     * @param   array<string, array<string, mixed>>     $baseline       Legacy sentinel page index.
     * @param   list<string>                            &$messages      Operator diagnostics to append.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function retirePages(
        ExecutionContext $context,
        array $target,
        array $baseline,
        array &$messages,
    ): void {
        $candidates = $baseline;
        foreach ($this->ledger->assets($context->site()->identifier(), self::DATASET) as $asset) {
            if (($asset['resource_type'] ?? null) !== 'content') {
                continue;
            }
            $fixtureKey = $asset['fixture_key'] ?? null;
            $state = $asset['last_applied_state'] ?? null;
            if (is_string($fixtureKey) && is_array($state) && !isset($candidates[$fixtureKey])) {
                $candidates[$fixtureKey] = ['resource_id' => $asset['resource_id'], ...$state];
            }
        }
        foreach (array_diff_key($candidates, $target) as $fixtureKey => $page) {
            $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, $fixtureKey);
            $resourceId = is_string($asset['resource_id'] ?? null)
                ? $asset['resource_id']
                : $this->requiredString($page, 'resource_id');
            $record = $this->findPage($context, $resourceId, true);
            if ($record === null || $record->deletedAt !== null) {
                continue;
            }
            $current = $this->pageRecordState($record);
            $currentChecksum = CanonicalDefinitionJson::checksum($current);
            $allowed = [CanonicalDefinitionJson::checksum($this->candidatePageState($page))];
            if (is_string($asset['last_applied_checksum'] ?? null)) {
                $allowed[] = $asset['last_applied_checksum'];
            }
            if (!in_array($currentChecksum, $allowed, true)) {
                $messages[] = sprintf('Preserved customized demo page %s.', $fixtureKey);
                continue;
            }
            $record = $this->content->trash($context, $record->entry->id(), $record->entry->version());
            $removed = ['removed' => true];
            $this->ledger->recordAsset(
                $context->site()->identifier(),
                self::DATASET,
                $fixtureKey,
                'content',
                $record->entry->id(),
                CanonicalDefinitionJson::checksum($removed),
                $record->entry->version(),
                $removed,
            );
            $messages[] = sprintf('Removed untouched demo page %s.', $fixtureKey);
        }
    }

    /**
     * Reconcile one primary menu and all its items in parent-before-child manifest order.
     *
     * @param   ExecutionContext       $context       Profile installer context.
     * @param   array<string, mixed>   $menu          Desired menu declaration.
     * @param   ?array<string, mixed>  $baseline      Legacy menu sentinel.
     * @param   array<string, string>  $pageIds       Actual content IDs keyed by fixture key.
     * @param   list<string>           &$messages     Operator diagnostics to append.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function reconcileMenu(
        ExecutionContext $context,
        array $menu,
        ?array $baseline,
        array $pageIds,
        array &$messages,
    ): void {
        $fixtureKey = $this->fixtureKey($menu);
        $stored = $this->findMenu($context, $this->requiredString($menu, 'handle'));
        if ($stored === null) {
            $stored = $this->navigation->createMenu(
                $context,
                $this->requiredString($menu, 'handle'),
                $this->requiredString($menu, 'title'),
            );
        }
        $desiredMenu = $this->menuState($menu);
        $currentMenu = $this->menuRecordState($stored);
        $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, $fixtureKey);
        $menuDiverged = false;
        if (
            $currentMenu !== $desiredMenu
            && !$this->safeToChange($currentMenu, $desiredMenu, $asset, $baseline === null
                ? null
                : $this->menuState($baseline))
        ) {
            $menuDiverged = true;
            $messages[] = sprintf('Preserved customized demo menu %s.', $fixtureKey);
        } elseif ($currentMenu !== $desiredMenu) {
            $stored = $this->navigation->updateMenu(
                $context,
                $stored->id,
                $stored->version,
                $this->requiredString($menu, 'handle'),
                $this->requiredString($menu, 'title'),
            );
        }
        if (!$menuDiverged) {
            $this->ledger->recordAsset(
                $context->site()->identifier(),
                self::DATASET,
                $fixtureKey,
                'menu',
                $stored->id,
                CanonicalDefinitionJson::checksum($desiredMenu),
                $stored->version,
                $desiredMenu,
            );
        }

        $currentItems = [];
        foreach ($this->navigation->items($context, $stored->id) as $item) {
            $currentItems[$item->id] = $item;
        }
        $baselineItems = $baseline === null ? [] : $this->itemIndex($baseline);
        $targetItems = $this->itemIndex($menu);
        $itemIds = [];
        foreach ($targetItems as $itemFixture => $item) {
            $record = $this->reconcileItem(
                $context,
                $stored,
                $itemFixture,
                $item,
                $baselineItems[$itemFixture] ?? null,
                $pageIds,
                $itemIds,
                $currentItems,
            );
            if ($record === null) {
                $messages[] = sprintf('Preserved customized demo menu item %s.', $itemFixture);
                continue;
            }
            $itemIds[$itemFixture] = $record->id;
        }
        $this->retireMenuItems(
            $context,
            $targetItems,
            $baselineItems,
            $currentItems,
            $messages,
        );
    }

    /**
     * Create or safely update one menu item after its parent and content target have been resolved.
     *
     * @param   ExecutionContext                     $context       Profile installer context.
     * @param   MenuRecord                           $menu          Parent menu.
     * @param   string                               $fixtureKey    Stable item fixture key.
     * @param   array<string, mixed>                 $item          Desired item declaration.
     * @param   ?array<string, mixed>                $baseline      Legacy item sentinel.
     * @param   array<string, string>                $pageIds       Actual content IDs by fixture.
     * @param   array<string, string>                $itemIds       Actual parent IDs by fixture.
     * @param   array<string, MenuItemRecord>        $currentItems  Current menu items by UUID.
     *
     * @return  ?MenuItemRecord  Reconciled item, or null when a customization was preserved.
     *
     * @since   2.0.0
     */
    private function reconcileItem(
        ExecutionContext $context,
        MenuRecord $menu,
        string $fixtureKey,
        array $item,
        ?array $baseline,
        array $pageIds,
        array $itemIds,
        array $currentItems,
    ): ?MenuItemRecord {
        $parentFixture = $item['parent_fixture_key'] ?? null;
        $parentId = is_string($parentFixture) ? ($itemIds[$parentFixture] ?? null) : null;
        if (is_string($parentFixture) && $parentId === null) {
            throw new RuntimeException(sprintf('Demo menu item %s has an unresolved parent.', $fixtureKey));
        }
        $contentFixture = $item['content_fixture_key'] ?? null;
        $contentId = is_string($contentFixture) ? ($pageIds[$contentFixture] ?? null) : null;
        if (is_string($contentFixture) && $contentId === null) {
            throw new RuntimeException(sprintf('Demo menu item %s has an unresolved content target.', $fixtureKey));
        }
        $desired = $this->itemState($item, $parentId, $contentId);
        $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, $fixtureKey);
        $resourceId = is_string($asset['resource_id'] ?? null)
            ? $asset['resource_id']
            : $this->requiredString($item, 'resource_id');
        $stored = $currentItems[$resourceId] ?? null;
        if ($stored === null) {
            $stored = $this->navigation->createItem(
                $context,
                $menu->id,
                $parentId,
                $this->requiredString($item, 'title'),
                $this->requiredString($item, 'slug'),
                $this->requiredInteger($item, 'position', 0),
                $this->requiredString($item, 'target_type'),
                $contentId,
                $this->nullableString($item, 'target_url'),
            );
        } else {
            $current = $this->itemRecordState($stored);
            $baselineState = $baseline === null
                ? null
                : $this->itemState(
                    $baseline,
                    $this->nullableString($baseline, 'parent_id'),
                    $this->nullableString($baseline, 'content_id'),
                );
            if (!$this->safeToChange($current, $desired, $asset, $baselineState)) {
                return null;
            }
            if ($current !== $desired) {
                $stored = $this->navigation->updateItem(
                    $context,
                    $stored->id,
                    $stored->version,
                    $parentId,
                    $this->requiredString($item, 'title'),
                    $this->requiredString($item, 'slug'),
                    $this->requiredInteger($item, 'position', 0),
                    $this->requiredString($item, 'target_type'),
                    $contentId,
                    $this->nullableString($item, 'target_url'),
                );
            }
        }
        $this->ledger->recordAsset(
            $context->site()->identifier(),
            self::DATASET,
            $fixtureKey,
            'menu_item',
            $stored->id,
            CanonicalDefinitionJson::checksum($desired),
            $stored->version,
            $desired,
        );

        return $stored;
    }

    /**
     * Delete untouched items absent from the target, deepest descendants first.
     *
     * @param   ExecutionContext                       $context       Profile installer context.
     * @param   array<string, array<string, mixed>>    $target        Desired item index.
     * @param   array<string, array<string, mixed>>    $baseline      Legacy sentinel item index.
     * @param   array<string, MenuItemRecord>          $currentItems  Current items by UUID.
     * @param   list<string>                           &$messages     Operator diagnostics to append.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function retireMenuItems(
        ExecutionContext $context,
        array $target,
        array $baseline,
        array $currentItems,
        array &$messages,
    ): void {
        $candidates = $baseline;
        foreach ($this->ledger->assets($context->site()->identifier(), self::DATASET) as $asset) {
            if (($asset['resource_type'] ?? null) !== 'menu_item') {
                continue;
            }
            $fixtureKey = $asset['fixture_key'] ?? null;
            $state = $asset['last_applied_state'] ?? null;
            if (is_string($fixtureKey) && is_array($state) && !isset($candidates[$fixtureKey])) {
                $candidates[$fixtureKey] = ['resource_id' => $asset['resource_id'], ...$state];
            }
        }
        $obsolete = array_diff_key($candidates, $target);
        uasort($obsolete, static fn (array $left, array $right): int => strlen((string) ($right['path'] ?? ''))
            <=> strlen((string) ($left['path'] ?? '')));
        foreach ($obsolete as $fixtureKey => $item) {
            $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, $fixtureKey);
            $resourceId = is_string($asset['resource_id'] ?? null)
                ? $asset['resource_id']
                : $this->requiredString($item, 'resource_id');
            $stored = $currentItems[$resourceId] ?? null;
            if ($stored === null) {
                continue;
            }
            $current = $this->itemRecordState($stored);
            $baselineState = $this->itemState(
                $item,
                $this->nullableString($item, 'parent_id'),
                $this->nullableString($item, 'content_id'),
            );
            if (!$this->safeToChange($current, $baselineState, $asset, $baselineState)) {
                $messages[] = sprintf('Preserved customized demo menu item %s.', $fixtureKey);
                continue;
            }
            $this->navigation->deleteItem($context, $stored->id, $stored->version);
            $removed = ['removed' => true];
            $this->ledger->recordAsset(
                $context->site()->identifier(),
                self::DATASET,
                $fixtureKey,
                'menu_item',
                $stored->id,
                CanonicalDefinitionJson::checksum($removed),
                $stored->version,
                $removed,
            );
            $messages[] = sprintf('Removed untouched demo menu item %s.', $fixtureKey);
        }
    }

    /**
     * Reconcile the one settings document after page and menu targets are available.
     *
     * @param   ExecutionContext       $context      Profile installer context.
     * @param   array<string, mixed>   $manifest     Desired content manifest.
     * @param   array<string, mixed>   $placeholder  Legacy adoption manifest.
     * @param   array<string, string>  $pageIds      Actual page IDs by fixture key.
     * @param   list<string>           &$messages    Operator diagnostics to append.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function reconcileSettings(
        ExecutionContext $context,
        array $manifest,
        array $placeholder,
        array $pageIds,
        array &$messages,
    ): void {
        $settings = $this->requiredMap($manifest, 'settings');
        $placeholderSettings = $this->requiredMap($placeholder, 'settings');
        $desired = $this->settingsState($settings, $pageIds);
        $baseline = $this->settingsState($placeholderSettings, [
            'page.home' => $this->requiredString($placeholderSettings, 'homepage_content_id'),
        ]);
        $current = $this->settings->managed($context);
        $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, 'settings.default');
        if (!$this->safeToChange($current, $desired, $asset, $baseline)) {
            $messages[] = 'Preserved customized site settings.';
            return;
        }
        if ($current !== $desired) {
            $this->settings->updateAll($context, $desired);
        }
        $this->ledger->recordAsset(
            $context->site()->identifier(),
            self::DATASET,
            'settings.default',
            'site_settings',
            $context->site()->identifier(),
            CanonicalDefinitionJson::checksum($desired),
            1,
            $desired,
        );
    }

    /**
     * Decide whether current state is the target, the prior applied state, or the legacy sentinel.
     *
     * @param   array<string, mixed>   $current   Current canonical state.
     * @param   array<string, mixed>   $desired   Desired canonical state.
     * @param   ?array<string, mixed>  $asset     Prior ledger checkpoint.
     * @param   ?array<string, mixed>  $baseline  Legacy adoption sentinel.
     *
     * @return  bool  Whether reconciliation may replace the resource.
     *
     * @since   2.0.0
     */
    private function safeToChange(
        array $current,
        array $desired,
        ?array $asset,
        ?array $baseline,
    ): bool {
        $checksum = CanonicalDefinitionJson::checksum($current);
        $allowed = [CanonicalDefinitionJson::checksum($desired)];
        if (is_string($asset['last_applied_checksum'] ?? null)) {
            $allowed[] = $asset['last_applied_checksum'];
        }
        if ($baseline !== null) {
            $allowed[] = CanonicalDefinitionJson::checksum($baseline);
        }

        return in_array($checksum, $allowed, true);
    }

    /**
     * Find one content record without treating absence as a failed installation.
     *
     * @param   ExecutionContext  $context         Profile installer context.
     * @param   string            $id              Candidate content UUID.
     * @param   bool              $includeDeleted  Whether a trashed profile page counts as present.
     *
     * @return  ?ContentRecord  Stored page or null.
     *
     * @since   2.0.0
     */
    private function findPage(ExecutionContext $context, string $id, bool $includeDeleted = false): ?ContentRecord
    {
        try {
            return $this->content->get($context, $id, $includeDeleted);
        } catch (ContentNotFound) {
            return null;
        }
    }

    /**
     * Find one menu by its stable handle.
     *
     * @param   ExecutionContext  $context  Profile installer context.
     * @param   string            $handle   Menu handle.
     *
     * @return  ?MenuRecord  Matching menu or null.
     *
     * @since   2.0.0
     */
    private function findMenu(ExecutionContext $context, string $handle): ?MenuRecord
    {
        foreach ($this->navigation->menus($context) as $menu) {
            if ($menu->handle === $handle) {
                return $menu;
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> Page declarations by fixture key. @since 2.0.0 */
    private function pageIndex(array $manifest): array
    {
        $pages = $manifest['content'] ?? null;
        if (!is_array($pages) || !array_is_list($pages) || count($pages) > 256) {
            throw new RuntimeException('A demo content manifest has an invalid page list.');
        }
        $result = [];
        foreach ($pages as $page) {
            if (!is_array($page) || array_is_list($page)) {
                throw new RuntimeException('A demo page declaration is invalid.');
            }
            $fixtureKey = $this->fixtureKey($page);
            if (isset($result[$fixtureKey])) {
                throw new RuntimeException(sprintf('Demo page fixture %s is duplicated.', $fixtureKey));
            }
            $result[$fixtureKey] = $page;
        }

        return $result;
    }

    /** @return list<array<string, mixed>> Validated menu declarations. @since 2.0.0 */
    private function menus(array $manifest): array
    {
        $menus = $manifest['menus'] ?? null;
        if (!is_array($menus) || !array_is_list($menus) || count($menus) > 16) {
            throw new RuntimeException('A demo content manifest has an invalid menu list.');
        }
        foreach ($menus as $menu) {
            if (!is_array($menu) || array_is_list($menu)) {
                throw new RuntimeException('A demo menu declaration is invalid.');
            }
        }

        /** @var list<array<string, mixed>> $menus */
        return $menus;
    }

    /** @return array<string, array<string, mixed>> Menu items by fixture key. @since 2.0.0 */
    private function itemIndex(array $menu): array
    {
        $items = $menu['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items) > 256) {
            throw new RuntimeException('A demo menu has an invalid item list.');
        }
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new RuntimeException('A demo menu item declaration is invalid.');
            }
            $fixtureKey = $this->fixtureKey($item);
            if (isset($result[$fixtureKey])) {
                throw new RuntimeException(sprintf('Demo menu item fixture %s is duplicated.', $fixtureKey));
            }
            $result[$fixtureKey] = $item;
        }

        return $result;
    }

    /** @return ?array<string, mixed> Menu matching the fixture key, or null. @since 2.0.0 */
    private function menuByFixture(array $menus, string $fixtureKey): ?array
    {
        foreach ($menus as $menu) {
            if ($this->fixtureKey($menu) === $fixtureKey) {
                return $menu;
            }
        }

        return null;
    }

    /** @return array<string, mixed> Canonical authored page state. @since 2.0.0 */
    private function pageState(array $page): array
    {
        return [
            'title' => $this->requiredString($page, 'title'),
            'slug' => $this->requiredString($page, 'slug'),
            'data' => $this->requiredMap($page, 'data'),
            'status' => $this->requiredString($page, 'workflow_state_key'),
        ];
    }

    /** @return array<string, mixed> Canonical authored state of a stored page. @since 2.0.0 */
    private function pageRecordState(ContentRecord $record): array
    {
        return [
            'title' => $record->entry->title(),
            'slug' => $record->entry->slug(),
            'data' => $record->entry->data(),
            'status' => $record->entry->statusKey(),
        ];
    }

    /**
     * Normalize either a manifest page declaration or a prior ledger page state.
     *
     * @param   array<string, mixed>  $page  Manifest declaration or stored canonical state.
     *
     * @return  array<string, mixed>  Canonical authored page state.
     *
     * @since   2.0.0
     */
    private function candidatePageState(array $page): array
    {
        if (isset($page['workflow_state_key'])) {
            return $this->pageState($page);
        }

        return [
            'title' => $this->requiredString($page, 'title'),
            'slug' => $this->requiredString($page, 'slug'),
            'data' => $this->requiredMap($page, 'data'),
            'status' => $this->requiredString($page, 'status'),
        ];
    }

    /** @return array<string, mixed> Canonical authored menu state. @since 2.0.0 */
    private function menuState(array $menu): array
    {
        return [
            'handle' => $this->requiredString($menu, 'handle'),
            'title' => $this->requiredString($menu, 'title'),
        ];
    }

    /** @return array<string, mixed> Canonical authored state of a stored menu. @since 2.0.0 */
    private function menuRecordState(MenuRecord $menu): array
    {
        return ['handle' => $menu->handle, 'title' => $menu->title];
    }

    /** @return array<string, mixed> Canonical resolved item state. @since 2.0.0 */
    private function itemState(array $item, ?string $parentId, ?string $contentId): array
    {
        return [
            'parent_id' => $parentId,
            'title' => $this->requiredString($item, 'title'),
            'slug' => $this->requiredString($item, 'slug'),
            'path' => $this->requiredString($item, 'path'),
            'position' => $this->requiredInteger($item, 'position', 0),
            'target_type' => $this->requiredString($item, 'target_type'),
            'content_id' => $contentId,
            'target_url' => $this->nullableString($item, 'target_url'),
        ];
    }

    /** @return array<string, mixed> Canonical resolved state of a stored item. @since 2.0.0 */
    private function itemRecordState(MenuItemRecord $item): array
    {
        return [
            'parent_id' => $item->parentId,
            'title' => $item->title,
            'slug' => $item->slug,
            'path' => $item->path,
            'position' => $item->position,
            'target_type' => $item->targetType,
            'content_id' => $item->contentId,
            'target_url' => $item->targetUrl,
        ];
    }

    /** @return array<string, mixed> Canonical public settings document with actual content IDs. @since 2.0.0 */
    private function settingsState(array $settings, array $pageIds): array
    {
        $homepageFixture = $settings['homepage_content_fixture_key'] ?? null;
        $homepageId = is_string($homepageFixture) ? ($pageIds[$homepageFixture] ?? null) : null;
        if (is_string($homepageFixture) && $homepageId === null) {
            throw new RuntimeException('The demo homepage fixture did not resolve to an installed page.');
        }

        return [
            'site_name' => $this->requiredString($settings, 'site_name'),
            'homepage_content_id' => $homepageId,
            'homepage_slug' => $this->requiredString($settings, 'homepage_slug'),
            'default_locale' => $this->requiredString($settings, 'default_locale'),
            'timezone' => $this->requiredString($settings, 'timezone'),
            'search_indexing_enabled' => $this->requiredBoolean($settings, 'search_indexing_enabled'),
            'presentation' => $this->requiredMap($settings, 'presentation'),
        ];
    }

    /** @return string Stable fixture key. @since 2.0.0 */
    private function fixtureKey(array $resource): string
    {
        $value = $this->requiredString($resource, 'fixture_key');
        if (preg_match('/^[a-z][a-z0-9.-]{0,190}$/D', $value) !== 1) {
            throw new RuntimeException('A demo fixture key is invalid.');
        }

        return $value;
    }

    /** @return string Required non-empty manifest string. @since 2.0.0 */
    private function requiredString(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Demo manifest field %s is invalid.', $key));
        }

        return $value;
    }

    /** @return ?string Nullable manifest string. @since 2.0.0 */
    private function nullableString(array $document, string $key): ?string
    {
        $value = $document[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new RuntimeException(sprintf('Demo manifest field %s is invalid.', $key));
        }

        return $value;
    }

    /** @return array<string, mixed> Required manifest object. @since 2.0.0 */
    private function requiredMap(array $document, string $key): array
    {
        $value = $document[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException(sprintf('Demo manifest field %s is invalid.', $key));
        }

        return $value;
    }

    /** @return int Required manifest integer at or above the supplied minimum. @since 2.0.0 */
    private function requiredInteger(array $document, string $key, int $minimum = 1): int
    {
        $value = $document[$key] ?? null;
        if (!is_int($value) || $value < $minimum) {
            throw new RuntimeException(sprintf('Demo manifest field %s is invalid.', $key));
        }

        return $value;
    }

    /** @return bool Required manifest boolean. @since 2.0.0 */
    private function requiredBoolean(array $document, string $key): bool
    {
        $value = $document[$key] ?? null;
        if (!is_bool($value)) {
            throw new RuntimeException(sprintf('Demo manifest field %s is invalid.', $key));
        }

        return $value;
    }
}
