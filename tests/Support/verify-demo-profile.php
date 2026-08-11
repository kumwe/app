<?php

declare(strict_types=1);

use Kumwe\CMS\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Kernel\Configuration\ConfigurationFactory;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$contract = $argv[1] ?? null;
$expected = match ($contract) {
    'documentation-vdm' => [
        'profiles' => ['business-demo' => 'vdm', 'site-content' => 'documentation'],
        'content' => 16,
        'navigation' => 16,
        'business_assets' => [
            'business_action' => 29,
            'business_archive' => 1,
            'business_definition' => 5,
            'business_record' => 30,
            'business_relation' => 43,
            'resource_policy' => 65,
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
    'SELECT COUNT(*) FROM %s WHERE deleted_at IS NULL',
    $tables->quoted('content_entries'),
));
if ($contentCount !== $expected['content']) {
    throw new RuntimeException(sprintf(
        'The %s smoke contract expected %d live content entries, found %d.',
        (string) $contract,
        $expected['content'],
        $contentCount,
    ));
}

$navigationCount = (int) $database->fetchOne(sprintf(
    'SELECT COUNT(*) FROM %s i INNER JOIN %s m ON m.id = i.menu_id WHERE m.handle = ?',
    $tables->quoted('navigation_items'),
    $tables->quoted('navigation_menus'),
), ['main']);
if ($navigationCount !== $expected['navigation']) {
    throw new RuntimeException(sprintf(
        'The %s smoke contract expected %d primary navigation items, found %d.',
        (string) $contract,
        $expected['navigation'],
        $navigationCount,
    ));
}

$businessAssets = [];
foreach ($database->fetchAllAssociative(sprintf(
    'SELECT resource_type, COUNT(*) AS asset_count FROM %s WHERE site_identifier = ? AND dataset_key = ? '
        . 'GROUP BY resource_type ORDER BY resource_type',
    $tables->quoted('demo_profile_assets'),
), [$configuration->publicSite, 'business-demo']) as $asset) {
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

$definitionCount = (int) $database->fetchOne(sprintf(
    'SELECT COUNT(*) FROM %s WHERE site_identifier = ? AND handle LIKE ?',
    $tables->quoted('business_definitions'),
), [$configuration->publicSite, 'site.default.vdm\_%']);
$installationCount = (int) $database->fetchOne(sprintf(
    'SELECT COUNT(*) FROM %s i INNER JOIN %s d ON d.id = i.definition_id '
        . 'WHERE i.site_identifier = ? AND i.status = ? AND d.handle LIKE ?',
    $tables->quoted('business_schema_installations'),
    $tables->quoted('business_definitions'),
), [$configuration->publicSite, 'active', 'site.default.vdm\_%']);
$expectedBusinessCount = $contract === 'documentation-vdm' ? 5 : 0;
if ($definitionCount !== $expectedBusinessCount || $installationCount !== $expectedBusinessCount) {
    throw new RuntimeException(sprintf(
        'The %s smoke contract expected %d published and installed VDM definitions.',
        (string) $contract,
        $expectedBusinessCount,
    ));
}

fwrite(STDOUT, sprintf("Verified %s demo profile contract.\n", (string) $contract));
