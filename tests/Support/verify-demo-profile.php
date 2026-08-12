<?php

declare(strict_types=1);

use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Kernel\Configuration\ConfigurationFactory;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$contract = $argv[1] ?? null;
$expected = match ($contract) {
    'documentation-vdm' => [
        'profiles' => ['business-demo' => 'vdm', 'site-content' => 'documentation'],
        'content' => 28,
        'navigation' => 29,
        'business_assets' => [
            'business_action' => 65,
            'business_archive' => 2,
            'business_definition' => 12,
            'business_record' => 80,
            'business_relation' => 130,
            'resource_policy' => 221,
        ],
    ],
    'placeholder-none' => [
        'profiles' => ['business-demo' => 'none', 'site-content' => 'placeholder'],
        'content' => 1,
        'navigation' => 4,
        'business_assets' => [],
    ],
    default => throw new RuntimeException(
        'Usage: php tests/Support/verify-demo-profile.php documentation-vdm|placeholder-none',
    ),
};

$configuration = (new ConfigurationFactory())->create(Environment::fromGlobals());
$database = (new DoctrineConnectionFactory($configuration->database))->create();
$tables = new TableNames($database, $configuration->database->tablePrefix);

$installations = $database->fetchAllAssociative(sprintf(
    'SELECT dataset_key, selected_profile, status FROM %s WHERE site_identifier = ? ORDER BY dataset_key',
    $tables->quoted('demo_profile_installations'),
), [$configuration->publicSite]);
$profiles = [];
foreach ($installations as $installation) {
    $dataset = $installation['dataset_key'] ?? null;
    $profile = $installation['selected_profile'] ?? null;
    if (!is_string($dataset) || !is_string($profile) || ($installation['status'] ?? null) !== 'complete') {
        throw new RuntimeException('A demo profile installation did not complete with a valid selection.');
    }
    $profiles[$dataset] = $profile;
}
if ($profiles !== $expected['profiles']) {
    throw new RuntimeException(sprintf(
        'The demo profile selections differ from the %s smoke contract.',
        (string) $contract,
    ));
}

$contentCount = (int) $database->fetchOne(sprintf(
    'SELECT COUNT(*) FROM %s WHERE site_identifier = ? AND deleted_at IS NULL',
    $tables->quoted('content_entries'),
), [$configuration->publicSite]);
if ($contentCount !== $expected['content']) {
    throw new RuntimeException(sprintf(
        'The %s smoke contract expected %d live content entries, found %d.',
        (string) $contract,
        $expected['content'],
        $contentCount,
    ));
}

$menuId = $database->fetchOne(sprintf(
    'SELECT id FROM %s WHERE handle = ?',
    $tables->quoted('navigation_menus'),
), ['main']);
if (!is_string($menuId)) {
    throw new RuntimeException('The primary navigation menu identifier is malformed.');
}
$menuOwnershipCount = (int) $database->fetchOne(sprintf(
    'SELECT COUNT(*) FROM %s WHERE resource_type = ? AND resource_id = ? AND site_identifier = ?',
    $tables->quoted('resource_site_ownership'),
), ['menu', $menuId, $configuration->publicSite]);
if ($menuOwnershipCount !== 1) {
    throw new RuntimeException('The primary navigation menu does not have exactly one site owner.');
}

$navigationRows = $database->fetchFirstColumn(sprintf(
    'SELECT id FROM %s WHERE menu_id = ? ORDER BY id',
    $tables->quoted('navigation_items'),
), [$menuId], [Types::GUID]);
$ownedNavigationRows = $database->fetchFirstColumn(sprintf(
    'SELECT resource_id FROM %s WHERE resource_type = ? AND site_identifier = ? ORDER BY resource_id',
    $tables->quoted('resource_site_ownership'),
), ['menu_item', $configuration->publicSite]);
$navigationIds = [];
foreach ($navigationRows as $navigationId) {
    if (!is_string($navigationId)) {
        throw new RuntimeException('A primary navigation item identifier is malformed.');
    }
    $navigationIds[] = $navigationId;
}
$ownedNavigationIds = [];
foreach ($ownedNavigationRows as $navigationId) {
    if (!is_string($navigationId)) {
        throw new RuntimeException('A navigation ownership identifier is malformed.');
    }
    $ownedNavigationIds[] = $navigationId;
}
$ownedNavigationLookup = array_fill_keys($ownedNavigationIds, true);
$navigationCount = 0;
foreach ($navigationIds as $navigationId) {
    $navigationCount += isset($ownedNavigationLookup[$navigationId]) ? 1 : 0;
}
if ($navigationCount !== count($navigationIds)) {
    throw new RuntimeException('A primary navigation item is not owned by the configured site.');
}
if ($navigationCount !== $expected['navigation']) {
    throw new RuntimeException(sprintf(
        'The %s smoke contract expected %d primary navigation items, found %d.',
        (string) $contract,
        $expected['navigation'],
        $navigationCount,
    ));
}

