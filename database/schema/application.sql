BEGIN;

CREATE SCHEMA IF NOT EXISTS {{schema}};

-- Core editorial model installed with every Kumwe site.
INSERT INTO {{schema}}.workflows (id, handle, name, version, created_at, updated_at)
VALUES (
    '018f22e2-7c8b-7ab0-8f3a-88e8026bb401',
    'editorial',
    'Editorial workflow',
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
)
ON CONFLICT (id) DO NOTHING;

INSERT INTO {{schema}}.workflow_states (workflow_id, state_key, name, is_initial, is_public)
VALUES
    ('018f22e2-7c8b-7ab0-8f3a-88e8026bb401', 'draft', 'Draft', true, false),
    ('018f22e2-7c8b-7ab0-8f3a-88e8026bb401', 'review', 'In review', false, false),
    ('018f22e2-7c8b-7ab0-8f3a-88e8026bb401', 'published', 'Published', false, true),
    ('018f22e2-7c8b-7ab0-8f3a-88e8026bb401', 'archived', 'Archived', false, false)
ON CONFLICT (workflow_id, state_key) DO NOTHING;

INSERT INTO {{schema}}.workflow_transitions (workflow_id, from_state, to_state, required_capability)
VALUES
    ('018f22e2-7c8b-7ab0-8f3a-88e8026bb401', 'draft', 'review', 'content.update'),
    ('018f22e2-7c8b-7ab0-8f3a-88e8026bb401', 'draft', 'archived', 'content.delete'),
    ('018f22e2-7c8b-7ab0-8f3a-88e8026bb401', 'review', 'draft', 'content.update'),
    ('018f22e2-7c8b-7ab0-8f3a-88e8026bb401', 'review', 'published', 'content.publish'),
    ('018f22e2-7c8b-7ab0-8f3a-88e8026bb401', 'review', 'archived', 'content.delete'),
    ('018f22e2-7c8b-7ab0-8f3a-88e8026bb401', 'published', 'draft', 'content.update'),
    ('018f22e2-7c8b-7ab0-8f3a-88e8026bb401', 'published', 'archived', 'content.delete'),
    ('018f22e2-7c8b-7ab0-8f3a-88e8026bb401', 'archived', 'draft', 'content.update')
ON CONFLICT (workflow_id, from_state, to_state) DO NOTHING;

INSERT INTO {{schema}}.content_types (
    id,
    workflow_id,
    handle,
    name,
    field_schema,
    version,
    created_at,
    updated_at
)
VALUES (
    '018f22e2-7c8b-7ab0-8f3a-88e8026bb402',
    '018f22e2-7c8b-7ab0-8f3a-88e8026bb401',
    'page',
    'Page',
    '{"type":"object","properties":{"body":{"type":"string"}}}'::jsonb,
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
)
ON CONFLICT (id) DO NOTHING;

INSERT INTO {{schema}}.capabilities (code, description)
VALUES
    ('administrator.access', 'Access the Kumwe administrator application.'),
    ('automation.manage', 'Manage scheduled tasks and queues.'),
    ('content.create', 'Create content entries.'),
    ('content.delete', 'Trash and restore content entries.'),
    ('content.publish', 'Publish, unpublish and archive content.'),
    ('content.read', 'Read non-public content.'),
    ('content.update', 'Edit content and presentation data.'),
    ('extensions.manage', 'Install, activate, disable and remove extensions.'),
    ('settings.manage', 'Manage site and runtime settings.'),
    ('users.manage', 'Manage users, roles and access tokens.')
ON CONFLICT (code) DO UPDATE SET description = EXCLUDED.description;

CREATE TABLE {{schema}}.administrator_sessions (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL REFERENCES {{schema}}.users (id) ON DELETE CASCADE,
    token_digest char(64) NOT NULL UNIQUE CHECK (token_digest ~ '^[a-f0-9]{64}$'),
    csrf_token varchar(128) NOT NULL CHECK (csrf_token ~ '^[A-Za-z0-9_-]{43,128}$'),
    ip_digest char(64) CHECK (ip_digest IS NULL OR ip_digest ~ '^[a-f0-9]{64}$'),
    user_agent_digest char(64) CHECK (user_agent_digest IS NULL OR user_agent_digest ~ '^[a-f0-9]{64}$'),
    created_at timestamptz NOT NULL,
    last_seen_at timestamptz NOT NULL,
    expires_at timestamptz NOT NULL,
    CONSTRAINT administrator_sessions_expiry CHECK (expires_at > created_at),
    CONSTRAINT administrator_sessions_seen CHECK (last_seen_at >= created_at)
);

