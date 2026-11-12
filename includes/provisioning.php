<?php
declare(strict_types=1);

function provisioning_mac(string $value): string
{
    $mac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $value) ?? '');
    return preg_match('/^[0-9A-F]{12}$/D', $mac) === 1 ? $mac : '';
}

function provisioning_devices(?string $deptId = null): array
{
    $where = $deptId !== null ? ' WHERE dept_id = ' . pbx_sql_literal($deptId) : '';
    return pbx_relational_json_rows(
        "SELECT device_id AS id, dept_id AS dept, mac_address AS mac, vendor, model, "
        . "extension_id, display_name, transport, account_slot, template_id, enabled, "
        . "last_generated_at::text, updated_at::text, updated_by "
        . "FROM autoprovision_devices{$where} ORDER BY dept_id, vendor, mac_address"
    );
}

function provisioning_device(string $id): ?array
{
    $id = ast_sanitize_id($id);
    if ($id === '') {
        return null;
    }
    $rows = pbx_relational_json_rows(
        "SELECT device_id AS id, dept_id AS dept, mac_address AS mac, vendor, model, "
        . "extension_id, display_name, transport, account_slot, template_id, enabled, "
        . "last_generated_at::text, updated_at::text, updated_by "
        . "FROM autoprovision_devices WHERE device_id = " . pbx_sql_literal($id) . ' LIMIT 1'
    );
    return $rows[0] ?? null;
}

function provisioning_db_exec(string $sql): void
{
    $result = pbx_odbc_sql($sql);
    if (empty($result['ok']) || str_contains((string) ($result['output'] ?? ''), '[ISQL]ERROR')) {
        throw new RuntimeException('Autoprovision veritabanı işlemi başarısız: ' . (string) ($result['output'] ?? ''));
    }
}

function provisioning_save_device(array $device): void
{
    provisioning_db_exec(
        'INSERT INTO autoprovision_devices '
        . '(device_id,dept_id,mac_address,vendor,model,extension_id,display_name,transport,account_slot,template_id,enabled,updated_at,updated_by) VALUES ('
        . pbx_sql_literal((string) $device['id']) . ','
        . pbx_sql_literal((string) $device['dept']) . ','
        . pbx_sql_literal((string) $device['mac']) . ','
        . pbx_sql_literal((string) $device['vendor']) . ','
        . pbx_sql_literal((string) $device['model']) . ','
        . pbx_sql_literal((string) $device['extension_id']) . ','
        . pbx_sql_literal((string) $device['display_name']) . ','
        . pbx_sql_literal((string) $device['transport']) . ','
        . (int) $device['account_slot'] . ','
        . pbx_sql_literal((string) $device['template_id']) . ','
        . (!empty($device['enabled']) ? 'TRUE' : 'FALSE') . ',now(),'
        . pbx_sql_literal(store_actor()) . ') '
        . 'ON CONFLICT (device_id) DO UPDATE SET '
        . 'dept_id=EXCLUDED.dept_id,mac_address=EXCLUDED.mac_address,vendor=EXCLUDED.vendor,'
        . 'model=EXCLUDED.model,extension_id=EXCLUDED.extension_id,display_name=EXCLUDED.display_name,'
        . 'transport=EXCLUDED.transport,account_slot=EXCLUDED.account_slot,template_id=EXCLUDED.template_id,'
        . 'enabled=EXCLUDED.enabled,'
        . 'updated_at=now(),updated_by=EXCLUDED.updated_by'
    );
}

function provisioning_delete_device(string $id): void
{
    provisioning_db_exec(
        'DELETE FROM autoprovision_devices WHERE device_id = ' . pbx_sql_literal($id)
    );
}

