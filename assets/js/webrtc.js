const AsteraRtc = {
    ua: null,
    session: null,
    muted: false,
    held: false,
    dnd: false,
    ac: null,
    ringNodes: null,
    titleTimer: null,
    callTimer: null,
    autoAnswerTimer: null,
    blfTimer: null,
    blfClockTimer: null,
    blfFilter: 'all',
    microphoneReady: null,
    localStream: null,
    callStarted: 0,
    pendingCall: null,
    incoming: false,
    incomingNumber: '',
    origTitle: document.title,
    history: [],
    crmPrevious: null,
    crmSeen: {},

    init() {
        const connectBtn = document.getElementById('rtcConnect');
        if (!connectBtn || typeof JsSIP === 'undefined') {
            if (document.getElementById('rtcState')) {
                document.getElementById('rtcState').textContent = 'JsSIP yüklenemedi';
            }
            return;
        }
        const sel = document.getElementById('rtcExt');
        if (sel && !sel.value) {
            const first = [...sel.options].find((o) => o.value);
            if (first) sel.value = first.value;
        }
        this.syncAccount();
        sel?.addEventListener('change', () => this.syncAccount());
        const autoEnabled = localStorage.getItem('asteraRtcAutoAnswer') === '1';
        document.getElementById('rtcAuto').checked = autoEnabled;
        document.getElementById('rtcAutoBtn').classList.toggle('on', autoEnabled);
        document.getElementById('rtcAutoBtn').dataset.on = autoEnabled ? '1' : '0';
        document.getElementById('rtcAutoBtn').setAttribute('aria-pressed', autoEnabled ? 'true' : 'false');

        connectBtn.onclick = () => this.connect();
        document.getElementById('rtcDisconnect').onclick = () => this.disconnect();
        document.getElementById('rtcAction').onclick = () => this.mainAction();
        document.getElementById('rtcAnswer').onclick = () => this.answer();
        document.getElementById('rtcRejectIn').onclick = () => this.hangup();
        document.getElementById('rtcHold').onclick = () => this.toggleHold();
        document.getElementById('rtcXfer').onclick = () => this.transfer();
        document.getElementById('sipDnd').onclick = () => this.toggleDnd();
        document.getElementById('rtcAutoBtn').onclick = () => this.toggleAuto();
        document.getElementById('sipConf').onclick = () => {
            Astera.toast('Konferans dahilini tuşlayıp Ara');
        };
        document.getElementById('sipBack').onclick = () => this.backspace();
        document.getElementById('sipClear').onclick = () => this.clearNumber();
        document.getElementById('sipPad').addEventListener('click', (e) => {
            const b = e.target.closest('[data-digit]');
            if (b) this.press(b.dataset.digit);
        });
        document.getElementById('rtcNum').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.mainAction();
            } else if (/^[0-9*#+]$/.test(e.key)) {
                this.playKeyTone(e.key);
            } else if (e.key === 'Backspace' || e.key === 'Delete') {
                this.playKeyTone('clear');
            }
        });
        document.getElementById('sipSpk').oninput = () => this.applyVolumes();
        document.getElementById('sipMic').oninput = () => this.applyVolumes();
        document.getElementById('sipHistBtn')?.addEventListener('click', () => this.tab('calls'));
        document.querySelectorAll('.sip-tabs [data-tab]').forEach((b) => {
            b.onclick = () => this.tab(b.dataset.tab);
        });
        document.querySelectorAll('.sip-person').forEach((b) => {
            b.onclick = () => {
                document.getElementById('rtcNum').value = b.dataset.num;
                this.tab('keys');
                this.call();
            };
        });
        document.getElementById('blfSearch')?.addEventListener('input', () => this.applyBlfFilter());
        document.querySelectorAll('[data-blf-filter]').forEach((button) => {
            button.onclick = () => {
                this.blfFilter = button.dataset.blfFilter || 'all';
                document.querySelectorAll('[data-blf-filter]').forEach((item) => {
                    item.classList.toggle('active', item === button);
                });
                this.applyBlfFilter();
            };
        });
        document.addEventListener('keydown', (e) => {
            if (e.target && ['INPUT', 'SELECT', 'TEXTAREA'].includes(e.target.tagName) && e.target.id !== 'rtcNum') return;
            if (e.target && e.target.id === 'rtcNum') return;
            if (/^[0-9*#]$/.test(e.key)) this.press(e.key);
            if (e.key === 'Backspace') this.backspace();
        });
        window.addEventListener('message', (event) => {
            if (
                event.origin === location.origin
                && event.data?.type === 'astera-webphone-call'
            ) {
                this.dialFromCrm(event.data.number || '');
            }
        });
        if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
            this.setState('mikrofon için localhost kullanın');
        }
        this.setState('Kapalı');
        this.setMode('idle');
        requestAnimationFrame(() => this.lockPaneSize());
        if (window.ASTERA.webphonePendingDial) {
            setTimeout(() => this.dialFromCrm(window.ASTERA.webphonePendingDial), 0);
        } else if (window.ASTERA.webphoneAutoConnect) {
            setTimeout(() => this.connect(), 0);
        }
        if (window.ASTERA.webphoneStatusUrl) {
            this.refreshBlf();
            this.blfTimer = setInterval(() => this.refreshBlf(), 3000);
            this.blfClockTimer = setInterval(() => this.renderBlfClocks(), 1000);
        }
    },

    lockPaneSize() {
        const keyPane = document.querySelector('.sip-pane[data-pane="keys"]');
        if (!keyPane) return;
        const height = Math.ceil(keyPane.getBoundingClientRect().height);
        if (height <= 0) return;
        document.querySelectorAll('.sip-pane').forEach((pane) => {
            pane.style.height = `${height}px`;
            pane.style.minHeight = `${height}px`;
        });
    },

    tab(name) {
        document.querySelectorAll('.sip-tabs [data-tab]').forEach((b) => b.classList.toggle('on', b.dataset.tab === name));
        document.querySelectorAll('.sip-pane').forEach((p) => {
            p.hidden = p.dataset.pane !== name;
            p.classList.toggle('on', p.dataset.pane === name);
        });
    },

    async refreshBlf() {
        try {
            const response = await fetch(window.ASTERA.webphoneStatusUrl, {
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (response.status === 401) {
                location.reload();
                return;
            }
            const data = await response.json();
            if (!response.ok || !data.ok) return;
            const states = data.extensions || {};
            document.querySelectorAll('.sip-contact[data-sip]').forEach((contact) => {
                const current = states[contact.dataset.sip] || {
                    state: 'offline',
                    label: 'Çevrimdışı',
                };
                const dot = contact.querySelector('.blf-dot');
                const label = contact.querySelector('.blf-label');
                const peer = contact.querySelector('.blf-peer');
                if (dot) {
                    dot.className = 'blf-dot ' + current.state;
                }
                const showPeer = ['ringing', 'talking'].includes(current.state) && current.peer;
                if (peer) {
                    peer.hidden = true;
                    peer.textContent = '';
                }
                contact.dataset.blfState = current.state || 'offline';
                contact.dataset.blfLabel = current.label || 'Bilinmiyor';
                contact.dataset.blfPeer = showPeer ? current.peer : '';
                contact.dataset.blfElapsed = String(Number(current.elapsed_seconds || 0));
                contact.dataset.blfObservedAt = String(Date.now());
                contact.title = (current.label || '')
                    + (current.peer ? ' · ' + current.peer : '');
            });
            this.renderBlfClocks();
            this.applyBlfFilter();
            if (Array.isArray(data.recent_calls)) {
                this.history = data.recent_calls.slice(0, 10);
                this.renderHistory();
            }
            this.handleUrlTrigger(data.url_trigger, states);
        } catch (error) {
            // Son bilinen BLF durumunu bağlantı geri gelene kadar koru.
        }
    },

    renderBlfClocks() {
        document.querySelectorAll('.sip-contact[data-blf-state]').forEach((contact) => {
            const label = contact.querySelector('.blf-label');
            if (!label) return;
            const state = contact.dataset.blfState || 'offline';
            const baseLabel = contact.dataset.blfLabel || 'Bilinmiyor';
            const peer = contact.dataset.blfPeer || '';
            let text = baseLabel + (peer ? ' - ' + peer : '');
            if (state === 'ringing' || state === 'talking') {
                const base = Number(contact.dataset.blfElapsed || 0);
                const observedAt = Number(contact.dataset.blfObservedAt || Date.now());
                const elapsed = Math.max(0, base + Math.floor((Date.now() - observedAt) / 1000));
                const hours = Math.floor(elapsed / 3600);
                const minutes = Math.floor((elapsed % 3600) / 60);
                const seconds = elapsed % 60;
                const clock = hours > 0
                    ? [hours, minutes, seconds].map((part) => String(part).padStart(2, '0')).join(':')
                    : [minutes, seconds].map((part) => String(part).padStart(2, '0')).join(':');
                text += ' · ' + clock;
            }
            label.textContent = text;
        });
    },

    applyBlfFilter() {
        const query = String(document.getElementById('blfSearch')?.value || '')
            .trim().toLocaleLowerCase('tr-TR');
        const entries = [...document.querySelectorAll('.sip-blf-board .sip-blf-entry')];
        const counts = { all: entries.length, available: 0, busy: 0, offline: 0 };
        let visible = 0;
        entries.forEach((entry) => {
            const state = entry.dataset.blfState || 'offline';
            if (state === 'available') counts.available++;
            else if (state === 'ringing' || state === 'talking') counts.busy++;
            else counts.offline++;
            const identity = [
                entry.querySelector('strong')?.textContent || '',
                entry.querySelector('small')?.textContent || '',
            ].join(' ').toLocaleLowerCase('tr-TR');
            const stateMatches = this.blfFilter === 'all'
                || (this.blfFilter === 'busy' && (state === 'ringing' || state === 'talking'))
                || state === this.blfFilter;
            const matches = stateMatches && (query === '' || identity.includes(query));
            entry.hidden = !matches;
            if (matches) visible++;
        });
        Object.entries(counts).forEach(([name, count]) => {
            const element = document.querySelector(`[data-blf-count="${name}"]`);
            if (element) element.textContent = String(count);
        });
        const empty = document.getElementById('blfEmpty');
        if (empty) empty.hidden = visible !== 0;
    },

    handleUrlTrigger(config, states) {
        const account = this.selected();
        if (!config || !account || !states[account.sip]) {
            this.crmPrevious = null;
            return;
        }
        const current = states[account.sip];
        const previous = this.crmPrevious;
        const trigger = config.trigger || 'ring';
        let event = '';
        if (
            current.state === 'ringing'
            && current.direction === 'incoming'
            && current.peer
            && ['ring', 'both'].includes(trigger)
            && (!previous || previous.state !== 'ringing' || previous.event_id !== current.event_id)
        ) {
            event = 'ring';
        } else if (
            current.state === 'talking'
            && current.direction === 'incoming'
            && current.peer
            && ['answer', 'both'].includes(trigger)
            && (
                previous?.state !== 'talking'
                || previous?.event_id !== current.event_id
            )
        ) {
            event = 'answer';
        }
        this.crmPrevious = { ...current };
        if (!event) return;
        const eventKey = [account.sip, event, current.event_id || current.peer].join('|');
        if (this.crmSeen[eventKey]) return;
        this.crmSeen[eventKey] = true;

        const raw = String(current.peer || '');
        const digits = raw.replace(/\D+/g, '');
        let caller = config.number_format === 'raw' ? raw : digits;
        if (config.number_format === 'e164_tr') {
            caller = digits.startsWith('90')
                ? '+' + digits
                : (digits.startsWith('0') ? '+90' + digits.slice(1) : '+90' + digits);
        }
        const replacements = {
            '{caller}': encodeURIComponent(caller),
            '{extension}': encodeURIComponent(account.exten),
            '{department}': encodeURIComponent(config.dept || account.dept || ''),
            '{event}': encodeURIComponent(event),
            '{callid}': encodeURIComponent(current.event_id || ''),
        };
        let url = String(config.url_template || '');
        Object.entries(replacements).forEach(([key, value]) => {
            url = url.split(key).join(value);
        });
        if (!String(config.url_template || '').includes('{caller}')) {
            url += encodeURIComponent(caller);
        }
        const popup = window.open(url, 'astera-crm');
        if (popup) {
            try { popup.focus(); } catch (error) { /* cross-origin olabilir */ }
        } else {
            Astera.toast('CRM penceresi engellendi; açılır pencereye izin verin.', true);
        }
    },

    syncAccount() {
        const row = this.selected();
        document.getElementById('sipTitleExt').textContent = row ? row.exten : 'telefon';
        document.getElementById('sipAccount').textContent = row
            ? String(row.sip || row.exten).replace(/_/g, ' ')
            : '';
    },

    press(d) {
        this.playKeyTone(d);
        if (this.session && this.session.isEstablished && this.session.isEstablished()) {
            try { this.session.sendDTMF(d); } catch (e) { /* ignore */ }
        }
        const inp = document.getElementById('rtcNum');
        inp.value += d;
        inp.focus();
    },

    backspace() {
        this.playKeyTone('clear');
        const inp = document.getElementById('rtcNum');
        inp.value = inp.value.slice(0, -1);
    },

    clearNumber() {
        this.playKeyTone('clear');
        document.getElementById('rtcNum').value = '';
    },

    dialFromCrm(number) {
        const cleanNumber = String(number || '').trim().replace(/[^0-9+*#]/g, '');
        if (!cleanNumber) {
            Astera.toast('Aranacak telefon numarası geçersiz', true);
            return false;
        }
        try { window.focus(); } catch (error) { /* tarayıcı engelleyebilir */ }
        if (this.session) {
            Astera.toast('Önce mevcut çağrıyı bitirin', true);
            return false;
        }
        document.getElementById('rtcNum').value = cleanNumber;
        this.tab('keys');
        this.call();
        return true;
    },

    mainAction() {
        if (this.incoming || document.getElementById('sipPhone')?.dataset.mode === 'in') {
            this.answer();
            return;
        }
        if (this.session) this.hangup();
        else this.call();
    },

    setMode(mode) {
        const phone = document.getElementById('sipPhone');
        phone.dataset.mode = mode;
        const act = document.getElementById('rtcAction');
        const banner = document.getElementById('sipIncoming');
        document.getElementById('rtcHold').disabled = mode !== 'talk';
        document.getElementById('rtcXfer').disabled = mode !== 'talk';
        if (mode === 'in') {
            this.tab('keys');
            act.textContent = 'Cevapla';
            act.className = 'sip-main answer';
            if (banner) banner.hidden = false;
            document.getElementById('rtcAnswer').disabled = false;
        } else if (mode === 'out' || mode === 'talk') {
            act.textContent = 'Çağrıyı bitir';
            act.className = 'sip-main hang';
            if (banner) banner.hidden = true;
        } else {
            act.textContent = 'Ara';
            act.className = 'sip-main idle';
            if (banner) banner.hidden = true;
            document.getElementById('rtcAnswer').disabled = true;
        }
        document.getElementById('sipLed').classList.toggle('on', !!(this.ua && this.ua.isRegistered && this.ua.isRegistered()));
        document.getElementById('sipLed').classList.toggle('call', mode === 'talk' || mode === 'in' || mode === 'out');
    },

    setState(t, ok) {
        const el = document.getElementById('rtcState');
        el.textContent = t;
        el.classList.toggle('ok', !!ok);
    },

    selected() {
        const sel = document.getElementById('rtcExt');
        const opt = sel?.selectedOptions[0];
        if (!opt || !opt.value) return null;
        return {
            exten: opt.value,
            sip: opt.dataset.sip,
            auth: opt.dataset.auth,
            pass: opt.dataset.pass,
            name: opt.dataset.name,
            dept: opt.dataset.dept || '',
        };
    },

    findExt(num) {
        const all = window.ASTERA.extIndex || [];
        const me = this.selected();
        const same = me ? all.find((x) => String(x.exten) === String(num) && x.dept === me.dept) : null;
        return same || all.find((x) => String(x.exten) === String(num));
    },

    unlockAudio() {
        try {
            const AudioEngine = window.AudioContext || window.webkitAudioContext;
            this.ac = this.ac || (AudioEngine ? new AudioEngine() : null);
            if (this.ac.state === 'suspended') this.ac.resume();
        } catch (e) { /* ignore */ }
        if (window.Notification && Notification.permission === 'default') {
            Notification.requestPermission().catch(() => {});
        }
    },

    playKeyTone(key) {
        const dtmf = {
            '1': [697, 1209], '2': [697, 1336], '3': [697, 1477],
            '4': [770, 1209], '5': [770, 1336], '6': [770, 1477],
            '7': [852, 1209], '8': [852, 1336], '9': [852, 1477],
            '*': [941, 1209], '0': [941, 1336], '#': [941, 1477],
            '+': [941, 1336], clear: [350, 440],
        };
        const frequencies = dtmf[key];
        if (!frequencies) return;
        this.unlockAudio();
        if (!this.ac) return;
        const now = this.ac.currentTime;
        const duration = key === 'clear' ? 0.07 : 0.12;
        const volume = Number(document.getElementById('sipSpk')?.value || 80) / 100;
        const gain = this.ac.createGain();
        const oscillators = frequencies.map((frequency) => {
            const oscillator = this.ac.createOscillator();
            oscillator.frequency.setValueAtTime(frequency, now);
            oscillator.connect(gain);
            return oscillator;
        });
        gain.gain.setValueAtTime(0.0001, now);
        gain.gain.exponentialRampToValueAtTime(Math.max(0.001, 0.08 * volume), now + 0.008);
        gain.gain.setValueAtTime(Math.max(0.001, 0.08 * volume), now + Math.max(0.009, duration - 0.015));
        gain.gain.exponentialRampToValueAtTime(0.0001, now + duration);
        gain.connect(this.ac.destination);
        oscillators.forEach((oscillator) => {
            oscillator.start(now);
            oscillator.stop(now + duration + 0.01);
        });
    },

    startRing() {
        this.stopRing();
        this.unlockAudio();
        if (!this.ac) return;
        const o1 = this.ac.createOscillator();
        const o2 = this.ac.createOscillator();
        const g = this.ac.createGain();
        o1.frequency.value = 440;
        o2.frequency.value = 480;
        g.gain.value = 0;
        o1.connect(g);
        o2.connect(g);
        g.connect(this.ac.destination);
        o1.start();
        o2.start();
        this.ringNodes = { o1, o2, g };
        const ringPulse = () => {
            const now = this.ac.currentTime;
            const volume = Number(document.getElementById('sipSpk')?.value || 80) / 100;
            const level = Math.max(0.001, 0.09 * volume);
            g.gain.cancelScheduledValues(now);
            g.gain.setValueAtTime(0.0001, now);
            g.gain.exponentialRampToValueAtTime(level, now + 0.02);
            g.gain.setValueAtTime(level, now + 1.98);
            g.gain.exponentialRampToValueAtTime(0.0001, now + 2);
        };
        ringPulse();
        this.ringNodes.iv = setInterval(ringPulse, 6000);
        this.origTitle = document.title;
        this.titleTimer = setInterval(() => {
            document.title = document.title.indexOf('Çağrı') === 0 ? this.origTitle : 'Çağrı — ' + this.origTitle;
        }, 600);
    },

    stopRing() {
        if (this.ringNodes) {
            clearInterval(this.ringNodes.iv);
            try { this.ringNodes.o1.stop(); this.ringNodes.o2.stop(); } catch (e) { /* ignore */ }
            this.ringNodes = null;
        }
        if (this.titleTimer) {
            clearInterval(this.titleTimer);
            this.titleTimer = null;
            document.title = this.origTitle;
        }
    },

    startCallClock() {
        this.stopCallClock();
        this.callStarted = Date.now();
        const tick = () => {
            const s = Math.floor((Date.now() - this.callStarted) / 1000);
            const m = String(Math.floor(s / 60)).padStart(1, '0');
            const sec = String(s % 60).padStart(2, '0');
            document.getElementById('sipTimer').textContent = m + ':' + sec;
        };
        tick();
        this.callTimer = setInterval(tick, 1000);
    },

    stopCallClock() {
        if (this.callTimer) clearInterval(this.callTimer);
        this.callTimer = null;
        document.getElementById('sipTimer').textContent = '';
    },

    logHist(dir, num, ok) {
        this.history.unshift({ dir, num, ok, at: new Date().toLocaleTimeString('tr-TR') });
        this.history = this.history.slice(0, 10);
        this.renderHistory();
    },

    renderHistory() {
        const box = document.getElementById('sipHistory');
        if (!box) return;
        box.innerHTML = this.history.map((h) =>
            `<button type="button" class="sip-person" data-num="${h.num}"><strong>${h.dir} ${h.num}</strong><span>${h.at}${h.ok ? '' : ' · başarısız'}</span></button>`
        ).join('') || '<p class="muted">Henüz arama yok.</p>';
        box.querySelectorAll('[data-num]').forEach((b) => {
            b.onclick = () => {
                document.getElementById('rtcNum').value = b.dataset.num;
                this.tab('keys');
            };
        });
    },

    incomingLabel(number, name = '') {
        const cleanName = String(name || '').trim();
        const cleanNumber = String(number || '').trim();
        return cleanName !== '' && cleanName !== cleanNumber
            ? cleanName + ' · ' + cleanNumber
            : cleanNumber;
    },

    async resolveIncomingCustomer(number, fallbackName = '') {
        const endpoint = window.ASTERA.webphoneCustomerUrl;
        if (!endpoint) return;
        try {
            const response = await fetch(endpoint + '?phone=' + encodeURIComponent(number), {
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const data = await response.json();
            if (!response.ok || !data.ok || !data.customer?.company) return;
            if (!this.incoming || this.incomingNumber !== number) return;
            const fromEl = document.getElementById('sipFrom');
            if (fromEl) {
                fromEl.textContent = this.incomingLabel(
                    number,
                    data.customer.company || fallbackName
                );
            }
        } catch (error) {
            // SIP üzerindeki arayan adı gösterilmeye devam eder.
        }
    },

    showIncoming(from, name = '') {
        this.incoming = true;
        this.incomingNumber = from;
        document.getElementById('rtcNum').value = from;
        const fromEl = document.getElementById('sipFrom');
        const label = this.incomingLabel(from, name);
        if (fromEl) fromEl.textContent = label;
        this.setMode('in');
        this.startRing();
        this.setState('Gelen çağrı', true);
        Astera.toast('Gelen çağrı: ' + label);
        if (window.Notification && Notification.permission === 'granted') {
            try { new Notification('ASTERA', { body: 'Gelen çağrı ' + label, silent: true }); } catch (e) { /* ignore */ }
        }
        this.resolveIncomingCustomer(from, name);
        if (document.getElementById('rtcAuto')?.checked) {
            clearTimeout(this.autoAnswerTimer);
            this.setState('Otomatik cevap hazırlanıyor…', true);
            Promise.resolve(this.microphoneReady || this.prepareMicrophone()).then((ready) => {
                if (!this.incoming || !this.session) return;
                if (!ready) {
                    this.setState('Mikrofon izni bekleniyor');
                    return;
                }
                this.autoAnswerTimer = setTimeout(() => {
                    this.autoAnswerTimer = null;
                    if (this.incoming && this.session) this.answer(true);
                }, 250);
            });
        }
    },

    hideIncoming() {
        if (this.autoAnswerTimer) {
            clearTimeout(this.autoAnswerTimer);
            this.autoAnswerTimer = null;
        }
        this.incoming = false;
        this.incomingNumber = '';
        const banner = document.getElementById('sipIncoming');
        if (banner) banner.hidden = true;
        this.stopRing();
    },

    connect() {
        const row = this.selected();
        if (!row) return Astera.toast('WebRTC açık bir abone seçin', true);
        this.unlockAudio();
        this.disconnect();
        this.prepareMicrophone();
        this.primeAudioOutput();
        const host = window.ASTERA.pbxHost;
        const socketUrl = location.protocol === 'https:'
            ? `wss://${location.host}/asterisk-ws`
            : window.ASTERA.ws;
        const socket = new JsSIP.WebSocketInterface(socketUrl);
        const ua = new JsSIP.UA({
            sockets: [socket],
            uri: 'sip:' + row.sip + '@' + host,
            password: row.pass,
            authorization_user: row.auth,
            display_name: row.name,
            register: true,
            session_timers: false,
            no_answer_timeout: 90,
            user_agent: 'ASTERA-WebRTC',
            connection_recovery_max_interval: 30,
        });
        ua.on('connected', () => this.setState('Soket açık…'));
        ua.on('disconnected', () => this.setState('Kopuk'));
        ua.on('registered', () => {
            this.setState(this.incoming ? 'Gelen çağrı' : 'Bağlandı', true);
            this.syncAccount();
            if (!this.session && !this.incoming) this.setMode('idle');
            document.getElementById('rtcDisconnect').disabled = false;
            Astera.toast('Kayıtlı: ' + row.exten);
            if (this.pendingCall) {
                const n = this.pendingCall;
                this.pendingCall = null;
                document.getElementById('rtcNum').value = n;
                this.call();
            }
        });
        ua.on('unregistered', () => this.setState('Kayıt yok'));
        ua.on('registrationFailed', (e) => {
            const why = (e && (e.cause || e.response?.reason_phrase)) || 'Kayıt hatası';
            this.setState(String(why));
            Astera.toast(String(why), true);
            this.pendingCall = null;
            document.getElementById('rtcConnect').disabled = false;
        });
        ua.on('newRTCSession', (e) => this.onSession(e));
        ua.start();
        this.ua = ua;
        this.setState('Bağlanıyor…');
        document.getElementById('rtcConnect').disabled = true;
        document.getElementById('rtcDisconnect').disabled = false;
    },

    disconnect() {
        this.hideIncoming();
        this.stopCallClock();
        if (this.session) {
            try { this.session.terminate(); } catch (e) { /* ignore */ }
            this.session = null;
        }
        if (this.ua) {
            try { this.ua.stop(); } catch (e) { /* ignore */ }
            this.ua = null;
        }
        if (this.localStream) {
            this.localStream.getTracks().forEach((track) => track.stop());
            this.localStream = null;
        }
        this.microphoneReady = null;
        document.getElementById('rtcConnect').disabled = false;
        document.getElementById('rtcDisconnect').disabled = true;
        this.setState('Kapalı');
        this.setMode('idle');
    },

    prepareMicrophone() {
        if (this.localStream?.getAudioTracks().some((track) => track.readyState === 'live')) {
            return Promise.resolve(true);
        }
        if (!navigator.mediaDevices?.getUserMedia) {
            this.microphoneReady = Promise.resolve(false);
            return this.microphoneReady;
        }
        if (this.microphoneReady) return this.microphoneReady;
        this.microphoneReady = navigator.mediaDevices.getUserMedia({ audio: true, video: false })
            .then((stream) => {
                this.localStream = stream;
                return true;
            })
            .catch((error) => {
                this.microphoneReady = null;
                Astera.toast('Otomatik cevap için mikrofon izni gerekli', true);
                return false;
            });
        return this.microphoneReady;
    },

    callMediaStream() {
        if (!this.localStream?.getAudioTracks().some((track) => track.readyState === 'live')) return undefined;
        return this.localStream.clone();
    },

    primeAudioOutput() {
        const audio = document.getElementById('rtcRemote');
        if (!audio || !this.ac) return;
        try {
            const destination = this.ac.createMediaStreamDestination();
            const oscillator = this.ac.createOscillator();
            const gain = this.ac.createGain();
            gain.gain.value = 0.0001;
            oscillator.connect(gain);
            gain.connect(destination);
            oscillator.start();
            audio.srcObject = destination.stream;
            audio.play().catch(() => {});
            setTimeout(() => {
                try { oscillator.stop(); } catch (e) { /* ignore */ }
                if (audio.srcObject === destination.stream && !this.session) audio.srcObject = null;
            }, 150);
        } catch (e) { /* ignore */ }
    },

    rtcOpts() {
        return {
            mediaConstraints: { audio: true, video: false },
            mediaStream: this.callMediaStream(),
            pcConfig: { iceServers: [] },
            eventHandlers: {
                peerconnection: (ev) => this.bindAudio(ev.peerconnection),
                progress: () => {
                    if (this.incoming) return;
                    this.setState('Çalıyor…');
                    this.setMode('out');
                },
                accepted: () => this.onTalk(),
                failed: (e) => {
                    const why = (e && e.cause) || 'arama başarısız';
                    this.setState(String(why));
                    Astera.toast(this.failText(why), true);
                    this.logHist('↗', document.getElementById('rtcNum').value, false);
                    this.clearCall();
                },
                ended: () => this.clearCall(),
            },
        };
    },

    onTalk() {
        this.hideIncoming();
        this.setState('Bağlandı', true);
        this.setMode('talk');
        this.startCallClock();
        this.held = false;
    },

    failText(why) {
        const s = String(why || '');
        if (/404|Not Found/i.test(s)) return 'Numara bu firmada yok.';
        if (/Denied|MEDIA/i.test(s)) return 'Mikrofon izni yok. localhost ile açın.';
        if (/Canceled/i.test(s)) return 'Arama iptal';
        if (/Busy/i.test(s)) return 'Meşgul';
        if (/Unavailable|480|NO_ANSWER/i.test(s)) return 'Cevap yok veya hedef kayıtlı değil.';
        return s;
    },

    bindAudio(pc) {
        if (!pc) return;
        pc.addEventListener('track', (tr) => {
            const audio = document.getElementById('rtcRemote');
            if (tr.streams && tr.streams[0]) {
                audio.srcObject = tr.streams[0];
                audio.play().catch(() => {
                    this.setState('Hoparlörü başlatmak için ekrana dokunun');
                });
                this.applyVolumes();
            }
        });
        this.applyVolumes();
    },

    applyVolumes() {
        const audio = document.getElementById('rtcRemote');
        const spk = Number(document.getElementById('sipSpk').value) / 100;
        const mic = Number(document.getElementById('sipMic').value) / 100;
        if (audio) audio.volume = spk;
        const pc = this.session && (this.session.connection || this.session._connection);
        try {
            pc?.getSenders()?.forEach((sender) => {
                if (sender.track && sender.track.kind === 'audio') {
                    sender.track.enabled = mic > 0;
                }
            });
        } catch (e) { /* ignore */ }
    },

    onSession(e) {
        try {
            const session = e.session;
            this.session = session;
            const incoming = e.originator === 'remote' || session.direction === 'incoming';
            if (incoming) {
                const from = session.remote_identity?.uri?.user
                    || session.remote_identity?.display_name
                    || '?';
                const fromName = session.remote_identity?.display_name || '';
                this.logHist('↙', from, true);
                this.showIncoming(from, fromName);
            } else {
                this.incoming = false;
                this.setState('Arıyor…');
                this.setMode('out');
            }
            session.on('peerconnection', (ev) => this.bindAudio(ev.peerconnection));
            session.on('ended', () => this.clearCall());
            session.on('failed', (ev) => {
                const why = (ev && ev.cause) || 'arama başarısız';
                this.setState(String(why));
                Astera.toast(this.failText(why), true);
                this.clearCall();
            });
            session.on('accepted', () => this.onTalk());
            session.on('progress', () => {
                if (this.incoming) return;
                this.setState('Çalıyor…');
                this.setMode('out');
            });
            session.on('hold', () => this.setState('Beklemede'));
            session.on('unhold', () => this.setState('Bağlandı', true));
            session.on('getusermediafailed', () => {
                this.setState('Mikrofon izni gerekli');
                Astera.toast('Çağrıyı açmak için tarayıcıda mikrofon izni verin', true);
            });
        } catch (err) {
            Astera.toast(err.message || 'Çağrı olayı hatası', true);
        }
    },

    call() {
        const num = (document.getElementById('rtcNum').value || '').trim();
        if (!num) return Astera.toast('Numara yazın', true);
        if (!this.ua || !this.ua.isRegistered()) {
            this.pendingCall = num;
            this.connect();
            return;
        }
        const me = this.selected();
        const dest = this.findExt(num);
        if (me && dest && dest.dept && me.dept && dest.dept !== me.dept) {
            return Astera.toast(num + ' başka firmada (' + (dest.deptName || dest.dept) + ').', true);
        }
        if (me && me.exten === num) {
            return Astera.toast('Kendi dahiliniz', true);
        }
        this.unlockAudio();
        this.logHist('↗', num, true);
        try {
            this.ua.call('sip:' + num + '@' + window.ASTERA.pbxHost, this.rtcOpts());
            this.setMode('out');
        } catch (err) {
            Astera.toast(err.message || 'Arama başlatılamadı', true);
        }
    },

    answer(auto = false, attempt = 0) {
        if (!this.session) {
            if (!auto) Astera.toast('Cevaplanacak çağrı yok', true);
            return false;
        }
        this.unlockAudio();
        try {
            this.session.answer({
                mediaConstraints: { audio: true, video: false },
                mediaStream: this.callMediaStream(),
                pcConfig: { iceServers: [] },
            });
            this.stopRing();
            if (auto) this.setState('Otomatik cevap bağlanıyor…', true);
            return true;
        } catch (err) {
            if (auto && this.incoming && attempt < 20) {
                clearTimeout(this.autoAnswerTimer);
                this.autoAnswerTimer = setTimeout(() => this.answer(true, attempt + 1), 150);
            } else {
                Astera.toast(err.message || 'Cevaplanamadı', true);
                if (auto) this.setState('Otomatik cevap başarısız');
            }
            return false;
        }
    },

    hangup() {
        this.hideIncoming();
        if (this.session) {
            try { this.session.terminate(); } catch (e) { /* ignore */ }
        }
        this.clearCall();
    },

    toggleHold() {
        if (!this.session || !this.session.isEstablished()) return;
        try {
            if (this.held) this.session.unhold();
            else this.session.hold();
            this.held = !this.held;
            document.getElementById('rtcHold').classList.toggle('on', this.held);
        } catch (e) {
            Astera.toast('Bekletilemedi', true);
        }
    },

    transfer() {
        if (!this.session) return;
        const to = prompt('Aktarılacak dahili', '');
        if (!to) return;
        try {
            this.session.refer('sip:' + to + '@' + window.ASTERA.pbxHost);
            Astera.toast('Aktarıldı: ' + to);
        } catch (e) {
            Astera.toast('Aktarılamadı', true);
        }
    },

    toggleDnd() {
        this.dnd = !this.dnd;
        document.getElementById('sipDnd').classList.toggle('on', this.dnd);
        const code = this.dnd ? '*78' : '*79';
        if (this.ua && this.ua.isRegistered()) {
            document.getElementById('rtcNum').value = code;
            this.call();
        }
        Astera.toast(this.dnd ? 'DND açık' : 'DND kapalı');
    },

    toggleAuto() {
        const box = document.getElementById('rtcAuto');
        box.checked = !box.checked;
        const button = document.getElementById('rtcAutoBtn');
        button.classList.toggle('on', box.checked);
        button.dataset.on = box.checked ? '1' : '0';
        button.setAttribute('aria-pressed', box.checked ? 'true' : 'false');
        localStorage.setItem('asteraRtcAutoAnswer', box.checked ? '1' : '0');
        Astera.toast(box.checked ? 'Otomatik cevap açık' : 'Otomatik cevap kapalı');
    },

    clearCall() {
        this.hideIncoming();
        this.stopCallClock();
        this.session = null;
        this.muted = false;
        this.held = false;
        this.incoming = false;
        document.getElementById('rtcHold').classList.remove('on');
        const audio = document.getElementById('rtcRemote');
        audio.srcObject = null;
        document.getElementById('rtcNum').value = '';
        this.setState(this.ua && this.ua.isRegistered() ? 'Bağlandı' : 'Kapalı', !!(this.ua && this.ua.isRegistered()));
        this.setMode('idle');
    },
};
