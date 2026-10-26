BEGIN;

CREATE TABLE IF NOT EXISTS system_backup_settings (
    setting_id SMALLINT PRIMARY KEY DEFAULT 1,
    company_name VARCHAR(120) NOT NULL DEFAULT 'Astera',
    local_path VARCHAR(500) NOT NULL DEFAULT '/var/backups/astera',
    include_recordings BOOLEAN NOT NULL DEFAULT TRUE,
    retention_count INTEGER NOT NULL DEFAULT 10,
    remote_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    remote_host VARCHAR(255) NOT NULL DEFAULT '',
    remote_port INTEGER NOT NULL DEFAULT 22,
    remote_username VARCHAR(120) NOT NULL DEFAULT '',
    remote_path VARCHAR(500) NOT NULL DEFAULT '',
    remote_auth_mode VARCHAR(12) NOT NULL DEFAULT 'password',
    remote_password_encrypted TEXT NOT NULL DEFAULT '',
    remote_key_path VARCHAR(500) NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (setting_id = 1),
    CHECK (retention_count BETWEEN 1 AND 100),
    CHECK (remote_port BETWEEN 1 AND 65535),
    CHECK (remote_auth_mode IN ('password', 'key'))
);
ALTER TABLE system_backup_settings
    ADD COLUMN IF NOT EXISTS company_name VARCHAR(120) NOT NULL DEFAULT 'Astera';
INSERT INTO system_backup_settings (setting_id) VALUES (1)
ON CONFLICT (setting_id) DO NOTHING;

CREATE TABLE IF NOT EXISTS system_backup_archives (
    backup_id VARCHAR(40) PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    local_path VARCHAR(1000) NOT NULL,
    remote_path VARCHAR(1000) NOT NULL DEFAULT '',
    storage_status VARCHAR(16) NOT NULL DEFAULT 'local',
    size_bytes BIGINT NOT NULL DEFAULT 0,
    sha256_checksum CHAR(64) NOT NULL DEFAULT '',
    includes_recordings BOOLEAN NOT NULL DEFAULT FALSE,
    manifest_version INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    completed_at TIMESTAMPTZ,
    CHECK (storage_status IN ('local', 'remote', 'both', 'missing')),
    CHECK (size_bytes >= 0)
);
CREATE INDEX IF NOT EXISTS system_backup_archives_time_idx
    ON system_backup_archives (created_at DESC, backup_id DESC);

CREATE TABLE IF NOT EXISTS system_backup_jobs (
    job_id BIGSERIAL PRIMARY KEY,
    action VARCHAR(16) NOT NULL,
    backup_id VARCHAR(40) REFERENCES system_backup_archives(backup_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'queued',
    requested_by VARCHAR(120) NOT NULL,
    output_message TEXT NOT NULL DEFAULT '',
    error_message TEXT NOT NULL DEFAULT '',
    requested_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    started_at TIMESTAMPTZ,
    completed_at TIMESTAMPTZ,
    CHECK (action IN ('backup', 'restore', 'delete', 'remote_upload')),
    CHECK (status IN ('queued', 'running', 'completed', 'failed')),
    CHECK (btrim(requested_by) <> '')
);
CREATE INDEX IF NOT EXISTS system_backup_jobs_status_idx
    ON system_backup_jobs (status, requested_at, job_id);
CREATE INDEX IF NOT EXISTS system_backup_jobs_time_idx
    ON system_backup_jobs (requested_at DESC, job_id DESC);

GRANT SELECT, INSERT, UPDATE, DELETE ON
    system_backup_settings,
    system_backup_archives,
    system_backup_jobs
    TO asterisk, "astera-panel";
GRANT USAGE, SELECT ON SEQUENCE system_backup_jobs_job_id_seq
    TO asterisk, "astera-panel";

INSERT INTO astera_schema_migrations (version)
VALUES ('034_system_backups')
ON CONFLICT (version) DO NOTHING;

COMMIT;
