BEGIN;

CREATE INDEX IF NOT EXISTS crm_customer_phones_normalized_trgm_idx
    ON crm_customer_phones USING gin (normalized_value gin_trgm_ops);

CREATE OR REPLACE FUNCTION astera_create_cdr_partitions(months_ahead INTEGER DEFAULT 18)
RETURNS TABLE(partition_name TEXT, created BOOLEAN)
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
DECLARE
    month_start DATE;
    month_end DATE;
    target_name TEXT;
    already_exists BOOLEAN;
    parent_table REGCLASS;
BEGIN
    IF months_ahead < 2 OR months_ahead > 36 THEN
        RAISE EXCEPTION 'months_ahead 2 ile 36 arasında olmalı';
    END IF;
    parent_table := COALESCE(
        to_regclass('public.cdr_partitioned'),
        to_regclass('public.cdr')
    );
    IF parent_table IS NULL THEN
        RAISE EXCEPTION 'Partition CDR üst tablosu bulunamadı';
    END IF;

    FOR month_start IN
        SELECT generate_series(
            date_trunc('month', CURRENT_DATE)::date,
            (date_trunc('month', CURRENT_DATE) + make_interval(months => months_ahead))::date,
            interval '1 month'
        )::date
    LOOP
        month_end := (month_start + interval '1 month')::date;
        target_name := 'cdr_p' || to_char(month_start, 'YYYYMM');
        SELECT to_regclass('public.' || target_name) IS NOT NULL INTO already_exists;

        IF NOT already_exists THEN
            IF EXISTS (
                SELECT 1
                FROM cdr_partitioned_default
                WHERE calldate >= month_start
                  AND calldate < month_end
            ) THEN
                RAISE EXCEPTION
                    '% tarih aralığında varsayılan partition içinde satır var; önce taşıma gerekli',
                    target_name;
            END IF;
            EXECUTE format(
                'CREATE TABLE %I PARTITION OF %s '
                'FOR VALUES FROM (%L) TO (%L)',
                target_name,
                parent_table,
                month_start,
                month_end
            );
        END IF;

        partition_name := target_name;
        created := NOT already_exists;
        RETURN NEXT;
    END LOOP;
END;
$$;

CREATE OR REPLACE FUNCTION astera_sync_call_recording_metadata()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
BEGIN
    IF COALESCE(NEW.recordingfile, '') = '' THEN
        RETURN NEW;
    END IF;

    INSERT INTO call_recordings (
        dept_id, linkedid, uniqueid, object_key, format, recorded_at
    ) VALUES (
        NEW.dept_id,
        COALESCE(NEW.linkedid, ''),
        COALESCE(NEW.uniqueid, ''),
        NEW.recordingfile,
        COALESCE(NULLIF(lower(substring(NEW.recordingfile FROM '\.([^.]+)$')), ''), 'unknown'),
        COALESCE(NEW.start, NEW.calldate)
    )
    ON CONFLICT (dept_id, object_key) DO UPDATE SET
        linkedid = EXCLUDED.linkedid,
        uniqueid = EXCLUDED.uniqueid,
        format = EXCLUDED.format,
        recorded_at = EXCLUDED.recorded_at;
    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION astera_cdr_default_partition_rows()
RETURNS BIGINT
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = public
AS $$
    SELECT count(*) FROM cdr_partitioned_default;
$$;

DROP TRIGGER IF EXISTS astera_sync_call_recording_metadata_trigger ON cdr_partitioned;
CREATE TRIGGER astera_sync_call_recording_metadata_trigger
AFTER INSERT OR UPDATE OF recordingfile, linkedid, uniqueid, dept_id, start
ON cdr_partitioned
FOR EACH ROW EXECUTE FUNCTION astera_sync_call_recording_metadata();

GRANT EXECUTE ON FUNCTION astera_create_cdr_partitions(INTEGER) TO asterisk;
GRANT EXECUTE ON FUNCTION astera_cdr_default_partition_rows() TO asterisk;

INSERT INTO astera_schema_migrations (version)
VALUES ('003_database_operations')
ON CONFLICT (version) DO NOTHING;

COMMIT;
