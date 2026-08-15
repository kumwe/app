<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Application\Preference;

use InvalidArgumentException;
use Kumwe\CMS\Presentation\Application\Preference\PresentationAccessGroup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the stable identity and fail-closed validation of presentation access groups.
 *
 * @since  2.0.0
 */
#[CoversClass(PresentationAccessGroup::class)]
final class PresentationAccessGroupTest extends TestCase
{
    /**
     * Proves a canonical role becomes the exact prefixed identity preferences persist.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testCanonicalRoleBecomesStablePresentationIdentity(): void
    {
        $roleId = '0191574f-f0b8-7bf3-a9aa-91c6b8244f10';

        $group = PresentationAccessGroup::fromRole($roleId, 'finance-reviewers', 'Finance reviewers');

        self::assertSame('role:' . $roleId, $group->id);
        self::assertSame($roleId, $group->roleId);
        self::assertSame('finance-reviewers', $group->code);
        self::assertSame('Finance reviewers', $group->name);
        self::assertSame($roleId, PresentationAccessGroup::roleIdFromIdentifier($group->id));
    }

    /**
     * Proves noncanonical presentation group identities cannot be mistaken for access-control roles.
     *
     * @param   string  $identifier  Malformed or differently namespaced candidate.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    #[DataProvider('invalidIdentifiers')]
    public function testIdentifierParserRejectsNoncanonicalValues(string $identifier): void
    {
        self::assertNull(PresentationAccessGroup::roleIdFromIdentifier($identifier));
    }

    /**
     * Supply identities that must fail closed before a repository query.
     *
     * @return  iterable<string, array{string}>  Named malformed identifier examples.
     *
     * @since  2.0.0
     */
    public static function invalidIdentifiers(): iterable
    {
        yield 'raw UUID' => ['0191574f-f0b8-7bf3-a9aa-91c6b8244f10'];
        yield 'workspace namespace' => ['workspace:0191574f-f0b8-7bf3-a9aa-91c6b8244f10'];
        yield 'uppercase UUID' => ['role:0191574F-F0B8-7BF3-A9AA-91C6B8244F10'];
        yield 'non-UUID suffix' => ['role:finance-reviewers'];
    }

    /**
     * Proves a corrupted role row is rejected instead of reaching preference resolution.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testStoredRoleFieldsMustMatchCanonicalIdentitySchema(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PresentationAccessGroup::fromRole(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244f10',
            'Invalid Role Code',
            'Finance reviewers',
        );
    }
}
