BEGIN;

CREATE TABLE IF NOT EXISTS network_static_routes (
    route_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL
        REFERENCES astera_tenants(dept_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    interface_name VARCHAR(31) NOT NULL,
    destination CIDR NOT NULL,
    gateway INET NOT NULL,
    metric INTEGER NOT NULL DEFAULT 100 CHECK (metric BETWEEN 0 AND 65535),
    description VARCHAR(160) NOT NULL DEFAULT '',
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_by VARCHAR(120) NOT NULL DEFAULT 'system',
    CHECK (interface_name ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,30}$'),
    CHECK (family(destination) = 4),
    CHECK (family(gateway) = 4),
    UNIQUE (destination, gateway, interface_name, metric)
);

CREATE INDEX IF NOT EXISTS network_static_routes_dept_idx
    ON network_static_routes (dept_id, destination, route_id);
CREATE INDEX IF NOT EXISTS network_static_routes_enabled_idx
    ON network_static_routes (enabled, interface_name, destination);

GRANT SELECT, INSERT, UPDATE, DELETE ON network_static_routes TO asterisk;
GRANT USAGE, SELECT ON SEQUENCE network_static_routes_route_id_seq TO asterisk;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'astera-panel') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE
            ON network_static_routes TO "astera-panel";
        GRANT USAGE, SELECT
            ON SEQUENCE network_static_routes_route_id_seq TO "astera-panel";
    END IF;
END
$$;

INSERT INTO astera_schema_migrations (version)
VALUES ('035_network_static_routes')
ON CONFLICT (version) DO NOTHING;

COMMIT;
