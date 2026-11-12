BEGIN;

CREATE TABLE IF NOT EXISTS system_ntp_settings (
    setting_id SMALLINT PRIMARY KEY CHECK (setting_id = 1),
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    ntp_servers TEXT[] NOT NULL DEFAULT ARRAY[
        'tr.pool.ntp.org',
        'pool.ntp.org',
        'time.cloudflare.com'
    ]::TEXT[],
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_by VARCHAR(120) NOT NULL DEFAULT 'system',
    CHECK (cardinality(ntp_servers) <= 8)
);

INSERT INTO system_ntp_settings (setting_id)
VALUES (1)
ON CONFLICT (setting_id) DO NOTHING;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'asterisk') THEN
        GRANT SELECT, INSERT, UPDATE ON system_ntp_settings TO asterisk;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'astera-panel') THEN
        GRANT SELECT, INSERT, UPDATE ON system_ntp_settings TO "astera-panel";
    END IF;
END
$$;

INSERT INTO astera_schema_migrations (version)
VALUES ('039_system_ntp_settings')
ON CONFLICT (version) DO NOTHING;

COMMIT;
