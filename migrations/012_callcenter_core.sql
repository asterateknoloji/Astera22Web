BEGIN;

CREATE TABLE IF NOT EXISTS callcenter_agents (
    agent_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    username VARCHAR(120) NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    extension VARCHAR(32) NOT NULL,
    password_hash TEXT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active'
        CHECK (status IN ('active', 'inactive', 'locked')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (agent_id, dept_id),
    UNIQUE (dept_id, username),
    UNIQUE (dept_id, extension),
    CHECK (btrim(username) <> '' AND btrim(extension) <> ''),
    CHECK (password_hash LIKE '$%')
);
CREATE INDEX IF NOT EXISTS callcenter_agents_dept_status_idx
    ON callcenter_agents (dept_id, status, username);

CREATE TABLE IF NOT EXISTS callcenter_agent_queue_assignments (
    assignment_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    agent_id BIGINT NOT NULL,
    queue_id VARCHAR(128) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    FOREIGN KEY (agent_id, dept_id) REFERENCES callcenter_agents(agent_id, dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE (dept_id, agent_id, queue_id),
    CHECK (btrim(queue_id) <> '')
);
CREATE INDEX IF NOT EXISTS callcenter_agent_queues_dept_queue_idx
    ON callcenter_agent_queue_assignments (dept_id, queue_id, agent_id) WHERE active;

CREATE TABLE IF NOT EXISTS callcenter_break_types (
    break_type_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    code VARCHAR(48) NOT NULL,
    name VARCHAR(120) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    max_duration_seconds INTEGER CHECK (max_duration_seconds IS NULL OR max_duration_seconds > 0),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (break_type_id, dept_id),
    UNIQUE (dept_id, code),
    CHECK (btrim(code) <> '' AND btrim(name) <> '')
);
CREATE INDEX IF NOT EXISTS callcenter_break_types_dept_active_idx
    ON callcenter_break_types (dept_id, active, name);

CREATE TABLE IF NOT EXISTS callcenter_agent_sessions (
    session_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    agent_id BIGINT NOT NULL,
    started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    ended_at TIMESTAMPTZ,
    end_reason VARCHAR(32),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    FOREIGN KEY (agent_id, dept_id) REFERENCES callcenter_agents(agent_id, dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE (session_id, dept_id),
    CHECK (ended_at IS NULL OR ended_at >= started_at),
    CHECK (end_reason IS NULL OR end_reason IN ('logout', 'timeout', 'admin', 'relogin'))
);
CREATE INDEX IF NOT EXISTS callcenter_agent_sessions_dept_agent_time_idx
    ON callcenter_agent_sessions (dept_id, agent_id, started_at DESC);
CREATE UNIQUE INDEX IF NOT EXISTS callcenter_agent_sessions_one_active_uq
    ON callcenter_agent_sessions (dept_id, agent_id) WHERE ended_at IS NULL;

CREATE TABLE IF NOT EXISTS callcenter_breaks (
    break_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    agent_id BIGINT NOT NULL,
    break_type_id BIGINT NOT NULL,
    session_id BIGINT,
    started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    ended_at TIMESTAMPTZ,
    note VARCHAR(240),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    FOREIGN KEY (agent_id, dept_id) REFERENCES callcenter_agents(agent_id, dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (break_type_id, dept_id) REFERENCES callcenter_break_types(break_type_id, dept_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    FOREIGN KEY (session_id, dept_id) REFERENCES callcenter_agent_sessions(session_id, dept_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CHECK (ended_at IS NULL OR ended_at >= started_at)
);
CREATE INDEX IF NOT EXISTS callcenter_breaks_dept_agent_time_idx
    ON callcenter_breaks (dept_id, agent_id, started_at DESC);
CREATE INDEX IF NOT EXISTS callcenter_breaks_dept_active_idx
    ON callcenter_breaks (dept_id, started_at DESC) WHERE ended_at IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS callcenter_breaks_one_active_uq
    ON callcenter_breaks (dept_id, agent_id) WHERE ended_at IS NULL;

CREATE TABLE IF NOT EXISTS callcenter_supervisor_users (
    supervisor_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    username VARCHAR(120) NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    password_hash TEXT NOT NULL,
    role VARCHAR(16) NOT NULL CHECK (role IN ('admin', 'supervisor', 'viewer')),
    active BOOLEAN NOT NULL DEFAULT TRUE,
    can_choose_tenant BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (supervisor_id, dept_id),
    UNIQUE (dept_id, username),
    CHECK (btrim(username) <> ''),
    CHECK (password_hash LIKE '$%'),
    CHECK (NOT can_choose_tenant OR role = 'admin')
);
CREATE INDEX IF NOT EXISTS callcenter_supervisors_dept_active_idx
    ON callcenter_supervisor_users (dept_id, active, username);

CREATE TABLE IF NOT EXISTS callcenter_supervisor_queue_scopes (
    scope_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    supervisor_id BIGINT NOT NULL,
    queue_id VARCHAR(128) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    FOREIGN KEY (supervisor_id, dept_id)
        REFERENCES callcenter_supervisor_users(supervisor_id, dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE (dept_id, supervisor_id, queue_id),
    CHECK (btrim(queue_id) <> '')
);
CREATE INDEX IF NOT EXISTS callcenter_supervisor_scopes_dept_queue_idx
    ON callcenter_supervisor_queue_scopes (dept_id, queue_id, supervisor_id);

CREATE TABLE IF NOT EXISTS callcenter_supervisor_tenant_scopes (
    scope_id BIGSERIAL PRIMARY KEY,
    owner_dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    supervisor_id BIGINT NOT NULL,
    allowed_dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    FOREIGN KEY (supervisor_id, owner_dept_id)
        REFERENCES callcenter_supervisor_users(supervisor_id, dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE (owner_dept_id, supervisor_id, allowed_dept_id)
);
CREATE INDEX IF NOT EXISTS callcenter_supervisor_tenant_scopes_owner_idx
    ON callcenter_supervisor_tenant_scopes
    (owner_dept_id, supervisor_id, allowed_dept_id);

GRANT SELECT, INSERT, UPDATE, DELETE ON
    callcenter_agents, callcenter_agent_queue_assignments, callcenter_break_types,
    callcenter_breaks, callcenter_agent_sessions, callcenter_supervisor_users,
    callcenter_supervisor_queue_scopes, callcenter_supervisor_tenant_scopes TO asterisk;
GRANT SELECT, INSERT, UPDATE, DELETE ON
    callcenter_agents, callcenter_agent_queue_assignments, callcenter_break_types,
    callcenter_breaks, callcenter_agent_sessions, callcenter_supervisor_users,
    callcenter_supervisor_queue_scopes, callcenter_supervisor_tenant_scopes TO "astera-panel";
GRANT USAGE, SELECT, UPDATE ON SEQUENCE
    callcenter_agents_agent_id_seq,
    callcenter_agent_queue_assignments_assignment_id_seq,
    callcenter_break_types_break_type_id_seq,
    callcenter_breaks_break_id_seq,
    callcenter_agent_sessions_session_id_seq,
    callcenter_supervisor_users_supervisor_id_seq,
    callcenter_supervisor_queue_scopes_scope_id_seq,
    callcenter_supervisor_tenant_scopes_scope_id_seq TO asterisk, "astera-panel";

INSERT INTO astera_schema_migrations (version)
VALUES ('012_callcenter_core')
ON CONFLICT (version) DO NOTHING;

COMMIT;
