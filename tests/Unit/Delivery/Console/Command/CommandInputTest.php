<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\CMS\Delivery\Console\Command\CommandInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommandInput::class)]
final class CommandInputTest extends TestCase
{
    /**
     * Protected temporary input files allocated by the current test.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $files = [];

    /**
     * Remove protected files and symlinks allocated by the current test.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach (array_reverse($this->files) as $file) {
            if (is_link($file) || is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testJsonObjectAcceptsAnEmptyObject(): void
    {
        self::assertSame([], CommandInput::jsonObject(['payload' => '{}'], 'payload'));
    }

    public function testJsonObjectPreservesNestedObjectData(): void
    {
        self::assertSame(
            ['context' => ['site' => 'main'], 'enabled' => true],
            CommandInput::jsonObject(
                ['payload' => '{"context":{"site":"main"},"enabled":true}'],
                'payload',
            ),
        );
    }

    public function testJsonObjectRejectsAList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The --payload option must be a JSON object.');

        CommandInput::jsonObject(['payload' => '[]'], 'payload');
    }

    /**
     * Proves protected JSON input preserves exact business-value strings.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProtectedJsonObjectPreservesExactBusinessStrings(): void
    {
        $file = $this->file('{"amount":"12345678901234567890.001200","enabled":true}');

        self::assertSame([
            'amount' => '12345678901234567890.001200',
            'enabled' => true,
        ], CommandInput::protectedJsonObject($file));
    }

    /**
     * Proves group-readable JSON input is rejected before parsing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProtectedJsonDocumentRejectsAGroupReadableFile(): void
    {
        $file = $this->file('{}');
        self::assertTrue(chmod($file, 0640));

        $this->expectException(InvalidArgumentException::class);
        CommandInput::protectedJsonObject($file);
    }

    /**
     * Proves protected JSON input cannot be supplied through a symbolic link.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProtectedJsonDocumentRejectsASymlink(): void
    {
        $target = $this->file('{}');
        $link = $target . '-link';
        self::assertTrue(symlink($target, $link));
        $this->files[] = $link;

        $this->expectException(InvalidArgumentException::class);
        CommandInput::protectedJsonObject($link);
    }

    /**
     * Proves protected string-list input rejects objects and mixed scalar members.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProtectedJsonStringListRejectsAnObjectAndNonStringMembers(): void
    {
        $object = $this->file('{}');
        $mixed = $this->file('["line-1",2]');

        try {
            CommandInput::protectedJsonStringList($object);
            self::fail('A JSON object must not be accepted as a string list.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        CommandInput::protectedJsonStringList($mixed);
    }

    /**
     * Proves integer readers reject overflow and admit zero only for non-negative controls.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIntegerReadersRejectOverflowAndAcceptZeroOnlyWhereDeclared(): void
    {
        self::assertSame(0, CommandInput::nonNegativeInteger(['position' => '0'], 'position', 10));

        $this->expectException(InvalidArgumentException::class);
        CommandInput::positiveInteger(['version' => str_repeat('9', 100)], 'version');
    }

    /**
     * Create one owner-only temporary input file.
     *
     * @param   string  $contents  Exact bytes to write.
     *
     * @return  string  Protected temporary path tracked for cleanup.
     *
     * @since   2.0.0
     */
    private function file(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'kumwe-command-input-');
        self::assertIsString($file);
        $this->files[] = $file;
        self::assertTrue(chmod($file, 0600));
        self::assertNotFalse(file_put_contents($file, $contents));

        return $file;
    }
}
