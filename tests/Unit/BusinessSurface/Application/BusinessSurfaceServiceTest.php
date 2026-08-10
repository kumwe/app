<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessSurface\Application;

use Kumwe\CMS\BusinessSurface\Application\BusinessSurfaceService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(BusinessSurfaceService::class)]
/**
 * Proves generated-business presentation helpers bound selector labels.
 *
 * @since  2.0.0
 */
final class BusinessSurfaceServiceTest extends TestCase
{
    /**
     * Proves selector labels are normalized to one line and a UTF-8-safe byte limit.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRelationshipChoiceLabelsAreSingleLineAndUtf8ByteBounded(): void
    {
        $choiceText = (new ReflectionClass(BusinessSurfaceService::class))->getMethod('choiceText');

        self::assertSame('Alpha Beta', $choiceText->invoke(null, " Alpha\n\tBeta ", 120));
        $bounded = $choiceText->invoke(null, str_repeat("\u{1F680}", 40), 120);
        self::assertIsString($bounded);
        self::assertLessThanOrEqual(120, strlen($bounded));
        self::assertSame(1, preg_match('//u', $bounded));
    }
}
