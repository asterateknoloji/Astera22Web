BEGIN;

ALTER TABLE callcenter_queue_settings
    ADD COLUMN IF NOT EXISTS missed_call_from_name VARCHAR(160)
        NOT NULL DEFAULT 'Astera Santral ve Callcenter',
    ADD COLUMN IF NOT EXISTS missed_call_subject_template VARCHAR(500)
        NOT NULL DEFAULT '[Astera Santral ve Callcenter] Kaçan çağrı - {kuyruk}',
    ADD COLUMN IF NOT EXISTS missed_call_body_template TEXT
        NOT NULL DEFAULT E'Astera Santral ve Callcenter\n\nKaçan çağrı bildirimi\n\nKuyruk Adı: {kuyruk}\nNumara: {numara}\nTarih / Saat: {tarih} {saat}\nKaçtığı Sıra: {sira}\nBekleme Süresi: {bekleme} sn\nSantral: {santral}';

ALTER TABLE callcenter_queue_settings
    DROP CONSTRAINT IF EXISTS callcenter_queue_settings_missed_from_name_check,
    DROP CONSTRAINT IF EXISTS callcenter_queue_settings_missed_subject_check,
    DROP CONSTRAINT IF EXISTS callcenter_queue_settings_missed_body_check;

ALTER TABLE callcenter_queue_settings
    ADD CONSTRAINT callcenter_queue_settings_missed_from_name_check
        CHECK (char_length(missed_call_from_name) BETWEEN 1 AND 160),
    ADD CONSTRAINT callcenter_queue_settings_missed_subject_check
        CHECK (char_length(missed_call_subject_template) BETWEEN 1 AND 500),
    ADD CONSTRAINT callcenter_queue_settings_missed_body_check
        CHECK (char_length(missed_call_body_template) BETWEEN 1 AND 8000);

INSERT INTO astera_schema_migrations (version)
VALUES ('050_queue_notification_templates')
ON CONFLICT (version) DO NOTHING;

COMMIT;
