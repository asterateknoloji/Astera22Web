<?php
$webphoneMode = !empty($webphoneMode);
if ($webphoneMode && !empty($webphoneExtension) && is_array($webphoneExtension)) {
    $rows = [$webphoneExtension];
    $pre = (string) ($webphoneExtension['exten'] ?? '');
    $showDept = false;
    $webphoneDept = (string) ($webphoneExtension['dept'] ?? '');
    $contacts = array_values(array_filter(
        store_read('extensions'),
        static fn($row) => (string) ($row['dept'] ?? '') === $webphoneDept
    ));
} else {
    $rows = scoped('extensions');
    $pre = preg_replace('/\D/', '', (string) ($_GET['ext'] ?? '')) ?? '';
    $showDept = current_dept_id() === null;
    $contacts = scoped('extensions');
}
$rtcRows = array_values(array_filter($rows, static fn($r) => !empty($r['webrtc'])));
$keys = [
    ['1', ''], ['2', 'ABC'], ['3', 'DEF'],
    ['4', 'GHI'], ['5', 'JKL'], ['6', 'MNO'],
    ['7', 'PQRS'], ['8', 'TUV'], ['9', 'WXYZ'],
    ['*', ''], ['0', '+'], ['#', ''],
];
?>
<?php if (!$rtcRows): ?>
    <p class="banner">Önce <a href="index.php?p=extensions">Dahili</a> sayfasında bir aboneyi düzenleyip <strong>WebRTC</strong> kutusunu işaretleyin.</p>
<?php endif; ?>

