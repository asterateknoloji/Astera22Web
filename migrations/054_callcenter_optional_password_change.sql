BEGIN;

ALTER TABLE callcenter_supervisor_users
    ALTER COLUMN must_change_password SET DEFAULT FALSE;

UPDATE callcenter_supervisor_users
SET must_change_password = FALSE,
    updated_at = now()
WHERE must_change_password;

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
        FALSE
    )
    ON CONFLICT (dept_id, username) DO NOTHING;
    RETURN NEW;
END;
$$;

INSERT INTO astera_schema_migrations (version)
VALUES ('054_callcenter_optional_password_change')
ON CONFLICT (version) DO NOTHING;

COMMIT;