function provisioning_builtin_templates(): array
{
    return [
        'yealink-t46s' => [
            'id' => 'yealink-t46s', 'dept' => '', 'name' => 'Yealink T46S',
            'vendor' => 'yealink', 'model' => 'SIP-T46S', 'account_count' => 16,
            'key_count' => 27, 'common_file' => 'y000000000066.cfg',
            'config_body' => '', 'builtin' => true,
        ],
        'yealink-t46u' => [
            'id' => 'yealink-t46u', 'dept' => '', 'name' => 'Yealink T46U',
            'vendor' => 'yealink', 'model' => 'SIP-T46U', 'account_count' => 16,
            'key_count' => 27, 'common_file' => 'y000000000108.cfg',
            'config_body' => '', 'builtin' => true,
        ],
        'yealink-t46g' => [
            'id' => 'yealink-t46g', 'dept' => '', 'name' => 'Yealink T46G',
            'vendor' => 'yealink', 'model' => 'SIP-T46G', 'account_count' => 16,
            'key_count' => 27, 'common_file' => 'y000000000028.cfg',
            'config_body' => '', 'builtin' => true,
        ],
        'yealink-t43u' => [
            'id' => 'yealink-t43u', 'dept' => '', 'name' => 'Yealink T43U',
            'vendor' => 'yealink', 'model' => 'SIP-T43U', 'account_count' => 12,
            'key_count' => 21, 'common_file' => 'y000000000107.cfg',
            'config_body' => '', 'builtin' => true,
        ],
        'yealink-t48u' => [
            'id' => 'yealink-t48u', 'dept' => '', 'name' => 'Yealink T48U',
            'vendor' => 'yealink', 'model' => 'SIP-T48U', 'account_count' => 16,
            'key_count' => 29, 'common_file' => 'y000000000109.cfg',
            'config_body' => '', 'builtin' => true,
        ],
        'yealink-t48s' => [
            'id' => 'yealink-t48s', 'dept' => '', 'name' => 'Yealink T48S',
            'vendor' => 'yealink', 'model' => 'SIP-T48S', 'account_count' => 16,
            'key_count' => 29, 'common_file' => 'y000000000065.cfg',
            'config_body' => '', 'builtin' => true,
        ],
        'yealink-t48g' => [
            'id' => 'yealink-t48g', 'dept' => '', 'name' => 'Yealink T48G',
            'vendor' => 'yealink', 'model' => 'SIP-T48G', 'account_count' => 16,
            'key_count' => 29, 'common_file' => 'y000000000035.cfg',
            'config_body' => '', 'builtin' => true,
        ],
        'yealink-t54w' => [
            'id' => 'yealink-t54w', 'dept' => '', 'name' => 'Yealink T54W',
            'vendor' => 'yealink', 'model' => 'SIP-T54W', 'account_count' => 16,
            'key_count' => 27, 'common_file' => 'y000000000096.cfg',
            'config_body' => '', 'builtin' => true,
        ],
        'yealink-t57w' => [
            'id' => 'yealink-t57w', 'dept' => '', 'name' => 'Yealink T57W',
            'vendor' => 'yealink', 'model' => 'SIP-T57W', 'account_count' => 16,
            'key_count' => 29, 'common_file' => 'y000000000097.cfg',
            'config_body' => '', 'builtin' => true,
        ],
        'yealink-t53w' => [
            'id' => 'yealink-t53w', 'dept' => '', 'name' => 'Yealink T53W / T53',
            'vendor' => 'yealink', 'model' => 'SIP-T53W', 'account_count' => 12,
            'key_count' => 21, 'common_file' => 'y000000000095.cfg',
            'config_body' => '', 'builtin' => true,
        ],
        'yealink-t42u' => [
            'id' => 'yealink-t42u', 'dept' => '', 'name' => 'Yealink T42U',
            'vendor' => 'yealink', 'model' => 'SIP-T42U', 'account_count' => 12,
            'key_count' => 15, 'common_file' => 'y000000000116.cfg',
            'config_body' => '', 'builtin' => true,
        ],
        'yealink-t42s' => [
            'id' => 'yealink-t42s', 'dept' => '', 'name' => 'Yealink T42S',
            'vendor' => 'yealink', 'model' => 'SIP-T42S', 'account_count' => 12,
            'key_count' => 15, 'common_file' => 'y000000000067.cfg',
            'config_body' => '', 'builtin' => true,
        ],
        'yealink-t41s' => [
            'id' => 'yealink-t41s', 'dept' => '', 'name' => 'Yealink T41S',
            'vendor' => 'yealink', 'model' => 'SIP-T41S', 'account_count' => 12,
            'key_count' => 15, 'common_file' => 'y000000000068.cfg',
            'config_body' => '', 'builtin' => true,
        ],
        'yealink-t33g' => [
            'id' => 'yealink-t33g', 'dept' => '', 'name' => 'Yealink T33G / T33P',
            'vendor' => 'yealink', 'model' => 'SIP-T33G', 'account_count' => 4,
            'key_count' => 12, 'common_file' => 'y000000000124.cfg',
            'config_body' => '', 'builtin' => true,
        ],
        'yealink-t40g' => [
            'id' => 'yealink-t40g', 'dept' => '', 'name' => 'Yealink T40G',
            'vendor' => 'yealink', 'model' => 'SIP-T40G', 'account_count' => 3,
            'key_count' => 9, 'common_file' => 'y000000000076.cfg',
            'config_body' => '', 'builtin' => true,
        ],
        'yealink-t29g' => [
            'id' => 'yealink-t29g', 'dept' => '', 'name' => 'Yealink T29G',
            'vendor' => 'yealink', 'model' => 'SIP-T29G', 'account_count' => 16,
            'key_count' => 27, 'common_file' => 'y000000000046.cfg',
            'config_body' => '', 'builtin' => true,
        ],
        'yealink-t27g' => [
            'id' => 'yealink-t27g', 'dept' => '', 'name' => 'Yealink T27G',
            'vendor' => 'yealink', 'model' => 'SIP-T27G', 'account_count' => 6,
            'key_count' => 21, 'common_file' => 'y000000000069.cfg',
            'config_body' => '', 'builtin' => true,
        ],
        'yealink-t23g' => [
            'id' => 'yealink-t23g', 'dept' => '', 'name' => 'Yealink T23G / T23P',
            'vendor' => 'yealink', 'model' => 'SIP-T23G', 'account_count' => 3,
            'key_count' => 3, 'common_file' => 'y000000000044.cfg',
            'config_body' => '', 'builtin' => true,
        ],
        'yealink-generic' => [
            'id' => 'yealink-generic', 'dept' => '', 'name' => 'Yealink Genel',
            'vendor' => 'yealink', 'model' => '', 'account_count' => 16,
            'key_count' => 27, 'config_body' => '', 'builtin' => true,
        ],
        'grandstream-generic' => [
            'id' => 'grandstream-generic', 'dept' => '', 'name' => 'Grandstream Genel',
            'vendor' => 'grandstream', 'model' => '', 'account_count' => 1,
            'key_count' => 0, 'config_body' => '', 'builtin' => true,
        ],
        'grandstream-grp2612' => [
            'id' => 'grandstream-grp2612', 'dept' => '', 'name' => 'Grandstream GRP2612',
            'vendor' => 'grandstream', 'model' => 'GRP2612', 'account_count' => 1,
            'key_count' => 0, 'config_body' => '', 'builtin' => true,
        ],
        'grandstream-grp2614' => [
            'id' => 'grandstream-grp2614', 'dept' => '', 'name' => 'Grandstream GRP2614',
            'vendor' => 'grandstream', 'model' => 'GRP2614', 'account_count' => 1,
            'key_count' => 0, 'config_body' => '', 'builtin' => true,
        ],
        'grandstream-grp2615' => [
            'id' => 'grandstream-grp2615', 'dept' => '', 'name' => 'Grandstream GRP2615',
            'vendor' => 'grandstream', 'model' => 'GRP2615', 'account_count' => 1,
            'key_count' => 0, 'config_body' => '', 'builtin' => true,
        ],
        'grandstream-grp2616' => [
            'id' => 'grandstream-grp2616', 'dept' => '', 'name' => 'Grandstream GRP2616',
            'vendor' => 'grandstream', 'model' => 'GRP2616', 'account_count' => 1,
            'key_count' => 0, 'config_body' => '', 'builtin' => true,
        ],
        'gigaset-generic' => [
            'id' => 'gigaset-generic', 'dept' => '', 'name' => 'Gigaset Genel',
            'vendor' => 'gigaset', 'model' => '', 'account_count' => 1,
            'key_count' => 0, 'config_body' => '', 'builtin' => true,
        ],
        'gigaset-n510' => [
            'id' => 'gigaset-n510', 'dept' => '', 'name' => 'Gigaset N510 IP PRO',
            'vendor' => 'gigaset', 'model' => 'N510 IP PRO', 'account_count' => 1,
            'key_count' => 0, 'config_body' => '', 'builtin' => true,
        ],
        'gigaset-n610' => [
            'id' => 'gigaset-n610', 'dept' => '', 'name' => 'Gigaset N610 IP PRO Baz',
            'vendor' => 'gigaset', 'model' => 'N610 IP PRO', 'account_count' => 1,
            'key_count' => 0, 'config_body' => '', 'builtin' => true, 'profile_kind' => 'gigaset-nx70',
        ],
        'gigaset-n670' => [
            'id' => 'gigaset-n670', 'dept' => '', 'name' => 'Gigaset N670 IP PRO Baz',
            'vendor' => 'gigaset', 'model' => 'N670 IP PRO', 'account_count' => 1,
            'key_count' => 0, 'config_body' => '', 'builtin' => true, 'profile_kind' => 'gigaset-nx70',
        ],
        'gigaset-n770' => [
            'id' => 'gigaset-n770', 'dept' => '', 'name' => 'Gigaset N770 IP PRO Baz',
            'vendor' => 'gigaset', 'model' => 'N770 IP PRO', 'account_count' => 1,
            'key_count' => 0, 'config_body' => '', 'builtin' => true, 'profile_kind' => 'gigaset-nx70',
        ],
        'gigaset-n870' => [
            'id' => 'gigaset-n870', 'dept' => '', 'name' => 'Gigaset N870 / N870E IP PRO Baz',
            'vendor' => 'gigaset', 'model' => 'N870 IP PRO', 'account_count' => 1,
            'key_count' => 0, 'config_body' => '', 'builtin' => true, 'profile_kind' => 'gigaset-nx70',
        ],
    ];
}

