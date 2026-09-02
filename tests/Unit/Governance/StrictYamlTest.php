<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Governance;

use Kumwe\App\Tools\Governance\GovernanceViolation;
use Kumwe\App\Tools\Governance\StrictYaml;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Holds `tools/Governance/StrictYaml.php` to the subset every Version 2 record is written in.
 *
 * The parser accepts exactly the constructs the specification lists and refuses everything else with the line
 * that carries it, so a governance record has one reading and no parser feature can smuggle a value past a
 * schema.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class StrictYamlTest extends TestCase
{
    /**
     * Load the governance classes once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/Governance/bootstrap.php';
    }

    /**
     * Every accepted construct parses to the typed value the subset defines.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAcceptedConstructsParseToTypedValues(): void
    {
        $yaml = implode("\n", [
            '# leading comment',
            'schema: kumwe-x/v1 # trailing comment',
            'id: KUMWE-CS-2026-001',
            'count: 42',
            'negative: -3',
            'flag: true',
            'off: false',
            'nothing: null',
            'tilde: ~',
            'empty:',
            'list: []',
            'map: {}',
            'url: https://github.com/kumwe/app',
            'double: "a \"b\" \\\\ \n c"',
            "single: 'it''s'",
            'digits: "007"',
            'nested:',
            '  inner: 1',
            '  sequence:',
            '    - x',
            '    - y',
            'items:',
            '  - id: S3-O01',
            '    ordinal: 1',
            '  - id: S3-O02',
            '    ordinal: 2',
            '  -',
            '    deep: v',
            '  - - n1',
            '    - n2',
            '"quoted key": v',
            '',
        ]);

        $parsed = StrictYaml::parse($yaml, 'record.yaml');

        self::assertSame('kumwe-x/v1', $parsed['schema']);
        self::assertSame(42, $parsed['count']);
        self::assertSame(-3, $parsed['negative']);
        self::assertTrue($parsed['flag']);
        self::assertFalse($parsed['off']);
        self::assertNull($parsed['nothing']);
        self::assertNull($parsed['tilde']);
        self::assertNull($parsed['empty']);
        self::assertSame([], $parsed['list']);
        self::assertSame([], $parsed['map']);
        self::assertSame('https://github.com/kumwe/app', $parsed['url']);
        self::assertSame("a \"b\" \\ \n c", $parsed['double']);
        self::assertSame("it's", $parsed['single']);
        self::assertSame('007', $parsed['digits'], 'A quoted number stays a string.');
        self::assertSame(['inner' => 1, 'sequence' => ['x', 'y']], $parsed['nested']);
        self::assertSame(
            [['id' => 'S3-O01', 'ordinal' => 1], ['id' => 'S3-O02', 'ordinal' => 2], ['deep' => 'v'], ['n1', 'n2']],
            $parsed['items'],
        );
        self::assertSame('v', $parsed['quoted key']);
    }

    /**
     * CRLF line endings and an empty document are accepted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCrlfAndEmptyDocumentsAreAccepted(): void
    {
        self::assertSame(['a' => 1, 'b' => ['c' => 'd']], StrictYaml::parse("a: 1\r\nb:\r\n  c: d\r\n"));
        self::assertSame([], StrictYaml::parse("# only a comment\n\n"));
    }

    /**
     * Every rejected construct raises a violation that names the line.
     *
     * @param   string  $yaml  Document outside the subset.
     * @param   string  $rule  Fragment of the expected message.
     * @param   int     $line  Line the violation must name.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('rejectedConstructs')]
    public function testRejectedConstructsNameTheirLine(string $yaml, string $rule, int $line): void
    {
        try {
            StrictYaml::parse($yaml, 'record.yaml');
            self::fail('The construct must be refused: ' . json_encode($yaml, JSON_THROW_ON_ERROR));
        } catch (GovernanceViolation $violation) {
            self::assertStringContainsString('record.yaml:' . $line . ':', $violation->getMessage());
            self::assertStringContainsString($rule, $violation->getMessage());
            self::assertStringContainsString('Fix:', $violation->getMessage());
        }
    }

    /**
     * Constructs the subset refuses, with the rule fragment and the line each must name.
     *
     * @return  iterable<string, array{string, string, int}>  Document, rule fragment, line.
     *
     * @since   2.0.0
     */
    public static function rejectedConstructs(): iterable
    {
        yield 'literal block scalar' => ["a: 1\nb: |\n  text\n", 'multi-line block scalars', 2];
        yield 'folded block scalar' => ["b: >\n  text\n", 'multi-line block scalars', 1];
        yield 'anchor' => ["a: &x 1\n", 'anchors', 1];
        yield 'alias' => ["a: *x\n", 'aliases', 1];
        yield 'tag' => ["a: !tag v\n", 'tags', 1];
        yield 'flow sequence with content' => ["a: [1, 2]\n", 'flow collections with content', 1];
        yield 'flow mapping with content' => ["a: {b: 1}\n", 'flow collections with content', 1];
        yield 'document marker' => ["a: 1\n---\nb: 2\n", 'document markers', 2];
        yield 'tab indentation' => ["a:\n\tb: 1\n", 'tab characters', 2];
        yield 'odd indentation' => ["a:\n   b: 1\n", 'not a multiple of two', 2];
        yield 'nested block indented by four' => ["a:\n    b: 1\n", 'exactly two spaces', 2];
        yield 'duplicate key' => ["a: 1\na: 2\n", 'repeated', 2];
        yield 'mapping value inside a plain scalar' => ["a: b: c\n", 'cannot contain `: `', 1];
        yield 'unterminated double quote' => ["a: \"open\n", 'not closed', 1];
        yield 'content after a quoted scalar' => ["a: \"x\" y\n", 'unexpected content', 1];
        yield 'unsupported escape' => ["a: \"\\t\"\n", 'escape', 1];
        yield 'top-level sequence' => ["- x\n- y\n", 'top-level node must be a mapping', 1];
        yield 'sequence at the key indentation' => ["a:\n- x\n", 'sequence item appears where a mapping key', 2];
        yield 'key inside a sequence' => ["a:\n  - x\n  b: 1\n", 'mapping key appears where a sequence item', 3];
        yield 'two spaces after the dash' => ["a:\n  -  x\n", 'exactly one space', 2];
        yield 'unexpected deeper line' => ["a: 1\n  b: 2\n", 'unexpected indentation', 2];
        yield 'plain scalar starting with @' => ["a: @x\n", 'quote it', 1];
        yield 'directive' => ["%YAML 1.2\na: 1\n", 'directives', 1];
        yield 'not a key line' => ["a:\n  https://x\n", 'not a `key: value` pair', 2];
    }

    /**
     * Front matter is split at its fences and its lines are numbered from the top of the file.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFrontMatterIsSplitAtItsFences(): void
    {
        $parsed = StrictYaml::parseFrontMatter("---\nid: X\nlist:\n  - a\n---\n\n## Body\n", 'record.md');

        self::assertSame(['id' => 'X', 'list' => ['a']], $parsed['front_matter']);
        self::assertSame("\n## Body\n", $parsed['body']);

        try {
            StrictYaml::parseFrontMatter("---\nid: X\nbad: [1]\n---\n", 'record.md');
            self::fail('A flow collection inside front matter must be refused.');
        } catch (GovernanceViolation $violation) {
            self::assertStringContainsString('record.md:3:', $violation->getMessage());
        }
    }

    /**
     * A record without an opening or closing fence is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMissingFencesAreRefused(): void
    {
        try {
            StrictYaml::parseFrontMatter("id: X\n", 'record.md');
            self::fail('A missing opening fence must be refused.');
        } catch (GovernanceViolation $violation) {
            self::assertStringContainsString('must start on line 1', $violation->getMessage());
        }
        try {
            StrictYaml::parseFrontMatter("---\nid: X\n", 'record.md');
            self::fail('A missing closing fence must be refused.');
        } catch (GovernanceViolation $violation) {
            self::assertStringContainsString('no closing', $violation->getMessage());
        }
    }
}
