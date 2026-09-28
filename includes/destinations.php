<?php
declare(strict_types=1);

function dest_types(): array
{
    return [
        'extension' => 'Abone',
        'queue' => 'Kuyruk',
        'ringgroup' => 'Ring grup',
        'ivr' => 'IVR',
        'time' => 'Zaman koşulu',
        'announcement' => 'Duyuru',
        'conference' => 'Konferans',
        'flow' => 'Gündüz / gece',
        'disa' => 'DISA',
        'paging' => 'Anons / page',
        'voicemail' => 'Gelen kutusu',
        'external' => 'Dış numara',
        'custom' => 'Özel hedef',
        'hangup' => 'Kapat',
    ];
}

function dest_allowed(string $type): string
{
    $type = ast_sanitize_id($type);
    return array_key_exists($type, dest_types()) ? $type : 'hangup';
}

function dest_catalog(?string $deptId): array
{
    $out = [];
    foreach (dest_types() as $type => $label) {
        $out[$type] = [];
        if ($type === 'hangup') {
            $out[$type][] = ['id' => 'hangup', 'label' => 'Çağrıyı kapat'];
            continue;
        }
        $store = match ($type) {
            'extension', 'voicemail' => 'extensions',
            'queue' => 'queues',
            'ringgroup' => 'ringgroups',
            'ivr' => 'ivrs',
            'time' => 'timeconditions',
            'announcement' => 'announcements',
            'conference' => 'conferences',
            'flow' => 'flows',
            'disa' => 'disas',
            'paging' => 'pagings',
            'custom' => 'customs',
            default => '',
        };
        if ($store === '') {
            continue;
        }
        foreach (store_read($store) as $row) {
            if ($deptId && (string) ($row['dept'] ?? '') !== $deptId) {
                continue;
            }
            $id = (string) ($row['id'] ?? $row['exten'] ?? '');
            if ($type === 'extension' || $type === 'voicemail') {
                $id = (string) ($row['id'] ?? ext_id((string) ($row['dept'] ?? ''), (string) ($row['exten'] ?? '')));
            }
            if ($id === '') {
                continue;
            }
            $name = (string) ($row['name'] ?? $id);
            $ext = (string) ($row['exten'] ?? '');
            $firm = $deptId ? '' : (' · ' . dept_name((string) ($row['dept'] ?? '')));
            $out[$type][] = [
                'id' => $id,
                'label' => trim($label . ' ' . $ext . ' ' . $name . $firm),
            ];
        }
    }
    return $out;
}

function dest_belongs_to_dept(string $dept, string $type, string $dest): bool
{
    $type = dest_allowed($type);
    $dest = ast_sanitize_id($dest);
    if ($type === 'external') {
        return preg_match('/^\d{3,20}$/', $dest) === 1;
    }
    foreach (dest_catalog($dept)[$type] ?? [] as $item) {
        $id = ast_sanitize_id((string) ($item['id'] ?? ''));
        if ($id === $dest || str_ends_with($id, '-' . $dest)) {
            return true;
        }
    }
    return false;
}

function dest_goto(string $dept, string $type, string $dest, string $intCtx): string
{
    $code = dept_code_of($dept);
    $dest = ast_sanitize_id($dest);
    $type = dest_allowed($type);
    if ($type === 'extension') {
        $row = find_by('extensions', 'id', $dest) ?: find_ext($dept, $dest);
        if ($row) {
            $local = ast_sanitize_id((string) ($row['exten'] ?? ''));
            $ctxInt = ctx((string) ($row['dept'] ?? $dept), 'int');
            return " same => n,Goto({$ctxInt},{$local},1)\n";
        }
        return " same => n,Goto({$intCtx},{$dest},1)\n";
    }
    if ($type === 'voicemail') {
        $row = find_by('extensions', 'id', $dest) ?: find_ext($dept, $dest);
        if ($row) {
            $num = ast_sanitize_id((string) ($row['exten'] ?? $dest));
            $vmCode = dept_code_of((string) ($row['dept'] ?? $dept));
            return " same => n,VoiceMail({$num}@{$vmCode},u)\n same => n,Hangup()\n";
        }
        return " same => n,VoiceMail({$dest}@{$code},u)\n same => n,Hangup()\n";
    }
    return match ($type) {
        'ivr' => " same => n,Goto(ivr-{$dest},s,1)\n",
        'time' => " same => n,Goto(tc-{$dest},s,1)\n",
        'announcement' => " same => n,Goto(ann-{$dest},s,1)\n",
        'flow' => " same => n,Goto(flow-{$dest},s,1)\n",
        'disa' => " same => n,Goto(disa-{$dest},s,1)\n",
        'external' => " same => n,Goto(" . ctx($dept, 'out') . ",{$dest},1)\n",
        'custom' => " same => n,Goto(cust-{$dest},s,1)\n",
        'hangup' => " same => n,Hangup()\n",
        'conference', 'queue', 'ringgroup', 'paging' => (static function () use ($dept, $type, $dest, $intCtx) {
            $map = $type === 'paging' ? 'paging' : $type;
            $local = dest_local_exten($dept, $map === 'conference' ? 'conference' : $map, $dest);
            if ($local === '') {
                $local = $dest;
            }
            return " same => n,Goto({$intCtx},{$local},1)\n";
        })(),
        default => " same => n,Hangup()\n",
    };
}

