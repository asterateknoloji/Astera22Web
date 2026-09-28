BEGIN;

CREATE TABLE IF NOT EXISTS astera_schema_migrations (
    version VARCHAR(64) PRIMARY KEY,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS astera_tenants (
    dept_id VARCHAR(64) PRIMARY KEY,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(160) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

ALTER TABLE astera_tenants
    ADD COLUMN IF NOT EXISTS record_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS outbound_cid VARCHAR(80) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS panel_user VARCHAR(120) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS panel_password TEXT NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS config_position INTEGER NOT NULL DEFAULT 0
        CHECK (config_position >= 0),
    ADD COLUMN IF NOT EXISTS extension_limit INTEGER NOT NULL DEFAULT 0
        CHECK (extension_limit >= 0);

-- Every PBX object is a row. Nested objects (IVR digits, time rules, etc.)
-- point to their parent; no configuration JSON is authoritative here.
CREATE TABLE IF NOT EXISTS pbx_config_entities (
    entity_id VARCHAR(64) PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL
        REFERENCES astera_tenants(dept_id) ON UPDATE CASCADE ON DELETE CASCADE,
    store_name VARCHAR(32) NOT NULL,
    external_id VARCHAR(128),
    parent_entity_id VARCHAR(64),
    relation_name VARCHAR(64),
    map_key VARCHAR(64),
    position INTEGER,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_by VARCHAR(120) NOT NULL DEFAULT 'system',
    CHECK (
        (parent_entity_id IS NULL AND external_id IS NOT NULL
            AND relation_name IS NULL AND map_key IS NULL AND position IS NOT NULL)
        OR
        (parent_entity_id IS NOT NULL AND external_id IS NULL
            AND relation_name IS NOT NULL
            AND ((map_key IS NOT NULL) <> (position IS NOT NULL)))
    ),
    CHECK (position IS NULL OR position >= 0),
    UNIQUE (entity_id, dept_id),
    FOREIGN KEY (parent_entity_id, dept_id)
        REFERENCES pbx_config_entities(entity_id, dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS pbx_config_entities_root_uq
    ON pbx_config_entities (dept_id, store_name, external_id)
    WHERE parent_entity_id IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS pbx_config_entities_root_position_uq
    ON pbx_config_entities (store_name, position)
    WHERE parent_entity_id IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS pbx_config_entities_child_map_uq
    ON pbx_config_entities (parent_entity_id, relation_name, map_key)
    WHERE map_key IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS pbx_config_entities_child_position_uq
    ON pbx_config_entities (parent_entity_id, relation_name, position)
    WHERE position IS NOT NULL;
CREATE INDEX IF NOT EXISTS pbx_config_entities_dept_store_idx
    ON pbx_config_entities (dept_id, store_name, external_id);
CREATE INDEX IF NOT EXISTS pbx_config_entities_parent_idx
    ON pbx_config_entities (dept_id, parent_entity_id, relation_name, position);

CREATE TABLE IF NOT EXISTS pbx_config_values (
    value_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL
        REFERENCES astera_tenants(dept_id) ON UPDATE CASCADE ON DELETE CASCADE,
    entity_id VARCHAR(64) NOT NULL,
    field_name VARCHAR(64) NOT NULL,
    position INTEGER,
    map_key VARCHAR(64),
    value_type VARCHAR(12) NOT NULL
        CHECK (value_type IN ('string', 'integer', 'number', 'boolean', 'null', 'container')),
    text_value TEXT,
    integer_value BIGINT,
    number_value NUMERIC,
    boolean_value BOOLEAN,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (position IS NULL OR position >= 0),
    CHECK (NOT (position IS NOT NULL AND map_key IS NOT NULL)),
    CHECK (
        (value_type = 'string' AND text_value IS NOT NULL
            AND integer_value IS NULL AND number_value IS NULL AND boolean_value IS NULL)
        OR (value_type = 'integer' AND text_value IS NULL
            AND integer_value IS NOT NULL AND number_value IS NULL AND boolean_value IS NULL)
        OR (value_type = 'number' AND text_value IS NULL
            AND integer_value IS NULL AND number_value IS NOT NULL AND boolean_value IS NULL)
        OR (value_type = 'boolean' AND text_value IS NULL
            AND integer_value IS NULL AND number_value IS NULL AND boolean_value IS NOT NULL)
        OR (value_type IN ('null', 'container') AND text_value IS NULL
            AND integer_value IS NULL AND number_value IS NULL AND boolean_value IS NULL)
    ),
    FOREIGN KEY (entity_id, dept_id)
        REFERENCES pbx_config_entities(entity_id, dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS pbx_config_values_scalar_uq
    ON pbx_config_values (entity_id, field_name)
    WHERE position IS NULL AND map_key IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS pbx_config_values_list_uq
    ON pbx_config_values (entity_id, field_name, position)
    WHERE position IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS pbx_config_values_map_uq
    ON pbx_config_values (entity_id, field_name, map_key)
    WHERE map_key IS NOT NULL;
CREATE INDEX IF NOT EXISTS pbx_config_values_dept_entity_idx
    ON pbx_config_values (dept_id, entity_id, field_name, position);
CREATE INDEX IF NOT EXISTS pbx_config_values_lookup_idx
    ON pbx_config_values (dept_id, field_name, text_value)
    WHERE text_value IS NOT NULL;

GRANT SELECT, INSERT, UPDATE, DELETE ON pbx_config_entities TO asterisk;
GRANT SELECT, INSERT, UPDATE, DELETE ON pbx_config_values TO asterisk;
GRANT SELECT, INSERT, UPDATE, DELETE ON astera_tenants TO asterisk;
GRANT USAGE, SELECT ON SEQUENCE pbx_config_values_value_id_seq TO asterisk;

CREATE UNIQUE INDEX IF NOT EXISTS astera_tenants_code_uq
    ON astera_tenants (code);

COMMIT;
