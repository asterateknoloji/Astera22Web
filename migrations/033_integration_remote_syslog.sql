BEGIN;

CREATE TABLE IF NOT EXISTS integration_syslog_settings (
    connector_id BIGINT PRIMARY KEY REFERENCES integration_connectors(connector_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    transport VARCHAR(8) NOT NULL DEFAULT 'udp',
    facility INTEGER NOT NULL DEFAULT 16,
    app_name VARCHAR(48) NOT NULL DEFAULT 'astera-pbx',
    export_system_alarms BOOLEAN NOT NULL DEFAULT TRUE,
    export_pbx_alarms BOOLEAN NOT NULL DEFAULT TRUE,
    export_audit_logs BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (transport IN ('udp', 'tcp', 'tls')),
    CHECK (facility BETWEEN 0 AND 23),
    CHECK (app_name ~ '^[A-Za-z0-9_.-]{1,48}$')
);

CREATE TABLE IF NOT EXISTS integration_syslog_cursors (
    connector_id BIGINT NOT NULL REFERENCES integration_connectors(connector_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    source_name VARCHAR(32) NOT NULL,
    last_id BIGINT NOT NULL DEFAULT 0,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (connector_id, source_name),
    CHECK (source_name IN ('alarm_events', 'audit_logs')),
    CHECK (last_id >= 0)
);
CREATE INDEX IF NOT EXISTS integration_syslog_cursors_updated_idx
    ON integration_syslog_cursors (updated_at, connector_id);

GRANT SELECT, INSERT, UPDATE, DELETE ON
    integration_syslog_settings,
    integration_syslog_cursors
    TO asterisk, "astera-panel";

INSERT INTO astera_schema_migrations (version)
VALUES ('033_integration_remote_syslog')
ON CONFLICT (version) DO NOTHING;

COMMIT;
