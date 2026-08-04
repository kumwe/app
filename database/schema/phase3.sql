BEGIN;

CREATE SCHEMA IF NOT EXISTS {{schema}};

CREATE TABLE {{schema}}.users (
    id UUID PRIMARY KEY,
    email VARCHAR(254) NOT NULL,
    email_normalized VARCHAR(254) NOT NULL,
    display_name VARCHAR(191) NOT NULL,
    status VARCHAR(32) NOT NULL,
    version BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT kumwe_users_email_normalized_unique UNIQUE (email_normalized),
    CONSTRAINT kumwe_users_status_check CHECK (status IN ('pending', 'active', 'suspended', 'disabled')),
    CONSTRAINT kumwe_users_version_check CHECK (version >= 0),
    CONSTRAINT kumwe_users_email_normalized_check CHECK (email_normalized = lower(email_normalized))
);

CREATE TABLE {{schema}}.password_credentials (
    user_id UUID PRIMARY KEY REFERENCES {{schema}}.users (id) ON DELETE CASCADE,
    password_hash TEXT NOT NULL,
    changed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT kumwe_password_credentials_hash_check CHECK (length(password_hash) >= 20)
);

CREATE TABLE {{schema}}.roles (
    id UUID PRIMARY KEY,
    code VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(191) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT kumwe_roles_code_check CHECK (code ~ '^[a-z][a-z0-9._-]{1,63}$')
);

CREATE TABLE {{schema}}.user_roles (
    user_id UUID NOT NULL REFERENCES {{schema}}.users (id) ON DELETE CASCADE,
    role_id UUID NOT NULL REFERENCES {{schema}}.roles (id) ON DELETE CASCADE,
    assigned_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_by UUID REFERENCES {{schema}}.users (id) ON DELETE SET NULL,
    PRIMARY KEY (user_id, role_id)
);

CREATE TABLE {{schema}}.capabilities (
    code VARCHAR(191) PRIMARY KEY,
    description VARCHAR(500) NOT NULL DEFAULT '',
    CONSTRAINT kumwe_capabilities_code_check
        CHECK (code ~ '^[a-z][a-z0-9]*([._:-][a-z0-9]+)*$')
);

CREATE TABLE {{schema}}.role_capability_grants (
    id UUID PRIMARY KEY,
    role_id UUID NOT NULL REFERENCES {{schema}}.roles (id) ON DELETE CASCADE,
    capability_code VARCHAR(191) NOT NULL REFERENCES {{schema}}.capabilities (code) ON DELETE CASCADE,
    scope_type VARCHAR(63) NOT NULL,
    scope_identifier VARCHAR(191),
    granted_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    granted_by UUID REFERENCES {{schema}}.users (id) ON DELETE SET NULL,
    CONSTRAINT kumwe_role_grants_scope_type_check CHECK (scope_type ~ '^[a-z][a-z0-9._-]{0,62}$'),
    CONSTRAINT kumwe_role_grants_scope_shape_check CHECK (
        (scope_type = 'global' AND scope_identifier IS NULL)
        OR (scope_type <> 'global' AND scope_identifier IS NOT NULL AND length(scope_identifier) > 0)
    )
);

CREATE UNIQUE INDEX kumwe_role_grants_unique
    ON {{schema}}.role_capability_grants (
        role_id,
        capability_code,
        scope_type,
        COALESCE(scope_identifier, '')
    );

CREATE INDEX kumwe_role_grants_lookup
    ON {{schema}}.role_capability_grants (role_id, capability_code);

CREATE TABLE {{schema}}.audit_events (
    id UUID PRIMARY KEY,
    occurred_at TIMESTAMPTZ NOT NULL,
    actor_id VARCHAR(191),
    action VARCHAR(127) NOT NULL,
    subject_type VARCHAR(63) NOT NULL,
    subject_id VARCHAR(191),
    outcome VARCHAR(31) NOT NULL,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    CONSTRAINT kumwe_audit_action_check CHECK (action ~ '^[a-z][a-z0-9._:-]*$'),
    CONSTRAINT kumwe_audit_subject_type_check CHECK (subject_type ~ '^[a-z][a-z0-9._:-]*$'),
    CONSTRAINT kumwe_audit_outcome_check CHECK (outcome ~ '^[a-z][a-z0-9._:-]*$'),
    CONSTRAINT kumwe_audit_metadata_object_check CHECK (jsonb_typeof(metadata) = 'object')
);

CREATE INDEX kumwe_audit_events_occurred_at_idx ON {{schema}}.audit_events (occurred_at DESC);
CREATE INDEX kumwe_audit_events_actor_idx ON {{schema}}.audit_events (actor_id, occurred_at DESC);
CREATE INDEX kumwe_audit_events_subject_idx
    ON {{schema}}.audit_events (subject_type, subject_id, occurred_at DESC);

COMMIT;
