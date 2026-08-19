<?php

declare(strict_types=1);

namespace KumweExample\Announcements;

use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;

final class BusinessDefinitions
{
    public static function severity(): FieldTypeDefinition
    {
        return new FieldTypeDefinition(
            'kumwe.announcements-example.severity',
            'Announcement severity',
            'A package-owned bounded severity value for announcements.',
            'string',
            'string',
            ['options'],
        );
    }

    /** @return list<EntityTypeDefinition> */
    public static function all(): array
    {
        return [
            EntityTypeDefinition::fromArray(self::category()),
            EntityTypeDefinition::fromArray(self::announcement()),
        ];
    }

    /** @return array<string, mixed> */
    private static function category(): array
    {
        return self::entity(
            '01912f8a-8c4b-7eb1-8f7d-c256efd39801',
            'kumwe.announcements-example.category',
            'Announcement category',
            'Announcement categories',
            [
                self::field('id', 'ID', 'core.uuid', required: true, unique: true, indexed: true),
                self::field(
                    'name',
                    'Name',
                    'core.text',
                    required: true,
                    length: 120,
                    unique: true,
                    indexed: true,
                    filterable: true,
                    sortable: true,
                ),
            ],
            [[
                'handle' => 'announcements', 'label' => 'Announcements', 'kind' => 'one_to_many',
                'target' => 'kumwe.announcements-example.announcement', 'inverse' => 'category',
                'ordered' => false, 'on_delete' => 'restrict',
            ]],
            [[
                'handle' => 'catalog', 'label' => 'Category catalog', 'kind' => 'list',
                'fields' => ['name'], 'filters' => ['name'], 'sorts' => ['name'],
            ]],
        );
    }

    /** @return array<string, mixed> */
    private static function announcement(): array
    {
        $document = self::entity(
            '01912f8a-8c4b-7eb1-8f7d-c256efd39802',
            'kumwe.announcements-example.announcement',
            'Announcement',
            'Announcements',
            [
                self::field('id', 'ID', 'core.uuid', required: true, unique: true, indexed: true),
                self::field(
                    'title',
                    'Title',
                    'core.text',
                    required: true,
                    length: 191,
                    indexed: true,
                    filterable: true,
                    sortable: true,
                ),
                self::field('message', 'Message', 'core.rich_text', required: true, length: 50000),
                self::field(
                    'severity',
                    'Severity',
                    'kumwe.announcements-example.severity',
                    required: true,
                    indexed: true,
                    filterable: true,
                    sortable: true,
                    configuration: ['options' => ['info', 'notice', 'warning', 'critical']],
                    validators: [[
                        'rule' => 'one_of',
                        'value' => ['info', 'notice', 'warning', 'critical'],
                    ]],
                ),
                self::field(
                    'published_at',
                    'Published at',
                    'core.instant',
                    indexed: true,
                    filterable: true,
                    sortable: true,
                ),
            ],
            [[
                'handle' => 'category', 'label' => 'Category', 'kind' => 'many_to_one',
                'target' => 'kumwe.announcements-example.category', 'inverse' => 'announcements',
                'on_delete' => 'restrict',
            ]],
            [
                [
                    'handle' => 'list',
                    'label' => 'Announcement list',
                    'kind' => 'list',
                    'fields' => ['title', 'severity', 'published_at'],
                    'filters' => ['severity'],
                    'sorts' => ['published_at'],
                ],
                [
                    'handle' => 'detail',
                    'label' => 'Announcement detail',
                    'kind' => 'detail',
                    'fields' => ['title', 'message', 'severity', 'published_at'],
                ],
                [
                    'handle' => 'form',
                    'label' => 'Announcement form',
                    'kind' => 'form',
                    'fields' => ['title', 'message', 'severity', 'published_at'],
                ],
            ],
        );
        $document['workflow'] = [
            'initial_state' => 'draft',
            'states' => ['draft', 'published', 'retired'],
            'transitions' => [
                [
                    'handle' => 'publish',
                    'from' => 'draft',
                    'to' => 'published',
                    'capability' => 'kumwe.announcements-example.manage',
                ],
                [
                    'handle' => 'retire',
                    'from' => 'published',
                    'to' => 'retired',
                    'capability' => 'kumwe.announcements-example.manage',
                ],
            ],
        ];
        $document['actions'] = [
            [
                'handle' => 'publish',
                'label' => 'Publish',
                'capability' => 'kumwe.announcements-example.manage',
                'high_impact' => true,
                'transition' => 'publish',
            ],
            [
                'handle' => 'retire',
                'label' => 'Retire',
                'capability' => 'kumwe.announcements-example.manage',
                'high_impact' => true,
                'transition' => 'retire',
            ],
        ];
        return $document;
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param list<array<string, mixed>> $relationships
     * @param list<array<string, mixed>> $views
     * @return array<string, mixed>
     */
    private static function entity(
        string $id,
        string $handle,
        string $singular,
        string $plural,
        array $fields,
        array $relationships,
        array $views,
    ): array {
        return [
            'id' => $id,
            'owner' => ['type' => 'extension', 'identifier' => 'kumwe/announcements-example'],
            'site' => 'default',
            'handle' => $handle,
            'singular_label' => $singular,
            'plural_label' => $plural,
            'status' => 'published',
            'definition_version' => 1,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => $fields,
            'relationships' => $relationships,
            'views' => $views,
            'actions' => [],
            'workflow' => null,
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
        ];
    }

    /**
     * Build one canonical field declaration for the example's signed business definitions.
     *
     * @param   string                      $handle         Stable field handle.
     * @param   string                      $label          Operator-facing label.
     * @param   string                      $type           Registered field-type identifier.
     * @param   bool                        $required       Whether callers must supply the field.
     * @param   ?int                        $length         Optional character bound.
     * @param   bool                        $unique         Whether persisted values are unique.
     * @param   bool                        $indexed        Whether storage carries an index.
     * @param   bool                        $filterable     Whether bounded queries may filter it.
     * @param   bool                        $sortable       Whether bounded queries may sort it.
     * @param   array<string, mixed>        $configuration  Type-owned configuration.
     * @param   list<array<string, mixed>>  $validators     Validation rules shared by every adapter.
     *
     * @return  array<string, mixed>  Canonical field document.
     *
     * @since   2.0.0
     */
    private static function field(
        string $handle,
        string $label,
        string $type,
        bool $required = false,
        ?int $length = null,
        bool $unique = false,
        bool $indexed = false,
        bool $filterable = false,
        bool $sortable = false,
        array $configuration = [],
        array $validators = [],
    ): array {
        return [
            'handle' => $handle, 'label' => $label, 'type' => $type,
            'required' => $required, 'nullable' => !$required, 'length' => $length,
            'unique' => $unique, 'indexed' => $indexed, 'filterable' => $filterable,
            'sortable' => $sortable, 'order' => 0,
            'immutable_after_create' => $type === 'core.uuid',
            'configuration' => $configuration,
            'validators' => $validators,
        ];
    }

    private function __construct()
    {
    }
}
