BEGIN;

ALTER TABLE system_backup_settings
    ADD COLUMN IF NOT EXISTS schedule_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS schedule_frequency VARCHAR(12) NOT NULL DEFAULT 'daily',
    ADD COLUMN IF NOT EXISTS schedule_time TIME NOT NULL DEFAULT '02:00',
    ADD COLUMN IF NOT EXISTS schedule_weekday SMALLINT NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS schedule_day SMALLINT NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS schedule_month SMALLINT NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS schedule_once_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS schedule_next_run TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS schedule_last_run TIMESTAMPTZ;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'system_backup_settings'::regclass
          AND conname = 'system_backup_settings_schedule_frequency_check'
    ) THEN
        ALTER TABLE system_backup_settings
            ADD CONSTRAINT system_backup_settings_schedule_frequency_check
            CHECK (schedule_frequency IN ('once', 'daily', 'weekly', 'monthly', 'yearly'));
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'system_backup_settings'::regclass
          AND conname = 'system_backup_settings_schedule_weekday_check'
    ) THEN
        ALTER TABLE system_backup_settings
            ADD CONSTRAINT system_backup_settings_schedule_weekday_check
            CHECK (schedule_weekday BETWEEN 1 AND 7);
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'system_backup_settings'::regclass
          AND conname = 'system_backup_settings_schedule_day_check'
    ) THEN
        ALTER TABLE system_backup_settings
            ADD CONSTRAINT system_backup_settings_schedule_day_check
            CHECK (schedule_day BETWEEN 1 AND 31);
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'system_backup_settings'::regclass
          AND conname = 'system_backup_settings_schedule_month_check'
    ) THEN
        ALTER TABLE system_backup_settings
            ADD CONSTRAINT system_backup_settings_schedule_month_check
            CHECK (schedule_month BETWEEN 1 AND 12);
    END IF;
END
$$;

INSERT INTO astera_schema_migrations (version)
VALUES ('040_system_backup_schedule')
ON CONFLICT (version) DO NOTHING;

COMMIT;