CREATE INDEX administrator_sessions_user ON {{schema}}.administrator_sessions (user_id, created_at DESC);
CREATE INDEX administrator_sessions_expiry ON {{schema}}.administrator_sessions (expires_at);

CREATE TABLE {{schema}}.authentication_attempts (
    id uuid PRIMARY KEY,
    subject_digest char(64) NOT NULL CHECK (subject_digest ~ '^[a-f0-9]{64}$'),
    source_digest char(64) NOT NULL CHECK (source_digest ~ '^[a-f0-9]{64}$'),
    succeeded boolean NOT NULL,
    occurred_at timestamptz NOT NULL
);

CREATE INDEX authentication_attempts_rate_limit
    ON {{schema}}.authentication_attempts (subject_digest, source_digest, occurred_at DESC);

CREATE TABLE {{schema}}.password_reset_tokens (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL REFERENCES {{schema}}.users (id) ON DELETE CASCADE,
    token_digest char(64) NOT NULL UNIQUE CHECK (token_digest ~ '^[a-f0-9]{64}$'),
    created_at timestamptz NOT NULL,
    expires_at timestamptz NOT NULL,
    consumed_at timestamptz,
    CONSTRAINT password_reset_tokens_expiry CHECK (expires_at > created_at),
    CONSTRAINT password_reset_tokens_consumed CHECK (consumed_at IS NULL OR consumed_at >= created_at)
);

CREATE TABLE {{schema}}.email_verification_tokens (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL REFERENCES {{schema}}.users (id) ON DELETE CASCADE,
    token_digest char(64) NOT NULL UNIQUE CHECK (token_digest ~ '^[a-f0-9]{64}$'),
    created_at timestamptz NOT NULL,
    expires_at timestamptz NOT NULL,
    consumed_at timestamptz,
    CONSTRAINT email_verification_tokens_expiry CHECK (expires_at > created_at),
    CONSTRAINT email_verification_tokens_consumed CHECK (consumed_at IS NULL OR consumed_at >= created_at)
);

CREATE TABLE {{schema}}.mfa_credentials (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL REFERENCES {{schema}}.users (id) ON DELETE CASCADE,
    credential_type varchar(32) NOT NULL CHECK (credential_type IN ('totp', 'webauthn')),
    label varchar(191) NOT NULL CHECK (btrim(label) <> ''),
    secret_ciphertext text NOT NULL,
    configuration jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(configuration) = 'object'),
    enabled boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL,
    verified_at timestamptz,
    last_used_at timestamptz
);

CREATE TABLE {{schema}}.mfa_recovery_codes (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL REFERENCES {{schema}}.users (id) ON DELETE CASCADE,
    code_digest char(64) NOT NULL CHECK (code_digest ~ '^[a-f0-9]{64}$'),
    created_at timestamptz NOT NULL,
    consumed_at timestamptz,
    UNIQUE (user_id, code_digest)
);

CREATE TABLE {{schema}}.taxonomies (
    id uuid PRIMARY KEY,
    handle varchar(100) NOT NULL UNIQUE CHECK (handle ~ '^[a-z][a-z0-9_]*$'),
    name varchar(255) NOT NULL CHECK (btrim(name) <> ''),
    hierarchical boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
);

CREATE TABLE {{schema}}.taxonomy_terms (
    id uuid PRIMARY KEY,
    taxonomy_id uuid NOT NULL REFERENCES {{schema}}.taxonomies (id) ON DELETE CASCADE,
    parent_id uuid,
    name varchar(255) NOT NULL CHECK (btrim(name) <> ''),
    slug varchar(160) NOT NULL CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL,
    UNIQUE (taxonomy_id, id),
    UNIQUE (taxonomy_id, slug),
    CONSTRAINT taxonomy_terms_parent_fk
        FOREIGN KEY (taxonomy_id, parent_id)
        REFERENCES {{schema}}.taxonomy_terms (taxonomy_id, id)
        ON DELETE RESTRICT
);

