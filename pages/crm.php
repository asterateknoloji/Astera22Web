<?php
$crmBase = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php')) === 'crm_popup.php'
    ? 'crm_popup.php'
    : 'index.php?p=crm';
$crmJoin = str_contains($crmBase, '?') ? '&' : '?';
$query = trim((string) ($_GET['q'] ?? ''));
$phoneQuery = trim((string) ($_GET['phone'] ?? ''));
$activeCallKey = preg_replace('/[^\w.\-]+/', '', substr((string) ($_GET['callid'] ?? ''), 0, 255)) ?? '';
$targetCallKey = preg_replace('/[^\w.\-]+/', '', substr((string) ($_GET['call'] ?? ''), 0, 255)) ?? '';
$allCustomers = crm_customers_db(current_dept_id(), $query);
$customersById = [];
foreach ($allCustomers as $customerRow) {
    $customersById[(string) ($customerRow['id'] ?? '')] = $customerRow;
}
$customers = $allCustomers;
$matchingCallNotes = $query !== ''
    ? crm_call_notes_db(current_dept_id(), null, $query)
    : [];
$matchingNoteCalls = [];
if ($matchingCallNotes) {
    $callCache = [];
    foreach (array_slice($matchingCallNotes, 0, 100) as $callNote) {
        $noteCustomerId = (string) ($callNote['customer_id'] ?? '');
        $noteCustomer = $customersById[$noteCustomerId] ?? null;
        if (!$noteCustomer) {
            continue;
        }
        if (!isset($callCache[$noteCustomerId])) {
            $callCache[$noteCustomerId] = [];
            foreach (crm_calls_for_customer($noteCustomer) as $customerCall) {
                $callCache[$noteCustomerId][(string) ($customerCall['crm_call_key'] ?? '')] = $customerCall;
            }
        }
        $noteCallKey = (string) ($callNote['call_key'] ?? '');
        if (isset($callCache[$noteCustomerId][$noteCallKey])) {
            $matchingNoteCalls[(string) ($callNote['id'] ?? '')] = $callCache[$noteCustomerId][$noteCallKey];
        }
    }
}
usort($customers, static fn(array $a, array $b): int =>
    strcasecmp((string) ($a['company'] ?? ''), (string) ($b['company'] ?? ''))
);

$customerId = ast_sanitize_id((string) ($_GET['id'] ?? ''));
$selected = $customerId !== '' ? crm_customer_find_db($customerId) : null;
if ($selected) {
    assert_row_scope($selected);
}
if (!$selected && $phoneQuery !== '') {
    $dept = current_dept_id();
    if ($dept !== null) {
        $selected = crm_customer_by_phone($dept, $phoneQuery);
    } else {
        foreach ($customers as $candidate) {
            if (in_array(crm_normalize_phone($phoneQuery), crm_customer_phones($candidate), true)) {
                $selected = $candidate;
                break;
            }
        }
    }
}

$calls = [];
$recordings = [];
$notesByCall = [];
$activeCallNote = '';
$activeCall = null;
$activeCallRecording = null;
if ($selected) {
    $calls = crm_calls_for_customer($selected);
    $recordings = array_values(array_filter(
        pbx_recordings((string) $selected['dept'], 500, $calls),
        static fn(array $recording): bool => empty($recording['empty'])
    ));
    foreach (crm_call_notes_db((string) $selected['dept'], (string) $selected['id']) as $note) {
        $notesByCall[(string) ($note['call_key'] ?? '')] = $note;
    }
    $activeCallNote = (string) ($notesByCall[$activeCallKey]['note'] ?? '');
    if ($activeCallKey !== '') {
        foreach ($calls as $customerCall) {
            if ((string) ($customerCall['crm_call_key'] ?? '') === $activeCallKey) {
                $activeCall = $customerCall;
                $activeCallRecording = crm_recording_for_call($customerCall, $recordings);
                break;
            }
        }
    }
}
?>
<div class="toolbar">
    <form method="get" class="crm-search">
        <input type="hidden" name="p" value="crm">
        <input name="q" value="<?= e($query) ?>" autocomplete="off" data-crm-live-search
            placeholder="Hatırladığınız herhangi bir bilgiyi yazın">
        <button class="btn" type="submit">Ara</button>
        <?php if ($query !== ''): ?><a class="btn" href="<?= e($crmBase) ?>">Temizle</a><?php endif; ?>
    </form>
    <button class="btn primary" type="button" id="addCrmCustomer">Müşteri ekle</button>
