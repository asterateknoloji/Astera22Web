BEGIN;

CREATE TABLE IF NOT EXISTS autoprovision_settings (
    dept_id VARCHAR(64) PRIMARY KEY
        REFERENCES astera_tenants(dept_id) ON UPDATE CASCADE ON DELETE CASCADE,
    schedule_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    schedule_frequency VARCHAR(12) NOT NULL DEFAULT 'daily'
        CHECK (schedule_frequency IN ('daily', 'weekly')),
    schedule_weekday SMALLINT NOT NULL DEFAULT 0
        CHECK (schedule_weekday BETWEEN 0 AND 6),
    schedule_time TIME NOT NULL DEFAULT '03:00',
    window_minutes SMALLINT NOT NULL DEFAULT 60
        CHECK (window_minutes BETWEEN 15 AND 360),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_by VARCHAR(120) NOT NULL DEFAULT 'system'
);

CREATE INDEX IF NOT EXISTS autoprovision_settings_dept_schedule_idx
    ON autoprovision_settings (dept_id, schedule_enabled, schedule_frequency);

GRANT SELECT, INSERT, UPDATE, DELETE ON autoprovision_settings TO asterisk;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'astera-panel') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE
            ON autoprovision_settings TO "astera-panel";
    END IF;
END
$$;

INSERT INTO astera_schema_migrations (version)
VALUES ('030_autoprovision_schedule')
ON CONFLICT (version) DO NOTHING;

COMMIT;
