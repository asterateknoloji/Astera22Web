BEGIN;

CREATE TABLE IF NOT EXISTS callcenter_surveys (
    survey_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    name VARCHAR(180) NOT NULL,
    description TEXT,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (survey_id, dept_id),
    UNIQUE (dept_id, name),
    CHECK (btrim(name) <> '')
);
CREATE INDEX IF NOT EXISTS callcenter_surveys_dept_active_idx
    ON callcenter_surveys (dept_id, active, name);

CREATE TABLE IF NOT EXISTS callcenter_survey_categories (
    category_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    survey_id BIGINT NOT NULL,
    name VARCHAR(180) NOT NULL,
    max_score NUMERIC(10,2) NOT NULL CHECK (max_score > 0),
    sort_order INTEGER NOT NULL DEFAULT 1 CHECK (sort_order > 0),
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    FOREIGN KEY (survey_id, dept_id) REFERENCES callcenter_surveys(survey_id, dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE (category_id, dept_id, survey_id),
    UNIQUE (dept_id, survey_id, name),
    CHECK (btrim(name) <> '')
);
CREATE INDEX IF NOT EXISTS callcenter_survey_categories_dept_survey_idx
    ON callcenter_survey_categories (dept_id, survey_id, active, sort_order, category_id);

CREATE TABLE IF NOT EXISTS callcenter_survey_questions (
    question_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    survey_id BIGINT NOT NULL,
    category_id BIGINT NOT NULL,
    question_text TEXT NOT NULL,
    question_type VARCHAR(16) NOT NULL DEFAULT 'score'
        CHECK (question_type IN ('score', 'yes_no')),
    max_score NUMERIC(10,2) NOT NULL CHECK (max_score > 0),
    sort_order INTEGER NOT NULL DEFAULT 1 CHECK (sort_order > 0),
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    FOREIGN KEY (category_id, dept_id, survey_id)
        REFERENCES callcenter_survey_categories(category_id, dept_id, survey_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE (question_id, dept_id, survey_id),
    CHECK (btrim(question_text) <> '')
);
CREATE INDEX IF NOT EXISTS callcenter_survey_questions_dept_category_idx
    ON callcenter_survey_questions (dept_id, category_id, active, sort_order, question_id);

CREATE TABLE IF NOT EXISTS callcenter_survey_evaluations (
    evaluation_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    survey_id BIGINT NOT NULL,
    agent_id BIGINT NOT NULL,
    evaluator_id BIGINT NOT NULL,
    call_linkedid VARCHAR(150) NOT NULL,
    call_uniqueid VARCHAR(150) NOT NULL DEFAULT '',
    evaluated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    status VARCHAR(16) NOT NULL DEFAULT 'completed'
        CHECK (status IN ('draft', 'completed', 'void')),
    total_score NUMERIC(10,2) NOT NULL CHECK (total_score >= 0),
    max_score NUMERIC(10,2) NOT NULL CHECK (max_score > 0),
    note TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    FOREIGN KEY (survey_id, dept_id) REFERENCES callcenter_surveys(survey_id, dept_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    FOREIGN KEY (agent_id, dept_id) REFERENCES callcenter_agents(agent_id, dept_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    FOREIGN KEY (evaluator_id, dept_id)
        REFERENCES callcenter_supervisor_users(supervisor_id, dept_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    UNIQUE (evaluation_id, dept_id, survey_id),
    CHECK (btrim(call_linkedid) <> ''),
    CHECK (total_score <= max_score)
);
CREATE INDEX IF NOT EXISTS callcenter_survey_evaluations_dept_date_idx
    ON callcenter_survey_evaluations (dept_id, evaluated_at DESC, evaluation_id);
CREATE INDEX IF NOT EXISTS callcenter_survey_evaluations_dept_agent_date_idx
    ON callcenter_survey_evaluations (dept_id, agent_id, evaluated_at DESC);
CREATE INDEX IF NOT EXISTS callcenter_survey_evaluations_dept_evaluator_date_idx
    ON callcenter_survey_evaluations (dept_id, evaluator_id, evaluated_at DESC);
CREATE INDEX IF NOT EXISTS callcenter_survey_evaluations_dept_survey_date_idx
    ON callcenter_survey_evaluations (dept_id, survey_id, evaluated_at DESC);
CREATE INDEX IF NOT EXISTS callcenter_survey_evaluations_dept_linkedid_idx
    ON callcenter_survey_evaluations (dept_id, call_linkedid);
CREATE INDEX IF NOT EXISTS callcenter_survey_evaluations_dept_uniqueid_idx
    ON callcenter_survey_evaluations (dept_id, call_uniqueid)
    WHERE call_uniqueid <> '';

CREATE TABLE IF NOT EXISTS callcenter_survey_answers (
    answer_id BIGSERIAL PRIMARY KEY,
    dept_id VARCHAR(64) NOT NULL REFERENCES astera_tenants(dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    survey_id BIGINT NOT NULL,
    evaluation_id BIGINT NOT NULL,
    question_id BIGINT NOT NULL,
    score NUMERIC(10,2) NOT NULL CHECK (score >= 0),
    max_score NUMERIC(10,2) NOT NULL CHECK (max_score > 0),
    answer_text TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    FOREIGN KEY (evaluation_id, dept_id, survey_id)
        REFERENCES callcenter_survey_evaluations(evaluation_id, dept_id, survey_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (question_id, dept_id, survey_id)
        REFERENCES callcenter_survey_questions(question_id, dept_id, survey_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    UNIQUE (dept_id, evaluation_id, question_id),
    CHECK (score <= max_score)
);
CREATE INDEX IF NOT EXISTS callcenter_survey_answers_dept_evaluation_idx
    ON callcenter_survey_answers (dept_id, evaluation_id, question_id);
CREATE INDEX IF NOT EXISTS callcenter_survey_answers_dept_question_idx
    ON callcenter_survey_answers (dept_id, question_id, evaluation_id);

GRANT SELECT, INSERT, UPDATE, DELETE ON
    callcenter_surveys, callcenter_survey_categories, callcenter_survey_questions,
    callcenter_survey_evaluations, callcenter_survey_answers TO asterisk, "astera-panel";
GRANT USAGE, SELECT, UPDATE ON SEQUENCE
    callcenter_surveys_survey_id_seq,
    callcenter_survey_categories_category_id_seq,
    callcenter_survey_questions_question_id_seq,
    callcenter_survey_evaluations_evaluation_id_seq,
    callcenter_survey_answers_answer_id_seq TO asterisk, "astera-panel";

INSERT INTO astera_schema_migrations (version)
VALUES ('017_callcenter_surveys')
ON CONFLICT (version) DO NOTHING;

COMMIT;
