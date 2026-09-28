BEGIN;

CREATE TABLE IF NOT EXISTS cdr_retention_policy (
    policy_id SMALLINT PRIMARY KEY DEFAULT 1 CHECK (policy_id = 1),
    online_months INTEGER NOT NULL DEFAULT 84 CHECK (online_months >= 12),
    archive_required BOOLEAN NOT NULL DEFAULT TRUE,
    automatic_drop_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (NOT automatic_drop_enabled OR archive_required)
);

INSERT INTO cdr_retention_policy (policy_id)
VALUES (1)
ON CONFLICT (policy_id) DO NOTHING;

CREATE OR REPLACE FUNCTION astera_resolve_cdr_dept(
    source_accountcode TEXT,
    source_userfield TEXT,
    source_dcontext TEXT
)
RETURNS VARCHAR(64)
LANGUAGE plpgsql
STABLE
SECURITY DEFINER
SET search_path = public
AS $$
DECLARE
    candidate TEXT;
    resolved VARCHAR(64);
BEGIN
    candidate := NULLIF(BTRIM(SPLIT_PART(COALESCE(source_accountcode, ''), ';', 1)), '');
    IF candidate IS NULL THEN
        candidate := NULLIF(BTRIM(SPLIT_PART(COALESCE(source_userfield, ''), '|', 1)), '');
    END IF;

    IF candidate IS NOT NULL THEN
        SELECT dept_id INTO resolved
        FROM astera_tenants
        WHERE active AND (code = candidate OR dept_id = candidate)
        ORDER BY (code = candidate) DESC
        LIMIT 1;
    END IF;

    IF resolved IS NULL AND COALESCE(source_dcontext, '') <> '' THEN
        SELECT dept_id INTO resolved
        FROM astera_tenants
        WHERE active
          AND (
              source_dcontext IN ('from-' || code, 'int-' || code, 'out-' || code)
              OR source_dcontext LIKE 'ivr-' || code || '-%'
              OR source_dcontext LIKE 'tc-' || code || '-%'
              OR source_dcontext LIKE '%-' || code
          )
        ORDER BY length(code) DESC
        LIMIT 1;
    END IF;

    IF resolved IS NULL THEN
        SELECT dept_id INTO resolved
        FROM astera_tenants
        WHERE active
        ORDER BY (dept_id = 'genel') DESC, dept_id
        LIMIT 1;
    END IF;

    IF resolved IS NULL THEN
        RAISE EXCEPTION 'CDR için aktif firma bulunamadı';
    END IF;
    RETURN resolved;
END;
$$;

