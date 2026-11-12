BEGIN;

-- Extension outbound authorization is stored as the tenant-scoped
-- outbound_enabled boolean field in the normalized PBX configuration tables.
-- Existing rows without the field remain authorized for backward compatibility.
INSERT INTO astera_schema_migrations (version)
VALUES ('022_extension_outbound_permissions')
ON CONFLICT (version) DO NOTHING;

COMMIT;
