BEGIN;

CREATE TABLE IF NOT EXISTS autoprovision_devices (
    device_id VARCHAR(64) PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL
        REFERENCES astera_tenants(dept_id) ON UPDATE CASCADE ON DELETE CASCADE,
    mac_address CHAR(12) NOT NULL,
    vendor VARCHAR(20) NOT NULL
        CHECK (vendor IN ('yealink', 'grandstream', 'gigaset')),
    model VARCHAR(80) NOT NULL DEFAULT '',
    extension_id VARCHAR(128) NOT NULL,
    display_name VARCHAR(120) NOT NULL DEFAULT '',
    transport VARCHAR(8) NOT NULL DEFAULT 'udp'
        CHECK (transport IN ('udp', 'tcp', 'tls')),
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    last_generated_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_by VARCHAR(120) NOT NULL DEFAULT 'system',
    UNIQUE (mac_address),
    CHECK (mac_address ~ '^[0-9A-F]{12}$'),
    CHECK (btrim(extension_id) <> '')
);

CREATE INDEX IF NOT EXISTS autoprovision_devices_dept_idx
    ON autoprovision_devices (dept_id, vendor, mac_address);
CREATE INDEX IF NOT EXISTS autoprovision_devices_dept_extension_idx
    ON autoprovision_devices (dept_id, extension_id);

GRANT SELECT, INSERT, UPDATE, DELETE ON autoprovision_devices TO asterisk;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'astera-panel') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE
            ON autoprovision_devices TO "astera-panel";
    END IF;
END
$$;

INSERT INTO astera_schema_migrations (version)
VALUES ('027_autoprovision_devices')
ON CONFLICT (version) DO NOTHING;

COMMIT;
