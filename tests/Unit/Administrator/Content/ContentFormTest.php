<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Administrator\Content;

use DateTimeImmutable;
use Kumwe\CMS\Administrator\Content\ContentFormDataMapper;
use Kumwe\CMS\Administrator\Content\ContentFormPresenter;
use Kumwe\CMS\Administrator\Content\ContentModelFormMapper;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Content\Domain\ContentTypeDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentFormDataMapper::class)]
#[CoversClass(ContentFormPresenter::class)]
#[CoversClass(ContentModelFormMapper::class)]
#[UsesClass(ContentTypeDefinition::class)]
#[UsesClass(SiteContext::class)]
final class ContentFormTest extends TestCase
{
    public function testPresentsAndMapsTypedSchemaFieldsWithoutJsonAuthoring(): void
    {
        $definition = $this->definition();
        $fields = (new ContentFormPresenter())->fields($definition, [
            'body' => 'Graphical rich text',
            'hero_image' => '/media/example/hero.jpg',
            'priority' => 2,
            'featured' => true,
            'metadata' => ['summary' => 'Visible summary'],
        ]);

        $fieldsByKey = array_column($fields, null, 'key');
        self::assertSame('rich-text', $fieldsByKey['body']['input']);
        self::assertSame('content-field-hero_image', $fieldsByKey['hero_image']['id']);
        self::assertTrue($fieldsByKey['hero_image']['accepts_media']);
        self::assertSame('number', $fieldsByKey['priority']['input']);
        self::assertSame('checkbox', $fieldsByKey['featured']['input']);
        self::assertSame('group', $fieldsByKey['metadata']['kind']);

        $mapped = (new ContentFormDataMapper())->map($definition, [
            'field__hero_image' => '/media/example/hero.jpg',
            'field__priority' => '3',
            'field__featured' => '1',
            'field__metadata__summary' => 'A graphical nested field',
        ]);
        self::assertSame([
            'hero_image' => '/media/example/hero.jpg',
            'priority' => 3,
            'featured' => true,
            'metadata' => ['summary' => 'A graphical nested field'],
        ], $mapped);
    }

    public function testBuildsSchemaAndWorkflowFromGraphicalRows(): void
    {
        $mapper = new ContentModelFormMapper();
        $schema = $mapper->contentTypeSchema([
            'field_0_key' => 'body',
            'field_0_title' => 'Article body',
            'field_0_type' => 'text',
            'field_0_required' => '1',
            'field_0_minimum' => '10',
            'field_1_key' => 'hero_image',
            'field_1_title' => 'Hero image',
            'field_1_type' => 'media',
        ]);

        self::assertSame('string', $schema['properties']['body']['type']);
        self::assertSame(10, $schema['properties']['body']['minLength']);
        self::assertSame('uri', $schema['properties']['hero_image']['format']);
        self::assertSame(['body'], $schema['required']);

        $states = $mapper->workflowStates([
            'initial_state_key' => 'draft',
            'state_0_key' => 'draft',
            'state_0_name' => 'Draft',
            'state_1_key' => 'published',
            'state_1_name' => 'Published',
            'state_1_public' => '1',
        ]);
        $transitions = $mapper->workflowTransitions([
            'transition_0_from' => 'draft',
            'transition_0_to' => 'published',
            'transition_0_capability' => 'content.publish',
        ]);

        self::assertTrue($states[0]['initial']);
        self::assertTrue($states[1]['public']);
        self::assertSame('content.publish', $transitions[0]['required_capability']);
    }

    private function definition(): ContentTypeDefinition
    {
        $time = new DateTimeImmutable('2026-08-06T12:00:00+00:00');

        return new ContentTypeDefinition(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb160',
            SiteContext::default(),
            'article',
            'Article',
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb161',
            1,
            [
                'type' => 'object',
                'properties' => [
                    'body' => ['type' => 'string', 'title' => 'Body'],
                    'hero_image' => ['type' => 'string', 'format' => 'uri', 'title' => 'Hero image'],
                    'priority' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                    'featured' => ['type' => 'boolean'],
                    'metadata' => [
                        'type' => 'object',
                        'properties' => ['summary' => ['type' => 'string']],
                        'required' => ['summary'],
                    ],
                ],
                'required' => ['hero_image', 'priority', 'metadata'],
                'additionalProperties' => false,
            ],
            1,
            $time,
            $time,
        );
    }
}
