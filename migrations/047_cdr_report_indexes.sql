BEGIN;

CREATE INDEX IF NOT EXISTS cdr_dept_disposition_time_idx
    ON cdr (dept_id, disposition, calldate DESC, id DESC);

CREATE INDEX IF NOT EXISTS cdr_dept_cnum_time_idx
    ON cdr (dept_id, cnum, calldate DESC)
    WHERE cnum IS NOT NULL AND cnum <> '';

CREATE INDEX IF NOT EXISTS cdr_dept_outbound_cnum_time_idx
    ON cdr (dept_id, outbound_cnum, calldate DESC)
    WHERE outbound_cnum IS NOT NULL AND outbound_cnum <> '';

INSERT INTO astera_schema_migrations (version)
VALUES ('047_cdr_report_indexes')
ON CONFLICT (version) DO NOTHING;

COMMIT;

ANALYZE cdr;
