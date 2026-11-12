BEGIN;

INSERT INTO pbx_config_values (
    dept_id,
    entity_id,
    field_name,
    value_type,
    integer_value
)
SELECT
    entity.dept_id,
    entity.entity_id,
    'qualify',
    'integer',
    60
FROM pbx_config_entities AS entity
WHERE entity.store_name = 'extensions'
  AND entity.parent_entity_id IS NULL
  AND NOT EXISTS (
      SELECT 1
      FROM pbx_config_values AS technology
      WHERE technology.entity_id = entity.entity_id
        AND technology.field_name = 'technology'
        AND technology.position IS NULL
        AND technology.map_key IS NULL
        AND technology.value_type = 'string'
        AND lower(btrim(technology.text_value)) IN ('dahdi', 'custom')
  )
ON CONFLICT (entity_id, field_name)
    WHERE position IS NULL AND map_key IS NULL
DO UPDATE SET
    value_type = 'integer',
    text_value = NULL,
    integer_value = CASE
        WHEN pbx_config_values.value_type = 'integer'
            AND pbx_config_values.integer_value > 0
            THEN pbx_config_values.integer_value
        ELSE 60
    END,
    number_value = NULL,
    boolean_value = NULL,
    updated_at = now();

INSERT INTO astera_schema_migrations (version)
VALUES ('043_extension_qualify_default')
ON CONFLICT (version) DO NOTHING;

COMMIT;
