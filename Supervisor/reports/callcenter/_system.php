<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
$route = basename($_SERVER['SCRIPT_FILENAME'] ?? '', '.php');
$titles = ['agent_tanim' => 'Agent Tanım', 'kuyruklistesi' => 'Kuyruk Listesi', 'mola_tanim' => 'Mola Tanım', 'kullanicilar' => 'Kullanıcılar'];
$title = $titles[$route] ?? 'Sistem Tanımları';
$message = '';
$error = '';

function supervisor_code_part(string $value): string
{
    $value = mb_strtoupper(trim($value), 'UTF-8');
    $value = strtr($value, [
        'Ç' => 'C', 'Ğ' => 'G', 'İ' => 'I',
        'Ö' => 'O', 'Ş' => 'S', 'Ü' => 'U',
    ]);
    return trim(preg_replace('/[^A-Z0-9]+/', '_', $value) ?? '', '_');
}

function supervisor_break_code_prefix(string $deptId): string
{
    $companyName = $deptId;
    foreach (departments() as $department) {
        if ((string) ($department['id'] ?? '') === $deptId) {
            $companyName = (string) ($department['name'] ?? $deptId);
            break;
        }
    }
    $company = supervisor_code_part($companyName);
    $department = supervisor_code_part($deptId);
    return ($company !== '' ? $company : 'FIRMA') . '_' . ($department !== '' ? $department : 'GENEL');
}

