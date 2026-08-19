<?php

declare(strict_types=1);

namespace @@PHP_NAMESPACE@@\Definition;

use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;

/**
 * Owns the exact business definitions declared by the signed manifest.
 *
 * @since  2.0.0
 */
final class BusinessDefinitions
{
    /**
     * Return the component's complete business-definition set.
     *
     * @return  list<EntityTypeDefinition>  Definitions in stable handle order.
     *
     * @since   2.0.0
     */
    public static function all(): array
    {
        return [EntityTypeDefinition::fromArray([
            'id' => '@@ENTITY_ID@@',
            'owner' => ['type' => 'extension', 'identifier' => '@@EXTENSION_IDENTIFIER@@'],
            'site' => 'default',
            'handle' => '@@EXTENSION_DOTTED@@.item',
            'singular_label' => '@@LABEL_PHP@@ item',
            'plural_label' => '@@LABEL_PHP@@ items',
            'status' => 'published',
            'definition_version' => 1,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [
                [
                    'handle' => 'id', 'label' => 'ID', 'type' => 'core.uuid', 'required' => true,
                    'nullable' => false, 'length' => null, 'unique' => true, 'indexed' => true,
                    'immutable_after_create' => true, 'filterable' => false, 'sortable' => false, 'order' => 0,
                ],
                [
                    'handle' => 'title', 'label' => 'Title', 'type' => 'core.text', 'required' => true,
                    'nullable' => false, 'length' => 191, 'unique' => false, 'indexed' => true,
                    'filterable' => true, 'sortable' => true, 'order' => 10,
                ],
            ],
            'relationships' => [],
            'views' => [
                [
                    'handle' => 'list', 'label' => 'Item list', 'kind' => 'list', 'fields' => ['title'],
                    'filters' => ['title'], 'sorts' => ['title'], 'administrator' => true,
                    'portal' => true, 'public' => false,
                ],
                [
                    'handle' => 'detail', 'label' => 'Item detail', 'kind' => 'detail', 'fields' => ['title'],
                    'administrator' => true, 'portal' => true, 'public' => false,
                ],
                [
                    'handle' => 'form', 'label' => 'Item form', 'kind' => 'form', 'fields' => ['title'],
                    'administrator' => true, 'portal' => false, 'public' => false,
                ],
            ],
            'actions' => [],
            'workflow' => null,
            'portal_operations' => ['browse', 'read'],
            'administrator_exposure' => true,
            'portal_exposure' => true,
            'public_exposure' => false,
        ])];
    }
}