function inbound_is_catchall(string $did): bool
{
    $d = strtoupper(trim($did));
    return $d === '' || in_array($d, ['S', '_', '_X', '_X.', '_.', '*', 'ANY', 'ANYTHING'], true);
}

function inbound_did_list(string $did): array
{
    $did = trim($did);
    if (inbound_is_catchall($did)) {
        return [];
    }
    $out = [$did];
    $digits = preg_replace('/\D+/', '', $did) ?? '';
    if ($digits !== '') {
        $out[] = $digits;
        $out[] = '+' . $digits;
        if (str_starts_with($digits, '90') && strlen($digits) > 4) {
            $national = substr($digits, 2);
            $out[] = $national;
            $out[] = '0' . $national;
        } elseif (str_starts_with($digits, '0') && strlen($digits) > 3) {
            $out[] = '90' . substr($digits, 1);
            $out[] = substr($digits, 1);
        }
    }
    $uniq = [];
    foreach ($out as $alias) {
        $alias = ast_quote($alias);
        if ($alias !== '') {
            $uniq[$alias] = $alias;
        }
    }
    return array_values($uniq);
}

function scoped_or_all(string $name, ?string $dept = null): array
{
    $dept ??= current_dept_id();
    $rows = store_read($name);
    if ($dept === null) {
        return $rows;
    }
    return array_values(array_filter($rows, static fn($r) => (string) ($r['dept'] ?? '') === $dept));
}

function scoped_id(string $dept, string $local): string
{
    $local = ast_sanitize_id($local);
    if ($local === '') {
        return '';
    }
    return str_starts_with($local, $dept . '-') ? $local : ($dept . '-' . $local);
}

function stock_sounds(): array
{
    return [
        'hello-world',
        'vm-goodbye',
        'dir-intro',
        'ss-noservice',
        'invalid',
        'beep',
        'silence/1',
        'privacy-please-stay-on-line',
        'queue-thankyou',
        'conf-placeintoconf',
    ];
}

function panel_sounds(?string $deptId = null): array
{
    $list = stock_sounds();
    return array_values(array_unique(array_merge($list, panel_uploaded_sounds($deptId))));
}

function panel_uploaded_sounds(?string $deptId = null): array
{
    $list = [];
    foreach (store_read('sounds') as $row) {
        if ($deptId !== null && (string) ($row['dept'] ?? '') !== $deptId) {
            continue;
        }
        $p = trim((string) ($row['playback'] ?? ''));
        if ($p !== '') {
            $list[] = $p;
        }
    }
    return array_values(array_unique($list));
}

function tenant_sound_allowed(string $deptId, string $sound): bool
{
    $sound = trim($sound);
    if ($sound === '' || !str_starts_with($sound, 'custom/')) {
        return true;
    }
    foreach (store_read('sounds') as $row) {
        if ((string) ($row['dept'] ?? '') === $deptId
            && (string) ($row['playback'] ?? '') === $sound) {
            return true;
        }
    }
    return false;
}

function normalize_sound_name(string $sound): string
{
    return preg_replace('/\.(wav|gsm|ulaw)$/i', '', trim($sound)) ?? '';
}

function tenant_store_names(): array
{
    return [
        'trunks', 'outbound', 'inbound', 'queues', 'ringgroups', 'ivrs', 'timeconditions',
        'announcements', 'conferences', 'blacklist', 'parking', 'flows', 'disas',
        'pagings', 'speeddials', 'customs', 'sounds', 'media', 'url_triggers',
    ];
}
