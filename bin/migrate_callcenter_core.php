<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

foreach ([
    '012_callcenter_core.sql',
    '013_callcenter_default_admin.sql',
    '014_callcenter_permissions.sql',
    '015_callcenter_audit_logs.sql',
    '016_agent_detail_recording_permissions.sql',
    '017_callcenter_surveys.sql',
    '018_callcenter_system_settings.sql',
    '019_callcenter_legacy_survey_import.sql',
    '020_callcenter_survey_call_unique.sql',
    '021_callcenter_agent_phone_mode.sql',
] as $migration) {
    $file = dirname(__DIR__) . '/migrations/' . $migration;
    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException("Çağrı merkezi migration dosyası okunamadı: {$migration}");
    }
    $result = pbx_pg_admin($sql);
    if (!$result['ok']) {
        throw new RuntimeException(
            "Çağrı merkezi şeması uygulanamadı ({$migration}): " . ($result['output'] ?? '')
        );
    }
}
$ok = false;
$rows = pbx_odbc_rows(
    "SELECT count(*)::integer AS table_count FROM information_schema.tables "
    . "WHERE table_schema = 'public' AND table_name IN ("
    . "'callcenter_agents','callcenter_agent_queue_assignments','callcenter_break_types',"
    . "'callcenter_breaks','callcenter_agent_sessions','callcenter_supervisor_users',"
    . "'callcenter_supervisor_queue_scopes','callcenter_supervisor_tenant_scopes',"
    . "'callcenter_permission_definitions','callcenter_supervisor_permissions',"
    . "'callcenter_audit_logs','callcenter_surveys','callcenter_survey_categories',"
    . "'callcenter_survey_questions','callcenter_survey_evaluations',"
    . "'callcenter_survey_answers')",
    $ok
);
if (!$ok || (int) ($rows[0]['table_count'] ?? 0) !== 16) {
    throw new RuntimeException('Çağrı merkezi tabloları doğrulanamadı');
}
echo json_encode(['ok' => true, 'migration' => '021_callcenter_agent_phone_mode', 'tables' => 16],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
