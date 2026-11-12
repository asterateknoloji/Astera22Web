BEGIN;

CREATE UNIQUE INDEX IF NOT EXISTS callcenter_survey_evaluations_active_call_uq
    ON callcenter_survey_evaluations (dept_id, survey_id, agent_id, call_linkedid)
    WHERE status <> 'void';

INSERT INTO astera_schema_migrations (version)
VALUES ('020_callcenter_survey_call_unique')
ON CONFLICT (version) DO NOTHING;

COMMIT;