function provisioning_templates(?string $deptId): array
{
    $templates = array_values(provisioning_builtin_templates());
    $where = $deptId !== null && $deptId !== ''
        ? ' WHERE dept_id = ' . pbx_sql_literal($deptId)
        : '';
    $rows = pbx_relational_json_rows(
        'SELECT template_id AS id, dept_id AS dept, name, vendor, model, account_count, '
        . "key_count, config_body, FALSE AS builtin, updated_at::text, updated_by "
        . 'FROM autoprovision_templates' . $where
        . ' ORDER BY vendor, name'
    );
    return array_merge($templates, $rows);
}

function provisioning_template(string $id, string $deptId): ?array
{
    $builtins = provisioning_builtin_templates();
    if (isset($builtins[$id])) {
        return $builtins[$id];
    }
    foreach (provisioning_templates($deptId) as $template) {
        if ((string) $template['id'] === $id && (string) ($template['dept'] ?? '') === $deptId) {
            return $template;
        }
    }
    return null;
}

function provisioning_save_template(array $template): void
{
    provisioning_db_exec(
        'INSERT INTO autoprovision_templates '
        . '(template_id,dept_id,name,vendor,model,account_count,key_count,config_body,updated_at,updated_by) VALUES ('
        . pbx_sql_literal((string) $template['id']) . ','
        . pbx_sql_literal((string) $template['dept']) . ','
        . pbx_sql_literal((string) $template['name']) . ','
        . pbx_sql_literal((string) $template['vendor']) . ','
        . pbx_sql_literal((string) $template['model']) . ','
        . (int) $template['account_count'] . ','
        . (int) $template['key_count'] . ','
        . pbx_sql_literal((string) $template['config_body']) . ',now(),'
        . pbx_sql_literal(store_actor()) . ') '
        . 'ON CONFLICT (template_id) DO UPDATE SET '
        . 'name=EXCLUDED.name,vendor=EXCLUDED.vendor,model=EXCLUDED.model,'
        . 'account_count=EXCLUDED.account_count,key_count=EXCLUDED.key_count,'
        . 'config_body=EXCLUDED.config_body,updated_at=now(),updated_by=EXCLUDED.updated_by '
        . 'WHERE autoprovision_templates.dept_id=EXCLUDED.dept_id'
    );
}

