<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Persistence\Migration;

use Kumwe\CMS\Content\Domain\JsonSchemaValidator;
use Kumwe\CMS\Infrastructure\Persistence\Migration\DocumentContentTypesMigration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Proves the seeded document layout types stay valid, distinct, and inside the supported schema subset.
 *
 * @since  2.0.0
 */
#[CoversClass(DocumentContentTypesMigration::class)]
#[UsesClass(JsonSchemaValidator::class)]
final class DocumentContentTypesMigrationTest extends TestCase
{
    /**
     * Validate every seeded schema against the closed keyword vocabulary the editor understands.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySeededContentTypeSchemaStaysInsideTheSupportedSubset(): void
    {
        $validator = new JsonSchemaValidator();
        $handles = [];
        foreach ($this->types() as $id => $type) {
            self::assertMatchesRegularExpression('/^018f22e2-7c8b-7ab0-8f3a-88e8026bb4[0-9a-f]{2}$/D', $id);
            self::assertIsString($type['handle']);
            self::assertNotContains($type['handle'], $handles, 'Content type handles must be unique.');
            $handles[] = $type['handle'];
            $validator->assertSupported($type['schema']);
        }

        self::assertSame(
            ['document', 'guide', 'reference', 'faq', 'landing', 'article'],
            $handles,
        );
    }

    /**
     * Prove representative payloads for each layout satisfy their seeded schemas.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRepresentativePayloadsSatisfyTheSeededSchemas(): void
    {
        $validator = new JsonSchemaValidator();
        $section = ['anchor' => 'first-part', 'heading' => 'First part', 'body' => 'Section prose.'];
        $payloads = [
            'document' => [
                'heading' => 'Handbook',
                'body' => 'Introduction prose.',
                'sections' => [$section],
            ],
            'guide' => [
                'heading' => 'Provision a client',
                'body' => 'Before you begin.',
                'steps' => [
                    [
                        'anchor' => 'sign-in',
                        'heading' => 'Sign in',
                        'body' => 'Open the administrator.',
                        'image' => '/media/step.png',
                        'image_caption' => 'The sign-in form.',
                    ],
                ],
                'outcome' => ['body' => 'The client can sign in.'],
            ],
            'reference' => [
                'heading' => 'REST objects',
                'entries' => [
                    [
                        'anchor' => 'content-object',
                        'term' => 'Content',
                        'kind' => 'object',
                        'body' => 'The content payload.',
                        'example' => '{"title": "Example"}',
                    ],
                ],
            ],
            'faq' => [
                'heading' => 'Questions',
                'items' => [['question' => 'What is Kumwe?', 'body' => 'A CMS and business platform.']],
            ],
            'landing' => [
                'heading' => 'Run your business on Kumwe',
                'primary_action' => ['label' => 'Start', 'url' => '/pages/start'],
                'features' => [['heading' => 'Typed business data', 'body' => 'Definitions become surfaces.']],
                'closing' => ['body' => 'Install the demo.', 'action' => ['label' => 'Demo', 'url' => '/pages/demo']],
            ],
            'article' => [
                'heading' => 'Release note',
                'published_label' => 'August 2026',
                'body' => 'Article prose.',
                'highlights' => ['One highlight'],
                'further_reading' => [['label' => 'Guide', 'url' => '/pages/guide']],
            ],
        ];

        $schemas = [];
        foreach ($this->types() as $type) {
            $schemas[$type['handle']] = $type['schema'];
        }
        foreach ($payloads as $handle => $payload) {
            $validator->assertValid($schemas[$handle], $payload);
        }

        self::addToAssertionCount(count($payloads));
    }

    /**
     * Read the private seeded-type map without touching a database connection.
     *
     * @return  array<string, array{handle: string, name: string, schema: array<string, mixed>}>
     *
     * @since   2.0.0
     */
    private function types(): array
    {
        $reflection = new ReflectionClass(DocumentContentTypesMigration::class);
        $migration = $reflection->newInstanceWithoutConstructor();
        $types = (new ReflectionMethod($migration, 'types'))->invoke($migration);
        self::assertIsArray($types);

        /** @var array<string, array{handle: string, name: string, schema: array<string, mixed>}> $types */
        return $types;
    }
}
