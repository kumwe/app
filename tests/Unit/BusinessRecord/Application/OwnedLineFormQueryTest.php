<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Application\Query\OwnedLineFormQuery;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OwnedLineFormQuery::class)]
/**
 * Pins the bounded source identity and relationship grammar for owned-line form requests.
 *
 * @since  2.0.0
 */
final class OwnedLineFormQueryTest extends TestCase
{
    /**
     * Valid source and relationship identities survive unchanged.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAcceptsOneExactSourceAndRelationship(): void
    {
        $query = new OwnedLineFormQuery(
            AuthorizationContext::human(['business.record.relate']),
            'site.default.order',
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb710',
            'lines',
        );

        self::assertSame('lines', $query->relationship);
    }

    /**
     * Malformed handles are rejected before any policy or repository work.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsMalformedRelationshipBeforeExecution(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OwnedLineFormQuery(
            AuthorizationContext::human(['business.record.relate']),
            'site.default.order',
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb710',
            'lines[]',
        );
    }
}
