BEGIN;

CREATE TABLE IF NOT EXISTS callcenter_audit_logs (
    audit_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL
        REFERENCES astera_tenants(dept_id) ON UPDATE CASCADE ON DELETE CASCADE,
    supervisor_id BIGINT,
    username VARCHAR(120) NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    category VARCHAR(32) NOT NULL,
    page VARCHAR(160) NOT NULL,
    action VARCHAR(160) NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    request_method VARCHAR(12) NOT NULL DEFAULT 'GET',
    request_path VARCHAR(1000) NOT NULL DEFAULT '',
    ip_address INET,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    FOREIGN KEY (supervisor_id, dept_id)
        REFERENCES callcenter_supervisor_users(supervisor_id, dept_id)
        ON UPDATE CASCADE ON DELETE SET NULL (supervisor_id),
    CHECK (btrim(username) <> ''),
    CHECK (event_type IN (
        'login', 'logout', 'page_view', 'create', 'update',
        'delete', 'access_denied', 'export'
    )),
    CHECK (category IN ('callcenter', 'cdr', 'system', 'auth')),
    CHECK (btrim(page) <> ''),
    CHECK (btrim(action) <> '')
);

CREATE INDEX IF NOT EXISTS callcenter_audit_logs_tenant_time_idx
    ON callcenter_audit_logs (dept_id, created_at DESC, audit_id DESC);
CREATE INDEX IF NOT EXISTS callcenter_audit_logs_tenant_id_idx
    ON callcenter_audit_logs (dept_id, audit_id DESC);
CREATE INDEX IF NOT EXISTS callcenter_audit_logs_tenant_user_time_idx
    ON callcenter_audit_logs (dept_id, username, created_at DESC, audit_id DESC);
CREATE INDEX IF NOT EXISTS callcenter_audit_logs_tenant_page_time_idx
    ON callcenter_audit_logs (dept_id, category, page, created_at DESC, audit_id DESC);
GRANT SELECT, INSERT ON callcenter_audit_logs TO asterisk, "astera-panel";
GRANT USAGE, SELECT, UPDATE ON SEQUENCE callcenter_audit_logs_audit_id_seq
    TO asterisk, "astera-panel";

INSERT INTO astera_schema_migrations (version)
VALUES ('015_callcenter_audit_logs')
ON CONFLICT (version) DO NOTHING;

COMMIT;
