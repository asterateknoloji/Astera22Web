BEGIN;

CREATE TABLE IF NOT EXISTS call_transfer_events (
    transfer_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL
        REFERENCES astera_tenants(dept_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    linkedid VARCHAR(150) NOT NULL,
    cdr_uniqueid VARCHAR(150) NOT NULL DEFAULT '',
    transferor_extension VARCHAR(80) NOT NULL DEFAULT '',
    original_caller_number VARCHAR(80) NOT NULL,
    original_caller_name VARCHAR(80) NOT NULL DEFAULT '',
    target_extension VARCHAR(80) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'completed'
        CHECK (status IN ('requested', 'completed', 'failed', 'cancelled')),
    started_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    completed_at TIMESTAMP WITHOUT TIME ZONE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (dept_id, linkedid, cdr_uniqueid, target_extension)
);

CREATE INDEX IF NOT EXISTS call_transfer_events_dept_time_idx
    ON call_transfer_events (dept_id, started_at DESC, transfer_id DESC);
CREATE INDEX IF NOT EXISTS call_transfer_events_dept_linkedid_idx
    ON call_transfer_events (dept_id, linkedid);
CREATE INDEX IF NOT EXISTS call_transfer_events_dept_target_idx
    ON call_transfer_events (dept_id, target_extension, started_at DESC);

CREATE OR REPLACE FUNCTION astera_sync_call_transfer_event()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
DECLARE
    event_linkedid TEXT;
    original_number TEXT;
    target_number TEXT;
BEGIN
    IF split_part(COALESCE(NEW.userfield, ''), '|', 2) <> 'ATXFER' THEN
        RETURN NEW;
    END IF;

    event_linkedid := COALESCE(
        NULLIF(split_part(NEW.userfield, '|', 3), ''),
        NULLIF(NEW.linkedid, ''),
        NULLIF(NEW.uniqueid, '')
    );
    original_number := COALESCE(
        NULLIF(split_part(NEW.userfield, '|', 4), ''),
        NULLIF(NEW.cnum, ''),
        NULLIF(NEW.src, '')
    );
    target_number := COALESCE(
        NULLIF(split_part(NEW.userfield, '|', 5), ''),
        NULLIF(NEW.dst, '')
    );

    IF event_linkedid IS NULL
       OR original_number IS NULL
       OR target_number IS NULL THEN
        RETURN NEW;
    END IF;

    INSERT INTO call_transfer_events (
        dept_id, linkedid, cdr_uniqueid, transferor_extension,
        original_caller_number, original_caller_name, target_extension,
        status, started_at, completed_at
    ) VALUES (
        NEW.dept_id,
        event_linkedid,
        COALESCE(NEW.uniqueid, ''),
        COALESCE(split_part(NEW.userfield, '|', 6), ''),
        original_number,
        COALESCE(NEW.cnam, ''),
        target_number,
        CASE
            WHEN UPPER(COALESCE(NEW.disposition, '')) = 'ANSWERED'
                THEN 'completed'
            WHEN UPPER(COALESCE(NEW.disposition, '')) IN ('FAILED', 'CONGESTION')
                THEN 'failed'
            ELSE 'requested'
        END,
        COALESCE(NEW.start, NEW.calldate),
        NEW.call_end
    )
    ON CONFLICT (dept_id, linkedid, cdr_uniqueid, target_extension)
    DO UPDATE SET
        transferor_extension = EXCLUDED.transferor_extension,
        original_caller_number = EXCLUDED.original_caller_number,
        original_caller_name = EXCLUDED.original_caller_name,
        status = EXCLUDED.status,
        started_at = EXCLUDED.started_at,
        completed_at = EXCLUDED.completed_at,
        updated_at = now();

    RETURN NEW;
END;
$$;

DO $$
DECLARE
    cdr_table REGCLASS;
BEGIN
    cdr_table := COALESCE(
        to_regclass('public.cdr_partitioned'),
        to_regclass('public.cdr')
    );
    IF cdr_table IS NULL THEN
        RAISE EXCEPTION 'CDR tablosu bulunamadı';
    END IF;
    EXECUTE format(
        'DROP TRIGGER IF EXISTS astera_sync_call_transfer_event_trigger ON %s',
        cdr_table
    );
    EXECUTE format(
        'CREATE TRIGGER astera_sync_call_transfer_event_trigger '
        'AFTER INSERT OR UPDATE OF '
        'userfield, transfered, linkedid, uniqueid, cnum, cnam, dst, '
        'disposition, start, call_end ON %s '
        'FOR EACH ROW EXECUTE FUNCTION astera_sync_call_transfer_event()',
        cdr_table
    );
END;
$$;

INSERT INTO astera_schema_migrations (version)
VALUES ('006_call_transfer_events')
ON CONFLICT (version) DO NOTHING;

COMMIT;
