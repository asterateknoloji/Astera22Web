BEGIN;

CREATE INDEX IF NOT EXISTS queue_log_missed_queue_time_idx
    ON queue_log (queuename, time DESC, id DESC)
    WHERE event IN ('ABANDON', 'EXITWITHTIMEOUT');

INSERT INTO astera_schema_migrations (version)
VALUES ('049_queue_missed_call_report_index')
ON CONFLICT (version) DO NOTHING;

COMMIT;
