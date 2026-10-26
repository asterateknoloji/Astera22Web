BEGIN;

CREATE TABLE IF NOT EXISTS callcenter_permission_definitions (
    permission_code VARCHAR(64) PRIMARY KEY,
    category VARCHAR(64) NOT NULL,
    label VARCHAR(120) NOT NULL,
    description VARCHAR(240) NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (btrim(permission_code) <> ''),
    CHECK (btrim(category) <> ''),
    CHECK (btrim(label) <> '')
);

CREATE INDEX IF NOT EXISTS callcenter_permission_definitions_category_idx
    ON callcenter_permission_definitions (category, sort_order, permission_code);

CREATE TABLE IF NOT EXISTS callcenter_supervisor_permissions (
    dept_id VARCHAR(64) NOT NULL
        REFERENCES astera_tenants(dept_id) ON UPDATE CASCADE ON DELETE CASCADE,
    supervisor_id BIGINT NOT NULL,
    permission_code VARCHAR(64) NOT NULL
        REFERENCES callcenter_permission_definitions(permission_code)
        ON UPDATE CASCADE ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (dept_id, supervisor_id, permission_code),
    FOREIGN KEY (supervisor_id, dept_id)
        REFERENCES callcenter_supervisor_users(supervisor_id, dept_id)
        ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS callcenter_supervisor_permissions_tenant_idx
    ON callcenter_supervisor_permissions (dept_id, supervisor_id, permission_code);

INSERT INTO callcenter_permission_definitions
    (permission_code, category, label, description, sort_order)
VALUES
    ('dashboard_genel', 'Genel', 'Genel Bakış', 'Ana operasyon özetini görüntüler.', 10),
    ('dashboard_callcenter', 'Genel', 'Callcenter Dashboard', 'Canlı çağrı merkezi dashboardunu görüntüler.', 20),
    ('canli_izleme', 'Genel', 'Canlı İzleme', 'Agent ve dahili durumlarını canlı görüntüler.', 30),
    ('cc_kuyruk_detay', 'Callcenter Raporları', 'Kuyruk Raporları', 'Kuyruk detay, özet ve analiz raporlarını görüntüler.', 110),
    ('cc_agent_detay', 'Callcenter Raporları', 'Agent Detay', 'Agent detay raporunu görüntüler.', 120),
    ('cc_login_logout', 'Callcenter Raporları', 'Login / Logout', 'Agent giriş ve çıkış raporunu görüntüler.', 130),
    ('cc_agent_ozet', 'Callcenter Raporları', 'Agent Özetleri', 'Agent özet raporlarını görüntüler.', 140),
    ('cc_mola_raporlar', 'Callcenter Raporları', 'Mola Raporları', 'Mola detay ve özet raporlarını görüntüler.', 150),
    ('cc_anket', 'Callcenter Raporları', 'Anket Raporları', 'Anket sonuç ve yönetim ekranlarını görüntüler.', 160),
    ('cdr_dahili_ozet', 'CDR Raporları', 'Dahili Özet', 'Dahili bazlı CDR özetlerini görüntüler.', 210),
    ('cdr_detay', 'CDR Raporları', 'CDR Detay', 'Ayrıntılı çağrı kayıtlarını görüntüler.', 220),
    ('cdr_grafik', 'CDR Raporları', 'Çağrı Grafiği', 'Grafik çağrı analizlerini görüntüler.', 230),
    ('cdr_rehber', 'CDR Raporları', 'Rehber', 'CDR rehber ekranını görüntüler.', 240),
    ('sistem_ayarlar', 'Sistem Yönetimi', 'Sistem Tanımları', 'Agent, kuyruk, mola ve program ayarlarını yönetir.', 310),
    ('sistem_kullanicilar', 'Sistem Yönetimi', 'Kullanıcılar', 'Supervisor kullanıcılarını yönetir.', 320),
    ('sistem_yetki', 'Sistem Yönetimi', 'Yetki Grupları', 'Kullanıcıların ayrıntılı yetkilerini yönetir.', 330),
    ('log_rapor', 'Sistem Yönetimi', 'Log Raporu', 'Sistem işlem kayıtlarını görüntüler.', 340)
ON CONFLICT (permission_code) DO UPDATE SET
    category = EXCLUDED.category,
    label = EXCLUDED.label,
    description = EXCLUDED.description,
    sort_order = EXCLUDED.sort_order,
    updated_at = now();

GRANT SELECT ON callcenter_permission_definitions TO asterisk, "astera-panel";
GRANT SELECT, INSERT, UPDATE, DELETE ON callcenter_supervisor_permissions
    TO asterisk, "astera-panel";

INSERT INTO astera_schema_migrations (version)
VALUES ('014_callcenter_permissions')
ON CONFLICT (version) DO NOTHING;

COMMIT;
