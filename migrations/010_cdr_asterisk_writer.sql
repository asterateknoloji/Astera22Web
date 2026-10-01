BEGIN;

CREATE OR REPLACE VIEW cdr_asterisk_writer AS
SELECT
    calldate,
    clid,
    src,
    dst,
    dcontext,
    channel,
    dstchannel,
    lastapp,
    lastdata,
    duration,
    billsec,
    disposition,
    amaflags,
    accountcode,
    uniqueid,
    userfield,
    did,
    recordingfile,
    cnum,
    cnam,
    outbound_cnum,
    outbound_cnam,
    linkedid,
    peeraccount,
    sequence,
    dst_cnam,
    transfered,
    start,
    answer,
    call_end
FROM cdr;

CREATE OR REPLACE FUNCTION astera_insert_cdr_from_asterisk()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
BEGIN
    INSERT INTO cdr (
        calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp,
        lastdata, duration, billsec, disposition, amaflags, accountcode,
        uniqueid, userfield, did, recordingfile, cnum, cnam, outbound_cnum,
        outbound_cnam, linkedid, peeraccount, sequence, dst_cnam, transfered,
        start, answer, call_end
    ) VALUES (
        COALESCE(
            NEW.calldate AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Istanbul',
            timezone('Europe/Istanbul', now())
        ),
        NEW.clid, NEW.src, NEW.dst, NEW.dcontext, NEW.channel, NEW.dstchannel,
        NEW.lastapp, NEW.lastdata, NEW.duration, NEW.billsec, NEW.disposition,
        NEW.amaflags, NEW.accountcode, NEW.uniqueid, NEW.userfield, NEW.did,
        NEW.recordingfile, NEW.cnum, NEW.cnam, NEW.outbound_cnum,
        NEW.outbound_cnam, NEW.linkedid, NEW.peeraccount, NEW.sequence,
        NEW.dst_cnam, NEW.transfered, NEW.start, NEW.answer, NEW.call_end
    );
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS astera_insert_cdr_from_asterisk_trigger
    ON cdr_asterisk_writer;
CREATE TRIGGER astera_insert_cdr_from_asterisk_trigger
INSTEAD OF INSERT ON cdr_asterisk_writer
FOR EACH ROW EXECUTE FUNCTION astera_insert_cdr_from_asterisk();

GRANT SELECT, INSERT ON cdr_asterisk_writer TO asterisk;

INSERT INTO astera_schema_migrations (version)
VALUES ('010_cdr_asterisk_writer')
ON CONFLICT (version) DO NOTHING;

COMMIT;
