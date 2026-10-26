BEGIN;

CREATE TABLE IF NOT EXISTS callcenter_settings (
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    name VARCHAR(120) NOT NULL,
    value TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (dept_id, name),
    CHECK (btrim(name) <> ''),
    CHECK (name ~ '^[A-Za-z0-9][A-Za-z0-9_.-]{0,119}$'),
    CHECK (char_length(value) <= 4000)
);
CREATE INDEX IF NOT EXISTS callcenter_settings_tenant_name_idx
    ON callcenter_settings (dept_id, name);

CREATE TABLE IF NOT EXISTS callcenter_queue_settings (
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    queue_id VARCHAR(128) NOT NULL,
    sla_seconds INTEGER NOT NULL DEFAULT 60,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (dept_id, queue_id),
    CHECK (btrim(queue_id) <> ''),
    CHECK (sla_seconds BETWEEN 1 AND 86400)
);
CREATE INDEX IF NOT EXISTS callcenter_queue_settings_tenant_queue_idx
    ON callcenter_queue_settings (dept_id, queue_id);

CREATE TABLE IF NOT EXISTS callcenter_queue_notification_recipients (
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    queue_id VARCHAR(128) NOT NULL,
    email VARCHAR(254) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (dept_id, queue_id, email),
    CHECK (btrim(queue_id) <> ''),
    CHECK (email = lower(btrim(email))),
    CHECK (email ~ '^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$')
);
CREATE INDEX IF NOT EXISTS callcenter_queue_notification_tenant_queue_idx
    ON callcenter_queue_notification_recipients (dept_id, queue_id, active, email);

GRANT SELECT, INSERT, UPDATE, DELETE ON
    callcenter_settings,
    callcenter_queue_settings,
    callcenter_queue_notification_recipients
    TO asterisk, "astera-panel";

INSERT INTO astera_schema_migrations (version)
VALUES ('018_callcenter_system_settings')
ON CONFLICT (version) DO NOTHING;

COMMIT;
