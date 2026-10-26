BEGIN;

-- CRM automatic opening is opt-in. Existing extensions are explicitly
-- disabled so a tenant-level URL trigger cannot enable them implicitly.
INSERT INTO pbx_config_values (
    dept_id,
    entity_id,
    field_name,
    position,
    map_key,
    value_type,
    text_value
)
SELECT
    entity.dept_id,
    entity.entity_id,
    'crm_trigger',
    NULL,
    NULL,
    'string',
    'disabled'
FROM pbx_config_entities AS entity
WHERE entity.store_name = 'extensions'
  AND entity.parent_entity_id IS NULL
ON CONFLICT (entity_id, field_name)
    WHERE position IS NULL AND map_key IS NULL
DO UPDATE SET
    value_type = 'string',
    text_value = 'disabled',
    integer_value = NULL,
    number_value = NULL,
    boolean_value = NULL;

INSERT INTO astera_schema_migrations (version)
VALUES ('023_extension_crm_default_disabled')
ON CONFLICT (version) DO NOTHING;

COMMIT;
