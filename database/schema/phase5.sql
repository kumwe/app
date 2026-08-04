BEGIN;

CREATE SCHEMA IF NOT EXISTS {{schema}};

CREATE TABLE {{schema}}.extensions (
    id UUID PRIMARY KEY,
    identifier VARCHAR(127) NOT NULL UNIQUE,
    extension_type VARCHAR(32) NOT NULL,
    installed_version VARCHAR(128) NOT NULL,
    status VARCHAR(32) NOT NULL,
    service_provider VARCHAR(255) NOT NULL,
    registry_version BIGINT NOT NULL DEFAULT 0,
    installed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT kumwe_extensions_identifier_check
        CHECK (identifier ~ '^[a-z0-9][a-z0-9._-]{0,62}/[a-z0-9][a-z0-9._-]{0,62}$'),
    CONSTRAINT kumwe_extensions_type_check
        CHECK (extension_type IN ('plugin', 'module', 'template', 'component', 'package', 'language')),
    CONSTRAINT kumwe_extensions_status_check CHECK (status IN ('disabled', 'active', 'failed')),
    CONSTRAINT kumwe_extensions_registry_version_check CHECK (registry_version >= 0)
);

CREATE TABLE {{schema}}.extension_releases (
    id UUID PRIMARY KEY,
    extension_id UUID NOT NULL REFERENCES {{schema}}.extensions (id) ON DELETE CASCADE,
    version VARCHAR(128) NOT NULL,
    manifest JSONB NOT NULL,
    package_sha256 CHAR(64) NOT NULL,
    signature_algorithm VARCHAR(32),
    signing_key_id VARCHAR(127),
    signature_base64 VARCHAR(256),
    released_at TIMESTAMPTZ NOT NULL,
    installed_at TIMESTAMPTZ,
    CONSTRAINT kumwe_extension_releases_unique UNIQUE (extension_id, version),
    CONSTRAINT kumwe_extension_releases_manifest_check CHECK (jsonb_typeof(manifest) = 'object'),
    CONSTRAINT kumwe_extension_releases_checksum_check CHECK (package_sha256 ~ '^[0-9a-f]{64}$'),
    CONSTRAINT kumwe_extension_releases_signature_check CHECK (
        (signature_algorithm IS NULL AND signing_key_id IS NULL AND signature_base64 IS NULL)
        OR (
            signature_algorithm = 'ed25519'
            AND signing_key_id IS NOT NULL
            AND signature_base64 IS NOT NULL
        )
    )
);

CREATE TABLE {{schema}}.extension_dependencies (
    release_id UUID NOT NULL REFERENCES {{schema}}.extension_releases (id) ON DELETE CASCADE,
    required_identifier VARCHAR(127) NOT NULL,
    version_constraint VARCHAR(255) NOT NULL,
    optional BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (release_id, required_identifier),
    CONSTRAINT kumwe_extension_dependencies_identifier_check
        CHECK (required_identifier ~ '^[a-z0-9][a-z0-9._-]{0,62}/[a-z0-9][a-z0-9._-]{0,62}$'),
    CONSTRAINT kumwe_extension_dependencies_constraint_check CHECK (length(version_constraint) > 0)
);

CREATE TABLE {{schema}}.extension_trust_keys (
    key_id VARCHAR(127) PRIMARY KEY,
    algorithm VARCHAR(32) NOT NULL,
    public_key_base64 VARCHAR(128) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    added_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMPTZ,
    CONSTRAINT kumwe_extension_trust_keys_id_check CHECK (key_id ~ '^[a-z0-9][a-z0-9._:-]{2,126}$'),
    CONSTRAINT kumwe_extension_trust_keys_algorithm_check CHECK (algorithm = 'ed25519'),
    CONSTRAINT kumwe_extension_trust_keys_revocation_check CHECK (revoked_at IS NULL OR enabled = FALSE)
);

CREATE TABLE {{schema}}.extension_install_audit (
    id UUID PRIMARY KEY,
    plan_id UUID NOT NULL,
    extension_identifier VARCHAR(127) NOT NULL,
    target_version VARCHAR(128) NOT NULL,
    package_sha256 CHAR(64) NOT NULL,
    state VARCHAR(32) NOT NULL,
    completed_actions JSONB NOT NULL DEFAULT '[]'::jsonb,
    failure_code VARCHAR(127),
    actor_id VARCHAR(191),
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    details JSONB NOT NULL DEFAULT '{}'::jsonb,
    CONSTRAINT kumwe_extension_install_audit_identifier_check
        CHECK (extension_identifier ~ '^[a-z0-9][a-z0-9._-]{0,62}/[a-z0-9][a-z0-9._-]{0,62}$'),
    CONSTRAINT kumwe_extension_install_audit_checksum_check CHECK (package_sha256 ~ '^[0-9a-f]{64}$'),
    CONSTRAINT kumwe_extension_install_audit_state_check CHECK (
        state IN ('planned', 'executing', 'failed', 'rolling_back', 'rolled_back', 'committed')
    ),
    CONSTRAINT kumwe_extension_install_audit_actions_check CHECK (jsonb_typeof(completed_actions) = 'array'),
    CONSTRAINT kumwe_extension_install_audit_details_check CHECK (jsonb_typeof(details) = 'object')
);

CREATE INDEX kumwe_extensions_type_status_idx ON {{schema}}.extensions (extension_type, status);
CREATE INDEX kumwe_extension_releases_lookup_idx
    ON {{schema}}.extension_releases (extension_id, released_at DESC);
CREATE INDEX kumwe_extension_dependencies_lookup_idx
    ON {{schema}}.extension_dependencies (required_identifier, optional);
CREATE INDEX kumwe_extension_install_audit_plan_idx
    ON {{schema}}.extension_install_audit (plan_id, occurred_at);
CREATE INDEX kumwe_extension_install_audit_extension_idx
    ON {{schema}}.extension_install_audit (extension_identifier, occurred_at DESC);

COMMIT;
