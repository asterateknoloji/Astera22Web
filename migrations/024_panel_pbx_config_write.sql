DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'astera-panel') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE
            ON pbx_config_entities, pbx_config_values
            TO "astera-panel";
        GRANT USAGE, SELECT
            ON SEQUENCE pbx_config_values_value_id_seq
            TO "astera-panel";
        GRANT SELECT, INSERT, UPDATE, DELETE
            ON astera_config_store
            TO "astera-panel";
        GRANT SELECT, INSERT
            ON astera_config_audit
            TO "astera-panel";
        GRANT SELECT, INSERT, UPDATE
            ON astera_apply_history
            TO "astera-panel";
        GRANT USAGE, SELECT
            ON SEQUENCE astera_config_audit_id_seq
            TO "astera-panel";
    END IF;
END
$$;
