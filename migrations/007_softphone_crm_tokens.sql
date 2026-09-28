BEGIN;

CREATE TABLE IF NOT EXISTS softphone_crm_tokens (
    token_id VARCHAR(64) PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL
        REFERENCES astera_tenants(dept_id) ON UPDATE CASCADE ON DELETE CASCADE,
    token_hash CHAR(64) NOT NULL UNIQUE,
    token_prefix VARCHAR(12) NOT NULL,
    name VARCHAR(120) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    expires_at TIMESTAMPTZ,
    last_used_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS softphone_crm_tokens_dept_active_idx
    ON softphone_crm_tokens (dept_id, active, created_at DESC);

INSERT INTO astera_schema_migrations (version)
VALUES ('007_softphone_crm_tokens')
ON CONFLICT (version) DO NOTHING;

COMMIT;
