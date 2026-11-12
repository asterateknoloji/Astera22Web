<?php
declare(strict_types=1);

const LICENSE_PUBLIC_KEY_PATH = '/etc/astera/license_ed25519.pub';
const LICENSE_FEATURE_CODES = [
    'subscribers.extensions',
    'concurrent.external_calls',
    'callcenter.queues',
    'callcenter.ringgroups',
    'callcenter.agents',
    'callcenter.supervisors',
    'callcenter.wallboard',
    'callcenter.monitor',
    'ivr.enabled',
    'integrations.enabled',
    'ai.enabled',
    'enterprise.multitenant',
];
// Eski imzalı lisanslar içe aktarılabilsin; yeni lisanslara bu özellik eklenmez.
const LICENSE_LEGACY_FEATURE_CODES = [
    'recording.enabled',
];

function license_installation_identity(bool $refresh = false): array
{
    static $identity = null;
    if (!$refresh && is_array($identity)) {
        return $identity;
    }

    $result = pbx_ssh(<<<'SH'
machine_id="$(tr -d '[:space:]' </etc/machine-id 2>/dev/null)"
root_uuid="$(findmnt -n -o UUID / 2>/dev/null | tr -d '[:space:]')"
pbx_identity="$(asterisk -rx 'core show settings' 2>/dev/null | awk -F: '/^[[:space:]]*System name:/ {sub(/^[[:space:]]*/, "", $2); print $2; exit}')"
[ -n "$pbx_identity" ] || pbx_identity="$(hostname -f 2>/dev/null || hostname)"
printf 'machine_id=%s\nroot_uuid=%s\npbx_identity=%s\n' "$machine_id" "$root_uuid" "$pbx_identity"
SH);
    if (empty($result['ok'])) {
        throw new RuntimeException('PBX kurulum kimliği okunamadı: ' . (string) ($result['output'] ?? ''));
    }

    $parts = [];
    foreach (preg_split('/\R/', (string) ($result['output'] ?? '')) ?: [] as $line) {
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $parts[trim($key)] = trim($value);
        }
    }
    foreach (['machine_id', 'root_uuid', 'pbx_identity'] as $required) {
        if (($parts[$required] ?? '') === '') {
            throw new RuntimeException("PBX kurulum kimliği bileşeni eksik: {$required}");
        }
    }

    $canonical = $parts['machine_id'] . "\n" . $parts['root_uuid'] . "\n" . $parts['pbx_identity'];
    $identity = [
        'fingerprint' => hash('sha256', $canonical),
        'machine_id_hash' => hash('sha256', $parts['machine_id']),
        'root_uuid_hash' => hash('sha256', $parts['root_uuid']),
        'pbx_identity_hash' => hash('sha256', $parts['pbx_identity']),
    ];
    return $identity;
}

function license_status(?string $deptId = null): array
{
    $legacy = [
        'mode' => 'legacy',
        'status' => 'valid',
        'licensed' => false,
        'unlimited' => true,
        'document_id' => null,
        'license_id' => null,
        'expires_at' => null,
        'grace_until' => null,
    ];
    $pdo = pbx_relational_pdo();
    if (!$pdo instanceof PDO) {
        return $legacy;
    }

    try {
        if ((int) $pdo->query(
            'SELECT count(*) FROM license_signed_documents WHERE is_active'
        )->fetchColumn() === 0) {
            return $legacy;
        }
        $identity = license_installation_identity();
        $stmt = $pdo->prepare(
            'SELECT d.document_id, d.license_id, d.issued_at, d.not_before, '
            . 'd.expires_at, d.grace_until '
            . 'FROM license_signed_documents d '
            . 'JOIN license_installations i ON i.installation_id = d.installation_id '
            . 'WHERE i.fingerprint = :fingerprint AND d.is_active '
            . 'ORDER BY d.imported_at DESC, d.document_id DESC LIMIT 1'
        );
        $stmt->execute(['fingerprint' => $identity['fingerprint']]);
        $document = $stmt->fetch();
    } catch (PDOException $error) {
        if ($error->getCode() === '42P01') {
            return $legacy;
        }
        throw $error;
    }
    if (!is_array($document)) {
        return [
            'mode' => 'signed',
            'status' => 'expired',
            'reason' => 'installation_mismatch',
            'licensed' => false,
            'unlimited' => false,
            'document_id' => null,
            'license_id' => null,
            'expires_at' => null,
            'grace_until' => null,
            'dept_id' => $deptId,
        ];
    }

    $now = new DateTimeImmutable('now');
    $notBefore = license_date((string) ($document['not_before'] ?? ''));
    $expiresAt = license_date((string) ($document['expires_at'] ?? ''));
    $graceUntil = license_date((string) ($document['grace_until'] ?? ''));
    $status = 'valid';
    $reason = null;
    if ($notBefore !== null && $now < $notBefore) {
        $status = 'expired';
        $reason = 'not_yet_valid';
    } elseif ($expiresAt !== null && $now > $expiresAt) {
        if ($graceUntil !== null && $now <= $graceUntil) {
            $status = 'grace';
        } else {
            $status = 'expired';
            $reason = 'expired';
        }
    }

    return [
        'mode' => 'signed',
        'status' => $status,
        'reason' => $reason,
        'licensed' => true,
        'unlimited' => false,
        'document_id' => (int) $document['document_id'],
        'license_id' => (string) $document['license_id'],
        'issued_at' => (string) $document['issued_at'],
        'not_before' => $document['not_before'],
        'expires_at' => $document['expires_at'],
        'grace_until' => $document['grace_until'],
        'dept_id' => $deptId,
    ];
}

