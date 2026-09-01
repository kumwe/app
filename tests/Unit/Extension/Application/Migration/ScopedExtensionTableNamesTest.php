<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Application\Migration;

use InvalidArgumentException;
use Kumwe\App\Extension\Application\Migration\ScopedExtensionTableNames;
use Kumwe\Extension\Manifest\ExtensionIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScopedExtensionTableNames::class)]
/**
 * Proves extension migrations only ever receive names confined to their own package namespace.
 *
 * @since  2.0.0
 */
final class ScopedExtensionTableNamesTest extends TestCase
{
    /**
     * Prove the raw physical name carries the marker and the flattened extension namespace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRawNameIsConfinedToTheExtensionNamespace(): void
    {
        self::assertSame('kw_ext_acme_data_probe_items', self::names()->raw('items'));
    }

    /**
     * Prove the quoted form quotes each dot-separated part of the same confined name.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheQuotedNameQuotesEveryPartOfTheConfinedName(): void
    {
        self::assertSame('`kw_ext_acme_data_probe_items`', self::names()->quoted('items'));
    }

    /**
     * Prove a name that is not a safe lowercase identifier never reaches the core compiler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnsafeNameIsRefusedBeforeCompilation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('safe lowercase identifier');

        self::names()->raw('Items');
    }

    /**
     * Build the compiler under test over deterministic core compile and quote closures.
     *
     * @return  ScopedExtensionTableNames  Compiler bound to the probe extension.
     *
     * @since   2.0.0
     */
    private static function names(): ScopedExtensionTableNames
    {
        return new ScopedExtensionTableNames(
            static fn (string $name): string => 'kw_' . $name,
            static fn (string $part): string => '`' . $part . '`',
            ExtensionIdentifier::fromString('acme/data-probe'),
        );
    }
}
