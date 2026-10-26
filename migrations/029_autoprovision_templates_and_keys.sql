BEGIN;

ALTER TABLE autoprovision_devices
    ADD COLUMN IF NOT EXISTS template_id VARCHAR(64) NOT NULL DEFAULT '';

CREATE TABLE IF NOT EXISTS autoprovision_templates (
    template_id VARCHAR(64) PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL
        REFERENCES astera_tenants(dept_id) ON UPDATE CASCADE ON DELETE CASCADE,
    name VARCHAR(120) NOT NULL,
    vendor VARCHAR(20) NOT NULL
        CHECK (vendor IN ('yealink', 'grandstream', 'gigaset')),
    model VARCHAR(80) NOT NULL DEFAULT '',
    account_count SMALLINT NOT NULL DEFAULT 1
        CHECK (account_count BETWEEN 1 AND 32),
    key_count SMALLINT NOT NULL DEFAULT 0
        CHECK (key_count BETWEEN 0 AND 120),
    config_body TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_by VARCHAR(120) NOT NULL DEFAULT 'system',
    UNIQUE (dept_id, name)
);

CREATE INDEX IF NOT EXISTS autoprovision_templates_dept_idx
    ON autoprovision_templates (dept_id, vendor, name);

CREATE TABLE IF NOT EXISTS autoprovision_device_keys (
    device_key_id VARCHAR(128) PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL
        REFERENCES astera_tenants(dept_id) ON UPDATE CASCADE ON DELETE CASCADE,
    device_id VARCHAR(64) NOT NULL
        REFERENCES autoprovision_devices(device_id) ON UPDATE CASCADE ON DELETE CASCADE,
    key_index SMALLINT NOT NULL CHECK (key_index BETWEEN 1 AND 120),
    key_type VARCHAR(20) NOT NULL DEFAULT 'none'
        CHECK (key_type IN ('none', 'line', 'blf', 'speed_dial', 'transfer', 'park', 'dtmf', 'url')),
    label VARCHAR(80) NOT NULL DEFAULT '',
    key_value VARCHAR(160) NOT NULL DEFAULT '',
    extension VARCHAR(80) NOT NULL DEFAULT '',
    account_slot SMALLINT NOT NULL DEFAULT 1 CHECK (account_slot BETWEEN 1 AND 32),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_by VARCHAR(120) NOT NULL DEFAULT 'system',
    UNIQUE (device_id, key_index)
);

CREATE INDEX IF NOT EXISTS autoprovision_device_keys_dept_device_idx
    ON autoprovision_device_keys (dept_id, device_id, key_index);

GRANT SELECT, INSERT, UPDATE, DELETE
    ON autoprovision_templates, autoprovision_device_keys TO asterisk;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'astera-panel') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE
            ON autoprovision_templates, autoprovision_device_keys TO "astera-panel";
    END IF;
END
$$;

INSERT INTO astera_schema_migrations (version)
VALUES ('029_autoprovision_templates_and_keys')
ON CONFLICT (version) DO NOTHING;

COMMIT;
