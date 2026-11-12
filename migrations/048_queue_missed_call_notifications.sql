BEGIN;

CREATE TABLE IF NOT EXISTS callcenter_queue_notification_state (
    dept_id VARCHAR(64) PRIMARY KEY REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    last_queue_log_id BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (last_queue_log_id >= 0)
);
CREATE INDEX IF NOT EXISTS callcenter_queue_notification_state_tenant_idx
    ON callcenter_queue_notification_state (dept_id, updated_at);

CREATE TABLE IF NOT EXISTS callcenter_queue_notification_deliveries (
    delivery_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    queue_log_id BIGINT NOT NULL,
    queue_id VARCHAR(128) NOT NULL,
    call_id VARCHAR(80) NOT NULL DEFAULT '',
    recipient_email VARCHAR(254) NOT NULL,
    caller_number VARCHAR(80) NOT NULL DEFAULT '',
    event_time TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    exit_position INTEGER,
    wait_seconds INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempt_count SMALLINT NOT NULL DEFAULT 0,
    error_message TEXT NOT NULL DEFAULT '',
    sent_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (dept_id, queue_log_id, recipient_email),
    CHECK (btrim(queue_id) <> ''),
    CHECK (recipient_email = lower(btrim(recipient_email))),
    CHECK (status IN ('pending', 'sent', 'failed')),
    CHECK (attempt_count BETWEEN 0 AND 10),
    CHECK (wait_seconds >= 0),
    CHECK (exit_position IS NULL OR exit_position >= 0)
);
CREATE INDEX IF NOT EXISTS callcenter_queue_notification_delivery_tenant_status_idx
    ON callcenter_queue_notification_deliveries (dept_id, status, updated_at, queue_log_id);
CREATE INDEX IF NOT EXISTS callcenter_queue_notification_delivery_tenant_time_idx
    ON callcenter_queue_notification_deliveries (dept_id, event_time DESC, delivery_id DESC);

GRANT SELECT, INSERT, UPDATE, DELETE ON
    callcenter_queue_notification_state,
    callcenter_queue_notification_deliveries
    TO "astera-panel";
GRANT USAGE, SELECT, UPDATE ON SEQUENCE callcenter_queue_notification_deliveries_delivery_id_seq
    TO "astera-panel";

INSERT INTO astera_schema_migrations (version)
VALUES ('048_queue_missed_call_notifications')
ON CONFLICT (version) DO NOTHING;

COMMIT;