CREATE TABLE IF NOT EXISTS cdr_partitioned (
    id BIGINT NOT NULL DEFAULT nextval('cdr_id_seq'::regclass),
    dept_id VARCHAR(64) NOT NULL
        REFERENCES astera_tenants(dept_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    calldate TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    clid VARCHAR(80),
    src VARCHAR(80),
    dst VARCHAR(80),
    src_normalized VARCHAR(32) GENERATED ALWAYS AS
        (regexp_replace(COALESCE(src, ''), '[^0-9]', '', 'g')) STORED,
    dst_normalized VARCHAR(32) GENERATED ALWAYS AS
        (regexp_replace(COALESCE(dst, ''), '[^0-9]', '', 'g')) STORED,
    dcontext VARCHAR(80),
    channel VARCHAR(80),
    dstchannel VARCHAR(80),
    lastapp VARCHAR(80),
    lastdata VARCHAR(255),
    duration INTEGER,
    billsec INTEGER,
    disposition VARCHAR(45),
    amaflags INTEGER,
    accountcode VARCHAR(80),
    uniqueid VARCHAR(150),
    userfield VARCHAR(255),
    did VARCHAR(80),
    recordingfile VARCHAR(255),
    cnum VARCHAR(80),
    cnam VARCHAR(80),
    outbound_cnum VARCHAR(80),
    outbound_cnam VARCHAR(80),
    linkedid VARCHAR(150),
    peeraccount VARCHAR(80),
    sequence INTEGER,
    dst_cnam VARCHAR(40),
    transfered VARCHAR(1) DEFAULT 'N',
    customer_id INTEGER,
    start TIMESTAMP WITHOUT TIME ZONE,
    answer TIMESTAMP WITHOUT TIME ZONE,
    call_end TIMESTAMP WITHOUT TIME ZONE,
    PRIMARY KEY (calldate, id)
) PARTITION BY RANGE (calldate);

DO $$
DECLARE
    first_month DATE;
    last_month DATE;
    month_start DATE;
    partition_name TEXT;
BEGIN
    SELECT date_trunc('month', COALESCE(MIN(calldate), CURRENT_DATE))::date
    INTO first_month
    FROM cdr;
    last_month := (date_trunc('month', CURRENT_DATE) + interval '18 months')::date;

    FOR month_start IN
        SELECT generate_series(first_month, last_month, interval '1 month')::date
    LOOP
        partition_name := 'cdr_p' || to_char(month_start, 'YYYYMM');
        EXECUTE format(
            'CREATE TABLE IF NOT EXISTS %I PARTITION OF cdr_partitioned '
            'FOR VALUES FROM (%L) TO (%L)',
            partition_name,
            month_start,
            (month_start + interval '1 month')::date
        );
    END LOOP;
END;
$$;

CREATE TABLE IF NOT EXISTS cdr_partitioned_default
    PARTITION OF cdr_partitioned DEFAULT;

CREATE INDEX IF NOT EXISTS cdr_partitioned_dept_time_idx
    ON cdr_partitioned (dept_id, calldate DESC, id DESC);
CREATE INDEX IF NOT EXISTS cdr_partitioned_linkedid_idx
    ON cdr_partitioned (dept_id, linkedid)
    WHERE linkedid IS NOT NULL AND linkedid <> '';
CREATE INDEX IF NOT EXISTS cdr_partitioned_uniqueid_idx
    ON cdr_partitioned (dept_id, uniqueid)
    WHERE uniqueid IS NOT NULL AND uniqueid <> '';
CREATE INDEX IF NOT EXISTS cdr_partitioned_src_idx
    ON cdr_partitioned (dept_id, src_normalized, calldate DESC);
CREATE INDEX IF NOT EXISTS cdr_partitioned_dst_idx
    ON cdr_partitioned (dept_id, dst_normalized, calldate DESC);
CREATE INDEX IF NOT EXISTS cdr_partitioned_time_brin_idx
    ON cdr_partitioned USING brin (calldate) WITH (pages_per_range = 64);

CREATE OR REPLACE FUNCTION astera_prepare_partitioned_cdr()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.dept_id := COALESCE(
        NULLIF(NEW.dept_id, ''),
        astera_resolve_cdr_dept(NEW.accountcode, NEW.userfield, NEW.dcontext)
    );
    NEW.start := COALESCE(NEW.start, NEW.calldate);
    NEW.cnum := COALESCE(NULLIF(NEW.cnum, ''), NEW.src);
    NEW.cnam := COALESCE(
        NULLIF(NEW.cnam, ''),
        NULLIF(BTRIM(SPLIT_PART(COALESCE(NEW.clid, ''), '<', 1), ' "'), '')
    );
    IF COALESCE(NEW.did, '') = '' AND NEW.dcontext = 'ext-did' THEN
        NEW.did := NEW.dst;
    ELSIF COALESCE(NEW.did, '') = ''
          AND NEW.dcontext LIKE 'int-%'
          AND length(regexp_replace(COALESCE(NEW.src, ''), '[^0-9]', '', 'g')) > 6 THEN
        NEW.did := SPLIT_PART(SPLIT_PART(COALESCE(NEW.channel, ''), '/', 2), '-', 1);
    END IF;
    NEW.call_end := COALESCE(
        NEW.call_end,
        NEW.calldate + make_interval(secs => COALESCE(NEW.duration, 0))
    );
    IF NEW.answer IS NULL
       AND (COALESCE(NEW.billsec, 0) > 0 OR UPPER(COALESCE(NEW.disposition, '')) = 'ANSWERED') THEN
        NEW.answer := NEW.call_end - make_interval(secs => COALESCE(NEW.billsec, 0));
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS astera_prepare_partitioned_cdr_trigger ON cdr_partitioned;
CREATE TRIGGER astera_prepare_partitioned_cdr_trigger
BEFORE INSERT OR UPDATE ON cdr_partitioned
FOR EACH ROW EXECUTE FUNCTION astera_prepare_partitioned_cdr();

INSERT INTO cdr_partitioned (
    id, dept_id, calldate, clid, src, dst, dcontext, channel, dstchannel,
    lastapp, lastdata, duration, billsec, disposition, amaflags, accountcode,
    uniqueid, userfield, did, recordingfile, cnum, cnam, outbound_cnum,
    outbound_cnam, linkedid, peeraccount, sequence, dst_cnam, transfered,
    customer_id, start, answer, call_end
)
SELECT
    id, astera_resolve_cdr_dept(accountcode, userfield, dcontext), calldate,
    clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata, duration,
    billsec, disposition, amaflags, accountcode, uniqueid, userfield, did,
    recordingfile, cnum, cnam, outbound_cnum, outbound_cnam, linkedid,
    peeraccount, sequence, dst_cnam, transfered, customer_id, start, answer,
    call_end
FROM cdr
ON CONFLICT (calldate, id) DO UPDATE SET
    dept_id = EXCLUDED.dept_id,
    clid = EXCLUDED.clid,
    src = EXCLUDED.src,
    dst = EXCLUDED.dst,
    dcontext = EXCLUDED.dcontext,
    channel = EXCLUDED.channel,
    dstchannel = EXCLUDED.dstchannel,
    lastapp = EXCLUDED.lastapp,
    lastdata = EXCLUDED.lastdata,
    duration = EXCLUDED.duration,
    billsec = EXCLUDED.billsec,
    disposition = EXCLUDED.disposition,
    amaflags = EXCLUDED.amaflags,
    accountcode = EXCLUDED.accountcode,
    uniqueid = EXCLUDED.uniqueid,
    userfield = EXCLUDED.userfield,
    did = EXCLUDED.did,
    recordingfile = EXCLUDED.recordingfile,
    cnum = EXCLUDED.cnum,
    cnam = EXCLUDED.cnam,
    outbound_cnum = EXCLUDED.outbound_cnum,
    outbound_cnam = EXCLUDED.outbound_cnam,
    linkedid = EXCLUDED.linkedid,
    peeraccount = EXCLUDED.peeraccount,
    sequence = EXCLUDED.sequence,
    dst_cnam = EXCLUDED.dst_cnam,
    transfered = EXCLUDED.transfered,
    customer_id = EXCLUDED.customer_id,
    start = EXCLUDED.start,
    answer = EXCLUDED.answer,
    call_end = EXCLUDED.call_end;

CREATE OR REPLACE FUNCTION astera_mirror_cdr_to_partitioned()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
BEGIN
    IF TG_OP IN ('UPDATE', 'DELETE') THEN
        DELETE FROM cdr_partitioned
        WHERE calldate = OLD.calldate AND id = OLD.id;
    END IF;
    IF TG_OP IN ('INSERT', 'UPDATE') THEN
        INSERT INTO cdr_partitioned (
            id, dept_id, calldate, clid, src, dst, dcontext, channel, dstchannel,
            lastapp, lastdata, duration, billsec, disposition, amaflags, accountcode,
            uniqueid, userfield, did, recordingfile, cnum, cnam, outbound_cnum,
            outbound_cnam, linkedid, peeraccount, sequence, dst_cnam, transfered,
            customer_id, start, answer, call_end
        ) VALUES (
            NEW.id, astera_resolve_cdr_dept(NEW.accountcode, NEW.userfield, NEW.dcontext),
            NEW.calldate, NEW.clid, NEW.src, NEW.dst, NEW.dcontext, NEW.channel,
            NEW.dstchannel, NEW.lastapp, NEW.lastdata, NEW.duration, NEW.billsec,
            NEW.disposition, NEW.amaflags, NEW.accountcode, NEW.uniqueid,
            NEW.userfield, NEW.did, NEW.recordingfile, NEW.cnum, NEW.cnam,
            NEW.outbound_cnum, NEW.outbound_cnam, NEW.linkedid, NEW.peeraccount,
            NEW.sequence, NEW.dst_cnam, NEW.transfered, NEW.customer_id,
            NEW.start, NEW.answer, NEW.call_end
        )
        ON CONFLICT (calldate, id) DO NOTHING;
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS astera_mirror_cdr_partitioned_trigger ON cdr;
CREATE TRIGGER astera_mirror_cdr_partitioned_trigger
AFTER INSERT OR UPDATE OR DELETE ON cdr
FOR EACH ROW EXECUTE FUNCTION astera_mirror_cdr_to_partitioned();

SELECT setval(
    'cdr_id_seq',
    GREATEST(
        COALESCE((SELECT MAX(id) FROM cdr), 0),
        COALESCE((SELECT MAX(id) FROM cdr_partitioned), 0),
        1
    ),
    TRUE
);

INSERT INTO call_recordings (
    dept_id, linkedid, uniqueid, object_key, format, recorded_at
)
SELECT DISTINCT ON (p.dept_id, p.recordingfile)
    p.dept_id,
    COALESCE(p.linkedid, ''),
    COALESCE(p.uniqueid, ''),
    p.recordingfile,
    COALESCE(NULLIF(lower(substring(p.recordingfile FROM '\.([^.]+)$')), ''), 'unknown'),
    COALESCE(p.start, p.calldate)
FROM cdr_partitioned p
WHERE COALESCE(p.recordingfile, '') <> ''
ORDER BY p.dept_id, p.recordingfile, p.calldate DESC
ON CONFLICT (dept_id, object_key) DO UPDATE SET
    linkedid = EXCLUDED.linkedid,
    uniqueid = EXCLUDED.uniqueid,
    format = EXCLUDED.format,
    recorded_at = EXCLUDED.recorded_at;

GRANT SELECT ON cdr_retention_policy TO asterisk;
GRANT SELECT, INSERT, UPDATE, DELETE ON cdr_partitioned TO asterisk;
GRANT USAGE, SELECT ON SEQUENCE cdr_id_seq TO asterisk;

INSERT INTO astera_schema_migrations (version)
VALUES ('002_cdr_partition_stage')
ON CONFLICT (version) DO NOTHING;

COMMIT;
