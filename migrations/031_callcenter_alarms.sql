BEGIN;

CREATE TABLE IF NOT EXISTS callcenter_alarm_rules (
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    alarm_key VARCHAR(80) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    threshold_value NUMERIC(14,3),
    warning_value NUMERIC(14,3),
    cooldown_minutes INTEGER NOT NULL DEFAULT 60,
    recovery_notification BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (dept_id, alarm_key),
    CHECK (alarm_key ~ '^[a-z][a-z0-9_]{1,79}$'),
    CHECK (cooldown_minutes BETWEEN 1 AND 10080),
    CHECK (threshold_value IS NULL OR threshold_value >= 0),
    CHECK (warning_value IS NULL OR warning_value >= 0)
);
CREATE INDEX IF NOT EXISTS callcenter_alarm_rules_tenant_enabled_idx
    ON callcenter_alarm_rules (dept_id, enabled, alarm_key);

CREATE TABLE IF NOT EXISTS callcenter_alarm_smtp_settings (
    dept_id VARCHAR(64) PRIMARY KEY REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    smtp_host VARCHAR(255) NOT NULL DEFAULT '',
    smtp_port INTEGER NOT NULL DEFAULT 587,
    encryption VARCHAR(10) NOT NULL DEFAULT 'tls',
    username VARCHAR(255) NOT NULL DEFAULT '',
    password_encrypted TEXT NOT NULL DEFAULT '',
    from_email VARCHAR(254) NOT NULL DEFAULT '',
    from_name VARCHAR(160) NOT NULL DEFAULT 'Astera PBX',
    timeout_seconds INTEGER NOT NULL DEFAULT 15,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (smtp_port BETWEEN 1 AND 65535),
    CHECK (encryption IN ('none', 'tls', 'ssl')),
    CHECK (timeout_seconds BETWEEN 3 AND 120),
    CHECK (char_length(password_encrypted) <= 4096)
);
CREATE INDEX IF NOT EXISTS callcenter_alarm_smtp_tenant_enabled_idx
    ON callcenter_alarm_smtp_settings (dept_id, enabled);

CREATE TABLE IF NOT EXISTS callcenter_alarm_recipients (
    recipient_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    email VARCHAR(254) NOT NULL,
    display_name VARCHAR(160) NOT NULL DEFAULT '',
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (dept_id, email),
    CHECK (email = lower(btrim(email))),
    CHECK (email ~ '^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$')
);
CREATE INDEX IF NOT EXISTS callcenter_alarm_recipients_tenant_active_idx
    ON callcenter_alarm_recipients (dept_id, active, email);

CREATE TABLE IF NOT EXISTS callcenter_alarm_states (
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    alarm_key VARCHAR(80) NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'ok',
    current_value NUMERIC(18,3),
    summary VARCHAR(500) NOT NULL DEFAULT '',
    details TEXT NOT NULL DEFAULT '',
    first_triggered_at TIMESTAMPTZ,
    last_checked_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_changed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_notified_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (dept_id, alarm_key),
    FOREIGN KEY (dept_id, alarm_key) REFERENCES callcenter_alarm_rules(dept_id, alarm_key)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CHECK (status IN ('ok', 'warning', 'alarm', 'unknown'))
);
CREATE INDEX IF NOT EXISTS callcenter_alarm_states_tenant_status_idx
    ON callcenter_alarm_states (dept_id, status, last_changed_at DESC);

CREATE TABLE IF NOT EXISTS callcenter_alarm_events (
    event_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    alarm_key VARCHAR(80) NOT NULL,
    event_type VARCHAR(16) NOT NULL,
    severity VARCHAR(12) NOT NULL,
    measured_value NUMERIC(18,3),
    summary VARCHAR(500) NOT NULL,
    details TEXT NOT NULL DEFAULT '',
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    resolved_at TIMESTAMPTZ,
    FOREIGN KEY (dept_id, alarm_key) REFERENCES callcenter_alarm_rules(dept_id, alarm_key)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CHECK (event_type IN ('triggered', 'reminder', 'recovered', 'test')),
    CHECK (severity IN ('info', 'warning', 'critical'))
);
CREATE INDEX IF NOT EXISTS callcenter_alarm_events_tenant_time_idx
    ON callcenter_alarm_events (dept_id, occurred_at DESC, event_id DESC);
CREATE INDEX IF NOT EXISTS callcenter_alarm_events_tenant_alarm_idx
    ON callcenter_alarm_events (dept_id, alarm_key, occurred_at DESC);

CREATE TABLE IF NOT EXISTS callcenter_alarm_delivery_attempts (
    delivery_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    event_id BIGINT REFERENCES callcenter_alarm_events(event_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    recipient_email VARCHAR(254) NOT NULL,
    status VARCHAR(12) NOT NULL,
    error_message VARCHAR(1000) NOT NULL DEFAULT '',
    attempted_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (status IN ('sent', 'failed'))
);
CREATE INDEX IF NOT EXISTS callcenter_alarm_delivery_tenant_time_idx
    ON callcenter_alarm_delivery_attempts (dept_id, attempted_at DESC);
CREATE INDEX IF NOT EXISTS callcenter_alarm_delivery_event_idx
    ON callcenter_alarm_delivery_attempts (dept_id, event_id);

GRANT SELECT, INSERT, UPDATE, DELETE ON
    callcenter_alarm_rules,
    callcenter_alarm_smtp_settings,
    callcenter_alarm_recipients,
    callcenter_alarm_states,
    callcenter_alarm_events,
    callcenter_alarm_delivery_attempts
    TO asterisk, "astera-panel";
GRANT USAGE, SELECT ON SEQUENCE
    callcenter_alarm_recipients_recipient_id_seq,
    callcenter_alarm_events_event_id_seq,
    callcenter_alarm_delivery_attempts_delivery_id_seq
    TO asterisk, "astera-panel";

INSERT INTO astera_schema_migrations (version)
VALUES ('031_callcenter_alarms')
ON CONFLICT (version) DO NOTHING;

COMMIT;
