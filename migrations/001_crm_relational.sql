BEGIN;

CREATE EXTENSION IF NOT EXISTS pg_trgm;

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

CREATE UNIQUE INDEX IF NOT EXISTS astera_tenants_code_uq
    ON astera_tenants (code);

CREATE TABLE IF NOT EXISTS crm_customers (
    id VARCHAR(96) PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL
        REFERENCES astera_tenants(dept_id) ON UPDATE CASCADE ON DELETE CASCADE,
    company VARCHAR(160) NOT NULL,
    contact VARCHAR(160) NOT NULL DEFAULT '',
    email VARCHAR(180) NOT NULL DEFAULT '',
    address TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    search_text TEXT GENERATED ALWAYS AS (
        lower(
            coalesce(company, '') || ' ' ||
            coalesce(contact, '') || ' ' ||
            coalesce(email, '') || ' ' ||
            coalesce(address, '') || ' ' ||
            coalesce(notes, '')
        )
    ) STORED,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_by VARCHAR(120) NOT NULL DEFAULT 'system',
    UNIQUE (id, dept_id)
);

CREATE INDEX IF NOT EXISTS crm_customers_dept_company_idx
    ON crm_customers (dept_id, company, id);
CREATE INDEX IF NOT EXISTS crm_customers_dept_updated_idx
    ON crm_customers (dept_id, updated_at DESC, id);
CREATE INDEX IF NOT EXISTS crm_customers_search_trgm_idx
    ON crm_customers USING gin (search_text gin_trgm_ops);

CREATE TABLE IF NOT EXISTS crm_customer_phones (
    id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL
        REFERENCES astera_tenants(dept_id) ON UPDATE CASCADE ON DELETE CASCADE,
    customer_id VARCHAR(96) NOT NULL,
    phone_type VARCHAR(20) NOT NULL CHECK (phone_type IN ('primary', 'alternate')),
    display_value VARCHAR(32) NOT NULL,
    normalized_value VARCHAR(20) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    FOREIGN KEY (customer_id, dept_id)
        REFERENCES crm_customers(id, dept_id) ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE (customer_id, phone_type)
);

CREATE UNIQUE INDEX IF NOT EXISTS crm_customer_phones_dept_normalized_uq
    ON crm_customer_phones (dept_id, normalized_value);
CREATE INDEX IF NOT EXISTS crm_customer_phones_customer_idx
    ON crm_customer_phones (dept_id, customer_id);

CREATE TABLE IF NOT EXISTS crm_call_notes (
    id VARCHAR(96) PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL
        REFERENCES astera_tenants(dept_id) ON UPDATE CASCADE ON DELETE CASCADE,
    customer_id VARCHAR(96) NOT NULL,
    call_key VARCHAR(255) NOT NULL,
    note TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_by VARCHAR(120) NOT NULL DEFAULT 'system',
    FOREIGN KEY (customer_id, dept_id)
        REFERENCES crm_customers(id, dept_id) ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE (dept_id, call_key)
);

CREATE INDEX IF NOT EXISTS crm_call_notes_customer_time_idx
    ON crm_call_notes (dept_id, customer_id, updated_at DESC, id);
CREATE INDEX IF NOT EXISTS crm_call_notes_call_key_idx
    ON crm_call_notes (dept_id, call_key);
CREATE INDEX IF NOT EXISTS crm_call_notes_note_trgm_idx
    ON crm_call_notes USING gin (note gin_trgm_ops);
CREATE INDEX IF NOT EXISTS crm_call_notes_note_fts_idx
    ON crm_call_notes USING gin (to_tsvector('simple', note));

CREATE TABLE IF NOT EXISTS call_recordings (
    id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL
        REFERENCES astera_tenants(dept_id) ON UPDATE CASCADE ON DELETE CASCADE,
    customer_id VARCHAR(96),
    linkedid VARCHAR(80) NOT NULL DEFAULT '',
    uniqueid VARCHAR(80) NOT NULL DEFAULT '',
    object_key TEXT NOT NULL,
    format VARCHAR(16) NOT NULL,
    size_bytes BIGINT,
    duration_seconds INTEGER,
    checksum_sha256 CHAR(64),
    recorded_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    FOREIGN KEY (customer_id, dept_id)
        REFERENCES crm_customers(id, dept_id)
        ON UPDATE CASCADE ON DELETE SET NULL (customer_id),
    UNIQUE (dept_id, object_key)
);

CREATE INDEX IF NOT EXISTS call_recordings_dept_time_idx
    ON call_recordings (dept_id, recorded_at DESC, id);
CREATE INDEX IF NOT EXISTS call_recordings_linkedid_idx
    ON call_recordings (dept_id, linkedid);
CREATE INDEX IF NOT EXISTS call_recordings_uniqueid_idx
    ON call_recordings (dept_id, uniqueid);
CREATE INDEX IF NOT EXISTS call_recordings_customer_idx
    ON call_recordings (dept_id, customer_id, recorded_at DESC);

GRANT SELECT, INSERT, UPDATE, DELETE ON
    astera_tenants,
    crm_customers,
    crm_customer_phones,
    crm_call_notes,
    call_recordings
TO asterisk;
GRANT SELECT ON astera_schema_migrations TO asterisk;
GRANT USAGE, SELECT ON SEQUENCE
    crm_customer_phones_id_seq,
    call_recordings_id_seq
TO asterisk;

INSERT INTO astera_schema_migrations (version)
VALUES ('001_crm_relational')
ON CONFLICT (version) DO NOTHING;

COMMIT;