</div>

<?php if ($phoneQuery !== '' && !$selected): ?>
    <article class="card crm-empty-call">
        <div>
            <h2><?= e($phoneQuery) ?> kayıtlı değil</h2>
            <p class="muted">Gelen numara için yeni müşteri kartı oluşturabilirsiniz.</p>
        </div>
        <button class="btn primary" type="button" data-new-phone="<?= e($phoneQuery) ?>">Kart oluştur</button>
    </article>
<?php endif; ?>

<?php if ($selected): ?>
    <article class="card crm-profile">
        <div>
            <span class="pill ok"><?= e(dept_name((string) $selected['dept'])) ?></span>
            <h2><?= e($selected['company'] ?? '') ?></h2>
            <p class="muted"><?= e($selected['contact'] ?? 'Yetkili belirtilmedi') ?></p>
        </div>
        <div class="crm-profile-actions">
            <button class="btn" type="button" onclick="location.reload()">Yenile</button>
            <a class="btn" target="astera-webphone"
                href="webphone.php?dial=<?= rawurlencode((string) ($selected['phone'] ?? '')) ?>"
                data-crm-dial="<?= e($selected['phone'] ?? '') ?>"><?= e($selected['phone'] ?? '') ?></a>
            <button class="btn" type="button"
                data-edit-crm='<?= e(json_encode($selected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
                Düzenle
            </button>
            <button class="btn danger" type="button" data-delete-crm="<?= e($selected['id']) ?>">Sil</button>
        </div>
        <dl class="crm-details">
            <div><dt>İkinci telefon</dt><dd>
                <?php if (!empty($selected['phone_alt'])): ?>
                    <a class="crm-phone-dial" target="astera-webphone"
                        href="webphone.php?dial=<?= rawurlencode((string) $selected['phone_alt']) ?>"
                        data-crm-dial="<?= e($selected['phone_alt']) ?>"><?= e($selected['phone_alt']) ?></a>
                <?php else: ?>—<?php endif; ?>
            </dd></div>
            <div><dt>E-posta</dt><dd><?= e($selected['email'] ?: '—') ?></dd></div>
            <div><dt>Adres</dt><dd><?= nl2br(e($selected['address'] ?: '—')) ?></dd></div>
            <div><dt>Kart notu</dt><dd><?= nl2br(e($selected['notes'] ?: '—')) ?></dd></div>
        </dl>
    </article>

    <?php if ($activeCallKey !== ''): ?>
        <article class="card crm-live-note">
            <div>
                <span class="pill ok"><?= $activeCall ? 'Son görüşme' : 'Aktif görüşme' ?></span>
                <h2><?= $activeCall ? 'Bu görüşmenin notu ve ses kaydı' : 'Görüşme sırasında not alın' ?></h2>
                <p class="muted">
                    <?= $activeCall
                        ? e(($activeCall['start'] ?? '') . ' · ' . ($activeCall['billsec'] ?? 0) . ' sn')
                        : 'Not, çağrı kapanınca bu görüşmenin yanında görünür.' ?>
                </p>
            </div>
            <?php if ($activeCall): ?>
                <div class="crm-active-recording">
                    <?php if ($activeCallRecording): ?>
                        <strong>Ses kaydı</strong>
                        <audio controls preload="metadata"
                            src="play.php?dept=<?= e($activeCallRecording['dept']) ?>&f=<?= e($activeCallRecording['relative_path'] ?? $activeCallRecording['file']) ?>"></audio>
                    <?php else: ?>
                        <span class="muted">Ses kaydı hazırlanıyor; Yenile düğmesine basın.</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <form class="crm-call-note crm-live-note-form">
                <input type="hidden" name="customer_id" value="<?= e($selected['id']) ?>">
                <input type="hidden" name="call_key" value="<?= e($activeCallKey) ?>">
                <textarea name="note" maxlength="3000" rows="4" autofocus
                    placeholder="Görüşmeyle ilgili notunuzu yazın…"><?= e($activeCallNote) ?></textarea>
                <button class="btn primary" type="submit">Notu kaydet</button>
            </form>
        </article>
    <?php endif; ?>

    <article class="card flush">
        <div class="crm-section-head">
            <div>
                <h2>Görüşme geçmişi</h2>
                <p class="muted"><?= count($calls) ?> görüşme bulundu.</p>
            </div>
        </div>
        <div class="crm-call-list">
            <?php foreach ($calls as $callIndex => $call): ?>
                <?php
                $callKey = (string) $call['crm_call_key'];
                $recording = crm_recording_for_call($call, $recordings);
                $note = (string) ($notesByCall[$callKey]['note'] ?? '');
                $incoming = ($call['crm_direction'] ?? '') === 'incoming';
                ?>
                <section class="crm-call <?= $callIndex >= 2 ? 'crm-call-extra' : '' ?> <?= $callKey === $targetCallKey ? 'crm-call-target' : '' ?>"
                    <?= $callIndex >= 2 && $callKey !== $targetCallKey ? 'hidden' : '' ?>>
                    <div class="crm-call-meta">
                        <span class="pill <?= $incoming ? 'ok' : 'dim' ?>">
                            <?= $incoming ? 'Gelen' : 'Giden' ?>
                        </span>
                        <strong><?= e($call['start'] ?? '') ?></strong>
                        <span><?= e((string) ($call['billsec'] ?? 0)) ?> sn</span>
                        <span class="pill <?= ($call['disposition'] ?? '') === 'ANSWERED' ? 'ok' : 'bad' ?>">
                            <?= e($call['disposition'] ?? '') ?>
                        </span>
                        <span class="muted">Dahili: <?= e($incoming ? ($call['dst'] ?? '—') : ($call['src'] ?? '—')) ?></span>
                    </div>
                    <?php if ($recording): ?>
                        <audio controls preload="none"
                            src="play.php?dept=<?= e($recording['dept']) ?>&f=<?= e($recording['relative_path'] ?? $recording['file']) ?>"></audio>
                    <?php else: ?>
                        <span class="muted">Ses kaydı yok</span>
                    <?php endif; ?>
                    <form class="crm-call-note">
                        <input type="hidden" name="customer_id" value="<?= e($selected['id']) ?>">
                        <input type="hidden" name="call_key" value="<?= e($callKey) ?>">
                        <textarea name="note" maxlength="3000" rows="2"
                            placeholder="Bu görüşme için not yazın…"><?= e($note) ?></textarea>
                        <button class="btn sm" type="submit">Notu kaydet</button>
                    </form>
                </section>
            <?php endforeach; ?>
            <?php if (count($calls) > 2): ?>
                <div class="crm-call-more">
                    <button class="btn" type="button" id="toggleCrmCalls"
                        data-hidden-count="<?= count($calls) - 2 ?>">
                        Tüm görüşmeleri göster (<?= count($calls) - 2 ?>)
                    </button>
                </div>
            <?php endif; ?>
            <?php if (!$calls): ?>
                <p class="muted crm-no-calls">Bu müşterinin telefonlarıyla eşleşen görüşme bulunamadı.</p>
            <?php endif; ?>
        </div>
    </article>