<div class="sip-wrap">
    <div class="sip-phone-column">
    <div class="sip-phone" id="sipPhone">
        <header class="sip-title">
            <span class="sip-caption">ASTERA — <span id="sipTitleExt">telefon</span></span>
            <select id="rtcExt" class="sip-acct" <?= $rtcRows ? '' : 'disabled' ?> title="Hesap"
                <?= $webphoneMode ? 'hidden aria-hidden="true"' : '' ?>>
                <option value="">Abone</option>
                <?php foreach ($rtcRows as $row): ?>
                    <option value="<?= e($row['exten']) ?>"
                        data-sip="<?= e($row['sipuser'] ?? $row['exten']) ?>"
                        data-auth="<?= e($row['authuser'] ?? $row['sipuser'] ?? $row['exten']) ?>"
                        data-pass="<?= e($row['password'] ?? '') ?>"
                        data-name="<?= e($row['name'] ?? $row['exten']) ?>"
                        data-dept="<?= e($row['dept'] ?? '') ?>"
                        <?= $pre !== '' && $pre === (string) $row['exten'] ? 'selected' : '' ?>>
                        <?= e($row['exten'] . ' · ' . ($row['name'] ?? '') . ($showDept ? ' (' . dept_name((string) $row['dept']) . ')' : '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="sip-win" aria-hidden="true"><i></i><i></i><i></i></span>
        </header>

        <nav class="sip-tabs">
            <button type="button" class="on" data-tab="keys">Tuşlar</button>
            <button type="button" data-tab="calls">Aramalar</button>
            <?php if (!$webphoneMode): ?>
                <button type="button" data-tab="people">Kişiler</button>
            <?php endif; ?>
        </nav>

        <div class="sip-incoming" id="sipIncoming" hidden>
            <p>Gelen çağrı: <strong id="sipFrom">—</strong></p>
            <div class="sip-in-actions">
                <button type="button" class="sip-main hang" id="rtcRejectIn">Reddet</button>
                <button type="button" class="sip-main answer" id="rtcAnswer">Cevapla</button>
            </div>
        </div>

        <div class="sip-pane on" data-pane="keys">
            <div class="sip-combo">
                <input id="rtcNum" class="sip-display" type="text" inputmode="tel" autocomplete="off" spellcheck="false" placeholder="">
                <button type="button" class="sip-combo-btn" id="sipHistBtn" title="Aramalar">▾</button>
            </div>
            <div class="sip-pad" id="sipPad">
                <?php foreach ($keys as [$d, $letters]): ?>
                    <button type="button" class="sip-key" data-digit="<?= e($d) ?>">
                        <strong><?= e($d) ?></strong>
                        <?php if ($letters !== '' && $d !== '0'): ?><small><?= e($letters) ?></small><?php endif; ?>
                        <?php if ($d === '0'): ?><small>+</small><?php endif; ?>
                    </button>
                <?php endforeach; ?>
                <button type="button" class="sip-key sip-util" id="sipBack" title="Sil">&lt;</button>
                <button type="button" class="sip-key sip-util" id="sipPlus" data-digit="+">+</button>
                <button type="button" class="sip-key sip-util" id="sipClear">C</button>
            </div>

            <div class="sip-callrow">
                <button type="button" class="sip-icon" id="rtcHold" disabled title="Beklet">❚❚</button>
                <button type="button" class="sip-main idle" id="rtcAction">Ara</button>
                <button type="button" class="sip-icon" id="rtcXfer" disabled title="Aktar">
                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M16 3h5v5h-2V6.4l-6.3 6.3-1.4-1.4L17.6 5H16V3zM5 7h6v2H7v8h8v-4h2v6H5V7z"/></svg>
                </button>
            </div>
            <div class="sip-vols">
                <label class="sip-vol"><span>🔈</span><b>−</b><input id="sipSpk" type="range" min="0" max="100" value="80"><b>+</b></label>
                <label class="sip-vol"><span>🎤</span><b>−</b><input id="sipMic" type="range" min="0" max="100" value="80"><b>+</b></label>
            </div>
            <div class="sip-feats">
                <button type="button" class="sip-feat" id="sipDnd">DND</button>
                <button type="button" class="sip-feat" id="rtcAutoBtn" data-on="0">AA</button>
                <button type="button" class="sip-feat" id="sipConf">CONF</button>
            </div>
            <input type="checkbox" id="rtcAuto" hidden>
        </div>

        <div class="sip-pane" data-pane="calls" hidden>
            <div id="sipHistory" class="sip-list"><p class="muted">Henüz arama yok.</p></div>
        </div>
        <?php if (!$webphoneMode): ?>
            <div class="sip-pane" data-pane="people" hidden>
                <div id="sipPeople" class="sip-list">
                    <?php foreach ($contacts as $c): ?>
                        <button type="button" class="sip-person"
                            data-num="<?= e($c['exten']) ?>" data-sip="<?= e(sip_user($c)) ?>">
                            <strong><?= e($c['exten']) ?></strong>
                            <span><?= e($c['name'] ?? '') ?> · <?= e(dept_name((string) ($c['dept'] ?? ''))) ?></span>
                        </button>
                    <?php endforeach; ?>
                    <?php if (!$contacts): ?><p class="muted">Kişi yok.</p><?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <footer class="sip-bar">
            <span class="sip-led" id="sipLed" title="Durum">☎</span>
            <span id="rtcState">Kapalı</span>
            <span id="sipTimer" class="sip-timer"></span>
            <span id="sipAccount" class="sip-right"></span>
        </footer>
        <audio id="rtcRemote" autoplay></audio>
    </div>
    <?php if ($webphoneMode): ?>
        <div class="webphone-phone-controls">
            <button class="btn primary" type="button" id="rtcConnect" <?= $rtcRows ? '' : 'disabled' ?>>Bağlan</button>
            <button class="btn" type="button" id="rtcDisconnect" disabled>Kopar</button>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <button class="btn" type="submit" name="logout" value="1">Çıkış yap</button>
            </form>
            <a class="btn" href="crm_popup.php" target="astera-crm" rel="opener">Müşteri Kartları</a>
        </div>
        <p class="webphone-phone-note">
            AA = gelen çağrıyı otomatik aç. DND = rahatsız etme (*78 / *79).
            Görüşmede tuşlar DTMF gönderir.
        </p>
    <?php endif; ?>
    </div>

    <div class="sip-help">
        <?php if ($webphoneMode): ?>
            <div class="blf-board-head">
                <div>
                    <h2>Dahili Durumları</h2>
                    <p>Aynı bölümdeki aboneler</p>
                </div>
                <span class="blf-live"><i></i> Canlı</span>
            </div>
            <label class="blf-search">
                <span aria-hidden="true">⌕</span>
                <input id="blfSearch" type="search" autocomplete="off"
                    placeholder="Dahili veya isim ara">
            </label>
            <div class="blf-filters" id="blfFilters">
                <button type="button" class="active" data-blf-filter="all">Tümü <b data-blf-count="all">0</b></button>
                <button type="button" data-blf-filter="available">Müsait <b data-blf-count="available">0</b></button>
                <button type="button" data-blf-filter="busy">Meşgul <b data-blf-count="busy">0</b></button>
                <button type="button" data-blf-filter="offline">Çevrimdışı <b data-blf-count="offline">0</b></button>
            </div>
            <div class="sip-blf-board">
                <?php foreach ($contacts as $c): ?>
                    <button type="button" class="sip-person sip-contact sip-blf-entry"
                        data-num="<?= e($c['exten']) ?>" data-sip="<?= e(sip_user($c)) ?>">
                        <i class="blf-dot offline" aria-hidden="true"></i>
                        <span class="sip-contact-copy">
                            <strong><?= e($c['exten']) ?></strong>
                            <small><?= e($c['name'] ?? '') ?></small>
                            <em class="blf-label">Durum bekleniyor</em>
                            <b class="blf-peer" hidden></b>
                        </span>
                    </button>
                <?php endforeach; ?>
                <?php if (!$contacts): ?><p class="muted">Bu bölümde dahili yok.</p><?php endif; ?>
                <p class="muted blf-empty" id="blfEmpty" hidden>Eşleşen dahili bulunamadı.</p>
            </div>
        <?php else: ?>
            <p class="muted">
                MicroSIP gibi tuşlarla arayın. Aboneyi seçin, numarayı tuşlayıp
                <strong>Ara</strong> deyin; ilk aramada kayıt da yapılır. Gelen çağrı bu pencerede çalar.
            </p>
        <?php endif; ?>
        <?php if (!$webphoneMode): ?>
            <div class="sip-help-actions">
                <button class="btn primary" type="button" id="rtcConnect" <?= $rtcRows ? '' : 'disabled' ?>>Bağlan</button>
                <button class="btn" type="button" id="rtcDisconnect" disabled>Kopar</button>
            </div>
            <p class="tiny muted">AA = gelen çağrıyı otomatik aç. DND = rahatsız etme (*78 / *79). Görüşmede tuşlar DTMF gönderir.</p>
        <?php endif; ?>
    </div>
</div>

<button type="button" id="rtcCall" hidden>Ara</button>
<button type="button" id="rtcHang" hidden>Kapat</button>
<button type="button" id="rtcMute" hidden>Sessiz</button>
<button type="button" id="rtcAnswer2" hidden></button>
<button type="button" id="rtcReject" hidden></button>
<div id="rtcIncoming" hidden></div>
<form id="rtcForm" hidden></form>

<script>
window.ASTERA = window.ASTERA || {};
window.ASTERA.extIndex = <?= json_encode(array_map(static fn($r) => [
    'exten' => (string) ($r['exten'] ?? ''),
    'dept' => (string) ($r['dept'] ?? ''),
    'deptName' => dept_name((string) ($r['dept'] ?? '')),
    'sip' => sip_user($r),
    'name' => (string) ($r['name'] ?? ''),
], $contacts), JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/js/jssip.min.js?v=312"></script>
<script src="assets/js/webrtc.js?v=28"></script>
<script>document.addEventListener('DOMContentLoaded', () => AsteraRtc.init());</script>
