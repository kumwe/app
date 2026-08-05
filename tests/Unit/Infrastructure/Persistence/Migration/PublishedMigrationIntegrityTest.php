<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Persistence\Migration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PublishedMigrationIntegrityTest extends TestCase
{
    public function testPublishedMigrationSourceRemainsByteForByteImmutable(): void
    {
        $root = dirname(__DIR__, 5);
        $published = [
            'ApplicationAuthorizationMigration.php' =>
                '793c097e9116054619e512fea66b24609129f124a11da8bc01ab9eba3dabccb7',
            'AuthorizationRecoveryIntegrationMigration.php' =>
                '3e94286df919901ea618a4f34891d82230cd3d3f6bce97d7e674758ae3b9f2ed',
            'CoreSchemaMigration.php' =>
                '04088624b20ac688f8cb6cf430a0cc8b20f3791cd22982acc24e8702f475a0dc',
            'JobRecoveryMigration.php' =>
                'db7140ab9f4991514a7011d2c96738a023dba98bb9afae1e0721bc69627f1261',
        ];

        foreach ($published as $file => $expected) {
            self::assertSame(
                $expected,
                hash_file('sha256', $root . '/src/Infrastructure/Persistence/Migration/' . $file),
                sprintf('Published migration %s changed; add a forward migration instead.', $file),
            );
        }
    }
}
