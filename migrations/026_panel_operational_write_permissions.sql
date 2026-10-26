BEGIN;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'astera-panel') THEN
        -- Main PBX panel writes tenant ownership and CRM records directly.
        GRANT SELECT, INSERT, UPDATE, DELETE
            ON astera_tenants,
               crm_customers,
               crm_customer_phones,
               crm_call_notes
            TO "astera-panel";

        -- Custom dialplan contexts are managed from the same authenticated panel.
        GRANT SELECT, INSERT, UPDATE, DELETE
            ON pbx_custom_contexts,
               pbx_custom_context_steps
            TO "astera-panel";

        GRANT USAGE, SELECT
            ON SEQUENCE crm_customer_phones_id_seq,
                        pbx_custom_context_steps_step_id_seq
            TO "astera-panel";
    END IF;
END
$$;

INSERT INTO astera_schema_migrations (version)
VALUES ('026_panel_operational_write_permissions')
ON CONFLICT (version) DO NOTHING;

COMMIT;
