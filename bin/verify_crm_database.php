<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$departments = departments();
$dept = (string) ($departments[0]['id'] ?? '');
if ($dept === '') {
    throw new RuntimeException('CRM doğrulaması için firma bulunamadı');
}

$token = bin2hex(random_bytes(6));
$customerId = $dept . '-verify-' . $token;
$callKey = 'verify-' . $token;
$noteId = crm_call_note_id($dept, $callKey);
$phone = '999' . substr((string) time(), -9);

try {
    crm_customer_save_db([
        'id' => $customerId,
        'dept' => $dept,
        'company' => 'CRM Doğrulama ' . $token,
        'contact' => 'Geçici Kayıt',
        'phone' => '+' . $phone,
        'phone_alt' => '',
        'email' => 'verify@example.invalid',
        'address' => 'Geçici doğrulama kaydı',
        'notes' => 'Otomatik doğrulama; işlem sonunda silinir.',
        'created_at' => date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM),
        'updated_by' => 'crm-verifier',
    ]);

    $byId = crm_customer_find_db($customerId);
    $byPhone = crm_customer_by_phone_db($dept, $phone);
    if (!$byId || (string) ($byPhone['id'] ?? '') !== $customerId) {
        throw new RuntimeException('Müşteri kimlik/telefon sorgusu doğrulanamadı');
    }

    crm_call_note_save_db([
        'id' => $noteId,
        'dept' => $dept,
        'customer_id' => $customerId,
        'call_key' => $callKey,
        'note' => 'Aranabilir doğrulama notu ' . $token,
        'created_at' => date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM),
        'updated_by' => 'crm-verifier',
    ]);
    $notes = crm_call_notes_db($dept, $customerId, $token);
    if (count($notes) !== 1 || (string) ($notes[0]['id'] ?? '') !== $noteId) {
        throw new RuntimeException('Görüşme notu araması doğrulanamadı');
    }
} finally {
    crm_customer_delete_db($customerId, $dept);
}

if (crm_customer_find_db($customerId) !== null || crm_call_notes_db($dept, $customerId)) {
    throw new RuntimeException('Geçici CRM kaydı silinemedi veya ilişkili not kalıntısı var');
}

echo json_encode([
    'ok' => true,
    'dept' => $dept,
    'checks' => [
        'customer_crud',
        'normalized_phone_lookup',
        'note_crud_and_search',
        'foreign_key_cascade',
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
