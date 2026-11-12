BEGIN;

-- Existing extensions keep the legacy behavior by starting in tenant-local
-- pickup group 1. Lists are stored as normalized pbx_config_values rows.
INSERT INTO pbx_config_values (
    dept_id,
    entity_id,
    field_name,
    position,
    value_type,
    integer_value
)
SELECT
    entity.dept_id,
    entity.entity_id,
    'pickup_groups',
    0,
    'integer',
    1
FROM pbx_config_entities AS entity
WHERE entity.store_name = 'extensions'
  AND entity.parent_entity_id IS NULL
  AND NOT EXISTS (
      SELECT 1
      FROM pbx_config_values AS value
      WHERE value.entity_id = entity.entity_id
        AND value.field_name = 'pickup_groups'
  );

INSERT INTO astera_schema_migrations (version)
VALUES ('046_extension_pickup_groups')
ON CONFLICT (version) DO NOTHING;

COMMIT;
