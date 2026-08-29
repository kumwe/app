<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Domain\Preview;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewDraft;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewGrant;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewIdentity;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderedDocument;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Exercises every fail-closed invariant on the immutable Studio preview value objects.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioPreviewDraft::class)]
#[CoversClass(StudioPreviewGrant::class)]
#[CoversClass(StudioPreviewIdentity::class)]
#[CoversClass(StudioPreviewRenderedDocument::class)]
#[CoversClass(StudioPreviewRenderRequest::class)]
final class StudioPreviewValueObjectTest extends TestCase
{
    /**
     * Reject malformed draft identity, node identity, slot structure and duplicate nodes.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testMalformedDraftStructuresAreRejected(): void
    {
        $cases = [
            'draft identity' => static fn () => new StudioPreviewDraft('', self::document()),
            'roots list' => static fn () => StudioPreviewIdentity::forDraft(new stdClass()),
            'node identity' => static fn () => StudioPreviewIdentity::forDraft((object) [
                'roots' => [new stdClass()],
            ]),
            'duplicate node' => static fn () => StudioPreviewIdentity::forDraft((object) [
                'roots' => [self::node('nodes/one'), self::node('nodes/one')],
            ]),
            'slot object' => static fn () => StudioPreviewIdentity::forDraft((object) [
                'roots' => [(object) ['id' => 'nodes/one']],
            ]),
            'slot children' => static fn () => StudioPreviewIdentity::forDraft((object) [
                'roots' => [(object) [
                    'id' => 'nodes/one',
                    'slots' => (object) ['main' => new stdClass()],
                ]],
            ]),
        ];

        foreach ($cases as $case => $operation) {
            self::assertInvalid($operation, $case);
        }
    }

    /**
     * Reject malformed render identities, closed payload aliases and incoherent rendered documents.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testMalformedRenderRequestsAndDocumentsAreRejected(): void
    {
        $cases = [
            'artifact identity' => static fn () => new StudioPreviewRenderRequest(
                '__proto__',
                str_repeat('a', 64),
                'r1',
                'requests/one',
                'expanded',
            ),
            'request identity' => static fn () => new StudioPreviewRenderRequest(
                'blueprints/one',
                str_repeat('a', 64),
                'r1',
                'bad request',
                'expanded',
            ),
            'draft revision' => static fn () => new StudioPreviewRenderRequest(
                'blueprints/one',
                str_repeat('a', 64),
                '',
                'requests/one',
                'expanded',
            ),
            'draft digest' => static fn () => new StudioPreviewRenderRequest(
                'blueprints/one',
                'invalid',
                'r1',
                'requests/one',
                'expanded',
            ),
            'viewport' => static fn () => new StudioPreviewRenderRequest(
                'blueprints/one',
                str_repeat('a', 64),
                'r1',
                'requests/one',
                'invalid viewport',
            ),
            'payload shape' => static fn () => StudioPreviewRenderRequest::fromPayload((object) [
                'artifactId' => 'blueprints/one',
            ]),
            'empty document' => static fn () => new StudioPreviewRenderedDocument('', [], []),
            'marker count' => static fn () => new StudioPreviewRenderedDocument(
                '<!doctype html>',
                ['markers/one'],
                [],
            ),
            'marker mapping' => static fn () => new StudioPreviewRenderedDocument(
                '<!doctype html>',
                ['markers/one'],
                ['markers/two' => 'nodes/one'],
            ),
            'stylesheet' => static fn () => new StudioPreviewRenderedDocument(
                '<!doctype html>',
                [],
                [],
                stylesheet: '',
            ),
        ];

        foreach ($cases as $case => $operation) {
            self::assertInvalid($operation, $case);
        }
    }

    /**
     * Refuse an incomplete trusted grant scope before a rendered document can be claimed.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testIncompletePreviewGrantBindingIsRejected(): void
    {
        self::assertInvalid(
            static fn () => new StudioPreviewGrant(
                'contexts/one',
                'actors/one',
                'default',
                null,
                'workspaces/one',
                str_repeat('a', 64),
                'generation-1',
                'https://kumwe.test',
                'channels/one',
                'sources/one',
                new StudioPreviewRenderRequest(
                    'blueprints/one',
                    str_repeat('a', 64),
                    'r1',
                    'requests/one',
                    'expanded',
                ),
                new StudioPreviewRenderedDocument('<!doctype html>', [], []),
                new DateTimeImmutable('2030-01-01T00:00:00+00:00'),
            ),
            'grant binding',
        );
    }

    /**
     * Build one minimal valid Blueprint document.
     *
     * @return  stdClass  Valid preview draft.
     *
     * @since  2.0.0
     */
    private static function document(): stdClass
    {
        return (object) [
            'id' => 'blueprints/one',
            'kind' => 'blueprint',
            'revision' => 'r1',
            'roots' => [],
        ];
    }

    /**
     * Build one marker-eligible empty node.
     *
     * @param   string  $id  Stable node identity.
     *
     * @return  stdClass  Valid node.
     *
     * @since  2.0.0
     */
    private static function node(string $id): stdClass
    {
        return (object) ['id' => $id, 'slots' => new stdClass()];
    }

    /**
     * Assert one preview value-object operation rejects malformed state.
     *
     * @param   callable  $operation  Invalid construction or derivation.
     * @param   string    $case       Scenario label.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    private static function assertInvalid(callable $operation, string $case): void
    {
        try {
            $operation();
            self::fail('The invalid Studio preview value was accepted: ' . $case);
        } catch (InvalidArgumentException $failure) {
            self::assertNotSame('', $failure->getMessage(), $case);
        }
    }
}