function provisioning_delete_template(string $id, string $deptId): void
{
    provisioning_db_exec(
        'BEGIN; UPDATE autoprovision_devices SET template_id=' . pbx_sql_literal('')
        . ' WHERE dept_id=' . pbx_sql_literal($deptId)
        . ' AND template_id=' . pbx_sql_literal($id) . '; '
        . 'DELETE FROM autoprovision_templates WHERE dept_id=' . pbx_sql_literal($deptId)
        . ' AND template_id=' . pbx_sql_literal($id) . '; COMMIT;'
    );
}

function provisioning_device_keys(string $deviceId, string $deptId): array
{
    return pbx_relational_json_rows(
        'SELECT device_key_id AS id, dept_id AS dept, device_id, key_index, key_type, '
        . 'label, key_value, extension, account_slot, updated_at::text, updated_by '
        . 'FROM autoprovision_device_keys WHERE dept_id=' . pbx_sql_literal($deptId)
        . ' AND device_id=' . pbx_sql_literal($deviceId) . ' ORDER BY key_index'
    );
}

function provisioning_save_device_keys(string $deviceId, string $deptId, array $keys): void
{
    $sql = 'BEGIN; DELETE FROM autoprovision_device_keys WHERE dept_id=' . pbx_sql_literal($deptId)
        . ' AND device_id=' . pbx_sql_literal($deviceId) . ';';
    foreach ($keys as $key) {
        $index = (int) ($key['key_index'] ?? 0);
        $type = (string) ($key['key_type'] ?? 'none');
        if ($index < 1 || $index > 120 || $type === 'none') {
            continue;
        }
        $sql .= 'INSERT INTO autoprovision_device_keys '
            . '(device_key_id,dept_id,device_id,key_index,key_type,label,key_value,extension,account_slot,updated_at,updated_by) VALUES ('
            . pbx_sql_literal($deviceId . '-key-' . $index) . ','
            . pbx_sql_literal($deptId) . ','
            . pbx_sql_literal($deviceId) . ','
            . $index . ','
            . pbx_sql_literal($type) . ','
            . pbx_sql_literal(substr(trim((string) ($key['label'] ?? '')), 0, 80)) . ','
            . pbx_sql_literal(substr(trim((string) ($key['key_value'] ?? '')), 0, 160)) . ','
            . pbx_sql_literal(substr(trim((string) ($key['extension'] ?? '')), 0, 80)) . ','
            . max(1, min(32, (int) ($key['account_slot'] ?? 1))) . ',now(),'
            . pbx_sql_literal(store_actor()) . ');';
    }
    provisioning_db_exec($sql . 'COMMIT;');
}