function license_feature_enabled(string $featureCode, ?string $deptId = null): bool
{
    license_validate_feature($featureCode);
    $status = license_status($deptId);
    if (!empty($status['unlimited'])) {
        return true;
    }
    $entitlement = license_entitlement($featureCode, $deptId, (int) $status['document_id']);
    return is_array($entitlement) && (int) $entitlement['enabled_flag'] === 1;
}

function license_limit(string $featureCode, ?string $deptId = null): ?int
{
    license_validate_feature($featureCode);
    $status = license_status($deptId);
    if (!empty($status['unlimited'])) {
        return null;
    }
    $entitlement = license_entitlement($featureCode, $deptId, (int) $status['document_id']);
    if (!is_array($entitlement) || (int) $entitlement['enabled_flag'] !== 1
        || $entitlement['limit_value'] === null) {
        return null;
    }
    return (int) $entitlement['limit_value'];
}

function license_has_tenant_entitlement(string $featureCode, string $deptId): bool
{
    license_validate_feature($featureCode);
    $status = license_status($deptId);
    if (!empty($status['unlimited']) || empty($status['document_id'])) {
        return false;
    }
    $pdo = pbx_relational_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    $stmt = $pdo->prepare(
        "SELECT 1 FROM license_entitlements WHERE document_id=:document_id "
        . "AND scope='tenant' AND dept_id=:dept_id AND feature_code=:feature_code"
    );
    $stmt->execute([
        'document_id' => $status['document_id'],
        'dept_id' => $deptId,
        'feature_code' => $featureCode,
    ]);
    return (bool) $stmt->fetchColumn();
}

