BEGIN;

CREATE TABLE IF NOT EXISTS license_installations (
    installation_id BIGSERIAL PRIMARY KEY,
    fingerprint CHAR(64) NOT NULL UNIQUE,
    machine_id_hash CHAR(64) NOT NULL,
    root_uuid_hash CHAR(64) NOT NULL,
    pbx_identity_hash CHAR(64) NOT NULL,
    first_seen_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (fingerprint ~ '^[0-9a-f]{64}$'),
    CHECK (machine_id_hash ~ '^[0-9a-f]{64}$'),
    CHECK (root_uuid_hash ~ '^[0-9a-f]{64}$'),
    CHECK (pbx_identity_hash ~ '^[0-9a-f]{64}$')
);
CREATE INDEX IF NOT EXISTS license_installations_seen_idx
    ON license_installations (last_seen_at DESC, installation_id);

CREATE TABLE IF NOT EXISTS license_signed_documents (
    document_id BIGSERIAL PRIMARY KEY,
    installation_id BIGINT NOT NULL REFERENCES license_installations(installation_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    license_id VARCHAR(128) NOT NULL,
    payload_bytes BYTEA NOT NULL,
    signature_bytes BYTEA NOT NULL,
    payload_sha256 CHAR(64) NOT NULL,
    signing_key_sha256 CHAR(64) NOT NULL,
    issued_at TIMESTAMPTZ NOT NULL,
    not_before TIMESTAMPTZ,
    expires_at TIMESTAMPTZ,
    grace_until TIMESTAMPTZ,
    is_active BOOLEAN NOT NULL DEFAULT FALSE,
    imported_by VARCHAR(120) NOT NULL DEFAULT 'system',
    imported_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (installation_id, payload_sha256),
    CHECK (btrim(license_id) <> ''),
    CHECK (payload_sha256 ~ '^[0-9a-f]{64}$'),
    CHECK (signing_key_sha256 ~ '^[0-9a-f]{64}$'),
    CHECK (octet_length(payload_bytes) BETWEEN 2 AND 1048576),
    CHECK (octet_length(signature_bytes) = 64),
    CHECK (not_before IS NULL OR expires_at IS NULL OR not_before <= expires_at),
    CHECK (expires_at IS NULL OR grace_until IS NULL OR expires_at <= grace_until)
);
CREATE UNIQUE INDEX IF NOT EXISTS license_signed_documents_active_uq
    ON license_signed_documents (installation_id) WHERE is_active;
CREATE INDEX IF NOT EXISTS license_signed_documents_installation_time_idx
    ON license_signed_documents (installation_id, imported_at DESC, document_id DESC);
CREATE INDEX IF NOT EXISTS license_signed_documents_expiry_idx
    ON license_signed_documents (expires_at, grace_until) WHERE is_active;

CREATE TABLE IF NOT EXISTS license_entitlements (
    entitlement_id BIGSERIAL PRIMARY KEY,
    document_id BIGINT NOT NULL REFERENCES license_signed_documents(document_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    scope VARCHAR(8) NOT NULL,
    dept_id VARCHAR(64) REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    feature_code VARCHAR(80) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    limit_value BIGINT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (scope IN ('global', 'tenant')),
    CHECK ((scope = 'global' AND dept_id IS NULL) OR (scope = 'tenant' AND dept_id IS NOT NULL)),
    CHECK (feature_code IN (
        'subscribers.extensions',
        'concurrent.external_calls',
        'callcenter.queues',
        'callcenter.agents',
        'callcenter.supervisors',
        'callcenter.wallboard',
        'callcenter.monitor',
        'recording.enabled',
        'ivr.enabled',
        'integrations.enabled',
        'ai.enabled',
        'enterprise.multitenant'
    )),
    CHECK (limit_value IS NULL OR limit_value >= 0)
);
CREATE UNIQUE INDEX IF NOT EXISTS license_entitlements_scope_feature_uq
    ON license_entitlements (document_id, scope, COALESCE(dept_id, ''), feature_code);
CREATE INDEX IF NOT EXISTS license_entitlements_global_idx
    ON license_entitlements (document_id, feature_code) WHERE scope = 'global';
CREATE INDEX IF NOT EXISTS license_entitlements_tenant_idx
    ON license_entitlements (dept_id, document_id, feature_code) WHERE scope = 'tenant';

CREATE TABLE IF NOT EXISTS license_audit_events (
    audit_id BIGSERIAL PRIMARY KEY,
    installation_id BIGINT NOT NULL REFERENCES license_installations(installation_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    document_id BIGINT REFERENCES license_signed_documents(document_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    scope VARCHAR(8) NOT NULL DEFAULT 'global',
    dept_id VARCHAR(64) REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    event_type VARCHAR(48) NOT NULL,
    actor VARCHAR(120) NOT NULL DEFAULT 'system',
    detail TEXT NOT NULL DEFAULT '',
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (scope IN ('global', 'tenant')),
    CHECK ((scope = 'global' AND dept_id IS NULL) OR (scope = 'tenant' AND dept_id IS NOT NULL)),
    CHECK (btrim(event_type) <> '')
);
CREATE INDEX IF NOT EXISTS license_audit_events_installation_time_idx
    ON license_audit_events (installation_id, occurred_at DESC, audit_id DESC);
CREATE INDEX IF NOT EXISTS license_audit_events_tenant_time_idx
    ON license_audit_events (dept_id, occurred_at DESC, audit_id DESC) WHERE scope = 'tenant';

CREATE TABLE IF NOT EXISTS license_usage_snapshots (
    snapshot_id BIGSERIAL PRIMARY KEY,
    installation_id BIGINT NOT NULL REFERENCES license_installations(installation_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    document_id BIGINT REFERENCES license_signed_documents(document_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    scope VARCHAR(8) NOT NULL,
    dept_id VARCHAR(64) REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    feature_code VARCHAR(80) NOT NULL,
    usage_value BIGINT NOT NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'panel',
    captured_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (scope IN ('global', 'tenant')),
    CHECK ((scope = 'global' AND dept_id IS NULL) OR (scope = 'tenant' AND dept_id IS NOT NULL)),
    CHECK (feature_code IN (
        'subscribers.extensions',
        'concurrent.external_calls',
        'callcenter.queues',
        'callcenter.agents',
        'callcenter.supervisors',
        'callcenter.wallboard',
        'callcenter.monitor',
        'recording.enabled',
        'ivr.enabled',
        'integrations.enabled',
        'ai.enabled',
        'enterprise.multitenant'
    )),
    CHECK (usage_value >= 0),
    CHECK (btrim(source) <> '')
);
CREATE INDEX IF NOT EXISTS license_usage_snapshots_installation_feature_time_idx
    ON license_usage_snapshots (installation_id, feature_code, captured_at DESC, snapshot_id DESC);
CREATE INDEX IF NOT EXISTS license_usage_snapshots_tenant_feature_time_idx
    ON license_usage_snapshots (dept_id, feature_code, captured_at DESC, snapshot_id DESC)
    WHERE scope = 'tenant';

GRANT SELECT, INSERT, UPDATE, DELETE ON
    license_installations,
    license_signed_documents,
    license_entitlements,
    license_audit_events,
    license_usage_snapshots
    TO "astera-panel";
GRANT USAGE, SELECT, UPDATE ON SEQUENCE
    license_installations_installation_id_seq,
    license_signed_documents_document_id_seq,
    license_entitlements_entitlement_id_seq,
    license_audit_events_audit_id_seq,
    license_usage_snapshots_snapshot_id_seq
    TO "astera-panel";

INSERT INTO astera_schema_migrations (version)
VALUES ('052_licensing')
ON CONFLICT (version) DO NOTHING;

COMMIT;