function provisioning_settings(string $deptId): array
{
    $rows = pbx_relational_json_rows(
        'SELECT dept_id AS dept, schedule_enabled, schedule_frequency, schedule_weekday, '
        . "to_char(schedule_time, 'HH24:MI') AS schedule_time, window_minutes, "
        . 'updated_at::text, updated_by FROM autoprovision_settings '
        . 'WHERE dept_id=' . pbx_sql_literal($deptId) . ' LIMIT 1'
    );
    return $rows[0] ?? [
        'dept' => $deptId,
        'schedule_enabled' => true,
        'schedule_frequency' => 'daily',
        'schedule_weekday' => 0,
        'schedule_time' => '03:00',
        'window_minutes' => 60,
    ];
}

function provisioning_save_settings(array $settings): void
{
    provisioning_db_exec(
        'INSERT INTO autoprovision_settings '
        . '(dept_id,schedule_enabled,schedule_frequency,schedule_weekday,schedule_time,window_minutes,updated_at,updated_by) VALUES ('
        . pbx_sql_literal((string) $settings['dept']) . ','
        . (!empty($settings['schedule_enabled']) ? 'TRUE' : 'FALSE') . ','
        . pbx_sql_literal((string) $settings['schedule_frequency']) . ','
        . (int) $settings['schedule_weekday'] . ','
        . pbx_sql_literal((string) $settings['schedule_time']) . '::time,'
        . (int) $settings['window_minutes'] . ',now(),'
        . pbx_sql_literal(store_actor()) . ') '
        . 'ON CONFLICT (dept_id) DO UPDATE SET '
        . 'schedule_enabled=EXCLUDED.schedule_enabled,schedule_frequency=EXCLUDED.schedule_frequency,'
        . 'schedule_weekday=EXCLUDED.schedule_weekday,schedule_time=EXCLUDED.schedule_time,'
        . 'window_minutes=EXCLUDED.window_minutes,updated_at=now(),updated_by=EXCLUDED.updated_by'
    );
}

function provisioning_schedule_window(array $settings): array
{
    $start = preg_match('/^\d{2}:\d{2}$/D', (string) ($settings['schedule_time'] ?? ''))
        ? (string) $settings['schedule_time']
        : '03:00';
    $date = DateTimeImmutable::createFromFormat('!H:i', $start) ?: new DateTimeImmutable('03:00');
    $minutes = max(15, min(360, (int) ($settings['window_minutes'] ?? 60)));
    return [$start, $date->modify("+{$minutes} minutes")->format('H:i')];
}

function provisioning_yealink_schedule(array $settings): string
{
    if (empty($settings['schedule_enabled'])) {
        return "static.auto_provision.weekly.enable = 0\n";
    }
    [$start, $end] = provisioning_schedule_window($settings);
    $days = (string) ($settings['schedule_frequency'] ?? 'daily') === 'weekly'
        ? (string) max(0, min(6, (int) ($settings['schedule_weekday'] ?? 0)))
        : '0123456';
    return "static.auto_provision.weekly.enable = 1\n"
        . "static.auto_provision.weekly_upgrade_interval = 0\n"
        . "static.auto_provision.weekly.dayofweek = {$days}\n"
        . "static.auto_provision.weekly.begin_time = {$start}\n"
        . "static.auto_provision.weekly.end_time = {$end}\n";
}

function provisioning_grandstream_schedule(array $settings): array
{
    if (empty($settings['schedule_enabled'])) {
        return ['P194' => '0'];
    }
    [$start] = provisioning_schedule_window($settings);
    $hour = (string) (int) substr($start, 0, 2);
    if ((string) ($settings['schedule_frequency'] ?? 'daily') === 'weekly') {
        return [
            'P194' => '2',
            'P285' => $hour,
            'P286' => (string) max(0, min(6, (int) ($settings['schedule_weekday'] ?? 0))),
        ];
    }
    return ['P194' => '1', 'P285' => $hour];
}

function provisioning_gigaset_schedule(array $settings): string
{
    if (empty($settings['schedule_enabled'])) {
        return '<SYMB_ITEM ID="BS_IP_Data1.ucB_AUTO_UPDATE_PROFILE" class="symb_item" value="0x0"/>' . "\n";
    }
    [$start, $end] = provisioning_schedule_window($settings);
    $interval = (string) ($settings['schedule_frequency'] ?? 'daily') === 'weekly' ? 10080 : 0;
    return '<SYMB_ITEM ID="BS_IP_Data1.ucB_AUTO_UPDATE_PROFILE" class="symb_item" value="0x1"/>' . "\n"
        . '<SYMB_ITEM ID="BS_IP_Data1.uiI_CHECK_FOR_UPDATES_TIMER_INIT" class="symb_item" value="'
        . $interval . "\"/>\n"
        . '<SYMB_ITEM ID="BS_IP_Data1.aucCheckForUpdatesTimeFrom" class="symb_item" value="'
        . provisioning_xml('"' . $start . '"') . "\"/>\n"
        . '<SYMB_ITEM ID="BS_IP_Data1.aucCheckForUpdatesTimeUntil" class="symb_item" value="'
        . provisioning_xml('"' . $end . '"') . "\"/>\n";
}

