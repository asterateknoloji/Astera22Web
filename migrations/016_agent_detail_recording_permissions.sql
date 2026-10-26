BEGIN;

INSERT INTO callcenter_permission_definitions
    (permission_code, category, label, description, sort_order)
VALUES
    ('recording_play', 'Kayıt Yetkileri', 'Ses Kaydı Dinleme', 'Yetkili çağrıların ses kayıtlarını tarayıcıda dinler.', 410),
    ('recording_download', 'Kayıt Yetkileri', 'Ses Kaydı İndirme', 'Yetkili çağrıların ses kayıtlarını bilgisayara indirir.', 420),
    ('phone_unmasked', 'Kayıt Yetkileri', 'Telefon Numarasını Açık Görme', 'Raporlarda telefon numarasının tamamını gösterir.', 430)
ON CONFLICT (permission_code) DO UPDATE SET
    category = EXCLUDED.category,
    label = EXCLUDED.label,
    description = EXCLUDED.description,
    sort_order = EXCLUDED.sort_order,
    updated_at = now();

ALTER TABLE callcenter_audit_logs
    DROP CONSTRAINT IF EXISTS callcenter_audit_logs_event_type_check;
ALTER TABLE callcenter_audit_logs
    ADD CONSTRAINT callcenter_audit_logs_event_type_check CHECK (event_type IN (
        'login', 'logout', 'page_view', 'create', 'update',
        'delete', 'access_denied', 'export', 'audio_play', 'audio_download'
    ));

GRANT SELECT ON call_recordings TO "astera-panel";

INSERT INTO astera_schema_migrations (version)
VALUES ('016_agent_detail_recording_permissions')
ON CONFLICT (version) DO NOTHING;

COMMIT;
