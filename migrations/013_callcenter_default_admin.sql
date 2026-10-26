BEGIN;

ALTER TABLE callcenter_supervisor_users
    ADD COLUMN IF NOT EXISTS must_change_password BOOLEAN NOT NULL DEFAULT TRUE;

CREATE OR REPLACE FUNCTION astera_create_default_callcenter_admin()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
BEGIN
    INSERT INTO callcenter_supervisor_users (
        dept_id, username, display_name, password_hash, role,
        active, can_choose_tenant, must_change_password
    ) VALUES (
        NEW.dept_id,
        'admin',
        'Firma Yöneticisi',
        '$2y$10$l8Eyg8.caa1UwD923CHAHeUH.E2fO5Er7jRah9hCvE4RPJb7ZoE86',
        'admin',
        TRUE,
        FALSE,
        TRUE
    )
    ON CONFLICT (dept_id, username) DO NOTHING;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS astera_default_callcenter_admin_trigger ON astera_tenants;
CREATE TRIGGER astera_default_callcenter_admin_trigger
AFTER INSERT ON astera_tenants
FOR EACH ROW EXECUTE FUNCTION astera_create_default_callcenter_admin();

INSERT INTO callcenter_supervisor_users (
    dept_id, username, display_name, password_hash, role,
    active, can_choose_tenant, must_change_password
)
SELECT
    tenant.dept_id,
    'admin',
    'Firma Yöneticisi',
    '$2y$10$l8Eyg8.caa1UwD923CHAHeUH.E2fO5Er7jRah9hCvE4RPJb7ZoE86',
    'admin',
    TRUE,
    FALSE,
    TRUE
FROM astera_tenants AS tenant
WHERE tenant.active
ON CONFLICT (dept_id, username) DO NOTHING;

GRANT SELECT ON cdr, queue_log TO "astera-panel";

INSERT INTO astera_schema_migrations (version)
VALUES ('013_callcenter_default_admin')
ON CONFLICT (version) DO NOTHING;

COMMIT;