$businessAssets = [];
$businessAssetRows = $database->fetchAllAssociative(
    sprintf(
        'SELECT resource_type, COUNT(*) AS asset_count FROM %s WHERE site_identifier = ? AND dataset_key = ? '
            . 'GROUP BY resource_type ORDER BY resource_type',
        $tables->quoted('demo_profile_assets'),
    ),
    [$configuration->publicSite, 'business-demo'],
);
foreach ($businessAssetRows as $asset) {
    $resourceType = $asset['resource_type'] ?? null;
    $count = $asset['asset_count'] ?? null;
    if (!is_string($resourceType) || !is_numeric($count)) {
        throw new RuntimeException('A business demo asset checkpoint is malformed.');
    }
    $businessAssets[$resourceType] = (int) $count;
}
if ($businessAssets !== $expected['business_assets']) {
    throw new RuntimeException(sprintf(
        'The business assets differ from the %s smoke contract.',
        (string) $contract,
    ));
}

$definitionHandles = $database->fetchFirstColumn(sprintf(
    'SELECT handle FROM %s WHERE site_identifier = ? AND owner_type = ? AND owner_identifier = ? ORDER BY handle',
    $tables->quoted('business_definitions'),
), [$configuration->publicSite, 'site', $configuration->publicSite]);
$installationHandles = $database->fetchFirstColumn(sprintf(
    'SELECT d.handle FROM %s i INNER JOIN %s d ON d.id = i.definition_id '
        . 'WHERE i.site_identifier = ? AND i.status = ? AND d.owner_type = ? AND d.owner_identifier = ? '
        . 'ORDER BY d.handle',
    $tables->quoted('business_schema_installations'),
    $tables->quoted('business_definitions'),
), [$configuration->publicSite, 'active', 'site', $configuration->publicSite]);
foreach ([...$definitionHandles, ...$installationHandles] as $businessHandle) {
    if (!is_string($businessHandle)) {
        throw new RuntimeException('A VDM definition handle is malformed.');
    }
}
$expectedBusinessHandles = $expected['business_assets'] !== [] ? [
    sprintf('site.%s.vdm_client_account', $configuration->publicSite),
    sprintf('site.%s.vdm_domain', $configuration->publicSite),
    sprintf('site.%s.vdm_engagement', $configuration->publicSite),
    sprintf('site.%s.vdm_invoice', $configuration->publicSite),
    sprintf('site.%s.vdm_invoice_line', $configuration->publicSite),
    sprintf('site.%s.vdm_product', $configuration->publicSite),
    sprintf('site.%s.vdm_quotation', $configuration->publicSite),
    sprintf('site.%s.vdm_quotation_line', $configuration->publicSite),
    sprintf('site.%s.vdm_service_catalog_item', $configuration->publicSite),
    sprintf('site.%s.vdm_service_request', $configuration->publicSite),
    sprintf('site.%s.vdm_subscription', $configuration->publicSite),
    sprintf('site.%s.vdm_work_entry', $configuration->publicSite),
] : [];
if ($definitionHandles !== $expectedBusinessHandles || $installationHandles !== $expectedBusinessHandles) {
    throw new RuntimeException(sprintf(
        'The %s smoke contract has unexpected published or installed VDM definitions.',
        (string) $contract,
    ));
}

fwrite(STDOUT, sprintf("Verified %s demo profile contract.\n", (string) $contract));
