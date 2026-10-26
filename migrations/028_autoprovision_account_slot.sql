BEGIN;

ALTER TABLE autoprovision_devices
    ADD COLUMN IF NOT EXISTS account_slot SMALLINT NOT NULL DEFAULT 1;

ALTER TABLE autoprovision_devices
    DROP CONSTRAINT IF EXISTS autoprovision_devices_account_slot_check;

ALTER TABLE autoprovision_devices
    ADD CONSTRAINT autoprovision_devices_account_slot_check
    CHECK (account_slot BETWEEN 1 AND 16);

INSERT INTO astera_schema_migrations (version)
VALUES ('028_autoprovision_account_slot')
ON CONFLICT (version) DO NOTHING;

COMMIT;
