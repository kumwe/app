BEGIN;

CREATE SCHEMA IF NOT EXISTS {{schema}};

CREATE TABLE {{schema}}.templates (
    id uuid PRIMARY KEY,
    handle varchar(100) NOT NULL UNIQUE CHECK (handle ~ '^[a-z][a-z0-9._-]*$'),
    name varchar(255) NOT NULL CHECK (btrim(name) <> ''),
    context varchar(16) NOT NULL CHECK (context IN ('site', 'administrator')),
    manifest jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(manifest) = 'object'),
    version integer NOT NULL DEFAULT 1 CHECK (version >= 1),
    enabled boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL,
    UNIQUE (id, context)
);

CREATE TABLE {{schema}}.template_slots (
    template_id uuid NOT NULL REFERENCES {{schema}}.templates (id) ON DELETE CASCADE,
    slot_name varchar(100) NOT NULL CHECK (slot_name ~ '^[a-z][a-z0-9_-]*$'),
    PRIMARY KEY (template_id, slot_name)
);

CREATE TABLE {{schema}}.template_assignments (
    id uuid PRIMARY KEY,
    template_id uuid NOT NULL,
    context varchar(16) NOT NULL CHECK (context IN ('site', 'administrator')),
    priority integer NOT NULL DEFAULT 0,
    conditions jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(conditions) = 'array'),
    is_default boolean NOT NULL DEFAULT false,
    enabled boolean NOT NULL DEFAULT true,
    version integer NOT NULL DEFAULT 1 CHECK (version >= 1),
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL,
    CONSTRAINT kumwe_template_assignments_template_context_fk
        FOREIGN KEY (template_id, context)
        REFERENCES {{schema}}.templates (id, context)
        ON DELETE CASCADE,
    CONSTRAINT kumwe_template_assignments_default_unconditional CHECK (
        NOT is_default OR conditions = '[]'::jsonb
    )
);

CREATE UNIQUE INDEX kumwe_template_assignments_one_default
    ON {{schema}}.template_assignments (context)
    WHERE is_default AND enabled;

CREATE INDEX kumwe_template_assignments_resolution
    ON {{schema}}.template_assignments (enabled, priority DESC, template_id, id);

CREATE TABLE {{schema}}.template_overrides (
    id uuid PRIMARY KEY,
    template_id uuid NOT NULL REFERENCES {{schema}}.templates (id) ON DELETE CASCADE,
    logical_view varchar(190) NOT NULL CHECK (logical_view ~ '^[a-z][a-z0-9._-]*$'),
    relative_path varchar(1024) NOT NULL,
    created_at timestamptz NOT NULL,
    UNIQUE (template_id, logical_view),
    CONSTRAINT kumwe_template_overrides_relative_path CHECK (
        relative_path ~ '^[A-Za-z0-9_./-]+[.]twig$'
        AND relative_path !~ '(^|/)[.][.](/|$)'
        AND relative_path !~ '(^|/)[.](/|$)'
        AND relative_path NOT LIKE '%//%'
        AND relative_path !~ '^/'
        AND relative_path !~ E'\\\\'
    )
);

CREATE TABLE {{schema}}.module_definitions (
    id uuid PRIMARY KEY,
    handle varchar(100) NOT NULL UNIQUE CHECK (handle ~ '^[a-z][a-z0-9._-]*$'),
    name varchar(255) NOT NULL CHECK (btrim(name) <> ''),
    settings_schema jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(settings_schema) = 'object'),
    version integer NOT NULL DEFAULT 1 CHECK (version >= 1),
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
);

CREATE TABLE {{schema}}.module_instances (
    id uuid PRIMARY KEY,
    module_definition_id uuid NOT NULL REFERENCES {{schema}}.module_definitions (id) ON DELETE RESTRICT,
    title varchar(255) NOT NULL CHECK (btrim(title) <> ''),
    settings jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(settings) = 'object'),
    publish_at timestamptz,
    unpublish_at timestamptz,
    enabled boolean NOT NULL DEFAULT true,
    version integer NOT NULL DEFAULT 1 CHECK (version >= 1),
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL,
    CONSTRAINT kumwe_module_instances_publication_window CHECK (
        publish_at IS NULL OR unpublish_at IS NULL OR publish_at < unpublish_at
    )
);

CREATE TABLE {{schema}}.module_assignments (
    id uuid PRIMARY KEY,
    module_instance_id uuid NOT NULL REFERENCES {{schema}}.module_instances (id) ON DELETE CASCADE,
    template_id uuid NOT NULL,
    slot_name varchar(100) NOT NULL,
    position integer NOT NULL DEFAULT 0 CHECK (position >= 0),
    conditions jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(conditions) = 'array'),
    enabled boolean NOT NULL DEFAULT true,
    version integer NOT NULL DEFAULT 1 CHECK (version >= 1),
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL,
    CONSTRAINT kumwe_module_assignments_declared_slot_fk
        FOREIGN KEY (template_id, slot_name)
        REFERENCES {{schema}}.template_slots (template_id, slot_name)
        ON DELETE RESTRICT,
    UNIQUE (module_instance_id, template_id, slot_name)
);

CREATE INDEX kumwe_module_assignments_render_order
    ON {{schema}}.module_assignments (template_id, slot_name, enabled, position, module_instance_id, id);

CREATE TABLE {{schema}}.block_documents (
    id uuid PRIMARY KEY,
    content_entry_id uuid NOT NULL UNIQUE REFERENCES {{schema}}.content_entries (id) ON DELETE RESTRICT,
    schema_version integer NOT NULL CHECK (schema_version >= 1),
    document jsonb NOT NULL CHECK (jsonb_typeof(document) = 'array'),
    checksum char(64) NOT NULL CHECK (checksum ~ '^[0-9a-f]{64}$'),
    version integer NOT NULL DEFAULT 1 CHECK (version >= 1),
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
);

CREATE INDEX kumwe_block_documents_document_gin
    ON {{schema}}.block_documents USING gin (document);

COMMIT;
