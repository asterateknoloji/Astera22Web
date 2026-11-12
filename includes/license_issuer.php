<?php
declare(strict_types=1);

const LICENSE_ISSUER_PRIVATE_KEY_PATH = '/etc/astera/license_vendor_private.pem';

function license_issuer_enabled(): bool
{
    return hash_equals('1', (string) getenv('ASTERA_LICENSE_ISSUER_ENABLED'))
        && is_file(LICENSE_ISSUER_PRIVATE_KEY_PATH)
        && is_readable(LICENSE_ISSUER_PRIVATE_KEY_PATH);
}

function license_issuer_assert_available(): void
{
    if (!license_issuer_enabled()) {
        throw new RuntimeException('Vendor lisans yöneticisi bu santralda etkin değil');
    }
    if (!is_super()) {
        throw new RuntimeException('Sadece sistem yöneticisi');
    }
    if (!pbx_relational_pdo() instanceof PDO) {
        throw new RuntimeException('PostgreSQL lisans deposu kullanılamıyor');
    }
}

function license_issuer_save_customer(array $input): int
{
    license_issuer_assert_available();
    $id = max(0, (int) ($input['customer_id'] ?? 0));
    $code = strtoupper(trim((string) ($input['customer_code'] ?? '')));
    $name = trim((string) ($input['legal_name'] ?? ''));
    $contact = trim((string) ($input['contact_name'] ?? ''));
    $email = strtolower(trim((string) ($input['contact_email'] ?? '')));
    if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{1,47}$/D', $code)) {
        throw new InvalidArgumentException('Müşteri kodu 2-48 karakter ve A-Z, 0-9, nokta, tire veya alt çizgi olmalı');
    }
    if ($name === '' || mb_strlen($name) > 200) {
        throw new InvalidArgumentException('Müşteri unvanı 1-200 karakter olmalı');
    }
    if ($contact !== '' && mb_strlen($contact) > 160) {
        throw new InvalidArgumentException('İletişim adı en fazla 160 karakter olabilir');
    }
    if ($email !== '' && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
        throw new InvalidArgumentException('Geçerli bir e-posta adresi girin');
    }
    $pdo = pbx_relational_pdo();
    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE license_issuer_customers SET customer_code=:code, legal_name=:name, '
            . 'contact_name=:contact, contact_email=:email, updated_at=now() '
            . 'WHERE customer_id=:id RETURNING customer_id'
        );
        $stmt->execute(compact('code', 'name', 'contact', 'email', 'id'));
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO license_issuer_customers '
            . '(customer_code, legal_name, contact_name, contact_email) '
            . 'VALUES (:code, :name, :contact, :email) RETURNING customer_id'
        );
        $stmt->execute(compact('code', 'name', 'contact', 'email'));
    }
    $customerId = (int) $stmt->fetchColumn();
    if ($customerId <= 0) {
        throw new InvalidArgumentException('Müşteri bulunamadı');
    }
    license_issuer_audit($pdo, 'customer.saved', $customerId, null, null, 'customer_code=' . $code);
    return $customerId;
}

function license_issuer_save_installation(array $input): int
{
    license_issuer_assert_available();
    $id = max(0, (int) ($input['installation_id'] ?? 0));
    $customerId = (int) ($input['customer_id'] ?? 0);
    $pbxName = trim((string) ($input['pbx_name'] ?? ''));
    $fingerprint = strtolower(trim((string) ($input['fingerprint'] ?? '')));
    if ($customerId <= 0 || $pbxName === '' || mb_strlen($pbxName) > 160) {
        throw new InvalidArgumentException('Müşteri ve 1-160 karakter PBX adı zorunlu');
    }
    if (!preg_match('/^[0-9a-f]{64}$/D', $fingerprint)) {
        throw new InvalidArgumentException('Kurulum parmak izi 64 karakter küçük harf SHA-256 olmalı');
    }
    $pdo = pbx_relational_pdo();
    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE license_issuer_installations SET customer_id=:customer, pbx_name=:name, '
            . 'fingerprint=:fingerprint, updated_at=now() WHERE installation_id=:id '
            . 'RETURNING installation_id'
        );
        $stmt->execute(['customer' => $customerId, 'name' => $pbxName, 'fingerprint' => $fingerprint, 'id' => $id]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO license_issuer_installations (customer_id, pbx_name, fingerprint) '
            . 'VALUES (:customer, :name, :fingerprint) '
            . 'ON CONFLICT (fingerprint) DO UPDATE SET customer_id=EXCLUDED.customer_id, '
            . 'pbx_name=EXCLUDED.pbx_name, active=TRUE, updated_at=now() RETURNING installation_id'
        );
        $stmt->execute(['customer' => $customerId, 'name' => $pbxName, 'fingerprint' => $fingerprint]);
    }
    $installationId = (int) $stmt->fetchColumn();
    if ($installationId <= 0) {
        throw new InvalidArgumentException('PBX kurulumu bulunamadı');
    }
    license_issuer_audit($pdo, 'installation.saved', $customerId, $installationId, null, 'pbx_name=' . $pbxName);
    return $installationId;
}