function license_usage(string $featureCode, ?string $deptId = null): int
{
    license_validate_feature($featureCode);
    if ($featureCode === 'concurrent.external_calls') {
        return license_external_call_usage($deptId);
    }
    $pdo = pbx_relational_pdo();
    if (!$pdo instanceof PDO) {
        return 0;
    }

    $where = $deptId === null ? '' : ' AND dept_id = :dept_id';
    $params = $deptId === null ? [] : ['dept_id' => $deptId];
    $queries = [
        'subscribers.extensions' => "SELECT count(*) FROM pbx_config_entities WHERE parent_entity_id IS NULL AND store_name = 'extensions'{$where}",
        'callcenter.queues' => "SELECT count(*) FROM pbx_config_entities WHERE parent_entity_id IS NULL AND store_name = 'queues'{$where}",
        'callcenter.ringgroups' => "SELECT count(*) FROM pbx_config_entities WHERE parent_entity_id IS NULL AND store_name = 'ringgroups'{$where}",
        'callcenter.agents' => "SELECT count(*) FROM callcenter_agents WHERE status = 'active'{$where}",
        'callcenter.supervisors' => "SELECT count(*) FROM callcenter_supervisor_users WHERE active{$where}",
        'ivr.enabled' => "SELECT count(*) FROM pbx_config_entities WHERE parent_entity_id IS NULL AND store_name = 'ivrs'{$where}",
        'integrations.enabled' => "SELECT count(*) FROM integration_connectors WHERE enabled{$where}",
        'enterprise.multitenant' => 'SELECT count(*) FROM astera_tenants WHERE active',
    ];
    if (isset($queries[$featureCode])) {
        $stmt = $pdo->prepare($queries[$featureCode]);
        $stmt->execute($params);
        return max(0, (int) $stmt->fetchColumn());
    }

    $status = license_status($deptId);
    if (empty($status['document_id'])) {
        return 0;
    }
    $sql = 'SELECT usage_value FROM license_usage_snapshots '
        . 'WHERE document_id = :document_id AND feature_code = :feature_code '
        . ($deptId === null ? "AND scope = 'global' " : "AND scope = 'tenant' AND dept_id = :dept_id ")
        . 'ORDER BY captured_at DESC, snapshot_id DESC LIMIT 1';
    $params = ['document_id' => $status['document_id'], 'feature_code' => $featureCode];
    if ($deptId !== null) {
        $params['dept_id'] = $deptId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return max(0, (int) ($stmt->fetchColumn() ?: 0));
}

function license_external_call_usage(?string $deptId = null): int
{
    $result = pbx_ssh("asterisk -rx 'group show channels'");
    if (empty($result['ok'])) {
        return 0;
    }
    $expectedGroup = $deptId === null ? 'external' : 'external-' . dept_code_of($deptId);
    $expectedCategory = $deptId === null ? 'license_global' : 'license_dept';
    $count = 0;
    foreach (preg_split('/\R/', (string) ($result['output'] ?? '')) ?: [] as $line) {
        $parts = preg_split('/\s+/', trim($line)) ?: [];
        if (count($parts) < 3) {
            continue;
        }
        $last = (string) $parts[count($parts) - 1];
        $previous = (string) $parts[count($parts) - 2];
        if (($last === $expectedCategory && $previous === $expectedGroup)
            || ($last === $expectedGroup && $previous === $expectedCategory)) {
            $count++;
        }
    }
    return $count;
}

function license_assert_feature(string $featureCode, ?string $deptId = null): void
{
    $status = license_status($deptId);
    if (($status['status'] ?? '') === 'expired') {
        throw new RuntimeException('Lisans süresi dolduğu için yeni işlem oluşturulamaz');
    }
    if (!license_feature_enabled($featureCode, $deptId)) {
        throw new RuntimeException("Lisans özelliği etkin değil: {$featureCode}");
    }
}

function license_assert_capacity(string $featureCode, int $additional = 1, ?string $deptId = null): void
{
    if ($additional <= 0) {
        return;
    }
    license_assert_feature($featureCode, $deptId);
    $limit = license_limit($featureCode, $deptId);
    if ($limit === null) {
        return;
    }
    $usage = license_usage($featureCode, $deptId);
    if ($usage + $additional > $limit) {
        throw new RuntimeException(
            "Lisans kapasitesi yetersiz: {$featureCode}; limit: {$limit}; mevcut: {$usage}; ek: {$additional}"
        );
    }
}

function license_assert_scoped_capacity(
    string $featureCode,
    int $additional,
    string $deptId
): void {
    license_assert_capacity($featureCode, $additional, null);
    if (license_has_tenant_entitlement($featureCode, $deptId)) {
        license_assert_capacity($featureCode, $additional, $deptId);
    }
}

function license_capacity_view(
    string $featureCode,
    string $label,
    ?string $deptId = null
): array {
    $view = [
        'blocked' => false,
        'enabled' => true,
        'limit' => null,
        'usage' => 0,
        'message' => '',
    ];
    try {
        $view['enabled'] = license_feature_enabled($featureCode, null);
        $view['limit'] = license_limit($featureCode, null);
        $view['usage'] = license_usage($featureCode, null);
        if (!$view['enabled']) {
            $view['blocked'] = true;
            $view['message'] = "{$label} lisansı etkin değil. Yeni kayıt eklenemez.";
        } elseif ($view['limit'] !== null && $view['usage'] >= $view['limit']) {
            $view['blocked'] = true;
            $view['message'] = sprintf(
                '%s lisansınız %d, mevcut kullanım %d. Yeni kayıt eklenemez.',
                $label,
                $view['limit'],
                $view['usage']
            );
        }
        if (!$view['blocked'] && $deptId !== null
            && license_has_tenant_entitlement($featureCode, $deptId)) {
            $view['enabled'] = license_feature_enabled($featureCode, $deptId);
            $view['limit'] = license_limit($featureCode, $deptId);
            $view['usage'] = license_usage($featureCode, $deptId);
            if (!$view['enabled']) {
                $view['blocked'] = true;
                $view['message'] = "Firma {$label} lisansı etkin değil. Yeni kayıt eklenemez.";
            } elseif ($view['limit'] !== null && $view['usage'] >= $view['limit']) {
                $view['blocked'] = true;
                $view['message'] = sprintf(
                    'Firma %s lisansınız %d, mevcut kullanım %d. Yeni kayıt eklenemez.',
                    $label,
                    $view['limit'],
                    $view['usage']
                );
            }
        }
    } catch (Throwable) {
        // Sunucu tarafındaki zorunlu lisans kontrolleri korunur.
    }
    return $view;
}

function license_import_envelope(array|string $envelope): array
{
    if (!extension_loaded('sodium')) {
        throw new RuntimeException('Ed25519 doğrulaması için sodium eklentisi gerekli');
    }
    if (is_string($envelope)) {
        $decoded = json_decode($envelope, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Lisans zarfı bir JSON nesnesi olmalı');
        }
        $envelope = $decoded;
    }
    $payloadEncoded = (string) ($envelope['payload'] ?? '');
    $signatureEncoded = (string) ($envelope['signature'] ?? '');
    $payloadBytes = license_base64url_decode($payloadEncoded);
    $signatureBytes = license_base64url_decode($signatureEncoded);
    if (strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
        throw new InvalidArgumentException('Ed25519 imzası 64 bayt olmalı');
    }
    $publicKey = license_public_key();
    if (!sodium_crypto_sign_verify_detached($signatureBytes, $payloadBytes, $publicKey)) {
        throw new RuntimeException('Lisans imzası doğrulanamadı');
    }

    $payload = json_decode($payloadBytes, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Lisans payload nesnesi geçersiz');
    }
    $identity = license_installation_identity(true);
    $fingerprint = strtolower((string) ($payload['installation_fingerprint'] ?? $payload['fingerprint'] ?? ''));
    if (!hash_equals($identity['fingerprint'], $fingerprint)) {
        throw new RuntimeException('Lisans bu PBX kurulumu için düzenlenmemiş');
    }

    $licenseId = trim((string) ($payload['license_id'] ?? ''));
    if ($licenseId === '' || strlen($licenseId) > 128) {
        throw new InvalidArgumentException('license_id eksik veya geçersiz');
    }
    $issuedAt = license_required_date($payload['issued_at'] ?? null, 'issued_at');
    $notBefore = license_optional_date($payload['not_before'] ?? null, 'not_before');
    $expiresAt = license_optional_date($payload['expires_at'] ?? null, 'expires_at');
    $graceUntil = license_optional_date($payload['grace_until'] ?? null, 'grace_until');
    if ($graceUntil === null && $expiresAt !== null && isset($payload['grace_days'])) {
        $graceDays = filter_var($payload['grace_days'], FILTER_VALIDATE_INT);
        if ($graceDays === false || $graceDays < 0 || $graceDays > 365) {
            throw new InvalidArgumentException('grace_days 0 ile 365 arasında olmalı');
        }
        $graceUntil = $expiresAt->modify("+{$graceDays} days");
    }
    if ($notBefore !== null && $expiresAt !== null && $notBefore > $expiresAt) {
        throw new InvalidArgumentException('not_before expires_at sonrasında olamaz');
    }
    if ($expiresAt !== null && $graceUntil !== null && $expiresAt > $graceUntil) {
        throw new InvalidArgumentException('grace_until expires_at öncesinde olamaz');
    }
    $entitlements = license_normalize_entitlements($payload['entitlements'] ?? []);

    $pdo = pbx_relational_pdo();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('PostgreSQL lisans deposu kullanılamıyor');
    }
    $pdo->beginTransaction();
    try {
        $installationStmt = $pdo->prepare(
            'INSERT INTO license_installations '
            . '(fingerprint, machine_id_hash, root_uuid_hash, pbx_identity_hash) '
            . 'VALUES (:fingerprint, :machine, :root_uuid, :pbx) '
            . 'ON CONFLICT (fingerprint) DO UPDATE SET '
            . 'machine_id_hash = EXCLUDED.machine_id_hash, root_uuid_hash = EXCLUDED.root_uuid_hash, '
            . 'pbx_identity_hash = EXCLUDED.pbx_identity_hash, last_seen_at = now(), updated_at = now() '
            . 'RETURNING installation_id'
        );
        $installationStmt->execute([
            'fingerprint' => $identity['fingerprint'],
            'machine' => $identity['machine_id_hash'],
            'root_uuid' => $identity['root_uuid_hash'],
            'pbx' => $identity['pbx_identity_hash'],
        ]);
        $installationId = (int) $installationStmt->fetchColumn();

        foreach ($entitlements as $entitlement) {
            if ($entitlement['dept_id'] === null) {
                continue;
            }
            $tenantStmt = $pdo->prepare('SELECT 1 FROM astera_tenants WHERE dept_id = :dept_id');
            $tenantStmt->execute(['dept_id' => $entitlement['dept_id']]);
            if (!$tenantStmt->fetchColumn()) {
                throw new InvalidArgumentException('Bilinmeyen tenant: ' . $entitlement['dept_id']);
            }
        }

        $pdo->prepare(
            'UPDATE license_signed_documents SET is_active = FALSE, updated_at = now() '
            . 'WHERE installation_id = :installation_id AND is_active'
        )->execute(['installation_id' => $installationId]);
        $documentStmt = $pdo->prepare(
            'INSERT INTO license_signed_documents '
            . '(installation_id, license_id, payload_bytes, signature_bytes, payload_sha256, '
            . 'signing_key_sha256, issued_at, not_before, expires_at, grace_until, is_active, imported_by) '
            . 'VALUES (:installation_id, :license_id, decode(:payload_hex, \'hex\'), '
            . 'decode(:signature_hex, \'hex\'), :payload_sha256, :key_sha256, :issued_at, '
            . ':not_before, :expires_at, :grace_until, TRUE, :actor) '
            . 'ON CONFLICT (installation_id, payload_sha256) DO UPDATE SET '
            . 'is_active = TRUE, updated_at = now(), imported_by = EXCLUDED.imported_by, '
            . 'imported_at = now() RETURNING document_id'
        );
        $documentStmt->execute([
            'installation_id' => $installationId,
            'license_id' => $licenseId,
            'payload_hex' => bin2hex($payloadBytes),
            'signature_hex' => bin2hex($signatureBytes),
            'payload_sha256' => hash('sha256', $payloadBytes),
            'key_sha256' => hash('sha256', $publicKey),
            'issued_at' => $issuedAt->format(DATE_ATOM),
            'not_before' => $notBefore?->format(DATE_ATOM),
            'expires_at' => $expiresAt?->format(DATE_ATOM),
            'grace_until' => $graceUntil?->format(DATE_ATOM),
            'actor' => store_actor(),
        ]);
        $documentId = (int) $documentStmt->fetchColumn();

        $pdo->prepare('DELETE FROM license_entitlements WHERE document_id = :document_id')
            ->execute(['document_id' => $documentId]);
        $insertEntitlement = $pdo->prepare(
            'INSERT INTO license_entitlements '
            . '(document_id, scope, dept_id, feature_code, enabled, limit_value) '
            . 'VALUES (:document_id, :scope, :dept_id, :feature_code, :enabled, :limit_value)'
        );
        foreach ($entitlements as $entitlement) {
            $insertEntitlement->execute([
                'document_id' => $documentId,
                'scope' => $entitlement['scope'],
                'dept_id' => $entitlement['dept_id'],
                'feature_code' => $entitlement['feature_code'],
                'enabled' => $entitlement['enabled'] ? 'true' : 'false',
                'limit_value' => $entitlement['limit_value'],
            ]);
        }
        $audit = $pdo->prepare(
            'INSERT INTO license_audit_events '
            . '(installation_id, document_id, event_type, actor, detail) '
            . 'VALUES (:installation_id, :document_id, :event_type, :actor, :detail)'
        );
        $audit->execute([
            'installation_id' => $installationId,
            'document_id' => $documentId,
            'event_type' => 'license.imported',
            'actor' => store_actor(),
            'detail' => 'license_id=' . $licenseId . '; entitlements=' . count($entitlements),
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    return license_status();
}

function license_entitlement(string $featureCode, ?string $deptId, int $documentId): ?array
{
    $pdo = pbx_relational_pdo();
    if (!$pdo instanceof PDO || $documentId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT CASE WHEN enabled THEN 1 ELSE 0 END AS enabled_flag, limit_value '
        . 'FROM license_entitlements '
        . 'WHERE document_id = :document_id AND feature_code = :feature_code '
        . 'AND ((scope = \'tenant\' AND dept_id = :dept_id) OR scope = \'global\') '
        . 'ORDER BY CASE WHEN scope = \'tenant\' THEN 0 ELSE 1 END LIMIT 1'
    );
    $stmt->execute([
        'document_id' => $documentId,
        'feature_code' => $featureCode,
        'dept_id' => $deptId,
    ]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function license_normalize_entitlements(mixed $input): array
{
    if (!is_array($input)) {
        throw new InvalidArgumentException('entitlements dizi veya nesne olmalı');
    }
    $rows = [];
    if (isset($input['global']) || isset($input['tenants'])) {
        foreach ((array) ($input['global'] ?? []) as $feature => $value) {
            $rows[] = license_normalize_entitlement_value((string) $feature, $value, null);
        }
        foreach ((array) ($input['tenants'] ?? []) as $deptId => $features) {
            foreach ((array) $features as $feature => $value) {
                $rows[] = license_normalize_entitlement_value((string) $feature, $value, (string) $deptId);
            }
        }
    } elseif (array_is_list($input)) {
        foreach ($input as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Entitlement satırı nesne olmalı');
            }
            $feature = (string) ($item['feature_code'] ?? $item['feature'] ?? '');
            $deptId = isset($item['dept_id']) ? (string) $item['dept_id'] : null;
            $scope = (string) ($item['scope'] ?? ($deptId === null ? 'global' : 'tenant'));
            if (!in_array($scope, ['global', 'tenant'], true)) {
                throw new InvalidArgumentException("Geçersiz entitlement scope: {$feature}");
            }
            if (($scope === 'global' && $deptId !== null) || ($scope === 'tenant' && $deptId === null)) {
                throw new InvalidArgumentException("Entitlement scope/dept_id uyumsuz: {$feature}");
            }
            $rows[] = license_normalize_entitlement_value($feature, $item, $deptId);
        }
    } else {
        foreach ($input as $feature => $value) {
            $rows[] = license_normalize_entitlement_value((string) $feature, $value, null);
        }
    }

    $seen = [];
    foreach ($rows as $row) {
        $key = ($row['dept_id'] ?? '*') . ':' . $row['feature_code'];
        if (isset($seen[$key])) {
            throw new InvalidArgumentException('Tekrarlanan entitlement: ' . $key);
        }
        $seen[$key] = true;
    }
    return $rows;
}

function license_normalize_entitlement_value(string $feature, mixed $value, ?string $deptId): array
{
    license_validate_feature($feature);
    if ($deptId !== null && !preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $deptId)) {
        throw new InvalidArgumentException('Geçersiz entitlement dept_id');
    }
    $enabled = true;
    $limit = null;
    if (is_bool($value)) {
        $enabled = $value;
    } elseif (is_int($value)) {
        $limit = $value;
    } elseif (is_array($value)) {
        if (array_key_exists('enabled', $value) && !is_bool($value['enabled'])) {
            throw new InvalidArgumentException("Entitlement enabled boolean olmalı: {$feature}");
        }
        $enabled = array_key_exists('enabled', $value) ? $value['enabled'] : true;
        if (array_key_exists('limit', $value) && $value['limit'] !== null) {
            $limit = filter_var($value['limit'], FILTER_VALIDATE_INT);
            if ($limit === false) {
                throw new InvalidArgumentException("Geçersiz entitlement limiti: {$feature}");
            }
        } elseif (array_key_exists('limit_value', $value) && $value['limit_value'] !== null) {
            $limit = filter_var($value['limit_value'], FILTER_VALIDATE_INT);
            if ($limit === false) {
                throw new InvalidArgumentException("Geçersiz entitlement limiti: {$feature}");
            }
        }
    } else {
        throw new InvalidArgumentException("Geçersiz entitlement değeri: {$feature}");
    }
    if ($limit !== null && ($limit < 0 || $limit > PHP_INT_MAX)) {
        throw new InvalidArgumentException("Entitlement limiti negatif olamaz: {$feature}");
    }
    return [
        'scope' => $deptId === null ? 'global' : 'tenant',
        'dept_id' => $deptId,
        'feature_code' => $feature,
        'enabled' => $enabled,
        'limit_value' => $limit,
    ];
}

function license_public_key(): string
{
    if (!extension_loaded('sodium')) {
        throw new RuntimeException('Ed25519 doğrulaması için sodium eklentisi gerekli');
    }
    $result = pbx_ssh(
        'base64 < ' . escapeshellarg(LICENSE_PUBLIC_KEY_PATH) . " | tr -d '\\n'"
    );
    if (empty($result['ok'])) {
        throw new RuntimeException('Lisans public key okunamadı');
    }
    $transport = base64_decode(trim((string) $result['output']), true);
    if ($transport === false || $transport === '') {
        throw new RuntimeException('Lisans public key çözümlenemedi');
    }
    $value = strlen($transport) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        ? $transport
        : trim($transport);
    if (str_starts_with($value, '-----BEGIN PUBLIC KEY-----')) {
        $body = str_replace(
            ['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\r", "\n", ' ', "\t"],
            '',
            $value
        );
        $der = base64_decode((string) $body, true);
        $prefix = hex2bin('302a300506032b6570032100');
        if ($der === false || $prefix === false || !str_starts_with($der, $prefix)) {
            throw new RuntimeException('Ed25519 PEM public key geçersiz');
        }
        $value = substr($der, strlen($prefix));
    } elseif (str_starts_with($value, 'ssh-ed25519 ')) {
        $parts = preg_split('/\s+/', $value);
        $blob = base64_decode((string) ($parts[1] ?? ''), true);
        if ($blob === false || strlen($blob) < 4) {
            throw new RuntimeException('OpenSSH Ed25519 public key geçersiz');
        }
        $typeLength = unpack('Nlength', substr($blob, 0, 4))['length'] ?? 0;
        $offset = 4 + $typeLength;
        if (substr($blob, 4, $typeLength) !== 'ssh-ed25519' || strlen($blob) < $offset + 4) {
            throw new RuntimeException('OpenSSH Ed25519 public key türü geçersiz');
        }
        $keyLength = unpack('Nlength', substr($blob, $offset, 4))['length'] ?? 0;
        $value = substr($blob, $offset + 4, $keyLength);
    } elseif (preg_match('/^[0-9a-fA-F]{64}$/', $value)) {
        $value = (string) hex2bin($value);
    } else {
        $decoded = base64_decode($value, true);
        if ($decoded !== false) {
            $value = $decoded;
        }
    }
    if (strlen($value) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        throw new RuntimeException('Ed25519 public key 32 bayt olmalı');
    }
    return $value;
}

function license_base64url_decode(string $value): string
{
    if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
        throw new InvalidArgumentException('Geçersiz base64url alanı');
    }
    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    if ($decoded === false) {
        throw new InvalidArgumentException('Base64url alanı çözümlenemedi');
    }
    return $decoded;
}

function license_validate_feature(string $featureCode): void
{
    if (
        !in_array($featureCode, LICENSE_FEATURE_CODES, true)
        && !in_array($featureCode, LICENSE_LEGACY_FEATURE_CODES, true)
    ) {
        throw new InvalidArgumentException("Bilinmeyen lisans özelliği: {$featureCode}");
    }
}

function license_date(string $value): ?DateTimeImmutable
{
    if ($value === '') {
        return null;
    }
    try {
        return new DateTimeImmutable($value);
    } catch (Throwable) {
        return null;
    }
}

function license_required_date(mixed $value, string $field): DateTimeImmutable
{
    $date = license_optional_date($value, $field);
    if ($date === null) {
        throw new InvalidArgumentException("{$field} zorunlu");
    }
    return $date;
}

function license_optional_date(mixed $value, string $field): ?DateTimeImmutable
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value)) {
        throw new InvalidArgumentException("{$field} ISO-8601 metni olmalı");
    }
    try {
        return new DateTimeImmutable($value);
    } catch (Throwable) {
        throw new InvalidArgumentException("{$field} geçersiz");
    }
}
