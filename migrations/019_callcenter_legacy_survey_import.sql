BEGIN;

ALTER TABLE callcenter_surveys
    ADD COLUMN IF NOT EXISTS legacy_source VARCHAR(80),
    ADD COLUMN IF NOT EXISTS legacy_id BIGINT;
ALTER TABLE callcenter_survey_categories
    ADD COLUMN IF NOT EXISTS legacy_source VARCHAR(80),
    ADD COLUMN IF NOT EXISTS legacy_id BIGINT;
ALTER TABLE callcenter_survey_questions
    ADD COLUMN IF NOT EXISTS legacy_source VARCHAR(80),
    ADD COLUMN IF NOT EXISTS legacy_id BIGINT;
ALTER TABLE callcenter_survey_evaluations
    ADD COLUMN IF NOT EXISTS legacy_source VARCHAR(80),
    ADD COLUMN IF NOT EXISTS legacy_id BIGINT;

CREATE UNIQUE INDEX IF NOT EXISTS callcenter_surveys_dept_legacy_uq
    ON callcenter_surveys (dept_id, legacy_source, legacy_id)
    WHERE legacy_source IS NOT NULL AND legacy_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS callcenter_survey_categories_dept_legacy_uq
    ON callcenter_survey_categories (dept_id, legacy_source, legacy_id)
    WHERE legacy_source IS NOT NULL AND legacy_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS callcenter_survey_questions_dept_legacy_uq
    ON callcenter_survey_questions (dept_id, legacy_source, legacy_id)
    WHERE legacy_source IS NOT NULL AND legacy_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS callcenter_survey_evaluations_dept_legacy_uq
    ON callcenter_survey_evaluations (dept_id, legacy_source, legacy_id)
    WHERE legacy_source IS NOT NULL AND legacy_id IS NOT NULL;

INSERT INTO astera_schema_migrations (version)
VALUES ('019_callcenter_legacy_survey_import')
ON CONFLICT (version) DO NOTHING;

COMMIT;