<?php else: ?>
    <article class="card flush">
        <table>
            <thead>
            <tr>
                <?php if (current_dept_id() === null): ?><th>Firma</th><?php endif; ?>
                <th>Müşteri</th>
                <th>Yetkili</th>
                <th>Telefon</th>
                <th>E-posta</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($customers as $customer): ?>
                <tr>
                    <?php if (current_dept_id() === null): ?><td><?= e(dept_name((string) $customer['dept'])) ?></td><?php endif; ?>
                    <td><strong><?= e($customer['company'] ?? '') ?></strong></td>
                    <td><?= e($customer['contact'] ?: '—') ?></td>
                    <td><a class="crm-phone-dial" target="astera-webphone"
                        href="webphone.php?dial=<?= rawurlencode((string) ($customer['phone'] ?? '')) ?>"
                        data-crm-dial="<?= e($customer['phone'] ?? '') ?>"><?= e($customer['phone'] ?? '') ?></a></td>
                    <td><?= e($customer['email'] ?: '—') ?></td>
                    <td class="right">
                        <a class="btn sm" href="<?= e($crmBase . $crmJoin . 'id=' . rawurlencode((string) $customer['id'])) ?>">Kartı aç</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$customers): ?>
                <tr><td colspan="<?= current_dept_id() === null ? 6 : 5 ?>" class="muted">Müşteri kartı bulunamadı.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </article>
    <?php if ($query !== '' && $matchingCallNotes): ?>
        <article class="card crm-note-results">
            <div class="crm-note-results-head">
                <div>
                    <h2>Görüşme notlarında bulunanlar</h2>
                    <p class="muted"><?= count($matchingCallNotes) ?> not eşleşti.</p>
                </div>
            </div>
            <div class="crm-note-result-list">
                <?php foreach (array_slice($matchingCallNotes, 0, 100) as $callNote): ?>
                    <?php $noteCustomer = $customersById[(string) ($callNote['customer_id'] ?? '')] ?? null; ?>
                    <?php if (!$noteCustomer) continue; ?>
                    <?php
                    $noteCall = $matchingNoteCalls[(string) ($callNote['id'] ?? '')] ?? null;
                    $noteRecording = trim((string) ($noteCall['recordingfile'] ?? ''), '/');
                    ?>
                    <section class="crm-note-result">
                        <div class="crm-note-result-meta">
                            <strong><?= e($noteCustomer['company'] ?? '') ?></strong>
                            <span><a class="crm-phone-dial" target="astera-webphone"
                                href="webphone.php?dial=<?= rawurlencode((string) ($noteCustomer['phone'] ?? '')) ?>"
                                data-crm-dial="<?= e($noteCustomer['phone'] ?? '') ?>"><?= e($noteCustomer['phone'] ?? '') ?></a></span>
                            <small><?= e(date(
                                'd.m.Y H:i',
                                strtotime((string) ($callNote['updated_at'] ?? 'now'))
                            )) ?></small>
                        </div>
                        <div class="crm-note-result-content">
                            <p><?= nl2br(e($callNote['note'] ?? '')) ?></p>
                            <?php if ($noteCall): ?>
                                <div class="crm-note-call-meta">
                                    <span><?= e($noteCall['start'] ?? '') ?></span>
                                    <span><?= ($noteCall['crm_direction'] ?? '') === 'incoming' ? 'Gelen' : 'Giden' ?></span>
                                    <span><?= e((string) ($noteCall['billsec'] ?? 0)) ?> sn</span>
                                </div>
                                <?php if ($noteRecording !== ''): ?>
                                    <audio controls preload="none"
                                        src="play.php?dept=<?= e($noteCustomer['dept']) ?>&f=<?= e($noteRecording) ?>"></audio>
                                <?php else: ?>
                                    <small class="muted">Bu görüşmenin ses kaydı yok.</small>
                                <?php endif; ?>
                            <?php else: ?>
                                <small class="muted">Görüşme kaydı henüz CDR ile eşleşmedi.</small>
                            <?php endif; ?>
                        </div>
                        <a class="btn sm"
                            href="<?= e($crmBase . $crmJoin . http_build_query([
                                'id' => (string) $noteCustomer['id'],
                                'call' => (string) ($callNote['call_key'] ?? ''),
                            ])) ?>">
                            Bu notu aç
                        </a>
                    </section>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => Astera.pageCrm({
    prefillPhone: <?= json_encode($phoneQuery) ?>,
    baseUrl: <?= json_encode($crmBase) ?>,
    searchQuery: <?= json_encode($query) ?>
}));
</script>
