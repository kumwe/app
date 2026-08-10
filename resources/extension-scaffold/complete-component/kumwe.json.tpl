{
  "schema": 4,
  "name": "@@EXTENSION_IDENTIFIER@@",
  "type": "component",
  "version": "@@VERSION@@",
  "provider": "@@PHP_NAMESPACE_JSON@@\\Provider",
  "autoload": {"psr-4": {"@@PHP_NAMESPACE_JSON@@\\": "src/"}},
  "requires": {"kumwe": "^2.0.0", "php": "^8.5.0"},
  "dependencies": [],
  "migrations": ["@@PHP_NAMESPACE_JSON@@\\Migration\\CreateComponentRecords"],
  "configuration": {"type": "object", "properties": {}},
  "permissions": ["@@EXTENSION_DOTTED@@.access"],
  "routes": [],
  "events": [],
  "assets": [],
  "contributions": {
    "version": 2,
    "capabilities": [{
      "id": "@@EXTENSION_DOTTED@@.access",
      "label": "Access @@LABEL_JSON@@",
      "description": "Open the administrator and portal surfaces supplied by @@LABEL_JSON@@."
    }],
    "resource_policies": [{
      "id": "@@EXTENSION_DOTTED@@.surface",
      "capability": "@@EXTENSION_DOTTED@@.access",
      "resources": [
        {"type": "administrator_session", "identifiers": []},
        {"type": "business_report", "identifiers": ["@@EXTENSION_DOTTED@@.item_report"]},
        {"type": "portal_session", "identifiers": []}
      ],
      "installation_global": false,
      "system_identities": [],
      "lifecycle": "active",
      "version": 1
    }],
    "administrator": {
      "workspaces": [{
        "id": "@@EXTENSION_DOTTED@@.workspace",
        "label": "@@LABEL_JSON@@",
        "description": "Manage @@LABEL_JSON@@ records.",
        "priority": 200
      }],
      "navigation": [{
        "id": "@@EXTENSION_DOTTED@@.navigation",
        "workspace": "@@EXTENSION_DOTTED@@.workspace",
        "label": "@@LABEL_JSON@@",
        "description": "Open @@LABEL_JSON@@ administration.",
        "path": "/",
        "icon": "extensions",
        "capability": "@@EXTENSION_DOTTED@@.access",
        "priority": 10,
        "keywords": "@@LABEL_JSON@@ component records"
      }],
      "routes": [{
        "name": "@@EXTENSION_DOTTED@@.administrator.index",
        "path": "/",
        "methods": ["GET"],
        "capability": "@@EXTENSION_DOTTED@@.access",
        "view": "@@EXTENSION_DOTTED@@.administrator.index"
      }],
      "views": [{
        "name": "@@EXTENSION_DOTTED@@.administrator.index",
        "template": "index.twig"
      }]
    },
    "portal": {
      "workspaces": [{
        "id": "@@EXTENSION_DOTTED@@.portal.workspace",
        "label": "@@LABEL_JSON@@",
        "description": "Use @@LABEL_JSON@@ in the portal.",
        "priority": 200
      }],
      "navigation": [{
        "id": "@@EXTENSION_DOTTED@@.portal.navigation",
        "workspace": "@@EXTENSION_DOTTED@@.portal.workspace",
        "label": "@@LABEL_JSON@@",
        "description": "Open @@LABEL_JSON@@.",
        "path": "/",
        "icon": "extensions",
        "capability": "@@EXTENSION_DOTTED@@.access",
        "priority": 10,
        "keywords": "@@LABEL_JSON@@ component records"
      }],
      "routes": [{
        "name": "@@EXTENSION_DOTTED@@.portal.index",
        "path": "/",
        "methods": ["GET"],
        "capability": "@@EXTENSION_DOTTED@@.access",
        "template": "@@EXTENSION_DOTTED@@.portal.index"
      }],
      "templates": [{
        "name": "@@EXTENSION_DOTTED@@.portal.index",
        "template": "index.twig"
      }]
    },
    "business": {
      "field_types": [],
      "field_presentations": [],
      "definitions": [{
        "id": "@@ENTITY_ID@@",
        "owner": {"type": "extension", "identifier": "@@EXTENSION_IDENTIFIER@@"},
        "site": "default",
        "handle": "@@EXTENSION_DOTTED@@.item",
        "singular_label": "@@LABEL_JSON@@ item",
        "plural_label": "@@LABEL_JSON@@ items",
        "status": "published",
        "definition_version": 1,
        "storage_mode": "relational",
        "identity_strategy": "uuid",
        "scope": "site",
        "audit_enabled": true,
        "revisions_enabled": true,
        "fields": [
          {
            "handle":"id","label":"ID","type":"core.uuid","required":true,"nullable":false,"length":null,
            "unique":true,"indexed":true,"immutable_after_create":true,"filterable":false,"sortable":false,"order":0
          },
          {
            "handle":"title","label":"Title","type":"core.text","required":true,"nullable":false,"length":191,
            "unique":false,"indexed":true,"filterable":true,"sortable":true,"order":10
          }
        ],
        "relationships": [],
        "views": [
          {
            "handle":"list","label":"Item list","kind":"list","fields":["title"],
            "filters":["title"],"sorts":["title"],"administrator":true,"portal":true,"public":false
          },
          {
            "handle":"detail","label":"Item detail","kind":"detail","fields":["title"],
            "administrator":true,"portal":true,"public":false
          },
          {
            "handle":"form","label":"Item form","kind":"form","fields":["title"],
            "administrator":true,"portal":false,"public":false
          }
        ],
        "actions": [],
        "workflow": null,
        "portal_operations": ["browse", "read"],
        "administrator_exposure": true,
        "portal_exposure": true,
        "public_exposure": false
      }]
    },
    "integration": {
      "event_schemas": [{
        "event_type": "@@EXTENSION_DOTTED@@.item_observed",
        "schema_version": 1,
        "sensitivity": "internal",
        "payload_schema": {
          "type": "object",
          "properties": {
            "item_id": {"type": "string", "maxLength": 191},
            "title": {"type": "string", "maxLength": 191}
          },
          "required": ["item_id", "title"],
          "additionalProperties": false
        },
        "maximum_bytes": 4096
      }],
      "domain_listeners": [{
        "listener_id": "@@EXTENSION_DOTTED@@.item_listener",
        "event_type": "@@EXTENSION_DOTTED@@.item_observed",
        "schema_versions": [1],
        "handler_version": "1.0.0",
        "priority": 0,
        "sensitivity_ceiling": "internal"
      }],
      "consumers": [{
        "consumer_id": "@@EXTENSION_DOTTED@@.item_consumer",
        "event_type": "@@EXTENSION_DOTTED@@.item_observed",
        "schema_versions": [1],
        "handler_version": "1.0.0",
        "queue": "@@EXTENSION_DOTTED@@.work",
        "aggregate_ordered": true,
        "idempotency": "event_id",
        "maximum_attempts": 5,
        "sensitivity_ceiling": "internal"
      }],
      "jobs": [{
        "job_type": "@@EXTENSION_DOTTED@@.digest",
        "schema_version": 1,
        "handler_version": "1.0.0",
        "payload_schema": {
          "type": "object",
          "properties": {"message": {"type": "string", "maxLength": 191}},
          "required": ["message"],
          "additionalProperties": false
        },
        "queue": "@@EXTENSION_DOTTED@@.work",
        "maximum_attempts": 5,
        "installation_wide": false
      }],
      "queues": [{
        "queue_id": "@@EXTENSION_DOTTED@@.work",
        "lease_seconds": 60,
        "maximum_attempts": 5,
        "maximum_in_flight": 8,
        "retention_days": 30
      }],
      "schedules": [{
        "schedule_id": "@@EXTENSION_DOTTED@@.hourly_digest",
        "job_type": "@@EXTENSION_DOTTED@@.digest",
        "cron_expression": "0 * * * *",
        "timezone": "UTC",
        "payload": {"message": "scheduled-health"},
        "queue": "@@EXTENSION_DOTTED@@.work",
        "site_identifier": "default",
        "enabled": true
      }],
      "projections": [{
        "identifier": "@@EXTENSION_DOTTED@@.item_projection",
        "version": 1,
        "handler_version": "1.0.0",
        "rebuildable": true,
        "sensitivity_ceiling": "internal",
        "sources": [{"event_type": "@@EXTENSION_DOTTED@@.item_observed", "schema_versions": [1]}],
        "fields": [
          {"name": "item_id", "type": "string", "nullable": false},
          {"name": "title", "type": "string", "nullable": false}
        ],
        "key_fields": ["item_id"],
        "rebuild_batch_size": 200
      }],
      "reports": [{
        "identifier": "@@EXTENSION_DOTTED@@.item_report",
        "version": 1,
        "title": "@@LABEL_JSON@@ items",
        "source_definition": "@@EXTENSION_DOTTED@@.item",
        "required_capability": "@@EXTENSION_DOTTED@@.access",
        "administrator_visible": true,
        "portal_visible": true,
        "parameters": [],
        "filters": [],
        "columns": [
          {"alias": "item_id", "label": "ID", "source": "id", "type": "string"},
          {"alias": "title", "label": "Title", "source": "title", "type": "string"}
        ],
        "groups": [],
        "aggregates": [],
        "formulas": [],
        "sorts": [],
        "drill_downs": [],
        "synchronous_row_cap": 100
      }],
      "webhooks": []
    }
  }
}
