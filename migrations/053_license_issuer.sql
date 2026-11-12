BEGIN;

CREATE TABLE IF NOT EXISTS license_issuer_customers (
    customer_id BIGSERIAL PRIMARY KEY,
    customer_code VARCHAR(48) NOT NULL UNIQUE,
    legal_name VARCHAR(200) NOT NULL,
    contact_name VARCHAR(160),
    contact_email VARCHAR(254),
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (customer_code ~ '^[A-Z0-9][A-Z0-9._-]{1,47}$'),
    CHECK (btrim(legal_name) <> ''),
    CHECK (contact_email IS NULL OR contact_email ~ '^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$')
);
CREATE INDEX IF NOT EXISTS license_issuer_customers_name_idx
    ON license_issuer_customers (lower(legal_name), customer_id);

CREATE TABLE IF NOT EXISTS license_issuer_installations (
    installation_id BIGSERIAL PRIMARY KEY,
    customer_id BIGINT NOT NULL REFERENCES license_issuer_customers(customer_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    pbx_name VARCHAR(160) NOT NULL,
    fingerprint CHAR(64) NOT NULL UNIQUE,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    first_registered_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_issued_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (btrim(pbx_name) <> ''),
    CHECK (fingerprint ~ '^[0-9a-f]{64}$')
);
CREATE INDEX IF NOT EXISTS license_issuer_installations_customer_idx
    ON license_issuer_installations (customer_id, active, pbx_name, installation_id);

CREATE TABLE IF NOT EXISTS license_issuer_documents (
    document_id BIGSERIAL PRIMARY KEY,
    installation_id BIGINT NOT NULL REFERENCES license_issuer_installations(installation_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    license_id VARCHAR(128) NOT NULL UNIQUE,
    previous_document_id BIGINT REFERENCES license_issuer_documents(document_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    payload_bytes BYTEA NOT NULL,
    signature_bytes BYTEA NOT NULL,
    payload_sha256 CHAR(64) NOT NULL UNIQUE,
    signing_key_sha256 CHAR(64) NOT NULL,
    issued_at TIMESTAMPTZ NOT NULL,
    not_before TIMESTAMPTZ,
    expires_at TIMESTAMPTZ,
    grace_until TIMESTAMPTZ,
    status VARCHAR(16) NOT NULL DEFAULT 'issued',
    issued_by VARCHAR(120) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (btrim(license_id) <> ''),
    CHECK (payload_sha256 ~ '^[0-9a-f]{64}$'),
    CHECK (signing_key_sha256 ~ '^[0-9a-f]{64}$'),
    CHECK (octet_length(payload_bytes) BETWEEN 2 AND 1048576),
    CHECK (octet_length(signature_bytes) = 64),
    CHECK (status IN ('issued', 'renewed', 'revoked')),
    CHECK (not_before IS NULL OR expires_at IS NULL OR not_before <= expires_at),
    CHECK (expires_at IS NULL OR grace_until IS NULL OR expires_at <= grace_until)
);
CREATE INDEX IF NOT EXISTS license_issuer_documents_installation_time_idx
    ON license_issuer_documents (installation_id, issued_at DESC, document_id DESC);
CREATE INDEX IF NOT EXISTS license_issuer_documents_status_expiry_idx
    ON license_issuer_documents (status, expires_at, document_id);

CREATE TABLE IF NOT EXISTS license_issuer_entitlements (
    entitlement_id BIGSERIAL PRIMARY KEY,
    document_id BIGINT NOT NULL REFERENCES license_issuer_documents(document_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    feature_code VARCHAR(80) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    limit_value BIGINT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (document_id, feature_code),
    CHECK (feature_code IN (
        'subscribers.extensions', 'concurrent.external_calls', 'callcenter.queues',
        'callcenter.agents', 'callcenter.supervisors', 'callcenter.wallboard',
        'callcenter.monitor', 'recording.enabled', 'ivr.enabled',
        'integrations.enabled', 'ai.enabled', 'enterprise.multitenant'
    )),
    CHECK (limit_value IS NULL OR limit_value >= 0)
);
CREATE INDEX IF NOT EXISTS license_issuer_entitlements_feature_idx
    ON license_issuer_entitlements (feature_code, enabled, document_id);

CREATE TABLE IF NOT EXISTS license_issuer_revocations (
    revocation_id BIGSERIAL PRIMARY KEY,
    document_id BIGINT NOT NULL UNIQUE REFERENCES license_issuer_documents(document_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    reason VARCHAR(500) NOT NULL,
    revoked_by VARCHAR(120) NOT NULL,
    revoked_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (btrim(reason) <> '')
);
CREATE INDEX IF NOT EXISTS license_issuer_revocations_time_idx
    ON license_issuer_revocations (revoked_at DESC, revocation_id DESC);

CREATE TABLE IF NOT EXISTS license_issuer_audit_events (
    audit_id BIGSERIAL PRIMARY KEY,
    customer_id BIGINT REFERENCES license_issuer_customers(customer_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    installation_id BIGINT REFERENCES license_issuer_installations(installation_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    document_id BIGINT REFERENCES license_issuer_documents(document_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    event_type VARCHAR(48) NOT NULL,
    actor VARCHAR(120) NOT NULL,
    detail TEXT NOT NULL DEFAULT '',
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (btrim(event_type) <> '')
);
CREATE INDEX IF NOT EXISTS license_issuer_audit_customer_time_idx
    ON license_issuer_audit_events (customer_id, occurred_at DESC, audit_id DESC);
CREATE INDEX IF NOT EXISTS license_issuer_audit_document_time_idx
    ON license_issuer_audit_events (document_id, occurred_at DESC, audit_id DESC)
    WHERE document_id IS NOT NULL;

GRANT SELECT, INSERT, UPDATE, DELETE ON
    license_issuer_customers, license_issuer_installations, license_issuer_documents,
    license_issuer_entitlements, license_issuer_revocations, license_issuer_audit_events
    TO "astera-panel";
GRANT USAGE, SELECT, UPDATE ON SEQUENCE
    license_issuer_customers_customer_id_seq,
    license_issuer_installations_installation_id_seq,
    license_issuer_documents_document_id_seq,
    license_issuer_entitlements_entitlement_id_seq,
    license_issuer_revocations_revocation_id_seq,
    license_issuer_audit_events_audit_id_seq
    TO "astera-panel";

INSERT INTO astera_schema_migrations (version)
VALUES ('053_license_issuer')
ON CONFLICT (version) DO NOTHING;

COMMIT;
