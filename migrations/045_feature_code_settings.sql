BEGIN;

CREATE TABLE IF NOT EXISTS feature_code_settings (
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    feature_key VARCHAR(64) NOT NULL,
    code VARCHAR(16) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (dept_id, feature_key),
    CHECK (feature_key ~ '^[a-z][a-z0-9_]{1,63}$'),
    CHECK (code ~ '^[*#0-9]{1,16}$')
);

CREATE UNIQUE INDEX IF NOT EXISTS feature_code_settings_dept_code_enabled_uidx
    ON feature_code_settings (dept_id, code)
    WHERE enabled;

CREATE INDEX IF NOT EXISTS feature_code_settings_dept_idx
    ON feature_code_settings (dept_id, feature_key);

GRANT SELECT, INSERT, UPDATE, DELETE ON feature_code_settings
    TO asterisk, "astera-panel";

INSERT INTO astera_schema_migrations (version)
VALUES ('045_feature_code_settings')
ON CONFLICT (version) DO NOTHING;

COMMIT;
