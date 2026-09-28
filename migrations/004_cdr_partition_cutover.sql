BEGIN;

SET LOCAL lock_timeout = '10s';

DO $$
DECLARE
    source_count BIGINT;
    target_count BIGINT;
    missing_count BIGINT;
    default_count BIGINT;
BEGIN
    IF EXISTS (
        SELECT 1 FROM astera_schema_migrations
        WHERE version = '004_cdr_partition_cutover'
    ) THEN
        RETURN;
    END IF;
    IF to_regclass('public.cdr_partitioned') IS NULL THEN
        RAISE EXCEPTION 'Hazırlanmış CDR partition tablosu bulunamadı';
    END IF;

    LOCK TABLE cdr IN ACCESS EXCLUSIVE MODE;
    LOCK TABLE cdr_partitioned IN ACCESS EXCLUSIVE MODE;

    SELECT count(*) INTO source_count FROM cdr;
    SELECT count(*) INTO target_count FROM cdr_partitioned;
    SELECT count(*) INTO missing_count
    FROM cdr s
    WHERE NOT EXISTS (
        SELECT 1 FROM cdr_partitioned p
        WHERE p.calldate = s.calldate AND p.id = s.id
    );
    SELECT astera_cdr_default_partition_rows() INTO default_count;

    IF source_count <> target_count OR missing_count <> 0 OR default_count <> 0 THEN
        RAISE EXCEPTION
            'CDR cutover doğrulaması başarısız: source=%, target=%, missing=%, default=%',
            source_count, target_count, missing_count, default_count;
    END IF;

    ALTER SEQUENCE cdr_id_seq OWNED BY NONE;
    ALTER TABLE cdr RENAME TO cdr_unpartitioned_archive;
    ALTER TABLE cdr_partitioned RENAME TO cdr;
    ALTER SEQUENCE cdr_id_seq OWNED BY cdr.id;

    INSERT INTO astera_schema_migrations (version)
    VALUES ('004_cdr_partition_cutover');
END;
$$;
COMMIT;

ANALYZE cdr;
