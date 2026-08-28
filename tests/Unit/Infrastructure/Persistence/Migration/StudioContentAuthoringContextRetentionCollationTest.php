<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Persistence\Migration;

use Kumwe\App\Infrastructure\Persistence\Migration\StudioContentAuthoringContextRetentionMigration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins the collation-safe shape of the retention migration's ownership cleanup.
 *
 * A fresh MariaDB installation whose server default collation differs from the
 * ownership registry's declared one refuses a cross-table string comparison
 * (SQLSTATE HY000/1267), so the migration must resolve automation resource
 * identifiers first and compare them only as bound parameters.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioContentAuthoringContextRetentionMigration::class)]
final class StudioContentAuthoringContextRetentionCollationTest extends TestCase
{
    /**
     * Proves the migration source carries no cross-table identifier subquery,
     * which is the exact statement shape MariaDB refuses across collations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOwnershipCleanupComparesIdentifiersAsBoundParametersOnly(): void
    {
        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(StudioContentAuthoringContextRetentionMigration::class))->getFileName()
        );

        self::assertStringNotContainsString(
            'IN (\'
                . \'SELECT',
            $source,
            'The ownership cleanup must not compare resource identifiers through a subquery.'
        );
        self::assertDoesNotMatchRegularExpression(
            '/resource_id\s+IN\s*\(\s*\'?\s*SELECT/i',
            $source,
            'resource_id must be compared against bound parameters, never a cross-table SELECT.'
        );
        self::assertStringContainsString(
            "array_fill(0, count(\$resourceIds), '?')",
            $source,
            'Resolved identifiers must be bound as one placeholder per identifier.'
        );
    }

    /**
     * Proves identifier normalization accepts integer and string identifiers
     * and refuses a malformed one outright.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testResourceIdentifierNormalizationFailsClosed(): void
    {
        $method = new ReflectionMethod(
            StudioContentAuthoringContextRetentionMigration::class,
            'resourceIds',
        );

        self::assertSame(['7', 'schedule-a'], $method->invoke(null, [7, 'schedule-a']));
        self::assertSame([], $method->invoke(null, []));

        $this->expectException(RuntimeException::class);
        $method->invoke(null, ['']);
    }
}