function supervisor_render_permission_picker(array $definitions, array $selected = []): void
{
    $groups = [];
    foreach ($definitions as $definition) {
        $groups[(string) $definition['category']][] = $definition;
    }
    echo '<fieldset class="permission-box detailed-permissions"><legend>Menü ve Rapor Yetkileri</legend>'
        . '<p>Yönetici tüm alanlara erişir. Görüntüleyici ve Supervisor için izinleri tek tek seçin.</p><div class="permission-category-grid">';
    foreach ($groups as $category => $items) {
        echo '<section><div class="permission-category-head"><strong>' . e($category) . '</strong>'
            . '<button type="button" class="permission-select-all">Tümünü seç</button></div>';
        foreach ($items as $item) {
            $code = (string) $item['permission_code'];
            echo '<label class="permission-item"><input type="checkbox" name="permissions[]" value="' . e($code) . '"'
                . (in_array($code, $selected, true) ? ' checked' : '') . '><span><strong>'
                . e((string) $item['label']) . '</strong><small>' . e((string) $item['description'])
                . '</small><code>' . e($code) . '</code></span></label>';
        }
        echo '</section>';
    }
    echo '</div></fieldset>';
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_ok()) {
            throw new RuntimeException('İstek doğrulanamadı. Sayfayı yenileyin.');
        }
        if ($route === 'agent_tanim') {
            $id = max(0, (int) ($_POST['agent_id'] ?? 0));
            $username = trim((string) ($_POST['username'] ?? ''));
            $name = trim((string) ($_POST['display_name'] ?? ''));
            $extension = preg_replace('/[^0-9*#+]/', '', (string) ($_POST['extension'] ?? '')) ?? '';
            $selected = array_values(array_intersect($queueIds, array_map('strval', (array) ($_POST['queues'] ?? []))));
            if ($username === '' || $name === '' || $extension === '') {
                throw new InvalidArgumentException('Kullanıcı adı, ad ve dahili zorunludur.');
            }
            if (!$selected) {
                throw new InvalidArgumentException('Agent girişi için en az bir kuyruk seçilmelidir.');
            }
            $extensionRow = find_ext($deptId, $extension);
            if (!$extensionRow) {
                throw new InvalidArgumentException('Dahili, bu firmadaki mevcut PBX dahililerinden seçilmelidir.');
            }
            if (!callcenter_extension_phone_modes($extensionRow)) {
                throw new InvalidArgumentException('Seçilen abone için önce bir telefon türü tanımlayın.');
            }
            $password = (string) ($_POST['password'] ?? '');
            $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
            if ($id === 0) {
                if (strlen($password) < 10) {
                    throw new InvalidArgumentException('Yeni agent parolası en az 10 karakter olmalıdır.');
                }
                if (!hash_equals($password, $passwordConfirm)) {
                    throw new InvalidArgumentException('Agent parolaları eşleşmiyor.');
                }
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $values = $selected ? implode(',', array_map(static fn(string $q): string => '(' . callcenter_literal($q) . ')', $selected)) : '';
                $assignment = $values !== '' ? " INSERT INTO callcenter_agent_queue_assignments (dept_id,agent_id,queue_id) SELECT :dept,n.agent_id,q.queue_id FROM n CROSS JOIN (VALUES {$values}) q(queue_id)" : ' SELECT 1 FROM n';
                callcenter_execute(
                    'WITH n AS (INSERT INTO callcenter_agents (dept_id,username,display_name,extension,password_hash) '
                    . 'VALUES (:dept,:username,:name,:extension,:hash) RETURNING agent_id)' . $assignment,
                    ['dept' => $deptId, 'username' => $username, 'name' => $name, 'extension' => $extension, 'hash' => $hash]
                );
                $message = 'Agent oluşturuldu.';
            } else {
                $updateSql = 'UPDATE callcenter_agents SET username=:username,display_name=:name,extension=:extension,status=:status,updated_at=now()';
                $updateParams = ['username' => $username, 'name' => $name, 'extension' => $extension, 'status' => ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active', 'dept' => $deptId, 'id' => $id];
                if ($password !== '') {
                    if (strlen($password) < 10) {
                        throw new InvalidArgumentException('Yeni agent parolası en az 10 karakter olmalıdır.');
                    }
                    if (!hash_equals($password, $passwordConfirm)) {
                        throw new InvalidArgumentException('Agent parolaları eşleşmiyor.');
                    }
                    $updateSql .= ',password_hash=:hash';
                    $updateParams['hash'] = password_hash($password, PASSWORD_DEFAULT);
                }
                $statements = [[
                    $updateSql . ' WHERE dept_id=:dept AND agent_id=:id',
                    $updateParams,
                ], [
                    'DELETE FROM callcenter_agent_queue_assignments WHERE dept_id=:dept AND agent_id=:id',
                    ['dept' => $deptId, 'id' => $id],
                ]];
                foreach ($selected as $queue) {
                    $statements[] = ['INSERT INTO callcenter_agent_queue_assignments (dept_id,agent_id,queue_id) VALUES (:dept,:id,:queue)', ['dept' => $deptId, 'id' => $id, 'queue' => $queue]];
                }
                callcenter_execute_batch($statements);
                $message = $password !== ''
                    ? 'Agent, parola ve kuyruk atamaları güncellendi.'
                    : 'Agent ve kuyruk atamaları güncellendi.';
            }
        } elseif ($route === 'mola_tanim') {
            $id = max(0, (int) ($_POST['break_type_id'] ?? 0));
            $codePrefix = supervisor_break_code_prefix($deptId);
            $shortCode = supervisor_code_part((string) ($_POST['code'] ?? ''));
            if (str_starts_with($shortCode, $codePrefix . '_')) {
                $shortCode = substr($shortCode, strlen($codePrefix) + 1);
            }
            $code = $shortCode !== '' ? $codePrefix . '_' . $shortCode : '';
            $name = trim((string) ($_POST['name'] ?? ''));
            $max = max(0, (int) ($_POST['max_minutes'] ?? 0));
            if ($code === '' || $name === '') {
                throw new InvalidArgumentException('Kısa kod ve mola adı zorunludur.');
            }
            if ($id) {
                callcenter_execute('UPDATE callcenter_break_types SET code=:code,name=:name,max_duration_seconds=:max,active=:active,updated_at=now() WHERE dept_id=:dept AND break_type_id=:id', ['code' => $code, 'name' => $name, 'max' => $max ? $max * 60 : null, 'active' => isset($_POST['active']), 'dept' => $deptId, 'id' => $id]);
            } else {
                callcenter_execute('INSERT INTO callcenter_break_types (dept_id,code,name,max_duration_seconds) VALUES (:dept,:code,:name,:max)', ['dept' => $deptId, 'code' => $code, 'name' => $name, 'max' => $max ? $max * 60 : null]);
            }
            $message = 'Mola tanımı kaydedildi.';
        } elseif ($route === 'kullanicilar') {
            $id = max(0, (int) ($_POST['supervisor_id'] ?? 0));
            $username = trim((string) ($_POST['username'] ?? ''));
            $name = trim((string) ($_POST['display_name'] ?? ''));
            $role = in_array($_POST['role'] ?? '', ['admin', 'supervisor', 'viewer'], true) ? (string) $_POST['role'] : 'viewer';
            $canManagePermissions = callcenter_supervisor_has_permission($supervisor, 'sistem_yetki');
            $queueScopeMode = ($_POST['queue_scope_mode'] ?? 'all') === 'selected' ? 'selected' : 'all';
            $selectedQueues = array_values(array_intersect(
                $queueIds,
                array_map('strval', (array) ($_POST['queue_scopes'] ?? []))
            ));
            $departmentIds = array_values(array_filter(array_map(
                static fn(array $department): string => trim((string) ($department['id'] ?? '')),
                departments()
            )));
            $selectedDepartments = array_values(array_diff(array_intersect(
                $departmentIds,
                array_map('strval', (array) ($_POST['tenant_scopes'] ?? []))
            ), [$deptId]));
            $availablePermissionCodes = array_values(array_map(
                'strval',
                array_column(callcenter_permission_definitions(), 'permission_code')
            ));
            $selectedPermissions = array_values(array_intersect(
                $availablePermissionCodes,
                array_map('strval', (array) ($_POST['permissions'] ?? []))
            ));
            $canChooseTenant = $role === 'admin' && isset($_POST['can_choose_tenant']);
            if ($username === '' || $name === '') {
                throw new InvalidArgumentException('Kullanıcı adı ve görünen ad zorunludur.');
            }
            if ($role === 'admin' && !$canManagePermissions) {
                throw new RuntimeException('Yönetici rolü atamak için Yetki Grupları izni gereklidir.');
            }
            if ($queueScopeMode === 'selected' && !$selectedQueues) {
                throw new InvalidArgumentException('Seçili kuyruk yetkisinde en az bir kuyruk işaretlenmelidir.');
            }
            if ($id === (int) $supervisor['supervisor_id'] && !isset($_POST['active'])) {
                throw new InvalidArgumentException('Aktif oturumdaki kullanıcı pasifleştirilemez.');
            }
            if ($id) {
                $sql = 'UPDATE callcenter_supervisor_users SET username=:username,display_name=:name,role=:role,active=:active,can_choose_tenant=:choose,updated_at=now()';
                $params = ['username' => $username, 'name' => $name, 'role' => $role, 'active' => isset($_POST['active']) ? 'true' : 'false', 'choose' => $canChooseTenant ? 'true' : 'false', 'dept' => $deptId, 'id' => $id];
                $password = (string) ($_POST['password'] ?? '');
                if ($password !== '') {
                    if (strlen($password) < 10) {
                        throw new InvalidArgumentException('Parola en az 10 karakter olmalıdır.');
                    }
                    $sql .= ',password_hash=:hash,must_change_password=TRUE';
                    $params['hash'] = password_hash($password, PASSWORD_DEFAULT);
                }
                $statements = [
                    [$sql . ' WHERE dept_id=:dept AND supervisor_id=:id', $params],
                    ['DELETE FROM callcenter_supervisor_queue_scopes WHERE dept_id=:dept AND supervisor_id=:id', ['dept' => $deptId, 'id' => $id]],
                    ['DELETE FROM callcenter_supervisor_tenant_scopes WHERE owner_dept_id=:dept AND supervisor_id=:id', ['dept' => $deptId, 'id' => $id]],
                ];
                if ($canManagePermissions) {
                    $statements[] = [
                        'DELETE FROM callcenter_supervisor_permissions WHERE dept_id=:dept AND supervisor_id=:id',
                        ['dept' => $deptId, 'id' => $id],
                    ];
                }
                if ($queueScopeMode === 'selected') {
                    foreach ($selectedQueues as $queueId) {
                        $statements[] = [
                            'INSERT INTO callcenter_supervisor_queue_scopes (dept_id,supervisor_id,queue_id) VALUES (:dept,:id,:queue)',
                            ['dept' => $deptId, 'id' => $id, 'queue' => $queueId],
                        ];
                    }
                }
                if ($canChooseTenant) {
                    foreach ($selectedDepartments as $allowedDeptId) {
                        $statements[] = [
                            'INSERT INTO callcenter_supervisor_tenant_scopes (owner_dept_id,supervisor_id,allowed_dept_id) VALUES (:dept,:id,:allowed)',
                            ['dept' => $deptId, 'id' => $id, 'allowed' => $allowedDeptId],
                        ];
                    }
                }
                if ($canManagePermissions && $role !== 'admin') {
                    foreach ($selectedPermissions as $permissionCode) {
                        $statements[] = [
                            'INSERT INTO callcenter_supervisor_permissions (dept_id,supervisor_id,permission_code) VALUES (:dept,:id,:permission)',
                            ['dept' => $deptId, 'id' => $id, 'permission' => $permissionCode],
                        ];
                    }
                }
                callcenter_execute_batch($statements);
            } else {
                $password = (string) ($_POST['password'] ?? '');
                if (strlen($password) < 10) {
                    throw new InvalidArgumentException('Yeni kullanıcı parolası en az 10 karakter olmalıdır.');
                }
                $statements = [[
                    'INSERT INTO callcenter_supervisor_users (dept_id,username,display_name,password_hash,role,can_choose_tenant) VALUES (:dept,:username,:name,:hash,:role,:choose)',
                    ['dept' => $deptId, 'username' => $username, 'name' => $name, 'hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role, 'choose' => $canChooseTenant ? 'true' : 'false'],
                ]];
                if ($queueScopeMode === 'selected') {
                    foreach ($selectedQueues as $queueId) {
                        $statements[] = [
                            'INSERT INTO callcenter_supervisor_queue_scopes (dept_id,supervisor_id,queue_id) SELECT :dept,supervisor_id,:queue FROM callcenter_supervisor_users WHERE dept_id=:dept AND username=:username',
                            ['dept' => $deptId, 'username' => $username, 'queue' => $queueId],
                        ];
                    }
                }
                if ($canChooseTenant) {
                    foreach ($selectedDepartments as $allowedDeptId) {
                        $statements[] = [
                            'INSERT INTO callcenter_supervisor_tenant_scopes (owner_dept_id,supervisor_id,allowed_dept_id) SELECT :dept,supervisor_id,:allowed FROM callcenter_supervisor_users WHERE dept_id=:dept AND username=:username',
                            ['dept' => $deptId, 'username' => $username, 'allowed' => $allowedDeptId],
                        ];
                    }
                }
                if ($canManagePermissions && $role !== 'admin') {
                    foreach ($selectedPermissions as $permissionCode) {
                        $statements[] = [
                            'INSERT INTO callcenter_supervisor_permissions (dept_id,supervisor_id,permission_code) SELECT :dept,supervisor_id,:permission FROM callcenter_supervisor_users WHERE dept_id=:dept AND username=:username',
                            ['dept' => $deptId, 'username' => $username, 'permission' => $permissionCode],
                        ];
                    }
                }
                callcenter_execute_batch($statements);
            }
            $message = 'Supervisor kullanıcısı ve yetki kapsamları kaydedildi.';
        }
        if ($message !== '') {
            callcenter_audit_write(
                $supervisor,
                !empty($id) ? 'update' : 'create',
                $route,
                $message
            );
        }
    }
} catch (Throwable $exception) {
    $error = $exception instanceof InvalidArgumentException || $exception instanceof RuntimeException ? $exception->getMessage() : 'İşlem tamamlanamadı.';
}

