BEGIN;

CREATE TABLE IF NOT EXISTS ami_managers (
    manager_name VARCHAR(80) PRIMARY KEY,
    secret VARCHAR(255) NOT NULL,
    writetimeout INTEGER NOT NULL DEFAULT 5000 CHECK (writetimeout BETWEEN 100 AND 60000),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_by VARCHAR(160) NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS ami_manager_acl (
    manager_name VARCHAR(80) NOT NULL REFERENCES ami_managers(manager_name) ON DELETE CASCADE,
    position SMALLINT NOT NULL CHECK (position >= 0),
    action VARCHAR(8) NOT NULL CHECK (action IN ('deny', 'permit')),
    network CIDR NOT NULL,
    PRIMARY KEY (manager_name, position)
);

CREATE INDEX IF NOT EXISTS ami_manager_acl_manager_idx
    ON ami_manager_acl (manager_name, position);

CREATE TABLE IF NOT EXISTS ami_manager_permissions (
    manager_name VARCHAR(80) NOT NULL REFERENCES ami_managers(manager_name) ON DELETE CASCADE,
    permission_name VARCHAR(32) NOT NULL,
    can_read BOOLEAN NOT NULL DEFAULT FALSE,
    can_write BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (manager_name, permission_name),
    CHECK (can_read OR can_write)
);

CREATE INDEX IF NOT EXISTS ami_manager_permissions_manager_idx
    ON ami_manager_permissions (manager_name, permission_name);

GRANT SELECT, INSERT, UPDATE, DELETE
    ON ami_managers, ami_manager_acl, ami_manager_permissions
    TO "astera-panel";

INSERT INTO astera_schema_migrations (version)
VALUES ('042_ami_managers')
ON CONFLICT (version) DO NOTHING;

COMMIT;
