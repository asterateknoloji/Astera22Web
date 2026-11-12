BEGIN;

ALTER TABLE callcenter_agent_sessions
    ADD COLUMN IF NOT EXISTS phone_mode VARCHAR(24);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'callcenter_agent_sessions_phone_mode_check'
          AND conrelid = 'callcenter_agent_sessions'::regclass
    ) THEN
        ALTER TABLE callcenter_agent_sessions
            ADD CONSTRAINT callcenter_agent_sessions_phone_mode_check
            CHECK (phone_mode IS NULL OR phone_mode IN ('webrtc', 'softphone', 'desk_phone'));
    END IF;
END
$$;

CREATE INDEX IF NOT EXISTS callcenter_agent_sessions_dept_phone_active_idx
    ON callcenter_agent_sessions (dept_id, phone_mode, started_at DESC)
    WHERE ended_at IS NULL;

INSERT INTO astera_schema_migrations (version)
VALUES ('021_callcenter_agent_phone_mode')
ON CONFLICT (version) DO NOTHING;

COMMIT;
