BEGIN;

ALTER TABLE network_interface_settings
    ADD COLUMN IF NOT EXISTS display_name VARCHAR(80) NOT NULL DEFAULT '';

INSERT INTO astera_schema_migrations (version)
VALUES ('044_network_interface_display_name')
ON CONFLICT (version) DO NOTHING;

COMMIT;