function provisioning_xml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function provisioning_display_label(array $device, array $extension, string $sip): string
{
    $extensionNumber = trim((string) ($extension['exten'] ?? $sip));
    $name = trim((string) ($device['display_name'] ?: $extension['name'] ?? ''));
    if ($name === '' || $name === $extensionNumber) {
        return $extensionNumber;
    }
    return $extensionNumber . ' - ' . $name;
}

function provisioning_cfg_value(string $value): string
{
    return trim(str_replace(["\r", "\n"], ' ', $value));
}

function provisioning_yealink_keys(array $device, array $template): string
{
    $keyCount = max(0, min(120, (int) ($template['key_count'] ?? 0)));
    if ($keyCount === 0) {
        return '';
    }
    $saved = [];
    foreach (provisioning_device_keys((string) $device['id'], (string) $device['dept']) as $key) {
        $saved[(int) $key['key_index']] = $key;
    }
    $types = [
        'line' => 15,
        'blf' => 16,
        'speed_dial' => 13,
        'transfer' => 3,
        'park' => 10,
        'dtmf' => 11,
        'url' => 17,
    ];
    $output = '';
    foreach ($saved as $index => $key) {
        if ($index < 1 || $index > $keyCount) {
            continue;
        }
        $type = (string) $key['key_type'];
        if (!isset($types[$type])) {
            continue;
        }
        $output .= "linekey.{$index}.type = {$types[$type]}\n";
        $value = provisioning_cfg_value((string) ($key['key_value'] ?: $key['extension'] ?? ''));
        $label = provisioning_cfg_value((string) ($key['label'] ?: $value));
        $line = max(1, min(16, (int) ($key['account_slot'] ?? $device['account_slot'] ?? 1)));
        $output .= "linekey.{$index}.line = {$line}\n"
            . "linekey.{$index}.label = {$label}\n"
            . "linekey.{$index}.value = {$value}\n";
        if ($type === 'blf') {
            $extension = provisioning_cfg_value((string) ($key['extension'] ?: $value));
            $output .= "linekey.{$index}.extension = {$extension}\n";
        }
    }
    return $output;
}

function provisioning_custom_body(array $template, array $device, array $extension): string
{
    $body = trim((string) ($template['config_body'] ?? ''));
    if ($body === '') {
        return '';
    }
    $replace = [
        '{{pbx_host}}' => PBX_HOST,
        '{{account_slot}}' => (string) ($device['account_slot'] ?? 1),
        '{{extension}}' => (string) ($extension['exten'] ?? ''),
        '{{sip_user}}' => sip_user($extension),
        '{{auth_user}}' => auth_user($extension),
        '{{display_name}}' => provisioning_display_label($device, $extension, sip_user($extension)),
        '{{mac}}' => (string) ($device['mac'] ?? ''),
    ];
    return strtr($body, $replace) . "\n";
}

function provisioning_yealink(array $device, array $extension): string
{
    $transport = ['udp' => 0, 'tcp' => 1, 'tls' => 2][(string) $device['transport']] ?? 0;
    $slot = max(1, min(16, (int) ($device['account_slot'] ?? 1)));
    $template = provisioning_template((string) ($device['template_id'] ?? ''), (string) $device['dept'])
        ?? provisioning_builtin_templates()['yealink-generic'];
    $settings = provisioning_settings((string) $device['dept']);
    $sip = sip_user($extension);
    $auth = auth_user($extension);
    $label = provisioning_display_label($device, $extension, $sip);
    $port = $transport === 2 ? 5061 : 5060;
    return "#!version:1.0.0.1\n"
        . "account.{$slot}.enable = 1\n"
        . "account.{$slot}.label = {$label}\n"
        . "account.{$slot}.display_name = {$label}\n"
        . "account.{$slot}.user_name = {$sip}\n"
        . "account.{$slot}.auth_name = {$auth}\n"
        . "account.{$slot}.password = " . (string) ($extension['password'] ?? '') . "\n"
        . "account.{$slot}.sip_server.1.address = " . PBX_HOST . "\n"
        . "account.{$slot}.sip_server.1.port = {$port}\n"
        . "account.{$slot}.sip_server.1.transport_type = {$transport}\n"
        . "account.{$slot}.sip_server.1.expires = 3600\n"
        . "account.{$slot}.outbound_proxy_enable = 0\n"
        . "local_time.time_zone = +3\n"
        . "local_time.time_zone_name = Turkey\n"
        . provisioning_yealink_schedule($settings)
        . provisioning_yealink_keys($device, $template)
        . provisioning_custom_body($template, $device, $extension);
}

