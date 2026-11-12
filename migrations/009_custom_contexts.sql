BEGIN;

CREATE TABLE IF NOT EXISTS pbx_custom_contexts (
    dept_id VARCHAR(64) NOT NULL
        REFERENCES astera_tenants(dept_id) ON UPDATE CASCADE ON DELETE CASCADE,
    context_id VARCHAR(64) NOT NULL,
    context_name VARCHAR(160) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (dept_id, context_id),
    UNIQUE (context_name),
    CHECK (context_id ~ '^[A-Za-z0-9_-]+$'),
    CHECK (context_name ~ '^[A-Za-z0-9_.-]+$')
);

CREATE INDEX IF NOT EXISTS pbx_custom_contexts_tenant_idx
    ON pbx_custom_contexts (dept_id, context_id);

CREATE TABLE IF NOT EXISTS pbx_custom_context_steps (
    step_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL,
    context_id VARCHAR(64) NOT NULL,
    position INTEGER NOT NULL CHECK (position >= 0),
    extension_pattern VARCHAR(80) NOT NULL,
    priority VARCHAR(80) NOT NULL,
    application VARCHAR(80) NOT NULL,
    application_data TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (dept_id, context_id, position),
    FOREIGN KEY (dept_id, context_id)
        REFERENCES pbx_custom_contexts(dept_id, context_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CHECK (extension_pattern ~ '^[-A-Za-z0-9_+*#!.]+$'),
    CHECK (priority ~ '^(n|[1-9][0-9]*)([(][A-Za-z0-9_-]+[)])?$'),
    CHECK (application ~ '^[A-Za-z][A-Za-z0-9_]*$')
);

CREATE INDEX IF NOT EXISTS pbx_custom_context_steps_tenant_context_idx
    ON pbx_custom_context_steps (dept_id, context_id, position);

GRANT SELECT, INSERT, UPDATE, DELETE ON pbx_custom_contexts TO asterisk;
GRANT SELECT, INSERT, UPDATE, DELETE ON pbx_custom_context_steps TO asterisk;
GRANT USAGE, SELECT ON SEQUENCE pbx_custom_context_steps_step_id_seq TO asterisk;
GRANT SELECT ON pbx_custom_contexts, pbx_custom_context_steps TO "astera-panel";

INSERT INTO astera_schema_migrations (version)
VALUES ('009_custom_contexts')
ON CONFLICT (version) DO NOTHING;

COMMIT;
