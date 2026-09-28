BEGIN;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'astera-panel') THEN
        CREATE ROLE "astera-panel" LOGIN;
    END IF;
END
$$;

GRANT CONNECT ON DATABASE asterisk TO "astera-panel";
GRANT USAGE ON SCHEMA public TO "astera-panel";
GRANT SELECT ON astera_tenants, crm_customers, crm_customer_phones
    TO "astera-panel";
GRANT SELECT ON softphone_crm_tokens TO "astera-panel";
GRANT UPDATE (last_used_at, updated_at) ON softphone_crm_tokens
    TO "astera-panel";

INSERT INTO astera_schema_migrations (version)
VALUES ('008_softphone_crm_api_role')
ON CONFLICT (version) DO NOTHING;

COMMIT;