function provisioning_grandstream(array $device, array $extension): string
{
    $transport = ['udp' => 0, 'tcp' => 1, 'tls' => 2][(string) $device['transport']] ?? 0;
    $sip = sip_user($extension);
    $auth = auth_user($extension);
    $label = provisioning_display_label($device, $extension, $sip);
    $settings = provisioning_settings((string) $device['dept']);
    $template = provisioning_template((string) ($device['template_id'] ?? ''), (string) $device['dept'])
        ?? provisioning_builtin_templates()['grandstream-generic'];
    $values = [
        'P271' => '1',
        'P270' => $label,
        'P47' => PBX_HOST,
        'P35' => $sip,
        'P36' => $auth,
        'P34' => (string) ($extension['password'] ?? ''),
        'P3' => $label,
        'P130' => (string) $transport,
        'P64' => 'TUR-3',
    ];
    $values = array_merge($values, provisioning_grandstream_schedule($settings));
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
        . "<gs_provision version=\"1\">\n"
        . '  <mac>' . strtolower((string) $device['mac']) . "</mac>\n"
        . "  <config version=\"1\">\n";
    foreach ($values as $key => $value) {
        $xml .= '    <' . $key . '>' . provisioning_xml($value) . '</' . $key . ">\n";
    }
    $custom = provisioning_custom_body($template, $device, $extension);
    return $xml . ($custom !== '' ? $custom : '') . "  </config>\n</gs_provision>\n";
}

function provisioning_gigaset_nx70(array $device, array $extension, array $template): string
{
    $settings = provisioning_settings((string) $device['dept']);
    $period = empty($settings['schedule_enabled'])
        ? 0
        : ((string) ($settings['schedule_frequency'] ?? 'daily') === 'weekly' ? 604800 : 86400);
    $transport = ['udp' => 0, 'tcp' => 1, 'tls' => 2][(string) $device['transport']] ?? 0;
    $custom = provisioning_custom_body($template, $device, $extension);
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
        . "<provisioning version=\"1.1\" productID=\"e2\">\n<nvm>\n"
        . '  <param name="SipProvider.0.Name" value="Astera PBX"/>' . "\n"
        . '  <param name="SipProvider.0.PhoneSystem" value="1"/>' . "\n"
        . '  <param name="SipProvider.0.Domain" value="' . provisioning_xml(PBX_HOST) . "\"/>\n"
        . '  <param name="SipProvider.0.ProxyServerAddress" value="' . provisioning_xml(PBX_HOST) . "\"/>\n"
        . '  <param name="SipProvider.0.ProxyServerPort" value="5060"/>' . "\n"
        . '  <param name="SipProvider.0.RegServerAddress" value="' . provisioning_xml(PBX_HOST) . "\"/>\n"
        . '  <param name="SipProvider.0.RegServerPort" value="5060"/>' . "\n"
        . '  <param name="SipProvider.0.TransportProtocol" value="' . $transport . "\"/>\n"
        . '  <param name="Provisioning.global.ProvisioningServer" value="http://'
        . provisioning_xml(PBX_HOST) . '/provision/%MACD.xml"/>' . "\n"
        . '  <param name="Provisioning.global.Period" value="' . $period . "\"/>\n"
        . ($custom !== '' ? $custom : '')
        . "</nvm>\n</provisioning>\n";
}

