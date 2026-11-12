BEGIN;

ALTER TABLE license_entitlements
    DROP CONSTRAINT IF EXISTS license_entitlements_feature_code_check;
ALTER TABLE license_entitlements
    ADD CONSTRAINT license_entitlements_feature_code_check CHECK (feature_code IN (
        'subscribers.extensions',
        'concurrent.external_calls',
        'callcenter.queues',
        'callcenter.ringgroups',
        'callcenter.agents',
        'callcenter.supervisors',
        'callcenter.wallboard',
        'callcenter.monitor',
        'recording.enabled',
        'ivr.enabled',
        'integrations.enabled',
        'ai.enabled',
        'enterprise.multitenant'
    ));

ALTER TABLE license_usage_snapshots
    DROP CONSTRAINT IF EXISTS license_usage_snapshots_feature_code_check;
ALTER TABLE license_usage_snapshots
    ADD CONSTRAINT license_usage_snapshots_feature_code_check CHECK (feature_code IN (
        'subscribers.extensions',
        'concurrent.external_calls',
        'callcenter.queues',
        'callcenter.ringgroups',
        'callcenter.agents',
        'callcenter.supervisors',
        'callcenter.wallboard',
        'callcenter.monitor',
        'recording.enabled',
        'ivr.enabled',
        'integrations.enabled',
        'ai.enabled',
        'enterprise.multitenant'
    ));

DO $$
BEGIN
    IF to_regclass('public.license_issuer_entitlements') IS NOT NULL THEN
        ALTER TABLE license_issuer_entitlements
            DROP CONSTRAINT IF EXISTS license_issuer_entitlements_feature_code_check;
        ALTER TABLE license_issuer_entitlements
            ADD CONSTRAINT license_issuer_entitlements_feature_code_check CHECK (feature_code IN (
                'subscribers.extensions',
                'concurrent.external_calls',
                'callcenter.queues',
                'callcenter.ringgroups',
                'callcenter.agents',
                'callcenter.supervisors',
                'callcenter.wallboard',
                'callcenter.monitor',
                'recording.enabled',
                'ivr.enabled',
                'integrations.enabled',
                'ai.enabled',
                'enterprise.multitenant'
            ));
    END IF;
END
$$;

INSERT INTO astera_schema_migrations (version)
VALUES ('055_ringgroup_licensing')
ON CONFLICT (version) DO NOTHING;

COMMIT;
