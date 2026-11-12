<?php
if (!is_super()) {
    http_response_code(403);
    exit('Yetkisiz');
}
$fail2banFlash = (array) ($_SESSION['fail2ban_flash'] ?? []);
unset($_SESSION['fail2ban_flash']);
$fail2banError = '';
try {
    $fail2banStatus = astera_fail2ban_status();
} catch (Throwable $exception) {
    $fail2banError = $exception->getMessage();
    $fail2banStatus = [
        'installed' => false, 'service' => 'inactive', 'jail' => 'inactive',
        'enabled' => false, 'bantime' => 86400, 'findtime' => 600,
        'maxretry' => 5, 'ignoreip' => '', 'currently_banned' => 0,
        'mail_enabled' => false, 'mail_recipient' => 'teknik@asterateknoloji.com.tr',
        'total_banned' => 0, 'banned_ips' => [],
    ];
}
?>
<style>
.f2b-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.f2b-stat{padding:14px;border:1px solid var(--line);border-radius:12px;background:var(--surface)}
.f2b-stat small,.f2b-stat strong{display:block}.f2b-stat small{color:var(--muted);margin-bottom:5px}
.f2b-stat strong{font-size:18px}.f2b-form{grid-template-columns:repeat(3,minmax(0,1fr));align-items:end}
.f2b-form .wide{grid-column:1/-1}.f2b-ip-actions{display:flex;gap:8px;align-items:end;flex-wrap:wrap}
.f2b-form label{display:grid;gap:6px;min-width:0}.f2b-form textarea{width:100%;min-height:220px;resize:vertical}
.f2b-row-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
.f2b-ip-actions label{flex:1;min-width:220px}@media(max-width:760px){.f2b-summary,.f2b-form{grid-template-columns:1fr}.f2b-form .wide{grid-column:auto}}
</style>

<div class="toolbar">
    <div>
        <p class="muted">SIP parola denemelerini ve tanımsız endpoint taramalarını izleyerek saldırgan IP adreslerini geçici olarak engeller.</p>
        <p class="muted">Yerel ağlar daima izin listesindedir. Sabit trunk IP’lerini aşağıdaki izin listesine ekleyin.</p>
    </div>
    <button class="btn" type="button" onclick="location.reload()">Yenile</button>
</div>

<?php if ($fail2banFlash): ?>
    <p class="alert <?= !empty($fail2banFlash['error']) ? 'bad' : 'good' ?>"><?= e((string) ($fail2banFlash['message'] ?? '')) ?></p>
<?php endif; ?>
<?php if ($fail2banError !== ''): ?>
    <p class="alert bad"><?= e($fail2banError) ?></p>
<?php endif; ?>

<article class="card">
    <h2>Fail2ban Durumu</h2>
    <div class="f2b-summary">
        <div class="f2b-stat"><small>Paket</small><strong><?= $fail2banStatus['installed'] ? 'Kurulu' : 'Kurulu değil' ?></strong></div>
        <div class="f2b-stat"><small>Servis</small><strong><?= e(strtoupper((string) $fail2banStatus['service'])) ?></strong></div>
        <div class="f2b-stat"><small>Asterisk jail</small><strong><?= e(strtoupper((string) $fail2banStatus['jail'])) ?></strong></div>
        <div class="f2b-stat"><small>Engellenen / toplam</small><strong><?= (int) $fail2banStatus['currently_banned'] ?> / <?= (int) $fail2banStatus['total_banned'] ?></strong></div>
    </div>
</article>

<article class="card">
    <h2>Asterisk Koruma Ayarları</h2>
    <form method="post" action="fail2ban.php" class="form-grid f2b-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="configure">
        <label class="wide"><span><input type="checkbox" name="enabled" value="1" <?= !empty($fail2banStatus['enabled']) ? 'checked' : '' ?>> Asterisk Fail2ban korumasını etkinleştir</span></label>
        <label>Engelleme süresi (sn)<input type="number" name="bantime" min="60" max="31536000" value="<?= (int) $fail2banStatus['bantime'] ?>"></label>
        <label>Takip aralığı (sn)<input type="number" name="findtime" min="10" max="86400" value="<?= (int) $fail2banStatus['findtime'] ?>"></label>
        <label>Azami hata<input type="number" name="maxretry" min="1" max="100" value="<?= (int) $fail2banStatus['maxretry'] ?>"></label>
        <label class="wide"><span><input type="checkbox" name="mail_enabled" value="1" <?= !empty($fail2banStatus['mail_enabled']) ? 'checked' : '' ?>> Yeni bir IP engellendiğinde e-posta gönder</span></label>
        <label class="wide">Bildirim e-posta adresi
            <input type="email" name="mail_recipient" required multiple maxlength="1000" value="<?= e((string) $fail2banStatus['mail_recipient']) ?>">
            <small>Birden fazla adresi virgülle ayırın. Bildirimde sunucu, müşteri, engellenen IP, hata sayısı ve son Asterisk olayları yer alır.</small>
        </label>
        <label class="wide">İzinli IP adresleri / ağlar
            <textarea name="ignoreip" rows="10" placeholder="85.29.45.11/32"><?= e(str_replace(' ', "\n", (string) $fail2banStatus['ignoreip'])) ?></textarea>
            <small>Her satıra bir IP veya CIDR ağı yazın. Bu adresler hiçbir zaman engellenmez.</small>
        </label>
        <div class="modal-actions wide"><button class="btn primary" type="submit">Kaydet ve Fail2ban’a Uygula</button></div>
    </form>
</article>

<article class="card">
    <h2>Engellenen IP Adresleri</h2>
    <div class="f2b-ip-actions">
        <form method="post" action="fail2ban.php" class="f2b-ip-actions">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="banip">
            <label>IP adresini elle engelle<input name="ip" required placeholder="195.214.172.4"></label>
            <button class="btn danger" type="submit">Engelle</button>
        </form>
    </div>
    <table style="margin-top:14px">
        <thead><tr><th>IP adresi</th><th></th></tr></thead>
        <tbody>
        <?php foreach ((array) $fail2banStatus['banned_ips'] as $ip): ?>
            <tr>
                <td><code><?= e((string) $ip) ?></code></td>
                <td class="right f2b-row-actions">
                    <form method="post" action="fail2ban.php" onsubmit="return confirm('IP engeli kaldırılsın mı?')">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="unbanip">
                        <input type="hidden" name="ip" value="<?= e((string) $ip) ?>">
                        <button class="btn sm" type="submit">Engeli kaldır</button>
                    </form>
                    <form method="post" action="fail2ban.php" onsubmit="return confirm('Bu IP kalıcı izin listesine eklensin ve engeli kaldırılsın mı?')">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="allowip">
                        <input type="hidden" name="ip" value="<?= e((string) $ip) ?>">
                        <button class="btn sm primary" type="submit">Kalıcı izin ver</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($fail2banStatus['banned_ips'])): ?>
            <tr><td colspan="2" class="muted">Şu anda engellenmiş IP adresi yok.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>
