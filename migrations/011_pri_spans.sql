BEGIN;

-- PRI span configuration is stored as tenant-scoped entities in the existing
-- relational PBX configuration tables. Recording the feature migration keeps
-- deployments idempotent and makes the required schema level explicit.
INSERT INTO astera_schema_migrations (version)
VALUES ('011_pri_spans')
ON CONFLICT (version) DO NOTHING;

COMMIT;
