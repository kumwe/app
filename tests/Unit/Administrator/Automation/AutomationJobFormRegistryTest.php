<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Administrator\Automation;

use Kumwe\CMS\Tests\Support\InterfaceTranslation;
use InvalidArgumentException;
use Kumwe\CMS\Administrator\Automation\AutomationJobField;
use Kumwe\CMS\Administrator\Automation\AutomationJobFormRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AutomationJobFormRegistry::class)]
#[UsesClass(AutomationJobField::class)]
final class AutomationJobFormRegistryTest extends TestCase
{
    public function testMapsTypedGraphicalFieldsToAJobPayload(): void
    {
        $registry = AutomationJobFormRegistry::core(InterfaceTranslation::translator());
        $payload = $registry->payload('content.workflow.transition', [
            'payload__id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb160',
            'payload__version' => '4',
            'payload__status' => 'published',
        ]);

        self::assertSame([
            'id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb160',
            'version' => 4,
            'status' => 'published',
        ], $payload);
        self::assertSame(
            'Purge expired administrator sessions',
            $registry->definitions(['system.sessions.purge'])[0]['label'],
        );
    }

    public function testRejectsAnOutOfRangeGraphicalValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside its limits');

        AutomationJobFormRegistry::core(InterfaceTranslation::translator())->payload('system.idempotency.purge', [
            'payload__batch_size' => '1000',
            'payload__maximum_batches' => '101',
        ]);
    }

    /**
     * Every field refusal reads as a sentence naming the field, not as a message identifier.
     *
     * The captions became catalogue identifiers, so the refusals that interpolate them had to move
     * with them; this pins that each branch resolves both the sentence and the caption inside it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryFieldRefusalNamesTheFieldInResolvedWording(): void
    {
        $registry = AutomationJobFormRegistry::core(InterfaceTranslation::translator());
        $cases = [
            [['payload__version' => '4', 'payload__status' => 'published'], 'The Content ID job field is required.'],
            [
                ['payload__id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb160', 'payload__version' => 'four',
                    'payload__status' => 'published'],
                'The Expected version job field must be a whole number.',
            ],
            [
                ['payload__id' => 'not-a-uuid', 'payload__version' => '4', 'payload__status' => 'published'],
                'The Content ID job field is invalid.',
            ],
        ];
        foreach ($cases as [$form, $expected]) {
            try {
                $registry->payload('content.workflow.transition', $form);
                self::fail('The registry accepted ' . $expected);
            } catch (InvalidArgumentException $refused) {
                self::assertSame($expected, $refused->getMessage());
            }
        }
    }

    /**
     * A registered caption that is not an identifier is presented as the words it already is.
     *
     * An extension registers the wording its own manifest carries, and core has no catalogue for it.
     * Resolving only what satisfies the identifier grammar is what keeps both kinds working.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExtensionCaptionIsPresentedAsTheWordsItAlreadyIs(): void
    {
        $registry = new AutomationJobFormRegistry(InterfaceTranslation::translator());
        $registry->register('acme.tools.sync', 'Synchronise partner records', [
            new AutomationJobField('window', 'Window in hours', 'integer', true, minimum: 1, maximum: 24),
        ]);

        $definition = $registry->definitions(['acme.tools.sync'])[0];

        self::assertSame('Synchronise partner records', $definition['label']);
        self::assertSame('Window in hours', $definition['fields'][0]['label']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The Window in hours job field is outside its limits.');
        $registry->payload('acme.tools.sync', ['payload__window' => '48']);
    }

    /**
     * A job type nobody registered still resolves, to a derived caption and no fields.
     *
     * The raw JSON escape hatch depends on it: an unrecognised type must stay in the selector rather
     * than disappearing because no form describes it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnregisteredJobTypeStillResolvesToADerivedCaption(): void
    {
        $registry = new AutomationJobFormRegistry(InterfaceTranslation::translator());

        $definition = $registry->definitions(['vendor.tool.reindex'])[0];

        self::assertSame('vendor.tool.reindex', $definition['type']);
        self::assertNotSame('', $definition['label']);
        self::assertSame([], $definition['fields']);
        self::assertSame([], $registry->payload('vendor.tool.reindex', ['payload__anything' => 'ignored']));
    }

    /**
     * A boolean field and an option field each refuse a value outside their declared set.
     *
     * These two branches are what stop a hand-edited form from queueing a job with a payload the
     * handler would then have to defend itself against at run time.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBooleanAndOptionFieldsRefuseUndeclaredValues(): void
    {
        $registry = new AutomationJobFormRegistry(InterfaceTranslation::translator());
        $registry->register('acme.tools.publish', 'Publish partner feed', [
            new AutomationJobField('dry_run', 'Dry run', 'boolean'),
            new AutomationJobField('channel', 'Channel', 'select', options: ['public', 'private']),
        ]);

        self::assertSame(
            ['dry_run' => true, 'channel' => 'public'],
            $registry->payload('acme.tools.publish', [
                'payload__dry_run' => 'true',
                'payload__channel' => 'public',
            ]),
        );

        try {
            $registry->payload('acme.tools.publish', ['payload__dry_run' => 'perhaps']);
            self::fail('The registry accepted a non-boolean value.');
        } catch (InvalidArgumentException $refused) {
            self::assertSame('The Dry run job field must be true or false.', $refused->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The Channel job field has an unsupported value.');
        $registry->payload('acme.tools.publish', ['payload__channel' => 'secret']);
    }
}
