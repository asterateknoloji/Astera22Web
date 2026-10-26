BEGIN;

CREATE TABLE IF NOT EXISTS feature_takeover_settings (
    dept_id VARCHAR(64) PRIMARY KEY REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    activation_prefix VARCHAR(8) NOT NULL DEFAULT '*88',
    cancel_code VARCHAR(8) NOT NULL DEFAULT '*89',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (activation_prefix ~ '^\*[0-9]{2,6}$'),
    CHECK (cancel_code ~ '^\*[0-9]{2,6}$'),
    CHECK (activation_prefix <> cancel_code)
);

CREATE INDEX IF NOT EXISTS feature_takeover_settings_dept_enabled_idx
    ON feature_takeover_settings (dept_id, enabled);

GRANT SELECT, INSERT, UPDATE, DELETE ON feature_takeover_settings
    TO asterisk, "astera-panel";

INSERT INTO astera_schema_migrations (version)
VALUES ('041_feature_takeover_settings')
ON CONFLICT (version) DO NOTHING;

COMMIT;
