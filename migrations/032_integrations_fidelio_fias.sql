BEGIN;

CREATE TABLE IF NOT EXISTS integration_connectors (
    connector_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    integration_type VARCHAR(40) NOT NULL,
    provider VARCHAR(60) NOT NULL,
    name VARCHAR(160) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    connection_mode VARCHAR(12) NOT NULL DEFAULT 'client',
    bind_address INET NOT NULL DEFAULT '0.0.0.0',
    remote_host VARCHAR(255) NOT NULL DEFAULT '',
    port INTEGER NOT NULL DEFAULT 5001,
    reconnect_seconds INTEGER NOT NULL DEFAULT 10,
    connect_timeout_seconds INTEGER NOT NULL DEFAULT 10,
    connection_status VARCHAR(20) NOT NULL DEFAULT 'disabled',
    last_connected_at TIMESTAMPTZ,
    last_message_at TIMESTAMPTZ,
    last_error TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (dept_id, provider, name),
    CHECK (integration_type ~ '^[a-z][a-z0-9_]{1,39}$'),
    CHECK (provider ~ '^[a-z][a-z0-9_]{1,59}$'),
    CHECK (connection_mode IN ('client', 'server')),
    CHECK (port BETWEEN 1 AND 65535),
    CHECK (reconnect_seconds BETWEEN 1 AND 3600),
    CHECK (connect_timeout_seconds BETWEEN 1 AND 120),
    CHECK (connection_status IN ('disabled', 'waiting', 'connecting', 'connected', 'error'))
);
CREATE INDEX IF NOT EXISTS integration_connectors_tenant_type_idx
    ON integration_connectors (dept_id, integration_type, provider, connector_id);
CREATE INDEX IF NOT EXISTS integration_connectors_enabled_idx
    ON integration_connectors (enabled, provider, connector_id)
    WHERE enabled;

CREATE TABLE IF NOT EXISTS pms_fidelio_settings (
    connector_id BIGINT PRIMARY KEY REFERENCES integration_connectors(connector_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    fias_version VARCHAR(20) NOT NULL DEFAULT '2.20',
    hotel_code VARCHAR(80) NOT NULL DEFAULT '',
    charset VARCHAR(20) NOT NULL DEFAULT 'UTF-8',
    message_terminator VARCHAR(8) NOT NULL DEFAULT 'CRLF',
    keepalive_seconds INTEGER NOT NULL DEFAULT 60,
    auto_create_room_mapping BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (charset IN ('UTF-8', 'ISO-8859-9', 'WINDOWS-1254')),
    CHECK (message_terminator IN ('CRLF', 'LF', 'ETX')),
    CHECK (keepalive_seconds BETWEEN 10 AND 3600)
);

CREATE TABLE IF NOT EXISTS pms_room_mappings (
    mapping_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    connector_id BIGINT NOT NULL REFERENCES integration_connectors(connector_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    room_number VARCHAR(40) NOT NULL,
    extension_number VARCHAR(40) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    occupancy_status VARCHAR(20) NOT NULL DEFAULT 'vacant',
    room_status VARCHAR(40) NOT NULL DEFAULT '',
    guest_name VARCHAR(255) NOT NULL DEFAULT '',
    guest_language VARCHAR(20) NOT NULL DEFAULT '',
    checked_in_at TIMESTAMPTZ,
    checked_out_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (connector_id, room_number),
    UNIQUE (connector_id, extension_number),
    CHECK (occupancy_status IN ('vacant', 'occupied', 'unknown')),
    CHECK (room_number <> ''),
    CHECK (extension_number <> '')
);
CREATE INDEX IF NOT EXISTS pms_room_mappings_tenant_room_idx
    ON pms_room_mappings (dept_id, room_number, mapping_id);
CREATE INDEX IF NOT EXISTS pms_room_mappings_tenant_extension_idx
    ON pms_room_mappings (dept_id, extension_number, mapping_id);

CREATE TABLE IF NOT EXISTS pms_fias_events (
    event_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    connector_id BIGINT NOT NULL REFERENCES integration_connectors(connector_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    direction VARCHAR(12) NOT NULL,
    record_type VARCHAR(8) NOT NULL,
    room_number VARCHAR(40) NOT NULL DEFAULT '',
    guest_name VARCHAR(255) NOT NULL DEFAULT '',
    raw_message TEXT NOT NULL,
    processing_status VARCHAR(16) NOT NULL DEFAULT 'received',
    error_message TEXT NOT NULL DEFAULT '',
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (direction IN ('incoming', 'outgoing')),
    CHECK (processing_status IN ('received', 'processed', 'ignored', 'failed'))
);
CREATE INDEX IF NOT EXISTS pms_fias_events_tenant_time_idx
    ON pms_fias_events (dept_id, occurred_at DESC, event_id DESC);
CREATE INDEX IF NOT EXISTS pms_fias_events_connector_time_idx
    ON pms_fias_events (connector_id, occurred_at DESC, event_id DESC);
CREATE INDEX IF NOT EXISTS pms_fias_events_room_time_idx
    ON pms_fias_events (dept_id, room_number, occurred_at DESC, event_id DESC)
    WHERE room_number <> '';

GRANT SELECT, INSERT, UPDATE, DELETE ON
    integration_connectors,
    pms_fidelio_settings,
    pms_room_mappings,
    pms_fias_events
    TO asterisk, "astera-panel";
GRANT USAGE, SELECT ON SEQUENCE
    integration_connectors_connector_id_seq,
    pms_room_mappings_mapping_id_seq,
    pms_fias_events_event_id_seq
    TO asterisk, "astera-panel";

INSERT INTO astera_schema_migrations (version)
VALUES ('032_integrations_fidelio_fias')
ON CONFLICT (version) DO NOTHING;

COMMIT;