function provisioning_gigaset(array $device, array $extension): string
{
    $template = provisioning_template((string) ($device['template_id'] ?? ''), (string) $device['dept'])
        ?? provisioning_builtin_templates()['gigaset-generic'];
    if (
        (string) ($template['profile_kind'] ?? '') === 'gigaset-nx70'
        || preg_match('/^N(?:610|670|770|870)/i', (string) ($template['model'] ?? '')) === 1
    ) {
        return provisioning_gigaset_nx70($device, $extension, $template);
    }
    $sip = sip_user($extension);
    $auth = auth_user($extension);
    $password = (string) ($extension['password'] ?? '');
    $label = provisioning_display_label($device, $extension, $sip);
    $settings = provisioning_settings((string) $device['dept']);
    $quoted = static fn(string $value): string => provisioning_xml('"' . $value . '"');
    return '<MAC_ADDRESS value="' . provisioning_xml((string) $device['mac']) . "\"/>\n"
        . '<PROFILE_NAME class="string" value="ASTERA"/>' . "\n"
        . '<SYMB_ITEM ID="BS_IP_Data1.aucS_SIP_DOMAIN" class="symb_item" value="' . $quoted(PBX_HOST) . "\"/>\n"
        . '<SYMB_ITEM ID="BS_IP_Data1.ucB_SIP_ACCOUNT_IS_ACTIVE_1" class="symb_item" value="1"/>' . "\n"
        . '<SYMB_ITEM ID="BS_IP_Data3.aucS_SIP_LOGIN_ID" class="symb_item" value="' . $quoted($auth) . "\"/>\n"
        . '<SYMB_ITEM ID="BS_IP_Data1.aucS_SIP_REGISTRAR" class="symb_item" value="' . $quoted(PBX_HOST) . "\"/>\n"
        . '<SYMB_ITEM ID="BS_IP_Data1.aucS_SIP_USER_ID" class="symb_item" value="' . $quoted($sip) . "\"/>\n"
        . '<SYMB_ITEM ID="BS_IP_Data1.aucS_SIP_PASSWORD" class="symb_item" value="' . $quoted($password) . "\"/>\n"
        . '<SYMB_ITEM ID="BS_Accounts.astAccounts[0].aucAccountName[0]" class="symb_item" value="' . $quoted($label) . "\"/>\n"
        . '<SYMB_ITEM ID="BS_IP_Data1.aucS_SIP_SERVER" class="symb_item" value="' . $quoted(PBX_HOST) . "\"/>\n"
        . '<SYMB_ITEM ID="BS_IP_Data1.aucS_SIP_DISPLAYNAME" class="symb_item" value="' . $quoted($label) . "\"/>\n"
        . provisioning_gigaset_schedule($settings)
        . '<SYMB_ITEM ID="BS_IP_Data3.ucI_ONESHOT_PROVISIONING_MODE_1" class="symb_item" value="0x1"/>' . "\n";
}

function provisioning_file_names(array $device): array
{
    $macLower = strtolower((string) $device['mac']);
    $macUpper = strtoupper((string) $device['mac']);
    return match ((string) $device['vendor']) {
        'yealink' => [$macLower . '.cfg'],
        'grandstream' => ['cfg' . $macLower . '.xml'],
        'gigaset' => [$macUpper . '.xml'],
        default => [],
    };
}

function provisioning_publish_all(): int
{
    $files = [];
    $generatedIds = [];
    foreach (provisioning_devices() as $device) {
        if (empty($device['enabled'])) {
            continue;
        }
        $extension = find_by('extensions', 'id', (string) $device['extension_id']);
        if (!$extension || (string) ($extension['dept'] ?? '') !== (string) $device['dept']) {
            continue;
        }
        $contents = match ((string) $device['vendor']) {
            'yealink' => provisioning_yealink($device, $extension),
            'grandstream' => provisioning_grandstream($device, $extension),
            'gigaset' => provisioning_gigaset($device, $extension),
            default => '',
        };
        foreach (provisioning_file_names($device) as $name) {
            $files[$name] = $contents;
        }
        $generatedIds[] = (string) $device['id'];
    }

    $localScript = tempnam(sys_get_temp_dir(), 'astera-provision-');
    if ($localScript === false) {
        throw new RuntimeException('Provision geçici dosyası oluşturulamadı');
    }
    $remoteScript = '/tmp/astera-provision-' . bin2hex(random_bytes(6)) . '.sh';
    $script = "#!/bin/bash\nset -e\n"
        . "install -d -o root -g www-data -m 0750 /var/lib/astera/provisioning\n"
        . "find /var/lib/astera/provisioning -maxdepth 1 -type f -delete\n";
    foreach ($files as $name => $contents) {
        $path = '/var/lib/astera/provisioning/' . $name;
        $script .= 'printf %s ' . escapeshellarg(base64_encode($contents))
            . ' | base64 -d > ' . escapeshellarg($path) . "\n"
            . 'chown root:www-data ' . escapeshellarg($path) . "\n"
            . 'chmod 0640 ' . escapeshellarg($path) . "\n";
    }
    file_put_contents($localScript, $script);
    $upload = pbx_upload($localScript, $remoteScript);
    @unlink($localScript);
    if (empty($upload['ok'])) {
        throw new RuntimeException('Provision dosyaları sunucuya aktarılamadı: ' . (string) ($upload['output'] ?? ''));
    }
    $result = pbx_ssh(
        'bash ' . escapeshellarg($remoteScript)
        . '; code=$?; rm -f ' . escapeshellarg($remoteScript) . '; exit $code'
    );
    if (empty($result['ok'])) {
        throw new RuntimeException('Provision dosyaları yayınlanamadı: ' . (string) ($result['output'] ?? ''));
    }
    if ($generatedIds) {
        provisioning_db_exec(
            'UPDATE autoprovision_devices SET last_generated_at=now() WHERE device_id IN ('
            . implode(',', array_map('pbx_sql_literal', $generatedIds)) . ')'
        );
    }
    return count($files);
}
