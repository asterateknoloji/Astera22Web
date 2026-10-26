BEGIN;

UPDATE pbx_config_values AS value
SET text_value = 'tr',
    updated_at = now()
FROM pbx_config_entities AS entity
WHERE entity.entity_id = value.entity_id
  AND entity.dept_id = value.dept_id
  AND entity.parent_entity_id IS NULL
  AND entity.store_name IN (
      'extensions',
      'queues',
      'ringgroups',
      'ivrs',
      'announcements'
  )
  AND value.field_name = 'language'
  AND value.value_type = 'string'
  AND lower(btrim(value.text_value)) = 'en';

INSERT INTO astera_schema_migrations (version)
VALUES ('036_pbx_default_turkish_language')
ON CONFLICT (version) DO NOTHING;

COMMIT;