CREATE TABLE {{schema}}.content_entry_terms (
    content_entry_id uuid NOT NULL REFERENCES {{schema}}.content_entries (id) ON DELETE CASCADE,
    term_id uuid NOT NULL REFERENCES {{schema}}.taxonomy_terms (id) ON DELETE CASCADE,
    PRIMARY KEY (content_entry_id, term_id)
);

CREATE TABLE {{schema}}.media_assets (
    id uuid PRIMARY KEY,
    storage_key varchar(1024) NOT NULL UNIQUE,
    original_name varchar(255) NOT NULL,
    media_type varchar(255) NOT NULL,
    byte_size bigint NOT NULL CHECK (byte_size >= 0),
    sha256 char(64) NOT NULL CHECK (sha256 ~ '^[a-f0-9]{64}$'),
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(metadata) = 'object'),
    created_by uuid REFERENCES {{schema}}.users (id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL,
    deleted_at timestamptz
);

CREATE INDEX media_assets_digest ON {{schema}}.media_assets (sha256);

CREATE TABLE {{schema}}.search_documents (
    content_entry_id uuid PRIMARY KEY REFERENCES {{schema}}.content_entries (id) ON DELETE CASCADE,
    locale varchar(35) NOT NULL DEFAULT 'en',
    title text NOT NULL,
    body text NOT NULL,
    search_vector tsvector GENERATED ALWAYS AS (
        setweight(to_tsvector('simple', coalesce(title, '')), 'A')
        || setweight(to_tsvector('simple', coalesce(body, '')), 'B')
    ) STORED,
    indexed_version integer NOT NULL CHECK (indexed_version >= 1),
    indexed_at timestamptz NOT NULL
);

CREATE INDEX search_documents_vector ON {{schema}}.search_documents USING gin (search_vector);

CREATE TABLE {{schema}}.site_settings (
    setting_key varchar(191) PRIMARY KEY CHECK (setting_key ~ '^[a-z][a-z0-9._-]*$'),
    setting_value jsonb NOT NULL,
    version integer NOT NULL DEFAULT 1 CHECK (version >= 1),
    updated_by uuid REFERENCES {{schema}}.users (id) ON DELETE SET NULL,
    updated_at timestamptz NOT NULL
);

CREATE TABLE {{schema}}.extension_runtime_generation (
    singleton boolean PRIMARY KEY DEFAULT true CHECK (singleton),
    generation bigint NOT NULL DEFAULT 0 CHECK (generation >= 0),
    rebuilt_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE {{schema}}.extensions
    ADD COLUMN runtime_path varchar(1024),
    ADD CONSTRAINT extensions_runtime_path_check CHECK (
        runtime_path IS NULL
        OR (
            runtime_path ~ '^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*/[0-9A-Za-z.+-]+$'
            AND runtime_path NOT LIKE '%..%'
        )
    );

ALTER TABLE {{schema}}.idempotency
    ADD COLUMN result_headers jsonb NOT NULL DEFAULT '{}'::jsonb
        CHECK (jsonb_typeof(result_headers) = 'object');

INSERT INTO {{schema}}.extension_runtime_generation (singleton, generation)
VALUES (true, 0)
ON CONFLICT (singleton) DO NOTHING;

INSERT INTO {{schema}}.schedules (
    id,
    name,
    cron_expression,
    timezone,
    queue,
    job_type,
    job_schema_version,
    payload,
    priority,
    maximum_attempts,
    enabled,
    next_run_at,
    version,
    created_at,
    updated_at
) VALUES (
    '00000000-0000-7000-8000-000000000801',
    'Purge expired administrator sessions',
    '*/15 * * * *',
    'UTC',
    'default',
    'system.sessions.purge',
    1,
    '{}'::jsonb,
    0,
    5,
    true,
    date_trunc('minute', CURRENT_TIMESTAMP) + interval '15 minutes',
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
) ON CONFLICT (id) DO NOTHING;

COMMIT;
