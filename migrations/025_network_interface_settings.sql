BEGIN;

CREATE TABLE IF NOT EXISTS network_interface_settings (
    interface_name VARCHAR(31) PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL
        REFERENCES astera_tenants(dept_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    method VARCHAR(8) NOT NULL CHECK (method IN ('dhcp', 'manual')),
    ipv4_cidr INET,
    gateway INET,
    dns_servers INET[] NOT NULL DEFAULT ARRAY[]::INET[],
    mtu INTEGER NOT NULL DEFAULT 1500 CHECK (mtu BETWEEN 576 AND 9000),
    connection_uuid VARCHAR(64) NOT NULL DEFAULT '',
    rollback_unit VARCHAR(96) NOT NULL DEFAULT '',
    backup_connection VARCHAR(96) NOT NULL DEFAULT '',
    confirmed BOOLEAN NOT NULL DEFAULT TRUE,
    applied_at TIMESTAMPTZ,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_by VARCHAR(120) NOT NULL DEFAULT 'system',
    CHECK (method = 'dhcp' OR ipv4_cidr IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS network_interface_settings_dept_idx
    ON network_interface_settings (dept_id, interface_name);

GRANT SELECT, INSERT, UPDATE, DELETE ON network_interface_settings TO asterisk;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'astera-panel') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE
            ON network_interface_settings TO "astera-panel";
    END IF;
END
$$;

INSERT INTO astera_schema_migrations (version)
VALUES ('025_network_interface_settings')
ON CONFLICT (version) DO NOTHING;

COMMIT;