supervisor_page_start($title, '../../dashboard.php?tab=callcenter&subtab=sistem');
if ($message) supervisor_notice($message);
if ($error) supervisor_notice($error, true);

if ($route === 'kuyruklistesi') {
    echo '<div class="alert">Kuyruklar ana PBX yapılandırmasından salt okunur gösterilir; burada kopyalanmaz.</div><div class="table-container"><table><thead><tr><th>Kuyruk</th><th>Ad</th><th>Üye Sayısı</th></tr></thead><tbody>';
    foreach ($queues as $queue) echo '<tr><td><span class="badge">' . e((string) $queue['id']) . '</span></td><td>' . e((string) $queue['name']) . '</td><td>' . count((array) $queue['members']) . '</td></tr>';
    echo '</tbody></table></div>';
} elseif ($route === 'agent_tanim') {
    $agents = callcenter_rows('SELECT a.agent_id,a.username,a.display_name,a.extension,a.status,string_agg(q.queue_id,\',\' ORDER BY q.queue_id) AS queues FROM callcenter_agents a LEFT JOIN callcenter_agent_queue_assignments q ON q.dept_id=a.dept_id AND q.agent_id=a.agent_id AND q.active WHERE a.dept_id=:dept GROUP BY a.agent_id ORDER BY a.display_name', ['dept' => $deptId]);
    $extensions = array_values(array_filter(
        store_read('extensions'),
        static fn(array $extension): bool => (string) ($extension['dept'] ?? '') === $deptId
    ));
    usort($extensions, static fn(array $left, array $right): int => strnatcmp(
        (string) ($left['exten'] ?? ''),
        (string) ($right['exten'] ?? '')
    ));
    $phoneModeLabels = callcenter_phone_mode_labels();
    $extensionIndex = [];
    foreach ($extensions as $extension) {
        $extensionIndex[(string) ($extension['exten'] ?? '')] = $extension;
    }
    $queueLabels = [];
    foreach ($queues as $queue) {
        $queueLabels[(string) $queue['id']] = (string) ($queue['name'] ?? $queue['id']);
    }
    echo '<section class="agent-guide"><div><span class="eyebrow">AGENT YÖNETİMİ</span><h2>Agent hesabını üç adımda oluşturun</h2><p>Giriş bilgilerini yazın, mevcut bir dahili seçin ve çağrı karşılayacağı kuyrukları işaretleyin.</p></div>'
        . '<div class="agent-guide-stats"><span><strong>' . count($agents) . '</strong> Tanımlı Agent</span><span><strong>' . count($extensions) . '</strong> Kullanılabilir Dahili</span><span><strong>' . count($queues) . '</strong> Kuyruk</span></div></section>';
    echo '<div class="card agent-create-card"><div class="card-header"><span class="step-number">1</span> Yeni Agent Bilgileri</div><div class="card-body">'
        . '<form method="post" class="agent-form"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<div class="agent-form-fields">'
        . '<label><span>Kullanıcı adı</span><small>Agent giriş ekranında kullanılacak</small><input name="username" placeholder="Örn. agent123" autocomplete="off" required></label>'
        . '<label><span>Ad Soyad</span><small>Ekranlarda görünecek isim</small><input name="display_name" placeholder="Örn. Ahmet Yılmaz" required></label>'
        . '<label><span>Dahili</span><small>Telefon türü Abone bilgilerinden alınır</small><select name="extension" required><option value="">Dahili seçin</option>';
    foreach ($extensions as $extension) {
        $exten = (string) ($extension['exten'] ?? '');
        $extensionName = trim((string) ($extension['name'] ?? ''));
        $modeNames = array_map(
            static fn(string $mode): string => $phoneModeLabels[$mode] ?? $mode,
            callcenter_extension_phone_modes($extension)
        );
        echo '<option value="' . e($exten) . '">' . e($exten . ($extensionName !== '' ? ' · ' . $extensionName : '') . ' · ' . implode(' / ', $modeNames)) . '</option>';
    }
    echo '</select></label>'
        . '<label><span>İlk parola</span><small>En az 10 karakter</small><input type="password" name="password" minlength="10" autocomplete="new-password" placeholder="Güvenli parola" required></label>'
        . '<label><span>Parola tekrar</span><small>Aynı parolayı yeniden yazın</small><input type="password" name="password_confirm" minlength="10" autocomplete="new-password" placeholder="Parolayı doğrulayın" required></label>'
        . '</div><fieldset class="queue-picker"><legend><span class="step-number">2</span> Çağrı karşılanacak kuyruklar</legend><p>Agent’ın dinamik olarak giriş yapacağı en az bir kuyruğu seçin.</p><div class="queue-options">';
    foreach ($queues as $queue) {
        echo '<label class="queue-option"><input type="checkbox" name="queues[]" value="' . e((string) $queue['id']) . '"><span><strong>'
            . e((string) ($queue['name'] ?? $queue['id'])) . '</strong><small>Kuyruk: ' . e((string) $queue['id']) . '</small></span></label>';
    }
    echo '</div></fieldset><div class="agent-submit"><span class="step-number">3</span><div><strong>Hazır</strong><small>Hesap oluşturulunca Agent seçilen kuyruklara giriş yapabilir.</small></div>'
        . '<button class="btn btn-primary" type="submit">Agent Oluştur</button></div></form></div></div>';
    echo '<section class="agent-list-section"><div class="agent-list-heading"><div><h2>Tanımlı Agentlar</h2><p>Bilgileri, parolayı veya kuyruk atamalarını Agent kartından güncelleyin.</p></div><span class="badge">' . count($agents) . ' kayıt</span></div><div class="agent-card-grid">';
    foreach ($agents as $agent) {
        $assigned = array_filter(explode(',', (string) $agent['queues']));
        $active = (string) $agent['status'] !== 'inactive';
        $agentExtension = $extensionIndex[(string) $agent['extension']] ?? [];
        $agentPhoneModes = array_map(
            static fn(string $mode): string => $phoneModeLabels[$mode] ?? $mode,
            callcenter_extension_phone_modes($agentExtension)
        );
        echo '<article class="agent-manage-card"><div class="agent-card-summary"><div class="agent-avatar">' . e(mb_strtoupper(mb_substr((string) $agent['display_name'], 0, 1))) . '</div>'
            . '<div class="agent-identity"><strong>' . e((string) $agent['display_name']) . '</strong><span>@' . e((string) $agent['username']) . '</span></div>'
            . '<span class="badge ' . ($active ? 'badge-success' : 'badge-danger') . '">' . ($active ? 'Aktif' : 'Pasif') . '</span></div>'
            . '<div class="agent-meta"><span><small>Dahili</small><strong>' . e((string) $agent['extension']) . '</strong></span><span><small>Telefon</small><strong>' . e(implode(' / ', $agentPhoneModes) ?: 'Tanımsız') . '</strong></span><span><small>Kuyruk</small><strong>' . count($assigned) . '</strong></span></div>'
            . '<div class="agent-queues">';
        foreach ($assigned as $queueId) {
            echo '<span title="' . e($queueId) . '">' . e($queueLabels[$queueId] ?? $queueId) . '</span>';
        }
        if (!$assigned) {
            echo '<span class="agent-no-queue">Kuyruk atanmamış</span>';
        }
        echo '</div><details class="agent-edit"><summary>Agent Bilgilerini Düzenle</summary><form method="post" class="agent-edit-form">'
            . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="agent_id" value="' . (int) $agent['agent_id'] . '">'
            . '<div class="agent-form-fields"><label><span>Kullanıcı adı</span><input name="username" value="' . e((string) $agent['username']) . '" required></label>'
            . '<label><span>Ad Soyad</span><input name="display_name" value="' . e((string) $agent['display_name']) . '" required></label>'
            . '<label><span>Dahili</span><select name="extension" required>';
        $currentExtensionFound = false;
        foreach ($extensions as $extension) {
            $exten = (string) ($extension['exten'] ?? '');
            $selectedExtension = $exten === (string) $agent['extension'];
            $currentExtensionFound = $currentExtensionFound || $selectedExtension;
            $modeNames = array_map(
                static fn(string $mode): string => $phoneModeLabels[$mode] ?? $mode,
                callcenter_extension_phone_modes($extension)
            );
            echo '<option value="' . e($exten) . '"' . ($selectedExtension ? ' selected' : '') . '>' . e($exten . ' · ' . (string) ($extension['name'] ?? 'Dahili') . ' · ' . implode(' / ', $modeNames)) . '</option>';
        }
        if (!$currentExtensionFound) {
            echo '<option value="' . e((string) $agent['extension']) . '" selected>' . e((string) $agent['extension']) . ' · Mevcut dahili</option>';
        }
        echo '</select></label><label><span>Durum</span><select name="status"><option value="active">Aktif</option><option value="inactive"' . (!$active ? ' selected' : '') . '>Pasif</option></select></label>'
            . '<label><span>Yeni parola</span><small>Değişmeyecekse boş bırakın</small><input type="password" name="password" minlength="10" autocomplete="new-password" placeholder="En az 10 karakter"></label>'
            . '<label><span>Yeni parola tekrar</span><small>Yeni parolayı doğrulayın</small><input type="password" name="password_confirm" minlength="10" autocomplete="new-password"></label></div>'
            . '<fieldset class="queue-picker compact"><legend>Kuyruk Atamaları</legend><div class="queue-options">';
        foreach ($queues as $queue) {
            $queueId = (string) $queue['id'];
            echo '<label class="queue-option"><input type="checkbox" name="queues[]" value="' . e($queueId) . '"' . (in_array($queueId, $assigned, true) ? ' checked' : '') . '><span><strong>'
                . e((string) ($queue['name'] ?? $queueId)) . '</strong><small>' . e($queueId) . '</small></span></label>';
        }
        echo '</div></fieldset><button class="btn btn-primary" type="submit">Değişiklikleri Kaydet</button></form></details></article>';
    }
    if (!$agents) {
        echo '<div class="empty">Henüz Agent tanımlanmamış. Yukarıdaki formdan ilk Agent hesabını oluşturun.</div>';
    }
    echo '</div></section>';
} elseif ($route === 'mola_tanim') {
    $types = callcenter_rows('SELECT break_type_id,code,name,active,max_duration_seconds FROM callcenter_break_types WHERE dept_id=:dept ORDER BY name', ['dept' => $deptId]);
    $codePrefix = supervisor_break_code_prefix($deptId);
    echo '<div class="alert">Mola kodları firma bazında otomatik oluşturulur. Örnek: <strong>' . e($codePrefix) . '_YEMEK</strong></div>'
        . '<div class="card"><div class="card-header">Yeni Mola Türü</div><div class="card-body"><form method="post" class="form-grid break-type-form"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<label>Otomatik mola kodu<div class="prefixed-input"><span>' . e($codePrefix) . '_</span><input name="code" placeholder="KISA_KOD" required></div></label>'
        . '<label>Mola adı<input name="name" placeholder="Yemek Molası" required></label><label>Azami dakika<input type="number" min="0" name="max_minutes" placeholder="Sınırsız"></label><button class="btn btn-primary">Ekle</button></form></div></div>'
        . '<div class="table-container"><table><thead><tr><th>Otomatik Kod</th><th>Ad</th><th>Azami Süre</th><th>Durum</th><th>İşlem</th></tr></thead><tbody>';
    foreach ($types as $type) {
        $storedCode = (string) $type['code'];
        $shortCode = str_starts_with($storedCode, $codePrefix . '_') ? substr($storedCode, strlen($codePrefix) + 1) : $storedCode;
        echo '<tr><td><span class="badge">' . e($storedCode) . '</span></td><td>' . e((string) $type['name']) . '</td><td>' . ($type['max_duration_seconds'] ? e(supervisor_duration($type['max_duration_seconds'])) : 'Sınırsız') . '</td><td>' . (supervisor_bool($type['active']) ? 'Aktif' : 'Pasif') . '</td><td><details><summary>Düzenle</summary><form method="post" class="form-grid break-type-edit-form"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="break_type_id" value="' . (int) $type['break_type_id'] . '">'
            . '<label>Kısa kod<div class="prefixed-input"><span>' . e($codePrefix) . '_</span><input name="code" value="' . e($shortCode) . '" required></div></label>'
            . '<label>Ad<input name="name" value="' . e((string) $type['name']) . '" required></label><label>Dakika<input type="number" min="0" name="max_minutes" value="' . (int) floor(((int) $type['max_duration_seconds']) / 60) . '"></label><label><input type="checkbox" name="active"' . (supervisor_bool($type['active']) ? ' checked' : '') . '> Aktif</label><button class="btn btn-primary">Kaydet</button></form></details></td></tr>';
    }
    echo '</tbody></table></div>';
} else {
    $users = callcenter_rows(
        'SELECT u.supervisor_id,u.username,u.display_name,u.role,u.active,u.can_choose_tenant,u.must_change_password,'
        . "COALESCE((SELECT string_agg(s.queue_id,',' ORDER BY s.queue_id) FROM callcenter_supervisor_queue_scopes s WHERE s.dept_id=u.dept_id AND s.supervisor_id=u.supervisor_id),'') AS queue_scopes,"
        . "COALESCE((SELECT string_agg(t.allowed_dept_id,',' ORDER BY t.allowed_dept_id) FROM callcenter_supervisor_tenant_scopes t WHERE t.owner_dept_id=u.dept_id AND t.supervisor_id=u.supervisor_id),'') AS tenant_scopes,"
        . "COALESCE((SELECT string_agg(p.permission_code,',' ORDER BY p.permission_code) FROM callcenter_supervisor_permissions p WHERE p.dept_id=u.dept_id AND p.supervisor_id=u.supervisor_id),'') AS permissions "
        . 'FROM callcenter_supervisor_users u WHERE u.dept_id=:dept ORDER BY u.display_name',
        ['dept' => $deptId]
    );
    $allDepartments = departments();
    $permissionDefinitions = callcenter_permission_definitions();
    $canManagePermissions = callcenter_supervisor_has_permission($supervisor, 'sistem_yetki');
    $roleLabels = ['viewer' => 'Görüntüleyici', 'supervisor' => 'Supervisor', 'admin' => 'Yönetici'];
    echo '<div class="permission-guide"><div><strong>Görüntüleyici</strong><span>Raporları görüntüler, yönetim ayarlarını değiştiremez.</span></div>'
        . '<div><strong>Supervisor</strong><span>Operasyonu ve izin verilen kuyrukları yönetir.</span></div>'
        . '<div><strong>Yönetici</strong><span>Kullanıcı, sistem ve firma erişimlerini yönetir.</span></div></div>';
    echo '<div class="card"><div class="card-header">Yeni Supervisor Kullanıcısı</div><div class="card-body"><form method="post" class="user-permission-form">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><div class="form-grid">'
        . '<label>Kullanıcı adı<input name="username" placeholder="Giriş kullanıcı adı" required></label>'
        . '<label>Görünen ad<input name="display_name" placeholder="Ad Soyad" required></label>'
        . '<label>Rol<select name="role"><option value="viewer">Görüntüleyici</option><option value="supervisor" selected>Supervisor</option>'
        . ($canManagePermissions ? '<option value="admin">Yönetici</option>' : '') . '</select></label>'
        . '<label>İlk parola<input type="password" name="password" minlength="10" autocomplete="new-password" placeholder="En az 10 karakter" required></label></div>';
    if ($canManagePermissions) {
        supervisor_render_permission_picker($permissionDefinitions);
    }
    echo '<fieldset class="permission-box"><legend>Kuyruk Yetkisi</legend><label class="choice-row"><input type="radio" name="queue_scope_mode" value="all" checked><span><strong>Tüm kuyruklar</strong><small>Bu firmadaki mevcut ve sonradan eklenecek tüm kuyruklar</small></span></label>'
        . '<label class="choice-row"><input type="radio" name="queue_scope_mode" value="selected"><span><strong>Yalnız seçili kuyruklar</strong><small>Aşağıdan erişebileceği kuyrukları işaretleyin</small></span></label><div class="permission-options">';
    foreach ($queues as $queue) {
        echo '<label><input type="checkbox" name="queue_scopes[]" value="' . e((string) $queue['id']) . '"> '
            . e((string) ($queue['name'] ?? $queue['id'])) . ' <small>' . e((string) $queue['id']) . '</small></label>';
    }
    echo '</div></fieldset><fieldset class="permission-box"><legend>Firma Yetkisi</legend>'
        . '<label class="choice-row"><input type="checkbox" name="can_choose_tenant"><span><strong>Firma değiştirebilir</strong><small>Yalnız Yönetici rolünde geçerlidir</small></span></label><div class="permission-options">';
    foreach ($allDepartments as $department) {
        $allowedId = (string) ($department['id'] ?? '');
        if ($allowedId === '' || $allowedId === $deptId) continue;
        echo '<label><input type="checkbox" name="tenant_scopes[]" value="' . e($allowedId) . '"> '
            . e((string) ($department['name'] ?? $allowedId)) . ' <small>' . e($allowedId) . '</small></label>';
    }
    echo '</div></fieldset><button class="btn btn-primary" type="submit">Kullanıcı ve Yetkileri Oluştur</button></form></div></div>'
        . '<div class="user-list-heading"><h2>Tanımlı Kullanıcılar</h2><span class="badge">' . count($users) . ' kullanıcı</span></div><div class="user-card-grid">';
    foreach ($users as $user) {
        $userQueues = array_values(array_filter(explode(',', (string) $user['queue_scopes'])));
        $userTenants = array_values(array_filter(explode(',', (string) $user['tenant_scopes'])));
        $userPermissions = array_values(array_filter(explode(',', (string) $user['permissions'])));
        $hasQueueRestriction = (bool) $userQueues;
        echo '<article class="user-manage-card"><div class="user-card-head"><div><strong>' . e((string) $user['display_name']) . '</strong><span>@' . e((string) $user['username']) . '</span></div>'
            . '<span class="badge ' . (supervisor_bool($user['active']) ? 'badge-success' : 'badge-danger') . '">' . (supervisor_bool($user['active']) ? 'Aktif' : 'Pasif') . '</span></div>'
            . '<div class="user-permission-summary"><span><small>Rol</small><strong>' . e($roleLabels[(string) $user['role']] ?? (string) $user['role']) . '</strong></span>'
            . '<span><small>Kuyruklar</small><strong>' . ($hasQueueRestriction ? count($userQueues) . ' seçili' : 'Tümü') . '</strong></span>'
            . '<span><small>Menü Yetkisi</small><strong>' . ($user['role'] === 'admin' ? 'Tam yetki' : count($userPermissions) . ' izin') . '</strong></span></div>'
            . (supervisor_bool($user['must_change_password']) ? '<div class="password-warning">İlk girişte parola değişikliği gerekli</div>' : '')
            . '<details class="user-edit"><summary>Kullanıcı ve Yetkileri Düzenle</summary><form method="post" class="user-permission-form">'
            . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="supervisor_id" value="' . (int) $user['supervisor_id'] . '"><div class="form-grid">'
            . '<label>Kullanıcı<input name="username" value="' . e((string) $user['username']) . '" required></label><label>Ad<input name="display_name" value="' . e((string) $user['display_name']) . '" required></label><label>Rol<select name="role">';
        foreach ($roleLabels as $value => $label) {
            if ($value === 'admin' && !$canManagePermissions && $user['role'] !== 'admin') continue;
            echo '<option value="' . $value . '"' . ($user['role'] === $value ? ' selected' : '') . '>' . $label . '</option>';
        }
        echo '</select></label><label>Yeni parola<input type="password" name="password" minlength="10" autocomplete="new-password" placeholder="Değişmeyecekse boş"></label></div>'
            . '<label class="choice-row"><input type="checkbox" name="active"' . (supervisor_bool($user['active']) ? ' checked' : '') . '><span><strong>Kullanıcı aktif</strong></span></label>';
        if ($canManagePermissions) {
            supervisor_render_permission_picker($permissionDefinitions, $userPermissions);
        }
        echo '<fieldset class="permission-box"><legend>Kuyruk Yetkisi</legend><label class="choice-row"><input type="radio" name="queue_scope_mode" value="all"' . (!$hasQueueRestriction ? ' checked' : '') . '><span><strong>Tüm kuyruklar</strong></span></label>'
            . '<label class="choice-row"><input type="radio" name="queue_scope_mode" value="selected"' . ($hasQueueRestriction ? ' checked' : '') . '><span><strong>Yalnız seçili kuyruklar</strong></span></label><div class="permission-options">';
        foreach ($queues as $queue) {
            $queueId = (string) $queue['id'];
            echo '<label><input type="checkbox" name="queue_scopes[]" value="' . e($queueId) . '"' . (in_array($queueId, $userQueues, true) ? ' checked' : '') . '> '
                . e((string) ($queue['name'] ?? $queueId)) . ' <small>' . e($queueId) . '</small></label>';
        }
        echo '</div></fieldset><fieldset class="permission-box"><legend>Firma Yetkisi</legend><label class="choice-row"><input type="checkbox" name="can_choose_tenant"' . (supervisor_bool($user['can_choose_tenant']) ? ' checked' : '') . '><span><strong>Firma değiştirebilir</strong><small>Yalnız Yönetici rolünde geçerlidir</small></span></label><div class="permission-options">';
        foreach ($allDepartments as $department) {
            $allowedId = (string) ($department['id'] ?? '');
            if ($allowedId === '' || $allowedId === $deptId) continue;
            echo '<label><input type="checkbox" name="tenant_scopes[]" value="' . e($allowedId) . '"' . (in_array($allowedId, $userTenants, true) ? ' checked' : '') . '> '
                . e((string) ($department['name'] ?? $allowedId)) . ' <small>' . e($allowedId) . '</small></label>';
        }
        echo '</div></fieldset><button class="btn btn-primary" type="submit">Kullanıcı ve Yetkileri Kaydet</button></form></details></article>';
    }
    echo '</div>';
}
supervisor_page_end();
