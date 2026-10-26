BEGIN;

WITH ringgroups_without_number AS (
    SELECT
        entity.entity_id,
        entity.dept_id,
        row_number() OVER (
            PARTITION BY entity.dept_id
            ORDER BY entity.position, entity.entity_id
        ) AS sequence
    FROM pbx_config_entities AS entity
    WHERE entity.store_name = 'ringgroups'
      AND entity.parent_entity_id IS NULL
      AND NOT EXISTS (
          SELECT 1
          FROM pbx_config_values AS value
          WHERE value.entity_id = entity.entity_id
            AND value.field_name = 'exten'
            AND value.position IS NULL
            AND value.map_key IS NULL
            AND btrim(COALESCE(value.text_value, '')) <> ''
      )
),
used_numbers AS (
    SELECT value.dept_id, value.text_value AS number
    FROM pbx_config_values AS value
    JOIN pbx_config_entities AS entity
      ON entity.entity_id = value.entity_id
     AND entity.dept_id = value.dept_id
    WHERE entity.parent_entity_id IS NULL
      AND entity.store_name IN (
          'extensions', 'queues', 'ringgroups', 'ivrs',
          'conferences', 'pagings', 'disas'
      )
      AND value.field_name = 'exten'
      AND value.text_value ~ '^[0-9]+$'
    UNION
    SELECT value.dept_id, value.text_value AS number
    FROM pbx_config_values AS value
    JOIN pbx_config_entities AS entity
      ON entity.entity_id = value.entity_id
     AND entity.dept_id = value.dept_id
    WHERE entity.parent_entity_id IS NULL
      AND (
          (entity.store_name = 'flows' AND value.field_name = 'feature')
          OR (entity.store_name = 'queues' AND value.field_name = 'dial_aliases')
      )
      AND value.text_value ~ '^[0-9]+$'
),
available_numbers AS (
    SELECT
        tenant.dept_id,
        candidate.number,
        row_number() OVER (
            PARTITION BY tenant.dept_id
            ORDER BY candidate.number
        ) AS sequence
    FROM astera_tenants AS tenant
    CROSS JOIN generate_series(8600, 9999) AS candidate(number)
    WHERE NOT EXISTS (
        SELECT 1
        FROM used_numbers AS used
        WHERE used.dept_id = tenant.dept_id
          AND used.number = candidate.number::text
    )
),
assignments AS (
    SELECT ringgroup.entity_id, ringgroup.dept_id, available.number::text AS number
    FROM ringgroups_without_number AS ringgroup
    JOIN available_numbers AS available
      ON available.dept_id = ringgroup.dept_id
     AND available.sequence = ringgroup.sequence
)
INSERT INTO pbx_config_values (
    dept_id, entity_id, field_name, value_type, text_value
)
SELECT dept_id, entity_id, 'exten', 'string', number
FROM assignments
ON CONFLICT (entity_id, field_name)
    WHERE position IS NULL AND map_key IS NULL
DO UPDATE SET
    value_type = EXCLUDED.value_type,
    text_value = EXCLUDED.text_value,
    integer_value = NULL,
    number_value = NULL,
    boolean_value = NULL,
    updated_at = now();

INSERT INTO astera_schema_migrations (version)
VALUES ('038_ringgroup_internal_numbers')
ON CONFLICT (version) DO NOTHING;

COMMIT;