function license_issuer_issue(array $input, ?int $previousDocumentId = null): array
{
    license_issuer_assert_available();
    $signingPassphrase = (string) ($input['signing_passphrase'] ?? '');
    if ($signingPassphrase === '' || strlen($signingPassphrase) > 512) {
        throw new InvalidArgumentException('İmza anahtarı parolası zorunlu');
    }
    $installationId = license_issuer_save_installation($input);
    $notBefore = license_optional_date($input['not_before'] ?? null, 'not_before')
        ?? new DateTimeImmutable('now');
    $expiresAt = license_required_date($input['expires_at'] ?? null, 'expires_at');
    $graceDays = filter_var($input['grace_days'] ?? 0, FILTER_VALIDATE_INT);
    if ($graceDays === false || $graceDays < 0 || $graceDays > 365) {
        throw new InvalidArgumentException('Ek süre 0-365 gün arasında olmalı');
    }
    if ($notBefore > $expiresAt) {
        throw new InvalidArgumentException('Başlangıç tarihi bitiş tarihinden sonra olamaz');
    }
    $graceUntil = $expiresAt->modify("+{$graceDays} days");
    $entitlements = [];
    foreach (LICENSE_FEATURE_CODES as $feature) {
        $enabled = !empty($input['entitlement_enabled'][$feature]);
        $rawLimit = trim((string) ($input['entitlement_limit'][$feature] ?? ''));
        if ($rawLimit !== '' && (!ctype_digit($rawLimit) || (int) $rawLimit < 0)) {
            throw new InvalidArgumentException("Geçersiz entitlement limiti: {$feature}");
        }
        $entitlements[$feature] = ['enabled' => $enabled, 'limit' => $rawLimit === '' ? null : (int) $rawLimit];
    }
    $licenseId = 'AST-' . gmdate('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(4)));
    $issuedAt = new DateTimeImmutable('now');
    $payload = [
        'license_id' => $licenseId,
        'installation_fingerprint' => strtolower(trim((string) $input['fingerprint'])),
        'issued_at' => $issuedAt->format(DATE_ATOM),
        'not_before' => $notBefore->format(DATE_ATOM),
        'expires_at' => $expiresAt->format(DATE_ATOM),
        'grace_until' => $graceUntil->format(DATE_ATOM),
        'entitlements' => ['global' => $entitlements],
    ];
    $payloadBytes = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $signature = license_issuer_sign($payloadBytes, $signingPassphrase);
    $signingPassphrase = str_repeat("\0", strlen($signingPassphrase));
    $envelope = json_encode([
        'payload' => rtrim(strtr(base64_encode($payloadBytes), '+/', '-_'), '='),
        'signature' => rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $pdo = pbx_relational_pdo();
    $pdo->beginTransaction();
    try {
        if ($previousDocumentId !== null) {
            $check = $pdo->prepare(
                'SELECT 1 FROM license_issuer_documents WHERE document_id=:id '
                . 'AND installation_id=:installation AND status <> \'revoked\''
            );
            $check->execute(['id' => $previousDocumentId, 'installation' => $installationId]);
            if (!$check->fetchColumn()) {
                throw new InvalidArgumentException('Yenilenecek lisans bu PBX kurulumu için bulunamadı');
            }
            $pdo->prepare(
                "UPDATE license_issuer_documents SET status='renewed', updated_at=now() WHERE document_id=:id"
            )->execute(['id' => $previousDocumentId]);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO license_issuer_documents '
            . '(installation_id, license_id, previous_document_id, payload_bytes, signature_bytes, '
            . 'payload_sha256, signing_key_sha256, issued_at, not_before, expires_at, grace_until, issued_by) '
            . 'VALUES (:installation, :license_id, :previous, decode(:payload, \'hex\'), '
            . 'decode(:signature, \'hex\'), :payload_hash, :key_hash, :issued, :not_before, '
            . ':expires, :grace, :actor) RETURNING document_id'
        );
        $stmt->execute([
            'installation' => $installationId, 'license_id' => $licenseId, 'previous' => $previousDocumentId,
            'payload' => bin2hex($payloadBytes), 'signature' => bin2hex($signature),
            'payload_hash' => hash('sha256', $payloadBytes), 'key_hash' => hash('sha256', license_public_key()),
            'issued' => $issuedAt->format(DATE_ATOM), 'not_before' => $notBefore->format(DATE_ATOM),
            'expires' => $expiresAt->format(DATE_ATOM), 'grace' => $graceUntil->format(DATE_ATOM),
            'actor' => store_actor(),
        ]);
        $documentId = (int) $stmt->fetchColumn();
        $insert = $pdo->prepare(
            'INSERT INTO license_issuer_entitlements '
            . '(document_id, feature_code, enabled, limit_value) VALUES (:document, :feature, :enabled, :limit)'
        );
        foreach ($entitlements as $feature => $value) {
            $insert->execute([
                'document' => $documentId, 'feature' => $feature,
                'enabled' => $value['enabled'] ? 'true' : 'false', 'limit' => $value['limit'],
            ]);
        }
        $pdo->prepare(
            'UPDATE license_issuer_installations SET last_issued_at=now(), updated_at=now() '
            . 'WHERE installation_id=:id'
        )->execute(['id' => $installationId]);
        license_issuer_audit(
            $pdo,
            $previousDocumentId === null ? 'license.issued' : 'license.renewed',
            (int) $input['customer_id'],
            $installationId,
            $documentId,
            'license_id=' . $licenseId
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    return ['document_id' => $documentId, 'license_id' => $licenseId, 'envelope' => $envelope];
}

function license_issuer_renew(int $documentId, array $input): array
{
    if ($documentId <= 0) {
        throw new InvalidArgumentException('Yenilenecek lisans zorunlu');
    }
    return license_issuer_issue($input, $documentId);
}

function license_issuer_revoke(int $documentId, string $reason): void
{
    license_issuer_assert_available();
    $reason = trim($reason);
    if ($documentId <= 0 || $reason === '' || mb_strlen($reason) > 500) {
        throw new InvalidArgumentException('Lisans ve 1-500 karakter iptal nedeni zorunlu');
    }
    $pdo = pbx_relational_pdo();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "UPDATE license_issuer_documents SET status='revoked', updated_at=now() "
            . "WHERE document_id=:id AND status <> 'revoked' RETURNING installation_id"
        );
        $stmt->execute(['id' => $documentId]);
        $installationId = (int) $stmt->fetchColumn();
        if ($installationId <= 0) {
            throw new InvalidArgumentException('Aktif lisans bulunamadı');
        }
        $pdo->prepare(
            'INSERT INTO license_issuer_revocations (document_id, reason, revoked_by) '
            . 'VALUES (:document, :reason, :actor)'
        )->execute(['document' => $documentId, 'reason' => $reason, 'actor' => store_actor()]);
        license_issuer_audit($pdo, 'license.revoked', null, $installationId, $documentId, $reason);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function license_issuer_list(): array
{
    license_issuer_assert_available();
    $pdo = pbx_relational_pdo();
    $documents = $pdo->query(
        'SELECT d.document_id, d.license_id, d.status, d.issued_at, d.expires_at, d.grace_until, '
        . "CASE WHEN d.status <> 'issued' THEN d.status "
        . "WHEN d.expires_at IS NULL OR d.expires_at >= now() THEN 'valid' "
        . "WHEN d.grace_until IS NOT NULL AND d.grace_until >= now() THEN 'grace' ELSE 'expired' END "
        . 'AS effective_status, '
        . 'i.installation_id, i.pbx_name, i.fingerprint, c.customer_id, c.customer_code, c.legal_name '
        . 'FROM license_issuer_documents d JOIN license_issuer_installations i USING (installation_id) '
        . 'JOIN license_issuer_customers c USING (customer_id) '
        . 'ORDER BY d.issued_at DESC, d.document_id DESC LIMIT 200'
    )->fetchAll();
    $entitlementStmt = $pdo->prepare(
        'SELECT feature_code, enabled, limit_value FROM license_issuer_entitlements '
        . 'WHERE document_id=:document ORDER BY feature_code'
    );
    foreach ($documents as &$document) {
        $entitlementStmt->execute(['document' => $document['document_id']]);
        $document['entitlements'] = $entitlementStmt->fetchAll();
    }
    unset($document);
    return [
        'customers' => $pdo->query(
            'SELECT customer_id, customer_code, legal_name, contact_name, contact_email '
            . 'FROM license_issuer_customers WHERE active ORDER BY legal_name, customer_id'
        )->fetchAll(),
        'documents' => $documents,
    ];
}

function license_issuer_sign(string $payload, string $passphrase): string
{
    $pem = (string) file_get_contents(LICENSE_ISSUER_PRIVATE_KEY_PATH);
    if (!str_contains($pem, 'BEGIN ENCRYPTED PRIVATE KEY')) {
        throw new RuntimeException('Vendor private key şifreli PKCS#8 PEM olmalı');
    }
    if ($passphrase === '') {
        throw new RuntimeException('Vendor private key passphrase gerekli');
    }
    $payloadPath = license_issuer_temp_file('payload', $payload);
    $signaturePath = license_issuer_temp_file('signature', '');
    $passphrasePath = license_issuer_temp_file('passphrase', $passphrase);
    try {
        license_issuer_openssl([
            'openssl', 'pkeyutl', '-sign', '-rawin', '-inkey', LICENSE_ISSUER_PRIVATE_KEY_PATH,
            '-passin', 'file:' . $passphrasePath, '-in', $payloadPath, '-out', $signaturePath,
        ]);
        $signature = (string) file_get_contents($signaturePath);
        if (strlen($signature) !== 64) {
            throw new RuntimeException('OpenSSL geçersiz Ed25519 imzası üretti');
        }
        license_issuer_openssl([
            'openssl', 'pkeyutl', '-verify', '-pubin', '-rawin', '-inkey', LICENSE_PUBLIC_KEY_PATH,
            '-in', $payloadPath, '-sigfile', $signaturePath,
        ]);
        return $signature;
    } finally {
        $passphrase = str_repeat("\0", strlen($passphrase));
        foreach ([$payloadPath, $signaturePath, $passphrasePath] as $path) {
            @unlink($path);
        }
    }
}

function license_issuer_temp_file(string $label, string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'astera-license-' . $label . '-');
    if ($path === false || file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Güvenli geçici dosya oluşturulamadı');
    }
    @chmod($path, 0600);
    return $path;
}

function license_issuer_openssl(array $command): void
{
    $pipes = [];
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('OpenSSL başlatılamadı');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('OpenSSL imza işlemi başarısız: ' . trim((string) ($stderr ?: $stdout)));
    }
}

function license_issuer_audit(
    PDO $pdo,
    string $event,
    ?int $customerId,
    ?int $installationId,
    ?int $documentId,
    string $detail
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO license_issuer_audit_events '
        . '(customer_id, installation_id, document_id, event_type, actor, detail) '
        . 'VALUES (:customer, :installation, :document, :event, :actor, :detail)'
    );
    $stmt->execute([
        'customer' => $customerId, 'installation' => $installationId, 'document' => $documentId,
        'event' => $event, 'actor' => store_actor(), 'detail' => mb_substr($detail, 0, 2000),
    ]);
}
