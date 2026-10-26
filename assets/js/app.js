const Astera = {
    post(action, data = {}, options = {}) {
        const body = new URLSearchParams();
        body.set('action', action);
        body.set('csrf', window.ASTERA.csrf);
        Object.entries(data).forEach(([k, v]) => {
            if (Array.isArray(v)) {
                v.forEach((item) => body.append(k + '[]', item));
            } else if (v !== undefined && v !== null) {
                body.set(k, v);
            }
        });
        return fetch('api.php', { method: 'POST', body, ...options }).then(async (r) => {
            const json = await r.json().catch(() => ({ ok: false, error: 'Yanıt okunamadı' }));
            if (!r.ok || json.ok === false) {
                throw new Error(json.error || json.output || 'İşlem başarısız');
            }
            return json;
        });
    },

    toast(msg, bad = false) {
        const el = document.getElementById('toast');
        el.hidden = false;
        el.classList.toggle('bad', bad);
        el.textContent = msg;
        clearTimeout(this._t);
        this._t = setTimeout(() => { el.hidden = true; }, 4200);
    },

    bindSystemClock() {
        const clock = document.getElementById('systemClock');
        if (!clock) return;
        const serverEpoch = Number(clock.dataset.serverEpoch || 0) * 1000;
        const offset = serverEpoch > 0 ? serverEpoch - Date.now() : 0;
        const render = () => {
            clock.textContent = new Intl.DateTimeFormat('tr-TR', {
                timeZone: 'Europe/Istanbul',
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            }).format(new Date(Date.now() + offset));
        };
        render();
        clearInterval(this._systemClockInterval);
        this._systemClockInterval = setInterval(render, 1000);
    },

    modal(html, { closeOnBackdrop = true } = {}) {
        const wrap = document.getElementById('modal');
        const card = document.getElementById('modalCard');
        card.innerHTML = html;
        wrap.hidden = false;
        wrap.onclick = (e) => {
            if (closeOnBackdrop && e.target === wrap) wrap.hidden = true;
        };
        this.bindTenantSounds(card);
    },

    closeModal() {
        document.getElementById('modal').hidden = true;
    },

    deptField(selected) {
        const cur = window.ASTERA.dept;
        if (cur) {
            return `<input type="hidden" name="dept" value="${cur}">`;
        }
        const opts = (window.ASTERA.depts || []).map((d) =>
            `<option value="${d.id}" ${selected === d.id ? 'selected' : ''}>${d.name}</option>`
        ).join('');
        return `<label>Firma<select name="dept" required>${opts}</select></label>`;
    },

    async apply() {
        const pending = Number(this._lastStatus?.pending_count
            ?? document.getElementById('pendingPill')?.textContent.match(/\d+/)?.[0]
            ?? 0);
        if (pending <= 0) {
            this.toast('Bekleyen değişiklik yok');
            return;
        }
        if (!confirm(`${pending} bekleyen değişiklik santrale uygulansın mı?`)) return;
        const button = document.getElementById('applyButton');
        const timer = document.getElementById('applyTimer');
        const originalText = button?.textContent || 'Santrale uygula';
        const startedAt = new Date();
        const started = performance.now();
        const elapsedText = () => `${((performance.now() - started) / 1000).toFixed(1)} sn`;
        const updateTimer = () => {
            const elapsed = elapsedText();
            if (button) button.textContent = `Uygulanıyor… ${elapsed}`;
            if (timer) {
                timer.hidden = false;
                timer.textContent = `Başladı: ${startedAt.toLocaleTimeString('tr-TR')} · ${elapsed}`;
            }
        };
        if (button) button.disabled = true;
        this._applyInProgress = true;
        updateTimer();
        const interval = setInterval(updateTimer, 100);
        this.toast('Santrale yazılıyor…');
        try {
            await this.post('apply');
            const elapsed = elapsedText();
            if (timer) {
                timer.classList.remove('bad');
                timer.classList.add('ok');
                timer.textContent = `Başladı: ${startedAt.toLocaleTimeString('tr-TR')} · Sürdü: ${elapsed}`;
            }
            const pendingPill = document.getElementById('pendingPill');
            if (pendingPill) {
                pendingPill.textContent = 'Bekleyen: 0';
                pendingPill.classList.remove('bad');
                pendingPill.classList.add('dim');
            }
            if (this._lastStatus) this._lastStatus.pending_count = 0;
            this.toast(`Santrale uygulandı · ${elapsed}`);
        } catch (e) {
            const elapsed = elapsedText();
            if (timer) {
                timer.hidden = false;
                timer.classList.remove('ok', 'dim');
                timer.classList.add('bad');
                timer.textContent = `Hata · ${elapsed}`;
            }
            this.toast(e.message, true);
        } finally {
            clearInterval(interval);
            this._applyInProgress = false;
            if (button) {
                button.disabled = false;
                button.textContent = originalText.trim();
            }
        }
    },

    bindGlobal() {
        this.bindSystemClock();
        if (window.ASTERA?.crmPopup) return;
        this.post('sound_languages').then((r) => {
            window.ASTERA.languages = r.languages || ['tr'];
            document.querySelectorAll('[data-language-list]').forEach((list) => {
                list.innerHTML = window.ASTERA.languages.map((lang) => `<option value="${lang}">`).join('');
            });
        }).catch(() => {});
        document.querySelectorAll('[data-action="apply"]').forEach((b) => {
            b.onclick = () => this.apply();
        });
        document.querySelectorAll('[data-action="reload"]').forEach((b) => {
            b.onclick = async () => {
                try {
                    await this.post('reload');
                    this.toast('Reload gönderildi');
                } catch (e) {
                    this.toast(e.message, true);
                }
            };
        });
        const sw = document.getElementById('deptSwitch');
        if (sw) {
            sw.onchange = async () => {
                try {
                    await this.post('dept_select', { dept: sw.value });
                    location.reload();
                } catch (e) {
                    this.toast(e.message, true);
                }
            };
        }
        this.refreshStatus();
        setInterval(() => this.refreshStatus(), 30000);
    },

    async refreshStatus() {
        if (this._applyInProgress) return null;
        if (this._statusRequest) return this._statusRequest;

        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 30000);
        this._statusRequest = (async () => {
            try {
                const r = await this.post('status', {}, { signal: controller.signal });
                const s = r.status || {};
                const pill = document.getElementById('callPill');
                if (pill) pill.textContent = 'Çağrı: ' + (s.active_calls ?? 0);
                const pendingPill = document.getElementById('pendingPill');
                const applyButton = document.getElementById('applyButton');
                const pendingCount = Number(s.pending_count ?? 0);
                if (pendingPill) {
                    pendingPill.textContent = 'Bekleyen: ' + pendingCount;
                    pendingPill.classList.toggle('bad', pendingCount > 0);
                    pendingPill.classList.toggle('dim', pendingCount === 0);
                }
                if (applyButton) applyButton.classList.toggle('primary', pendingCount > 0);
                const dot = document.getElementById('liveDot');
                if (dot) dot.classList.add('on');
                document.querySelectorAll('.ext-state').forEach((el) => {
                    const sip = el.dataset.sip;
                    const online = (s.registered || []).includes(sip);
                    const st = (s.endpoint_state || {})[sip] || (online ? 'Kayıtlı' : 'Yok');
                    el.textContent = online ? 'Kayıtlı' : st;
                    el.classList.toggle('ok', online);
                    el.classList.toggle('dim', !online);
                });
                const extensionBody = document.getElementById('dashboardExtensionBody');
                if (extensionBody) {
                    const registered = new Set((s.registered || []).map(String));
                    const collator = new Intl.Collator('tr', { numeric: true, sensitivity: 'base' });
                    [...extensionBody.querySelectorAll('[data-dashboard-extension]')]
                        .sort((left, right) => {
                            const leftOnline = left.dataset.technology === 'pjsip'
                                && registered.has(left.dataset.sip || '');
                            const rightOnline = right.dataset.technology === 'pjsip'
                                && registered.has(right.dataset.sip || '');
                            if (leftOnline !== rightOnline) return leftOnline ? -1 : 1;
                            return collator.compare(left.dataset.exten || '', right.dataset.exten || '');
                        })
                        .forEach((row) => extensionBody.appendChild(row));
                }
                this._lastStatus = s;
                if (document.getElementById('regCount')) this.renderDashboard(s);
            } catch (e) {
                const dot = document.getElementById('liveDot');
                if (dot) dot.classList.remove('on');
                const pill = document.getElementById('callPill');
                if (pill) pill.textContent = 'Çağrı: —';
                if (document.getElementById('regCount')) this.renderDashboardUnavailable();
            } finally {
                clearTimeout(timeout);
                this._statusRequest = null;
            }
        })();

        return this._statusRequest;
    },

    pollDashboard() {
        this.refreshStatus();
    },

    renderDashboard(s) {
        document.getElementById('regCount').textContent = (s.registered || []).length;
        document.getElementById('callCount').textContent = s.active_calls ?? 0;
        document.getElementById('uptimeBox').textContent = s.uptime || '—';
        const escapeStatus = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[char]);
        const extensionRows = [...document.querySelectorAll('[data-dashboard-extension]')];
        const extensionBySip = new Map(extensionRows.map((row, index) => [
            row.dataset.sip || '',
            { exten: row.dataset.exten || row.dataset.sip || '', index },
        ]));
        const regs = [...(s.registered || [])].sort((left, right) => {
            const leftMeta = extensionBySip.get(String(left));
            const rightMeta = extensionBySip.get(String(right));
            return (leftMeta?.index ?? Number.MAX_SAFE_INTEGER)
                - (rightMeta?.index ?? Number.MAX_SAFE_INTEGER);
        });
        document.getElementById('regList').innerHTML = regs.length
            ? regs.map((sip) => {
                const extension = extensionBySip.get(String(sip))?.exten || sip;
                return `<div class="row"><code>${escapeStatus(extension)}</code><span class="pill ok">online</span></div>`;
            }).join('')
            : '<div class="muted">Kayıtlı telefon yok.</div>';
        const ch = s.channels || [];
        document.getElementById('chanCount').textContent = ch.length;
        document.getElementById('chanList').innerHTML = ch.length
            ? ch.map((x) => `<div class="row"><span>${escapeStatus(x)}</span></div>`).join('')
            : '<div class="muted">Aktif kanal yok.</div>';
    },

    renderDashboardUnavailable() {
        document.getElementById('regCount').textContent = '—';
        document.getElementById('callCount').textContent = '—';
        document.getElementById('regList').innerHTML = '<div class="muted">Santral durumu alınamadı.</div>';
        document.getElementById('chanCount').textContent = '—';
        document.getElementById('chanList').innerHTML = '<div class="muted">Santral durumu alınamadı.</div>';
    },

    codecChecks(selected = ['alaw', 'ulaw']) {
        return ['ulaw', 'alaw', 'gsm', 'g726', 'g729', 'g722', 'opus'].map((c) =>
            `<label><input type="checkbox" name="codecs" value="${c}" ${selected.includes(c) ? 'checked' : ''}> ${c}</label>`
        ).join('');
    },

    formSubmit(form, action, extra = {}, { skipUnchanged = false } = {}) {
        const snapshot = () => JSON.stringify(
            [...new FormData(form).entries()]
                .map(([key, value]) => [key, String(value)])
                .sort(([aKey, aValue], [bKey, bValue]) =>
                    aKey.localeCompare(bKey) || aValue.localeCompare(bValue)
                )
        );
        const initialSnapshot = skipUnchanged ? snapshot() : '';
        form.onsubmit = async (e) => {
            e.preventDefault();
            if (skipUnchanged && snapshot() === initialSnapshot) {
                this.toast('Değişiklik yapılmadı');
                return;
            }
            const data = Object.fromEntries(new FormData(form).entries());
            const passwordInput = form.querySelector('[name="password"]');
            if (passwordInput) data.password = passwordInput.value;
            const codecs = [...form.querySelectorAll('[name="codecs"]:checked')].map((i) => i.value);
            const members = [...form.querySelectorAll('[name="members"]:checked')].map((i) => i.value);
            const phoneModes = [...form.querySelectorAll('[name="phone_modes"]:checked')].map((i) => i.value);
            const outboundRoutes = [...form.querySelectorAll('[name="outbound_routes"]:checked')].map((i) => i.value);
            form.querySelectorAll('input[type="checkbox"][name]').forEach((input) => {
                data[input.name] = input.checked ? '1' : '';
            });
            const rec = form.querySelector('[name="record"]');
            if (rec && rec.type === 'checkbox') data.record = rec.checked ? '1' : '';
            const vm = form.querySelector('[name="vm_enabled"]');
            if (vm) data.vm_enabled = vm.checked ? '1' : '';
            const dx = form.querySelector('[name="duplex"]');
            if (dx) data.duplex = dx.checked ? '1' : '';
            const rtc = form.querySelector('[name="webrtc"]');
            if (rtc) data.webrtc = rtc.checked ? '1' : '';
            try {
                await this.post(action, {
                    ...data,
                    ...extra,
                    codecs,
                    members,
                    phone_modes: phoneModes,
                    outbound_routes: outboundRoutes
                });
                location.reload();
            } catch (err) {
                this.toast(err.message, true);
            }
        };
    },

    soundField(name, value = '', label = 'Ses', uploadedOnly = false) {
        const source = uploadedOnly
            ? Object.values(window.ASTERA.uploadedSoundSets || {}).flat()
            : (window.ASTERA.sounds || []);
        const sounds = [...new Set([...source, value].filter(Boolean))];
        const options = sounds.map((sound) =>
            `<option value="${sound}" ${sound === value ? 'selected' : ''}>${sound}</option>`
        ).join('');
        return `<label>${label}<select name="${name}" data-tenant-sound data-uploaded-only="${uploadedOnly ? '1' : '0'}" ${uploadedOnly ? 'required' : ''}><option value="">Ses seçin</option>${options}</select></label>`;
    },

    languageField(value = 'tr', label = 'Anons dili') {
        const id = `languages-${Math.random().toString(36).slice(2)}`;
        const languages = window.ASTERA.languages || ['tr', 'en'];
        const options = languages.map((lang) => `<option value="${lang}">`).join('');
        return `<label>${label}<input name="language" list="${id}" value="${value || 'tr'}" placeholder="tr"><datalist id="${id}" data-language-list>${options}</datalist></label>`;
    },

    bindTenantSounds(root) {
        const select = root.querySelector('[name="dept"]');
        const update = () => {
            const dept = select ? select.value : window.ASTERA.dept;
            root.querySelectorAll('[data-tenant-sound]').forEach((input) => {
                const sounds = input.dataset.uploadedOnly === '1'
                    ? ((window.ASTERA.uploadedSoundSets || {})[dept] || [])
                    : ((window.ASTERA.soundSets || {})[dept] || window.ASTERA.sounds || []);
                if (input.tagName === 'SELECT') {
                    const current = input.value;
                    const emptyLabel = input.dataset.emptyLabel || 'Ses seçin';
                    input.innerHTML = `<option value="">${emptyLabel}</option>`
                        + sounds.map((sound) => `<option value="${sound}">${sound}</option>`).join('');
                    input.value = sounds.includes(current) ? current : '';
                    return;
                }
                const list = root.querySelector('#' + input.getAttribute('list'));
                if (list) list.innerHTML = sounds.map((s) => `<option value="${s}">`).join('');
            });
        };
        if (select) select.addEventListener('change', update);
        update();
    },

    async postFile(action, form, extra = {}) {
        const fd = new FormData(form);
        fd.set('action', action);
        fd.set('csrf', window.ASTERA.csrf);
        if (window.ASTERA.dept) fd.set('dept', window.ASTERA.dept);
        Object.entries(extra).forEach(([key, value]) => {
            fd.delete(key);
            if (Array.isArray(value)) {
                value.forEach((item) => fd.append(key + '[]', item));
            } else if (value !== undefined && value !== null) {
                fd.set(key, value);
            }
        });
        const r = await fetch('api.php', { method: 'POST', body: fd });
        const json = await r.json().catch(() => ({ ok: false, error: 'Yanıt okunamadı' }));
        if (!r.ok || json.ok === false) throw new Error(json.error || json.output || 'Yükleme başarısız');
        return json;
    },

    destPair(typeName, destName, type = 'extension', dest = '', compact = false) {
        const types = window.ASTERA.destTypes || {};
        const t = type || 'extension';
        const typeOpts = Object.entries(types).map(([k, v]) =>
            `<option value="${k}" ${k === t ? 'selected' : ''}>${v}</option>`
        ).join('');
        const destOpts = ((window.ASTERA.dests || {})[t] || []).map((i) => {
            const sel = String(i.id) === String(dest) || String(i.id).endsWith('-' + dest);
            return `<option value="${i.id}" ${sel ? 'selected' : ''}>${i.label}</option>`;
        }).join('');
        const manual = t === 'external';
        return `<div class="dest-pair grid-2${compact ? ' dest-pair-compact' : ''}">
            <label>${compact ? '' : 'Tür'}<select name="${typeName}" class="dest-type">${typeOpts}</select></label>
            <label>${compact ? '' : 'Hedef'}
                <select name="${destName}" class="dest-id" ${manual ? 'disabled hidden' : ''}>${destOpts}</select>
                <input name="${destName}" class="dest-manual" inputmode="numeric" pattern="[0-9]{3,20}"
                    placeholder="905307711714" value="${manual ? dest : ''}" ${manual ? '' : 'disabled hidden'}>
            </label>
        </div>`;
    },

    bindDestPairs() {
        document.querySelectorAll('.dest-type').forEach((sel) => {
            const render = (refreshOptions = true) => {
                const pair = sel.closest('.dest-pair');
                const destSel = pair?.querySelector('.dest-id');
                const manual = pair?.querySelector('.dest-manual');
                if (!destSel || !manual) return;
                const isExternal = sel.value === 'external';
                destSel.disabled = isExternal;
                destSel.hidden = isExternal;
                manual.disabled = !isExternal;
                manual.hidden = !isExternal;
                if (isExternal) {
                    manual.focus();
                    return;
                }
                if (refreshOptions) {
                    const items = (window.ASTERA.dests || {})[sel.value] || [];
                    destSel.innerHTML = items.map((i) => `<option value="${i.id}">${i.label}</option>`).join('');
                }
            };
            sel.onchange = () => render(true);
            render(false);
        });
    },

    pageDepartments() {
        const open = (row = {}) => {
            this.modal(`
                <h3>${row.id ? 'Firma düzenle' : 'Firma ekle'}</h3>
                <form id="f">
                    <label>Kod (kalıcı)<input name="id" required value="${row.id || ''}" ${row.id ? 'readonly' : ''} placeholder="acme"></label>
                    <label>Ad<input name="name" required value="${row.name || ''}" placeholder="Acme Ltd"></label>
                    <label>Giden arayan no (CID)<input name="cid" value="${row.cid || ''}"></label>
                    <label>Abone limiti
                        <input name="extension_limit" type="number" min="0" max="100000" value="${row.extension_limit ?? 0}">
                        <small class="muted">0 = sınırsız</small>
                    </label>
                    <label><input type="checkbox" name="record" ${row.record !== false ? 'checked' : ''}> Ses kaydı</label>
                    <label>Firma paneli kullanıcısı<input name="panel_user" value="${row.panel_user || ''}" placeholder="sadece bu firmayı görür"></label>
                    <label>Firma paneli parolası<input name="panel_pass" type="password" minlength="8" value="" placeholder="${row.panel_pass ? 'boş = değişmesin' : 'en az 8 karakter'}"></label>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `);
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(document.getElementById('f'), 'department_save');
        };
        document.getElementById('addDept').onclick = () => open();
        document.querySelectorAll('[data-edit-dept]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editDept)));
        document.querySelectorAll('[data-del-dept]').forEach((b) => b.onclick = async () => {
            if (!confirm('Firma silinsin mi?')) return;
            try { await this.post('department_delete', { id: b.dataset.delDept }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
    },

    pageExtensions() {
        const extensions = window.ASTERA.extensions || [];
        const outboundRoutes = window.ASTERA.outboundRoutes || [];
        const priSpans = window.ASTERA.priSpans || [];
        const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[char]);
        const newNumericPin = () => {
            if (window.crypto?.getRandomValues) {
                const value = new Uint32Array(1);
                window.crypto.getRandomValues(value);
                return String(100000 + (value[0] % 900000));
            }
            return String(Math.floor(100000 + Math.random() * 900000));
        };
        const open = (row = {}) => {
            const deptId = row.dept || window.ASTERA.dept || '';
            const dept = (window.ASTERA.depts || []).find((item) => item.id === deptId) || {};
            const recordEnabled = row.record ?? dept.record ?? true;
            const configuredPhoneModes = Array.isArray(row.phone_modes) && row.phone_modes.length
                ? row.phone_modes
                : (row.webrtc ? ['webrtc'] : ['softphone']);
            const phoneMode = configuredPhoneModes[0];
            const technology = ['dahdi', 'custom'].includes(row.technology)
                ? row.technology
                : 'pjsip';
            const defaultFallback = (window.ASTERA.extensionDefaultFallbacks || {})[deptId]
                || { type: 'hangup', dest: '' };
            const hasFallback = Object.prototype.hasOwnProperty.call(row, 'fallback_type');
            const fallbackType = hasFallback ? (row.fallback_type || 'hangup') : defaultFallback.type;
            const fallbackDest = hasFallback ? (row.fallback_dest || '') : defaultFallback.dest;
            this.modal(`
                <div class="extension-modal-head">
                    <span class="extension-avatar">${row.exten || '+'}</span>
                    <div>
                        <h3>${row.exten ? 'Abone düzenle' : 'Abone ekle'}</h3>
                        <p>${row.exten ? `${row.exten} numaralı abonenin kimlik, çağrı ve bağlantı ayarları` : 'Yeni abonenin kimlik, çağrı ve bağlantı ayarları'}</p>
                    </div>
                </div>
                <form id="f" class="extension-form">
                    <div class="extension-section-title">Kimlik ve erişim</div>
                    ${this.deptField(row.dept)}
                    <label>Abone türü
                        <select name="technology" id="extensionTechnology">
                            <option value="pjsip" ${technology === 'pjsip' ? 'selected' : ''}>SIP / PJSIP abone</option>
                            <option value="dahdi" ${technology === 'dahdi' ? 'selected' : ''}>DAHDI / PRI abone</option>
                            <option value="custom" ${technology === 'custom' ? 'selected' : ''}>Özel (Custom) abone</option>
                        </select>
                    </label>
                    <label>Dahili<input name="exten" required value="${row.exten || ''}" ${row.exten ? 'readonly' : ''}></label>
                    <label>Ad<input name="name" value="${row.name || ''}"></label>
                    <label data-extension-tech="pjsip">SIP kullanıcı<input name="sipuser" value="${row.sipuser || ''}" placeholder="boş = dahili no"></label>
                    <label data-extension-tech="pjsip">Auth kullanıcı<input name="authuser" value="${row.authuser || row.sipuser || ''}" placeholder="softphone Authentication ID"></label>
                    <label data-extension-tech="pjsip">Parola
                        <span class="extension-password-edit">
                            <input name="password" type="password" minlength="8" autocomplete="new-password" ${row.exten || technology === 'dahdi' ? '' : 'required'} value="" placeholder="${row.exten ? 'boş = değişmesin' : 'en az 8 karakter'}">
                            ${row.id ? '<button class="btn sm" type="button" id="copyExtensionPassword">Şifreyi kopyala</button>' : ''}
                        </span>
                    </label>
                    <label data-extension-tech="pjsip">Eşzamanlı kayıt<input name="max_contacts" type="number" min="1" value="${row.max_contacts || 1}"></label>
                    <label data-extension-tech="dahdi">DAHDI / PRI grubu
                        <select name="dahdi_group" id="extensionDahdiGroup"></select>
                    </label>
                    <label data-extension-tech="dahdi">DAHDI kanal / hedef
                        <input name="dahdi_dial" value="${technology === 'dahdi' && row.exten ? 'g' + (row.dahdi_group ?? 0) + '/' + (row.dahdi_dial || row.exten) : ''}" placeholder="Örn. g3/201">
                        <small class="muted">Kart tanımlı olmasa da g3/201 biçiminde girebilirsiniz.</small>
                    </label>
                    <label data-extension-tech="custom">Özel kanal hedefi
                        <input name="custom_dial" maxlength="160" value="${row.custom_dial || ''}" placeholder="Örn. local/110@from-alcatel">
                    </label>
                    <label>Çalma süresi (sn)<input name="ringtime" type="number" min="8" value="${row.ringtime || 30}"></label>
                    <label>Giden CID<input name="cid_num" value="${row.cid_num || ''}" placeholder="firma CID yoksa"></label>
                    <div class="extension-section-title">Çağrı özellikleri</div>
                    <fieldset class="fieldset extension-wide extension-outbound-permissions">
                        <legend>Giden Aramalar</legend>
                        <input type="hidden" name="outbound_routes_present" value="1">
                        <p class="muted">Bu abonenin kullanabileceği firma çıkışlarını seçin.</p>
                        <div class="checks extension-outbound-options" id="extensionOutboundRoutes"></div>
                    </fieldset>
                    <fieldset class="fieldset"><legend>Ses kaydı</legend>
                        <label>Çağrı kaydı
                            <select name="record">
                                <option value="1" ${recordEnabled ? 'selected' : ''}>Açık</option>
                                <option value="" ${!recordEnabled ? 'selected' : ''}>Kapalı</option>
                            </select>
                        </label>
                        <label>Kayıt formatı
                            <select name="record_format">
                                <option value="wav" ${(row.record_format || 'wav') === 'wav' ? 'selected' : ''}>WAV</option>
                                <option value="gsm" ${row.record_format === 'gsm' ? 'selected' : ''}>GSM</option>
                                <option value="ulaw" ${row.record_format === 'ulaw' ? 'selected' : ''}>ULAW</option>
                            </select>
                        </label>
                    </fieldset>
                    <fieldset class="fieldset"><legend>Mesaj Kutusu</legend>
                        <label><input type="checkbox" name="vm_enabled" ${row.vm_enabled ? 'checked' : ''}> Mesaj Kutusu - Açık</label>
                        <label>PIN / WebPhone şifre<input name="vm_pin" inputmode="numeric" pattern="[0-9]{4,10}" value="${row.vm_pin || newNumericPin()}"></label>
                        <label>E-posta<input name="vm_email" value="${row.vm_email || ''}"></label>
                    </fieldset>
                    <label class="extension-wide">Follow me (dahililer, virgül)<input name="followme" value="${row.followme || ''}" placeholder="121,122"></label>
                    <fieldset class="fieldset extension-wide">
                        <legend>Cevaplanmazsa / meşgulse</legend>
                        <p class="muted">Çalma süresi dolduğunda veya abone meşgul/ulaşılamaz olduğunda seçilen hedefe aktarılır.</p>
                        ${this.destPair('fallback_type', 'fallback_dest', fallbackType, fallbackDest)}
                    </fieldset>
                    <div class="extension-section-title">Bağlantı ve cihaz</div>
                    <fieldset class="fieldset" data-extension-tech="pjsip"><legend>Telefon türü</legend>
                        <label><input type="radio" name="phone_modes" value="webrtc" ${phoneMode === 'webrtc' ? 'checked' : ''}> WebRTC · Tarayıcı WebPhone</label>
                        <label><input type="radio" name="phone_modes" value="softphone" ${phoneMode === 'softphone' ? 'checked' : ''}> Softphone · Bilgisayar SIP uygulaması</label>
                        <label><input type="radio" name="phone_modes" value="desk_phone" ${phoneMode === 'desk_phone' ? 'checked' : ''}> Masaüstü Telefon · Fiziksel IP telefon</label>
                        <p class="muted">WebRTC seçildiğinde WSS/WS ve Opus ayarları; diğer seçeneklerde klasik PJSIP transport ayarları uygulanır.</p>
                    </fieldset>
                    <fieldset class="fieldset" data-extension-tech="pjsip"><legend>CRM</legend>
                        <label>CRM otomatik açma
                            <select name="crm_trigger">
                                <option value="inherit" ${row.crm_trigger === 'inherit' ? 'selected' : ''}>Firma ayarını kullan</option>
                                <option value="enabled" ${row.crm_trigger === 'enabled' ? 'selected' : ''}>Açık</option>
                                <option value="disabled" ${(row.crm_trigger || 'disabled') === 'disabled' ? 'selected' : ''}>Kapalı</option>
                            </select>
                        </label>
                    </fieldset>
                    <fieldset class="fieldset" data-extension-tech="pjsip"><legend>SIP</legend>
                        <label>DTMF
                            <select name="dtmf">
                                <option value="rfc4733" ${(row.dtmf || 'rfc4733') === 'rfc4733' ? 'selected' : ''}>RFC4733</option>
                                <option value="inband" ${row.dtmf === 'inband' ? 'selected' : ''}>Inband</option>
                                <option value="info" ${row.dtmf === 'info' ? 'selected' : ''}>SIP INFO</option>
                            </select>
                        </label>
                        <label>Transport
                            <select name="transport">
                                <option value="udp" ${row.transport !== 'tcp' ? 'selected' : ''}>UDP</option>
                                <option value="tcp" ${row.transport === 'tcp' ? 'selected' : ''}>TCP</option>
                            </select>
                        </label>
                        ${this.languageField(row.language || 'tr', 'Abone dili')}
                        <label>Qualify (sn, 0=kapalı)<input name="qualify" type="number" min="0" value="${row.qualify ?? 60}"></label>
                    </fieldset>
                    <fieldset class="fieldset extension-wide" data-extension-tech="pjsip"><legend>Ses kodekleri</legend>
                        <div class="checks">${this.codecChecks(row.codecs || ['alaw','ulaw'])}</div>
                    </fieldset>
                    <div class="modal-actions extension-wide">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            this.bindDestPairs();
            const form = document.getElementById('f');
            const copyPasswordButton = document.getElementById('copyExtensionPassword');
            if (copyPasswordButton) {
                copyPasswordButton.onclick = async () => {
                    copyPasswordButton.disabled = true;
                    try {
                        const result = await this.post('extension_secret', { id: row.id });
                        const password = result.password || '';
                        if (navigator.clipboard && window.isSecureContext) {
                            await navigator.clipboard.writeText(password);
                        } else {
                            const helper = document.createElement('textarea');
                            helper.value = password;
                            helper.style.position = 'fixed';
                            helper.style.opacity = '0';
                            document.body.appendChild(helper);
                            helper.select();
                            document.execCommand('copy');
                            helper.remove();
                        }
                        this.toast('SIP şifresi kopyalandı');
                    } catch (error) {
                        this.toast('SIP şifresi kopyalanamadı', true);
                    } finally {
                        copyPasswordButton.disabled = false;
                    }
                };
            }
            const outboundBox = document.getElementById('extensionOutboundRoutes');
            const deptInput = form.querySelector('[name="dept"]');
            const technologyInput = document.getElementById('extensionTechnology');
            const dahdiGroupInput = document.getElementById('extensionDahdiGroup');
            const initialAllowedRoutes = Array.isArray(row.outbound_routes)
                ? row.outbound_routes.map(String)
                : (row.outbound_enabled === false ? [] : null);
            const renderOutboundRoutes = (useInitialSelection = false) => {
                const selectedDept = deptInput?.value || deptId;
                const routes = outboundRoutes.filter((route) => String(route.dept || '') === String(selectedDept || ''));
                const allowed = useInitialSelection && initialAllowedRoutes !== null
                    ? new Set(initialAllowedRoutes)
                    : new Set(routes.map((route) => String(route.id)));
                outboundBox.innerHTML = routes.length
                    ? routes.map((route) => `
                        <label>
                            <input type="checkbox" name="outbound_routes" value="${escape(route.id)}" ${allowed.has(String(route.id)) ? 'checked' : ''}>
                            <span><strong>${escape(route.name || route.id)}</strong><small>${escape(route.pattern || '')} · ${escape(route.trunk || '')}</small></span>
                        </label>
                    `).join('')
                    : '<span class="muted">Bu firma için Giden Aramalar kuralı tanımlı değil.</span>';
            };
            const renderDahdiGroups = (useInitialSelection = false) => {
                const selectedDept = deptInput?.value || deptId;
                const spans = priSpans.filter((span) =>
                    span.enabled && String(span.dept || '') === String(selectedDept || '')
                );
                const selectedGroup = useInitialSelection
                    ? String(row.dahdi_group ?? '')
                    : String(dahdiGroupInput.value || '');
                dahdiGroupInput.innerHTML = spans.length
                    ? spans.map((span) => `
                        <option value="${escape(span.group)}" ${String(span.group) === selectedGroup ? 'selected' : ''}>
                            ${escape(span.name || span.id)} · grup ${escape(span.group)}
                        </option>
                    `).join('')
                    : `<option value="${escape(selectedGroup || '0')}">
                        Kart tanımlı değil · grup ${escape(selectedGroup || '0')}
                    </option>`;
            };
            const applyTechnology = () => {
                const selectedTechnology = ['dahdi', 'custom'].includes(technologyInput.value)
                    ? technologyInput.value
                    : 'pjsip';
                form.querySelectorAll('[data-extension-tech]').forEach((panel) => {
                    const active = panel.dataset.extensionTech === selectedTechnology;
                    panel.hidden = !active;
                    panel.querySelectorAll('input, select, textarea').forEach((control) => {
                        control.disabled = !active;
                    });
                });
                const passwordInput = form.elements.password;
                if (passwordInput) {
                    passwordInput.required = selectedTechnology === 'pjsip'
                        && (!row.exten || technology === 'dahdi');
                }
                dahdiGroupInput.required = selectedTechnology === 'dahdi';
            };
            renderOutboundRoutes(Boolean(row.exten));
            renderDahdiGroups(Boolean(row.exten));
            applyTechnology();
            technologyInput.addEventListener('change', applyTechnology);
            if (deptInput && deptInput.tagName === 'SELECT') {
                deptInput.addEventListener('change', () => {
                    renderOutboundRoutes(false);
                    renderDahdiGroups(false);
                });
            }
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(
                form,
                'extension_save',
                {},
                { skipUnchanged: Boolean(row.exten) }
            );
        };
        document.getElementById('addExt').onclick = () => {
            if (!(window.ASTERA.dept || (window.ASTERA.depts || []).length)) return this.toast('Önce firma ekleyin', true);
            open();
        };
        document.getElementById('importExt').onclick = () => {
            if (!(window.ASTERA.dept || (window.ASTERA.depts || []).length)) return this.toast('Önce firma ekleyin', true);
            this.modal(`
                <h3>Aboneleri CSV ile içe aktar</h3>
                <form id="extensionImportForm">
                    ${this.deptField()}
                    <label>CSV dosyası<input name="file" type="file" accept=".csv,text/csv" required></label>
                    <p class="muted tiny">Zorunlu sütun: <code>dahili</code>. <code>abone_turu</code>: PJSIP, DAHDI veya CUSTOM. PJSIP için en az 8 karakter parola gerekir. Cevaplanmazsa/meşgulse hedefi için tür ve hedef sütunlarını kullanabilirsiniz; örnek: <code>ivr;8502</code>.</p>
                    <div id="extensionImportConflicts"></div>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="downloadExtensionTemplate">Örnek CSV</button>
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary" type="submit">Dosyayı kontrol et</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            document.getElementById('c').onclick = () => this.closeModal();
            document.getElementById('downloadExtensionTemplate').onclick = () => {
                const csv = '\uFEFFdahili;ad;abone_turu;sip_kullanici;auth_kullanici;parola;dahdi_grup;dahdi_hedef;custom_hedef;dis_arama;telefon;kayit;kodekler;cevaplanmazsa_mesgulse_tur;cevaplanmazsa_mesgulse_hedef\r\n'
                    + '101;Muhasebe;PJSIP;101;;GucluParola101;;;;evet;softphone;evet;alaw,ulaw;ivr;8502\r\n'
                    + '201;PRI Oda;DAHDI;;;;;g3/201;;hayir;dahdi;hayir;;ivr;8502\r\n'
                    + '301;Özel Kanal;CUSTOM;;;;;;local/301@from-alcatel;hayir;custom;hayir;;ivr;8502\r\n';
                const link = document.createElement('a');
                link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
                link.download = 'abone-import-ornek.csv';
                link.click();
                setTimeout(() => URL.revokeObjectURL(link.href), 0);
            };
            const form = document.getElementById('extensionImportForm');
            const finishImport = async (updateMode, selected = []) => {
                const buttons = form.querySelectorAll('button');
                buttons.forEach((item) => { item.disabled = true; });
                try {
                    const result = await this.postFile('extension_import', form, {
                        update_mode: updateMode,
                        selected_existing: selected,
                    });
                    this.toast(`${result.created} eklendi, ${result.updated} güncellendi, ${result.skipped} atlandı`);
                    location.reload();
                } catch (error) {
                    buttons.forEach((item) => { item.disabled = false; });
                    this.toast(error.message, true);
                }
            };
            form.onsubmit = async (event) => {
                event.preventDefault();
                const button = form.querySelector('button[type="submit"]');
                button.disabled = true;
                try {
                    const preview = await this.postFile('extension_import', form, { preview_only: '1' });
                    const conflicts = Array.isArray(preview.conflicts) ? preview.conflicts : [];
                    if (!conflicts.length) {
                        await finishImport('none');
                        return;
                    }
                    const conflictBox = document.getElementById('extensionImportConflicts');
                    conflictBox.innerHTML = `
                        <div class="alert bad">
                            <strong>${conflicts.length} abone zaten var. Hangileri değiştirilsin?</strong>
                            <div class="checks extension-import-conflicts">
                                ${conflicts.map((item) => `
                                    <label>
                                        <input type="checkbox" class="extension-import-conflict" value="${escape(item.exten)}" checked>
                                        ${escape(item.exten)} · ${escape(item.current_name || item.import_name || 'Adsız')}
                                    </label>
                                `).join('')}
                            </div>
                            <div class="modal-actions">
                                <button class="btn" type="button" id="importSkipExisting">Var olanları atla</button>
                                <button class="btn" type="button" id="importSelectedExisting">Yalnız seçilenleri değiştir</button>
                                <button class="btn primary" type="button" id="importAllExisting">Tümünü değiştir</button>
                            </div>
                        </div>`;
                    button.hidden = true;
                    document.getElementById('importSkipExisting').onclick = () => finishImport('none');
                    document.getElementById('importAllExisting').onclick = () => finishImport('all');
                    document.getElementById('importSelectedExisting').onclick = () => {
                        const selected = [...form.querySelectorAll('.extension-import-conflict:checked')]
                            .map((input) => input.value);
                        if (!selected.length) {
                            this.toast('Değiştirilecek en az bir abone seçin veya “Var olanları atla” seçeneğini kullanın', true);
                            return;
                        }
                        finishImport('selected', selected);
                    };
                } catch (error) {
                    button.disabled = false;
                    this.toast(error.message, true);
                }
            };
        };
        document.getElementById('exportExt').onclick = async (event) => {
            const button = event.currentTarget;
            button.disabled = true;
            try {
            const exportResult = await this.post('extension_export');
            const exportRows = Array.isArray(exportResult.extensions) ? exportResult.extensions : [];
            const departmentNames = new Map((window.ASTERA.depts || []).map((department) => [
                String(department.id || ''), String(department.name || department.id || '')
            ]));
            const routeNames = new Map(outboundRoutes.map((route) => [
                String(route.id || ''), String(route.name || route.id || '')
            ]));
            const csvCell = (value) => {
                let text = String(value ?? '');
                if (/^[=+\-@]/.test(text)) text = `'${text}`;
                return `"${text.replace(/"/g, '""')}"`;
            };
            const headers = [
                'firma', 'dahili', 'ad', 'abone_turu',
                'sip_kullanici', 'auth_kullanici', 'parola',
                'dahdi_grup', 'dahdi_hedef', 'custom_hedef',
                'telefon', 'crm', 'dis_arama', 'giden_arama_kurallari',
                'kayit', 'kayit_formati', 'mesaj_kutusu',
                'calma_suresi', 'follow_me', 'kodekler',
                'cevaplanmazsa_mesgulse_tur', 'cevaplanmazsa_mesgulse_hedef'
            ];
            const lines = exportRows.map((row) => {
                const technology = ['dahdi', 'custom'].includes(String(row.technology || '').toLowerCase())
                    ? String(row.technology).toLowerCase()
                    : 'pjsip';
                const routeIds = Array.isArray(row.outbound_routes) ? row.outbound_routes.map(String) : null;
                const outbound = routeIds !== null
                    ? (routeIds.map((id) => routeNames.get(id) || id).join(', ') || 'Kapalı')
                    : (row.outbound_enabled === false ? 'Kapalı' : 'Tüm çıkışlar');
                const phoneModes = Array.isArray(row.phone_modes) && row.phone_modes.length
                    ? row.phone_modes.join(', ')
                    : (row.webrtc ? 'webrtc' : 'softphone');
                return [
                    departmentNames.get(String(row.dept || '')) || row.dept || '',
                    row.exten || '', row.name || '', technology.toUpperCase(),
                    technology === 'pjsip' ? (row.sipuser || row.exten || '') : '',
                    technology === 'pjsip' ? (row.authuser || row.sipuser || row.exten || '') : '',
                    technology === 'pjsip' ? (row.password || '') : '',
                    technology === 'dahdi' ? (row.dahdi_group ?? '') : '',
                    technology === 'dahdi' ? (row.dahdi_dial || row.exten || '') : '',
                    technology === 'custom' ? (row.custom_dial || '') : '',
                    phoneModes, row.crm_trigger || 'disabled',
                    row.outbound_enabled === false ? 'hayir' : 'evet', outbound,
                    row.record ? 'evet' : 'hayir',
                    String(row.record_format || 'wav').toUpperCase(),
                    row.vm_enabled ? 'evet' : 'hayir', row.ringtime ?? 30,
                    row.followme || '', Array.isArray(row.codecs) ? row.codecs.join(',') : '',
                    row.fallback_type || 'hangup', row.fallback_export_dest || ''
                ].map(csvCell).join(';');
            });
            const csv = `\uFEFF${headers.map(csvCell).join(';')}\r\n${lines.join('\r\n')}\r\n`;
            const link = document.createElement('a');
            link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
            link.download = `aboneler-${new Date().toISOString().slice(0, 10)}.csv`;
            link.click();
            setTimeout(() => URL.revokeObjectURL(link.href), 0);
            } catch (error) {
                this.toast(error.message, true);
            } finally {
                button.disabled = false;
            }
        };
        const openBulkEdit = () => {
            const targetIds = [...document.querySelectorAll('.ext-select:checked')].map((input) => input.value);
            const selected = targetIds
                .map((id) => extensions.find((item) => String(item.id) === String(id)))
                .filter(Boolean);
            if (!selected.length) return this.toast('Önce düzenlenecek aboneleri seçin', true);
            const departments = new Set(selected.map((item) => String(item.dept || '')));
            if (departments.size !== 1) {
                return this.toast('Seçilen aboneler aynı firmaya ait olmalıdır', true);
            }
            const baseline = selected[0];
            const deptId = String(baseline.dept || '');
            const routes = outboundRoutes.filter((route) => String(route.dept || '') === deptId);
            const selectedRoutes = new Set(Array.isArray(baseline.outbound_routes)
                ? baseline.outbound_routes.map(String)
                : (baseline.outbound_enabled === false ? [] : routes.map((route) => String(route.id))));
            const phoneModes = Array.isArray(baseline.phone_modes) && baseline.phone_modes.length
                ? baseline.phone_modes
                : (baseline.webrtc ? ['webrtc'] : ['softphone']);
            this.modal(`
                <h3>Seçilen aboneleri düzenle</h3>
                <form id="extensionBulkEditForm" class="extension-copy-form">
                    <div class="copy-selected-summary">
                        <strong>${selected.length} seçili abone</strong>
                        <div>${selected.map((item) => `<span class="copy-selected-chip">${escape(item.exten)} · ${escape(item.name || 'Adsız')}</span>`).join('')}</div>
                    </div>
                    <p class="muted extension-wide">İşaretlediğiniz özellikler ve aşağıdaki değerler tüm seçili abonelere uygulanır.</p>
                    <fieldset class="fieldset bulk-feature-group extension-wide">
                        <legend><label><input type="checkbox" name="features" value="outbound"> Giden Aramalar</label></legend>
                        <div class="checks">
                            ${routes.length ? routes.map((route) => `<label><input type="checkbox" name="outbound_routes" value="${escape(route.id)}" ${selectedRoutes.has(String(route.id)) ? 'checked' : ''}> ${escape(route.name || route.id)}</label>`).join('') : '<span class="muted">Bu firmada çıkış kuralı yok.</span>'}
                        </div>
                    </fieldset>
                    <fieldset class="fieldset bulk-feature-group">
                        <legend><label><input type="checkbox" name="features" value="recording"> Ses kaydı</label></legend>
                        <label><input type="checkbox" name="record" ${baseline.record ? 'checked' : ''}> Çağrı kaydı açık</label>
                        <label>Format<select name="record_format">
                            ${['wav', 'gsm', 'ulaw'].map((format) => `<option value="${format}" ${(baseline.record_format || 'wav') === format ? 'selected' : ''}>${format.toUpperCase()}</option>`).join('')}
                        </select></label>
                    </fieldset>
                    <fieldset class="fieldset bulk-feature-group">
                        <legend><label><input type="checkbox" name="features" value="voicemail"> Mesaj Kutusu</label></legend>
                        <label><input type="checkbox" name="vm_enabled" ${baseline.vm_enabled ? 'checked' : ''}> Mesaj Kutusu - Açık</label>
                    </fieldset>
                    <fieldset class="fieldset bulk-feature-group extension-wide">
                        <legend><label><input type="checkbox" name="features" value="routing"> Çağrı yönlendirme</label></legend>
                        <div class="grid-2">
                            <label>Çalma süresi (sn)<input name="ringtime" type="number" min="8" value="${baseline.ringtime ?? 30}"></label>
                            <label>Follow me<input name="followme" value="${escape(baseline.followme || '')}" placeholder="121,122"></label>
                        </div>
                        ${this.destPair('fallback_type', 'fallback_dest', baseline.fallback_type || 'hangup', baseline.fallback_dest || '')}
                    </fieldset>
                    <fieldset class="fieldset bulk-feature-group">
                        <legend><label><input type="checkbox" name="features" value="phone"> Telefon türü</label></legend>
                        <label>Telefon<select name="phone_mode">
                            <option value="webrtc" ${phoneModes[0] === 'webrtc' ? 'selected' : ''}>WebRTC</option>
                            <option value="softphone" ${phoneModes[0] === 'softphone' ? 'selected' : ''}>Softphone</option>
                            <option value="desk_phone" ${phoneModes[0] === 'desk_phone' ? 'selected' : ''}>Masaüstü telefon</option>
                        </select></label>
                        <label>Eşzamanlı kayıt<input name="max_contacts" type="number" min="1" value="${baseline.max_contacts || 1}"></label>
                    </fieldset>
                    <fieldset class="fieldset bulk-feature-group">
                        <legend><label><input type="checkbox" name="features" value="crm"> CRM</label></legend>
                        <label>CRM otomatik açma<select name="crm_trigger">
                            <option value="inherit" ${baseline.crm_trigger === 'inherit' ? 'selected' : ''}>Firma ayarı</option>
                            <option value="enabled" ${baseline.crm_trigger === 'enabled' ? 'selected' : ''}>Açık</option>
                            <option value="disabled" ${(baseline.crm_trigger || 'disabled') === 'disabled' ? 'selected' : ''}>Kapalı</option>
                        </select></label>
                    </fieldset>
                    <fieldset class="fieldset bulk-feature-group extension-wide">
                        <legend><label><input type="checkbox" name="features" value="sip"> SIP, dil ve kodek</label></legend>
                        <div class="checks">${this.codecChecks(baseline.codecs || ['alaw', 'ulaw'])}</div>
                        <div class="grid-2">
                            <label>DTMF<select name="dtmf">
                                <option value="rfc4733" ${(baseline.dtmf || 'rfc4733') === 'rfc4733' ? 'selected' : ''}>RFC4733</option>
                                <option value="inband" ${baseline.dtmf === 'inband' ? 'selected' : ''}>Inband</option>
                                <option value="info" ${baseline.dtmf === 'info' ? 'selected' : ''}>SIP INFO</option>
                            </select></label>
                            ${this.languageField(baseline.language || 'tr', 'Abone dili')}
                            <label>Transport<select name="transport">
                                <option value="udp" ${baseline.transport !== 'tcp' ? 'selected' : ''}>UDP</option>
                                <option value="tcp" ${baseline.transport === 'tcp' ? 'selected' : ''}>TCP</option>
                            </select></label>
                            <label>Qualify (sn)<input name="qualify" type="number" min="0" value="${baseline.qualify ?? 60}"></label>
                        </div>
                    </fieldset>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary" type="submit">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            document.getElementById('c').onclick = () => this.closeModal();
            this.bindDestPairs();
            const form = document.getElementById('extensionBulkEditForm');
            form.onsubmit = async (event) => {
                event.preventDefault();
                const data = Object.fromEntries(new FormData(form).entries());
                data.features = [...form.querySelectorAll('[name="features"]:checked')].map((input) => input.value);
                data.target_ids = targetIds;
                data.outbound_routes = [...form.querySelectorAll('[name="outbound_routes"]:checked')].map((input) => input.value);
                data.codecs = [...form.querySelectorAll('[name="codecs"]:checked')].map((input) => input.value);
                data.record = form.querySelector('[name="record"]').checked ? '1' : '';
                data.vm_enabled = form.querySelector('[name="vm_enabled"]').checked ? '1' : '';
                const saveButton = form.querySelector('button[type="submit"]');
                saveButton.disabled = true;
                try {
                    const result = await this.post('extension_bulk_update_features', data);
                    this.toast(`${result.updated} abone güncellendi`);
                    location.reload();
                } catch (error) {
                    saveButton.disabled = false;
                    this.toast(error.message, true);
                }
            };
        };
        const loadExtensionSecret = async (container) => {
            if (container.dataset.loaded === '1') return container.querySelector('input').value;
            const result = await this.post('extension_secret', { id: container.dataset.extensionSecret });
            const input = container.querySelector('input');
            input.value = result.password || '';
            container.dataset.loaded = '1';
            return input.value;
        };
        document.querySelectorAll('[data-secret-toggle]').forEach((button) => {
            button.onclick = async () => {
                const container = button.closest('[data-extension-secret]');
                const input = container.querySelector('input');
                try {
                    await loadExtensionSecret(container);
                    const reveal = input.type === 'password';
                    input.type = reveal ? 'text' : 'password';
                    button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
                    button.setAttribute('aria-label', reveal ? 'SIP şifresini gizle' : 'SIP şifresini göster');
                    button.title = reveal ? 'Şifreyi gizle' : 'Şifreyi göster';
                } catch (error) {
                    this.toast(error.message, true);
                }
            };
        });
        document.querySelectorAll('[data-secret-copy]').forEach((button) => {
            button.onclick = async () => {
                const container = button.closest('[data-extension-secret]');
                const input = container.querySelector('input');
                try {
                    const password = await loadExtensionSecret(container);
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(password);
                    } else {
                        const previousType = input.type;
                        input.type = 'text';
                        input.select();
                        document.execCommand('copy');
                        input.type = previousType;
                    }
                    this.toast('SIP şifresi kopyalandı');
                } catch (error) {
                    this.toast('SIP şifresi kopyalanamadı', true);
                }
            };
        });
        document.querySelectorAll('[data-edit-ext]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editExt)));
        document.getElementById('copyExtFeatures').onclick = openBulkEdit;
        document.querySelectorAll('[data-del-ext]').forEach((b) => b.onclick = async () => {
            if (!confirm('Abone silinsin mi?')) return;
            try { await this.post('extension_delete', { id: b.dataset.delExt }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
        const search = document.getElementById('extSearch');
        const selectAll = document.getElementById('selectAllExt');
        const deleteSelected = document.getElementById('deleteSelectedExt');
        const editSelected = document.getElementById('copyExtFeatures');
        const selectedCount = document.getElementById('extSelectedCount');
        const extensionRows = [...document.querySelectorAll('[data-ext-row]')];
        const updateSelection = () => {
            const visibleChecks = extensionRows
                .filter((row) => !row.hidden)
                .map((row) => row.querySelector('.ext-select'))
                .filter(Boolean);
            const selected = [...document.querySelectorAll('.ext-select:checked')];
            selectedCount.textContent = `${selected.length} abone seçildi`;
            deleteSelected.disabled = selected.length === 0;
            editSelected.disabled = selected.length === 0;
            selectAll.checked = visibleChecks.length > 0 && visibleChecks.every((box) => box.checked);
            selectAll.indeterminate = visibleChecks.some((box) => box.checked) && !selectAll.checked;
        };
        search.oninput = () => {
            const query = search.value.trim().toLocaleLowerCase('tr-TR');
            extensionRows.forEach((row) => {
                const matches = query === '' || (row.dataset.search || '').toLocaleLowerCase('tr-TR').includes(query);
                row.hidden = !matches;
                if (!matches) row.querySelector('.ext-select').checked = false;
            });
            updateSelection();
        };
        selectAll.onchange = () => {
            extensionRows.forEach((row) => {
                if (!row.hidden) row.querySelector('.ext-select').checked = selectAll.checked;
            });
            updateSelection();
        };
        document.querySelectorAll('.ext-select').forEach((box) => box.onchange = updateSelection);
        deleteSelected.onclick = async () => {
            const selected = [...document.querySelectorAll('.ext-select:checked')];
            const extensions = selected.map((box) =>
                box.closest('[data-ext-row]')?.querySelector('td code')?.textContent?.trim()
            ).filter(Boolean);
            if (!selected.length) return;
            if (!confirm(`${selected.length} abone silinsin mi?\n${extensions.slice(0, 20).join(', ')}${extensions.length > 20 ? '…' : ''}`)) return;
            deleteSelected.disabled = true;
            try {
                await this.post('extension_bulk_delete', { ids: selected.map((box) => box.value) });
                location.reload();
            } catch (e) {
                deleteSelected.disabled = false;
                this.toast(e.message, true);
            }
        };
        updateSelection();
        this.refreshStatus();
    },

    pageAmi() {
        const permissions = window.ASTERA.amiPermissions || [];
        const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[char]);
        const defaultRead = new Set([
            'system', 'call', 'log', 'verbose', 'command', 'agent', 'user',
            'config', 'dtmf', 'reporting', 'cdr', 'dialplan',
        ]);
        const defaultWrite = new Set([...defaultRead, 'originate']);
        const open = (row = {}) => {
            const editing = Boolean(row.manager_name);
            const read = new Set(editing ? (row.read_permissions || []) : defaultRead);
            const write = new Set(editing ? (row.write_permissions || []) : defaultWrite);
            const acl = Array.isArray(row.acl) ? row.acl : [];
            const deny = acl.filter((item) => item.action === 'deny').map((item) => item.network).join('\n')
                || '0.0.0.0/0';
            const permit = acl.filter((item) => item.action === 'permit').map((item) => item.network).join('\n')
                || '127.0.0.1/32\n10.0.0.0/8\n192.168.0.0/16\n172.16.0.0/12';
            this.modal(`
                <h3>${editing ? 'AMI kullanıcısını düzenle' : 'AMI kullanıcısı ekle'}</h3>
                <form id="amiUserForm" class="ami-user-form">
                    <label>Kullanıcı adı
                        <input name="manager_name" required maxlength="80" value="${escape(row.manager_name || '')}" ${editing ? 'readonly' : ''}>
                    </label>
                    <label>Parola
                        <input name="secret" type="password" minlength="8" maxlength="255" autocomplete="new-password"
                            ${editing ? '' : 'required'} placeholder="${editing ? 'Boş = değişmesin' : 'En az 8 karakter'}">
                    </label>
                    <label>Deny ağları
                        <textarea name="deny" rows="4" placeholder="0.0.0.0/0">${escape(deny)}</textarea>
                    </label>
                    <label>Permit ağları
                        <textarea name="permit" rows="4" placeholder="10.0.0.0/8">${escape(permit)}</textarea>
                    </label>
                    <label>Yazma zaman aşımı (ms)
                        <input name="writetimeout" type="number" min="100" max="60000" value="${Number(row.writetimeout || 5000)}">
                    </label>
                    <p class="muted">Her satıra bir IPv4 ağı yazın. Örnek: <code>10.0.0.0/8</code></p>
                    <fieldset class="fieldset ami-permissions">
                        <legend>Yetkiler</legend>
                        <div class="ami-permission-head"><strong>Yetki</strong><strong>Read</strong><strong>Write</strong></div>
                        ${permissions.map((permission) => `
                            <label class="ami-permission-row">
                                <span>${escape(permission)}</span>
                                <input type="checkbox" name="read_permissions" value="${escape(permission)}" ${read.has(permission) ? 'checked' : ''}>
                                <input type="checkbox" name="write_permissions" value="${escape(permission)}" ${write.has(permission) ? 'checked' : ''}>
                            </label>
                        `).join('')}
                    </fieldset>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="cancelAmiUser">Vazgeç</button>
                        <button class="btn primary" type="submit">Kaydet ve Asterisk’e Uygula</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            document.getElementById('cancelAmiUser').onclick = () => this.closeModal();
            const form = document.getElementById('amiUserForm');
            form.onsubmit = async (event) => {
                event.preventDefault();
                const data = Object.fromEntries(new FormData(form).entries());
                data.read_permissions = [...form.querySelectorAll('[name="read_permissions"]:checked')].map((item) => item.value);
                data.write_permissions = [...form.querySelectorAll('[name="write_permissions"]:checked')].map((item) => item.value);
                const button = form.querySelector('button[type="submit"]');
                button.disabled = true;
                try {
                    await this.post('ami_user_save', data);
                    this.toast('AMI kullanıcısı kaydedildi');
                    location.reload();
                } catch (error) {
                    button.disabled = false;
                    this.toast(error.message, true);
                }
            };
        };
        document.getElementById('addAmiUser').onclick = () => open();
        document.querySelectorAll('[data-edit-ami]').forEach((button) => {
            button.onclick = () => open(JSON.parse(button.dataset.editAmi));
        });
        document.querySelectorAll('[data-delete-ami]').forEach((button) => {
            button.onclick = async () => {
                if (!confirm(`${button.dataset.deleteAmi} AMI kullanıcısı silinsin mi?`)) return;
                button.disabled = true;
                try {
                    await this.post('ami_user_delete', { manager_name: button.dataset.deleteAmi });
                    location.reload();
                } catch (error) {
                    button.disabled = false;
                    this.toast(error.message, true);
                }
            };
        });
    },

    pageTrunks() {
        const open = (row = {}, isCopy = false) => {
            const editing = Boolean(row.id) && !isCopy;
            const inboundMode = row.inbound_mode || (row.id && row.type === 'peer' ? 'direct' : 'routes');
            const qualifyFrequency = Number(row.qualify_frequency ?? 60);
            const qualifyEnabled = qualifyFrequency > 0;
            this.modal(`
                <h3>${editing ? 'Trunk düzenle' : (isCopy ? 'Trunk çoğalt' : 'Trunk ekle')}</h3>
                <form id="f" class="trunk-form">
                    ${this.deptField(row.dept)}
                    <label>Kimlik<input name="id" required value="${row.id || ''}" ${editing ? 'readonly' : ''} placeholder="tt-acme"></label>
                    <label>Görünen ad<input name="name" value="${row.name || ''}"></label>
                    <label>Tür
                        <select name="type">
                            <option value="register" ${row.type !== 'peer' ? 'selected' : ''}>Kayıtlı (username/password)</option>
                            <option value="peer" ${row.type === 'peer' ? 'selected' : ''}>IP eş (peer)</option>
                        </select>
                    </label>
                    <p class="muted"><strong>Kayıtlı trunk:</strong> Kimlik, bağlantı, codec, NAT ve medya ayarları bu trunk için ayrı ayrı kaydedilir.</p>
                    <label>Gelen çağrı işleme
                        <select name="inbound_mode">
                            <option value="routes" ${inboundMode === 'routes' ? 'selected' : ''}>Gelen çağrı kurallarını kullan</option>
                            <option value="direct" ${inboundMode === 'direct' ? 'selected' : ''}>Dahilileri doğrudan ara (PBX bağlantısı)</option>
                        </select>
                    </label>
                    <p class="muted">Telekom/SIP operatörü için “Gelen çağrı kuralları”, Alcatel veya başka bir PBX doğrudan dahili gönderiyorsa “Dahilileri doğrudan ara” seçin.</p>
                    <label>Sunucu / IP<input name="host" required value="${row.host || ''}"></label>
                    <label>Port<input name="port" type="number" value="${row.port || 5060}"></label>
                    <label>Kullanıcı<input name="username" value="${row.username || ''}"></label>
                    <label>Parola<input name="password" value="${row.password || ''}"></label>
                    <label>From user (SIP kimliği)<input name="from_user" value="${row.from_user || ''}" placeholder="Örn. ofisastera"></label>
                    <p class="muted">Boş bırakılırsa arayan dahili kullanılır. Karşı santralde dahili çakışması varsa trunk’a özel bir SIP kimliği girin.</p>
                    <label>From domain<input name="from_domain" value="${row.from_domain || ''}"></label>

                    <h4>Giden Arayan Kimliği</h4>
                    <label>Giden Arayan Numarası
                        <input name="outbound_caller_id" value="${row.outbound_caller_id || ''}" placeholder="Örn. 08505822278">
                    </label>
                    <label>CID davranışı
                        <select name="cid_option">
                            <option value="allow_any" ${row.cid_option === 'allow_any' ? 'selected' : ''}>Her CID’ye izin ver</option>
                            <option value="block_foreign" ${row.cid_option === 'block_foreign' ? 'selected' : ''}>Yabancı CID’leri engelle</option>
                            <option value="remove_cnam" ${row.cid_option === 'remove_cnam' ? 'selected' : ''}>Arayan adını kaldır (CNAM)</option>
                            <option value="force_trunk" ${!row.cid_option || row.cid_option === 'force_trunk' ? 'selected' : ''}>Trunk CID’yi zorla</option>
                        </select>
                    </label>
                    <p class="muted">Yabancı CID engelleme; firmaya tanımlı abone CID’leri, gelen DID’ler ve trunk CID’si dışındaki numaraları reddeder.</p>

                    <h4>Ses ve Sinyalleşme</h4>
                    <label>DTMF modu
                        <select name="dtmf_mode">
                            <option value="auto" ${(row.dtmf_mode || 'auto') === 'auto' ? 'selected' : ''}>Auto</option>
                            <option value="rfc4733" ${row.dtmf_mode === 'rfc4733' ? 'selected' : ''}>RFC4733</option>
                            <option value="inband" ${row.dtmf_mode === 'inband' ? 'selected' : ''}>Inband</option>
                            <option value="info" ${row.dtmf_mode === 'info' ? 'selected' : ''}>SIP INFO</option>
                        </select>
                    </label>
                    <div class="checks">${this.codecChecks(row.codecs || ['ulaw','alaw','gsm','g726','g722'])}</div>

                    <h4>NAT ve Medya</h4>
                    <label><input type="checkbox" name="rewrite_contact" ${row.rewrite_contact !== false ? 'checked' : ''}> Rewrite contact</label>
                    <label><input type="checkbox" name="rtp_symmetric" ${row.rtp_symmetric !== false ? 'checked' : ''}> Symmetric RTP</label>
                    <label><input type="checkbox" name="direct_media" ${row.direct_media ? 'checked' : ''}> Direct media</label>
                    <label><input type="checkbox" name="trust_id_inbound" ${row.trust_id_inbound ? 'checked' : ''}> Gelen kimliğe güven</label>
                    <label>Medya şifreleme
                        <select name="media_encryption">
                            <option value="no" ${(row.media_encryption || 'no') === 'no' ? 'selected' : ''}>Yok</option>
                            <option value="sdes" ${row.media_encryption === 'sdes' ? 'selected' : ''}>SDES</option>
                            <option value="dtls" ${row.media_encryption === 'dtls' ? 'selected' : ''}>DTLS</option>
                        </select>
                    </label>

                    <h4>Kayıt ve AOR</h4>
                    <label>Qualify
                        <select name="qualify_enabled" id="trunkQualifyEnabled">
                            <option value="yes" ${qualifyEnabled ? 'selected' : ''}>Yes</option>
                            <option value="no" ${!qualifyEnabled ? 'selected' : ''}>No</option>
                        </select>
                    </label>
                    <label>Qualify sıklığı (sn)<input name="qualify_frequency" id="trunkQualifyFrequency" type="number" min="1" value="${qualifyEnabled ? qualifyFrequency : 60}"></label>
                    <label>Kayıt süresi (sn)<input name="expiration" type="number" min="60" value="${row.expiration ?? 3600}"></label>
                    <label>Tekrar deneme (sn)<input name="retry_interval" type="number" min="1" value="${row.retry_interval ?? 60}"></label>
                    <label>Azami deneme<input name="max_retries" type="number" min="0" value="${row.max_retries ?? 10}"></label>
                    <label><input type="checkbox" name="auth_rejection_permanent" ${row.auth_rejection_permanent ? 'checked' : ''}> Yetki reddinde kaydı kalıcı durdur</label>
                    <label><input type="checkbox" name="support_path" ${row.support_path ? 'checked' : ''}> SIP Path desteği</label>

                    <h4>Faks</h4>
                    <label><input type="checkbox" name="fax_detect" ${row.fax_detect ? 'checked' : ''}> Faks algılama</label>
                    <label><input type="checkbox" name="t38_udptl" ${row.t38_udptl ? 'checked' : ''}> T.38 UDPTL</label>
                    <label><input type="checkbox" name="t38_udptl_nat" ${row.t38_udptl_nat ? 'checked' : ''}> T.38 NAT</label>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            document.getElementById('c').onclick = () => this.closeModal();
            const qualifySelect = document.getElementById('trunkQualifyEnabled');
            const qualifyFrequencyInput = document.getElementById('trunkQualifyFrequency');
            const updateQualify = () => {
                qualifyFrequencyInput.disabled = qualifySelect.value === 'no';
            };
            qualifySelect.onchange = updateQualify;
            updateQualify();
            this.formSubmit(document.getElementById('f'), 'trunk_save');
        };
        document.getElementById('addTrunk').onclick = () => open();
        document.querySelectorAll('[data-edit-trunk]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editTrunk)));
        document.querySelectorAll('[data-copy-trunk]').forEach((b) => b.onclick = () => {
            const source = JSON.parse(b.dataset.copyTrunk);
            const suffix = Date.now().toString().slice(-6);
            open({
                ...source,
                id: `${source.id}-kopya-${suffix}`,
                name: `${source.name || source.id} Kopya`,
            }, true);
        });
        document.querySelectorAll('[data-toggle-trunk]').forEach((b) => b.onclick = async () => {
            b.disabled = true;
            try {
                await this.post('trunk_toggle', { id: b.dataset.toggleTrunk });
                location.reload();
            } catch (e) {
                b.disabled = false;
                this.toast(e.message, true);
            }
        });
        document.querySelectorAll('[data-del-trunk]').forEach((b) => b.onclick = async () => {
            if (!confirm('Trunk silinsin mi?')) return;
            try { await this.post('trunk_delete', { id: b.dataset.delTrunk }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
    },

    pagePri() {
        const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[char]);
        let detectedSpans = [];
        const refresh = async () => {
            const hardware = document.getElementById('priHardware');
            const status = document.getElementById('priStatus');
            hardware.textContent = 'Sorgulanıyor…';
            status.textContent = 'Sorgulanıyor…';
            try {
                const result = await this.post('pri_status');
                detectedSpans = Array.isArray(result.spans) ? result.spans : [];
                const output = String(result.output || 'DAHDI çıktısı alınamadı.');
                const marker = output.indexOf('--- Asterisk DAHDI ---');
                hardware.textContent = marker >= 0 ? output.slice(0, marker).trim() : output;
                status.textContent = marker >= 0 ? output.slice(marker).trim() : output;
            } catch (error) {
                hardware.textContent = error.message;
                status.textContent = error.message;
            }
        };
        const open = (row = {}) => {
            const configured = window.ASTERA.priSpans || [];
            const available = detectedSpans.find((item) =>
                !configured.some((saved) => Number(saved.span) === Number(item.span))
            ) || detectedSpans[0];
            const span = Number(row.span || available?.span || 1);
            const detected = detectedSpans.find((item) => Number(item.span) === span);
            const firstChannel = Number(detected?.basechan || (((span - 1) * 31) + 1));
            const dChannel = firstChannel + 15;
            const lastChannel = firstChannel + Math.max(1, Number(detected?.totchans || 31)) - 1;
            const spanField = detectedSpans.length
                ? `<label>Fiziksel span<select name="span" required>${detectedSpans.map((item) =>
                    `<option value="${Number(item.span)}" ${Number(item.span) === span ? 'selected' : ''}>`
                    + `${Number(item.span)} — ${escape(item.description || item.name || 'E1')} `
                    + `(kanal ${Number(item.basechan)}-${Number(item.basechan) + Number(item.totchans) - 1})`
                    + `</option>`
                ).join('')}</select></label>`
                : `<label>Fiziksel span<input name="span" type="number" min="1" max="32" required value="${span}"></label>`;
            const defaults = {
                group: span,
                timing: 1,
                lbo: 0,
                framing: 'ccs',
                coding: 'hdb3',
                bchannels: `${firstChannel}-${dChannel - 1},${dChannel + 1}-${lastChannel}`,
                dchannel: dChannel,
                signalling: 'pri_cpe',
                switchtype: 'euroisdn',
                pridialplan: 'unknown',
                rxgain: 0,
                txgain: 0,
                crc4: true,
                echocancel: true,
                denoise: false,
                enabled: false,
                ...row
            };
            this.modal(`
                <h3>${row.id ? 'PRI spanını düzenle' : 'PRI spanı ekle'}</h3>
                <form id="f" class="trunk-form">
                    ${this.deptField(defaults.dept)}
                    <input type="hidden" name="id" value="${escape(defaults.id || '')}">
                    <label>Görünen ad<input name="name" maxlength="120" value="${escape(defaults.name || `PRI Span ${span}`)}"></label>
                    ${spanField}
                    <p class="muted tiny">Kartın algılanan fiziksel portunu seçin; B/D kanal numaraları otomatik doldurulur.</p>
                    <label>DAHDI grup<input name="group" type="number" min="0" max="63" required value="${Number(defaults.group)}"></label>

                    <h4>E1 hat ayarları</h4>
                    <label>Zamanlama kaynağı
                        <select name="timing">
                            <option value="0" ${Number(defaults.timing) === 0 ? 'selected' : ''}>0 — dahili saat</option>
                            <option value="1" ${Number(defaults.timing) === 1 ? 'selected' : ''}>1 — birincil hat saati</option>
                            <option value="2" ${Number(defaults.timing) === 2 ? 'selected' : ''}>2 — ikincil hat saati</option>
                            <option value="3" ${Number(defaults.timing) === 3 ? 'selected' : ''}>3 — üçüncül hat saati</option>
                            <option value="4" ${Number(defaults.timing) === 4 ? 'selected' : ''}>4 — dördüncül hat saati</option>
                        </select>
                    </label>
                    <label>Line build-out<input name="lbo" type="number" min="0" max="7" value="${Number(defaults.lbo)}"></label>
                    <label>Framing
                        <select name="framing">
                            <option value="ccs" ${defaults.framing === 'ccs' ? 'selected' : ''}>CCS</option>
                            <option value="cas" ${defaults.framing === 'cas' ? 'selected' : ''}>CAS</option>
                        </select>
                    </label>
                    <label>Kodlama
                        <select name="coding">
                            <option value="hdb3" ${defaults.coding === 'hdb3' ? 'selected' : ''}>HDB3</option>
                            <option value="ami" ${defaults.coding === 'ami' ? 'selected' : ''}>AMI</option>
                        </select>
                    </label>
                    <label><input type="checkbox" name="crc4" ${defaults.crc4 ? 'checked' : ''}> CRC4</label>
                    <label>B-kanalları<input name="bchannels" required value="${escape(defaults.bchannels)}" placeholder="1-15,17-31"></label>
                    <label>D-kanalı<input name="dchannel" type="number" min="1" max="1024" required value="${Number(defaults.dchannel)}"></label>

                    <h4>PRI sinyalleşme</h4>
                    <label>Rol
                        <select name="signalling">
                            <option value="pri_cpe" ${defaults.signalling === 'pri_cpe' ? 'selected' : ''}>CPE — operatöre bağlanan taraf</option>
                            <option value="pri_net" ${defaults.signalling === 'pri_net' ? 'selected' : ''}>NET — şebeke tarafı</option>
                        </select>
                    </label>
                    <label>Switch tipi
                        <select name="switchtype">
                            ${['euroisdn', 'qsig', 'national', 'dms100', '4ess', '5ess'].map((type) =>
                                `<option value="${type}" ${defaults.switchtype === type ? 'selected' : ''}>${type}</option>`
                            ).join('')}
                        </select>
                    </label>
                    <label>Numaralandırma planı
                        <select name="pridialplan">
                            ${['unknown', 'local', 'national', 'international', 'dynamic'].map((type) =>
                                `<option value="${type}" ${defaults.pridialplan === type ? 'selected' : ''}>${type}</option>`
                            ).join('')}
                        </select>
                    </label>
                    <h4>Ses seviyeleri</h4>
                    <label>Gelen gain — Alcatel → SIPPhone (dB)
                        <input name="rxgain" type="number" min="-12" max="12" step="0.5" value="${Number(defaults.rxgain || 0)}">
                    </label>
                    <label>Giden gain — SIPPhone → Alcatel (dB)
                        <input name="txgain" type="number" min="-12" max="12" step="0.5" value="${Number(defaults.txgain || 0)}">
                    </label>
                    <p class="muted tiny">Negatif değer sesi ve dip gürültüsünü azaltır. 0,5 dB adımlarla -12 ile +12 dB arasında ayarlanabilir.</p>
                    <label><input type="checkbox" name="overlapdial" ${defaults.overlapdial ? 'checked' : ''}> Overlap dialing</label>
                    <label><input type="checkbox" name="echocancel" ${defaults.echocancel ? 'checked' : ''}> Yankı giderme</label>
                    <label><input type="checkbox" name="denoise" ${defaults.denoise ? 'checked' : ''}> Alcatel’e giden sese gürültü azaltma uygula</label>
                    <label><input type="checkbox" name="enabled" ${defaults.enabled ? 'checked' : ''}> Spanı etkinleştir</label>
                    <p class="muted tiny">Birden fazla span varsa B/D kanalları global DAHDI kanal numaralarıyla ve çakışmayacak şekilde girilmelidir.</p>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            const spanInput = document.querySelector('#f [name="span"]');
            spanInput.onchange = () => {
                const selectedSpan = Number(spanInput.value);
                const selected = detectedSpans.find((item) => Number(item.span) === selectedSpan);
                const first = Number(selected?.basechan || (((selectedSpan - 1) * 31) + 1));
                const count = Math.max(1, Number(selected?.totchans || 31));
                const dchan = first + 15;
                document.querySelector('#f [name="bchannels"]').value =
                    `${first}-${dchan - 1},${dchan + 1}-${first + count - 1}`;
                document.querySelector('#f [name="dchannel"]').value = dchan;
                if (!row.id) {
                    document.querySelector('#f [name="group"]').value = selectedSpan;
                }
            };
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(document.getElementById('f'), 'pri_span_save');
        };
        document.getElementById('priRefresh').onclick = refresh;
        document.getElementById('addPriSpan').onclick = async () => {
            if (!detectedSpans.length) await refresh();
            open();
        };
        document.querySelectorAll('[data-edit-pri]').forEach((button) => {
            button.onclick = () => open(JSON.parse(button.dataset.editPri));
        });
        document.querySelectorAll('[data-del-pri]').forEach((button) => {
            button.onclick = async () => {
                if (!confirm('PRI spanı silinsin mi?')) return;
                try {
                    await this.post('pri_span_delete', { id: button.dataset.delPri });
                    location.reload();
                } catch (error) {
                    this.toast(error.message, true);
                }
            };
        });
        refresh();
    },

    pageOutbound() {
        const trunks = window.ASTERA.trunks || [];
        const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[char]);
        const legacyPattern = (row) => {
            const original = String(row.pattern || '_0X.');
            const strip = Math.max(0, Number(row.strip || 0));
            const body = original.startsWith('_') ? original.slice(1) : original;
            const removable = body.slice(0, strip);
            if (strip > 0 && /^[0-9]+$/.test(removable)) {
                const remaining = body.slice(strip) || 'X.';
                const match = /[XZN.!\[]/.test(remaining) ? `_${remaining}` : remaining;
                return { prepend: row.prefix || '', prefix: removable, match, caller_id: '' };
            }
            return {
                prepend: row.prefix || '',
                prefix: '',
                match: original,
                caller_id: '',
                strip_count: strip,
            };
        };
        const open = (row = {}) => {
            const deptNow = row.dept || window.ASTERA.dept || (trunks[0] && trunks[0].dept) || '';
            let patterns = Array.isArray(row.patterns) && row.patterns.length
                ? row.patterns.map((item) => ({ ...item }))
                : [legacyPattern(row)];
            let routeTrunks = Array.isArray(row.trunks) && row.trunks.length
                ? row.trunks.map(String)
                : (row.trunk ? [String(row.trunk)] : []);
            this.modal(`
                <h3>${row.id ? 'Kural düzenle' : 'Giden kural'}</h3>
                <form id="f" class="outbound-form">
                    ${this.deptField(deptNow)}
                    <input type="hidden" name="id" value="${esc(row.id || '')}">
                    <input type="hidden" name="patterns_json">
                    <input type="hidden" name="trunks_json">
                    <div class="grid-2">
                        <label>Ad<input name="name" value="${esc(row.name || '')}"></label>
                        <label>PIN (boş = yok)<input name="pin" value="${esc(row.pin || '')}"></label>
                    </div>
                    <fieldset class="fieldset">
                        <legend>Arama kalıpları</legend>
                        <div class="outbound-pattern-head">
                            <span>Başına ekle</span><span>Silinecek önek</span>
                            <span>Eşleşme kalıbı</span><span>Arayan No kalıbı</span><span></span>
                        </div>
                        <div id="outboundPatterns"></div>
                        <button class="btn sm" type="button" id="addOutboundPattern">+ Kalıp ekle</button>
                        <p class="tiny muted">Örnek: <strong>Başına ekle 90</strong>, <strong>silinecek önek 0</strong>, <strong>eşleşme _X.</strong> → 0532… tuşlandığında 90532… gönderilir.</p>
                    </fieldset>
                    <fieldset class="fieldset">
                        <legend>Trunk sırası</legend>
                        <div id="outboundTrunks"></div>
                        <button class="btn sm" type="button" id="addOutboundTrunk">+ Trunk ekle</button>
                        <label class="outbound-balance"><input type="checkbox" name="equal_distribution" ${row.equal_distribution ? 'checked' : ''}>
                            Seçili trunklara çağrıları eşit ve sırayla dağıt
                        </label>
                        <p class="tiny muted">Dağıtım kapalıysa ilk trunk başarısız olduğunda sıradaki trunk denenir.</p>
                    </fieldset>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            const deptInp = document.querySelector('[name="dept"]');
            const form = document.getElementById('f');
            const syncPayload = () => {
                form.elements.patterns_json.value = JSON.stringify(patterns);
                form.elements.trunks_json.value = JSON.stringify(routeTrunks);
            };
            const renderPatterns = () => {
                document.getElementById('outboundPatterns').innerHTML = patterns.map((item, index) => `
                    <div class="outbound-pattern-row" data-pattern-index="${index}">
                        <input data-pattern-key="prepend" value="${esc(item.prepend || '')}" placeholder="90">
                        <input data-pattern-key="prefix" value="${esc(item.prefix || '')}" placeholder="0">
                        <input data-pattern-key="match" required value="${esc(item.match || item.pattern || '_X.')}" placeholder="_X.">
                        <input data-pattern-key="caller_id" value="${esc(item.caller_id || '')}" placeholder="İsteğe bağlı">
                        <button class="btn sm danger" type="button" data-remove-pattern="${index}" title="Kalıbı sil">×</button>
                    </div>
                `).join('');
                document.querySelectorAll('[data-pattern-key]').forEach((input) => {
                    input.oninput = () => {
                        const index = Number(input.closest('[data-pattern-index]').dataset.patternIndex);
                        patterns[index][input.dataset.patternKey] = input.value;
                        delete patterns[index].strip_count;
                        syncPayload();
                    };
                });
                document.querySelectorAll('[data-remove-pattern]').forEach((button) => {
                    button.onclick = () => {
                        if (patterns.length <= 1) return this.toast('En az bir kalıp olmalıdır', true);
                        patterns.splice(Number(button.dataset.removePattern), 1);
                        renderPatterns();
                        syncPayload();
                    };
                });
            };
            const renderTrunks = () => {
                const dept = deptInp ? deptInp.value : deptNow;
                const available = trunks.filter((item) => !dept || item.dept === dept);
                routeTrunks = routeTrunks.filter((id) => available.some((item) => String(item.id) === id));
                document.getElementById('outboundTrunks').innerHTML = routeTrunks.map((current, index) => `
                    <div class="outbound-trunk-row" data-trunk-index="${index}">
                        <span class="outbound-trunk-order">${index + 1}</span>
                        <select>${available.map((item) =>
                            `<option value="${esc(item.id)}" ${String(item.id) === current ? 'selected' : ''}>${esc(item.name || item.id)}</option>`
                        ).join('')}</select>
                        <button class="btn sm" type="button" data-move-trunk="up" ${index === 0 ? 'disabled' : ''}>↑</button>
                        <button class="btn sm" type="button" data-move-trunk="down" ${index === routeTrunks.length - 1 ? 'disabled' : ''}>↓</button>
                        <button class="btn sm danger" type="button" data-remove-trunk="${index}">×</button>
                    </div>
                `).join('');
                document.querySelectorAll('[data-trunk-index] select').forEach((select) => {
                    select.onchange = () => {
                        routeTrunks[Number(select.closest('[data-trunk-index]').dataset.trunkIndex)] = select.value;
                        syncPayload();
                    };
                });
                document.querySelectorAll('[data-move-trunk]').forEach((button) => {
                    button.onclick = () => {
                        const index = Number(button.closest('[data-trunk-index]').dataset.trunkIndex);
                        const target = button.dataset.moveTrunk === 'up' ? index - 1 : index + 1;
                        [routeTrunks[index], routeTrunks[target]] = [routeTrunks[target], routeTrunks[index]];
                        renderTrunks();
                        syncPayload();
                    };
                });
                document.querySelectorAll('[data-remove-trunk]').forEach((button) => {
                    button.onclick = () => {
                        routeTrunks.splice(Number(button.dataset.removeTrunk), 1);
                        renderTrunks();
                        syncPayload();
                    };
                });
                syncPayload();
                return available;
            };
            document.getElementById('addOutboundPattern').onclick = () => {
                patterns.push({ prepend: '', prefix: '', match: '_X.', caller_id: '' });
                renderPatterns();
                syncPayload();
            };
            document.getElementById('addOutboundTrunk').onclick = () => {
                const dept = deptInp ? deptInp.value : deptNow;
                const available = trunks.filter((item) => !dept || item.dept === dept);
                const next = available.find((item) => !routeTrunks.includes(String(item.id))) || available[0];
                if (!next) return this.toast('Bu firmada trunk yok', true);
                routeTrunks.push(String(next.id));
                renderTrunks();
            };
            renderPatterns();
            const available = renderTrunks();
            if (!routeTrunks.length && available.length) {
                routeTrunks.push(String(available[0].id));
                renderTrunks();
            }
            if (!available.length) this.toast('Bu firmada trunk yok', true);
            if (deptInp && deptInp.tagName === 'SELECT') deptInp.onchange = () => {
                routeTrunks = [];
                const nextAvailable = renderTrunks();
                if (nextAvailable.length) {
                    routeTrunks.push(String(nextAvailable[0].id));
                    renderTrunks();
                }
            };
            document.getElementById('c').onclick = () => this.closeModal();
            syncPayload();
            this.formSubmit(form, 'outbound_save');
        };
        document.getElementById('addOut').onclick = () => {
            if (!trunks.length) return this.toast('Önce bir firmaya trunk ekleyin. Trunk Astera’da.', true);
            open();
        };
        document.querySelectorAll('[data-move-out]').forEach((button) => {
            button.onclick = async () => {
                const moveButtons = [...document.querySelectorAll('[data-move-out]')];
                moveButtons.forEach((item) => { item.disabled = true; });
                try {
                    await this.post('outbound_reorder', {
                        id: button.dataset.moveOut,
                        direction: button.dataset.direction,
                    });
                    location.reload();
                } catch (error) {
                    moveButtons.forEach((item) => { item.disabled = false; });
                    this.toast(error.message, true);
                }
            };
        });
        document.querySelectorAll('[data-edit-out]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editOut)));
        document.querySelectorAll('[data-del-out]').forEach((b) => b.onclick = async () => {
            if (!confirm('Kural silinsin mi?')) return;
            try { await this.post('outbound_delete', { id: b.dataset.delOut }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
    },

    pageInbound() {
        const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[char]);
        const open = (row = {}, isCopy = false) => {
            const editing = Boolean(row.id) && !isCopy;
            this.modal(`
                <h3>${editing ? 'Gelen kuralı düzenle' : (isCopy ? 'Gelen kuralı çoğalt' : 'Gelen kural ekle')}</h3>
                <form id="f" class="inbound-form">
                    ${this.deptField(row.dept)}
                    <input type="hidden" name="id" value="${esc(row.id || '')}">

                    <fieldset class="fieldset">
                        <legend>Gelen çağrı eşleşmesi</legend>
                        <div class="grid-2">
                            <label>Açıklama<input name="name" value="${esc(row.name || '')}" placeholder="Ana numara"></label>
                            <label>DID numarası / kalıp<input name="did" required value="${esc(row.did || '_X.')}" placeholder="908503338006 veya _X."></label>
                            <label>Arayan numara / kalıp<input name="caller_id" value="${esc(row.caller_id || '')}" placeholder="Boş = tüm arayanlar"></label>
                            <label>Öncelik<input name="priority" type="number" min="0" max="9999" value="${Number(row.priority ?? 100)}"><small>Küçük sayı önce değerlendirilir.</small></label>
                        </div>
                    </fieldset>

                    <fieldset class="fieldset">
                        <legend>Arayan bilgisi ve zil davranışı</legend>
                        <div class="grid-2">
                            <label>CID ad öneki<input name="cid_prefix" value="${esc(row.cid_prefix || '')}" placeholder="Satış: "></label>
                            <label>Alert-Info<input name="alert_info" value="${esc(row.alert_info || '')}" placeholder="Özel zil başlığı"></label>
                            <label>Bekleme müziği sınıfı<input name="musicclass" value="${esc(row.musicclass || row.dept || '')}" placeholder="Firma kodu"></label>
                            ${this.languageField(row.language || 'tr')}
                            <label>Cevap öncesi bekleme (sn)<input name="pause_before_answer" type="number" min="0" max="30" value="${Number(row.pause_before_answer ?? 0)}"></label>
                            <label>Çağrı kaydı
                                <select name="record_mode">
                                    <option value="inherit" ${(row.record_mode || 'inherit') === 'inherit' ? 'selected' : ''}>Hedef ayarını kullan</option>
                                    <option value="force" ${row.record_mode === 'force' ? 'selected' : ''}>Zorunlu kaydet</option>
                                    <option value="off" ${row.record_mode === 'off' ? 'selected' : ''}>Kaydetme</option>
                                </select>
                            </label>
                        </div>
                        <div class="checks">
                            <label><input type="checkbox" name="signal_ringing" ${row.signal_ringing ? 'checked' : ''}> Hedefe göndermeden önce çalma sinyali ver</label>
                            <label><input type="checkbox" name="privacy_manager" ${row.privacy_manager ? 'checked' : ''}> Numarası gizli arayanlarda Privacy Manager çalıştır</label>
                        </div>
                    </fieldset>

                    <fieldset class="fieldset">
                        <legend>Hedef</legend>
                        ${this.destPair('dest_type', 'dest', row.dest_type || 'extension', row.dest || '')}
                    </fieldset>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            this.bindDestPairs();
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(document.getElementById('f'), 'inbound_save');
        };
        document.getElementById('addIn').onclick = () => open();
        document.querySelectorAll('[data-edit-in]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editIn)));
        document.querySelectorAll('[data-copy-in]').forEach((b) => b.onclick = () => {
            const source = JSON.parse(b.dataset.copyIn);
            open({
                ...source,
                id: '',
                name: `${source.name || source.did || 'Gelen kural'} Kopya`,
            }, true);
        });
        document.querySelectorAll('[data-del-in]').forEach((b) => b.onclick = async () => {
            if (!confirm('Kural silinsin mi?')) return;
            try { await this.post('inbound_delete', { id: b.dataset.delIn }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
    },

    pageQueues() {
        const exts = window.ASTERA.exts || [];
        const strategies = window.ASTERA.strategies || {};
        const checked = (row, key, fallback = false) => (row[key] ?? fallback) ? 'checked' : '';
        const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[char]);
        const open = (row = {}, isCopy = false) => {
            const editing = Boolean(row.id) && !isCopy;
            let members = [...new Set((row.members || []).map(String))];
            const memberPenalties = {};
            if (Array.isArray(row.member_penalties)) {
                row.member_penalties.forEach((item) => {
                    if (item && item.member !== undefined) {
                        memberPenalties[String(item.member)] = Number(item.penalty || 1);
                    }
                });
            } else {
                Object.entries(row.member_penalties || {}).forEach(([member, penalty]) => {
                    memberPenalties[String(member)] = Number(penalty || 1);
                });
            }
            const deptId = row.dept || window.ASTERA.dept || '';
            const dept = (window.ASTERA.depts || []).find((item) => item.id === deptId) || {};
            const recordEnabled = row.record ?? dept.record ?? true;
            const selectedStrategy = row.strategy || 'linear';
            const st = Object.entries(strategies).map(([k, v]) =>
                `<option value="${k}" ${selectedStrategy === k ? 'selected' : ''}>${v}</option>`
            ).join('');
            const localId = row.id ? String(row.id).split('-').slice(1).join('-') || row.id : '';
            this.modal(`
                <h3>${editing ? 'Kuyruk düzenle' : (isCopy ? 'Kuyruk çoğalt' : 'Kuyruk ekle')}</h3>
                <form id="f" class="queue-form">
                    ${this.deptField(row.dept)}
                    <fieldset class="fieldset">
                        <legend>Temel ayarlar</legend>
                        <div class="grid-2">
                            <label>Kimlik<input name="id" required value="${row.id || localId}" ${editing ? 'readonly' : ''} placeholder="destek"></label>
                            <label>Kuyruk adı<input name="name" value="${row.name || ''}" placeholder="Satış kuyruğu"></label>
                            ${this.languageField(row.language || 'tr')}
                            <label>Dahili numarası<input name="exten" ${isCopy ? 'readonly' : 'required'} value="${row.exten || ''}" placeholder="${isCopy ? 'Kaydedildiğinde otomatik atanır' : '8001'}"></label>
                            <label>Dağıtım stratejisi<select name="strategy">${st}</select></label>
                            <label>Üye çalma süresi (sn)<input name="timeout" type="number" min="5" value="${row.timeout ?? 20}"></label>
                            <label>Tekrar deneme aralığı (sn)<input name="retry" type="number" min="1" max="60" value="${row.retry ?? 5}"></label>
                            <label>Çağrı sonrası mola (sn)<input name="wrapuptime" type="number" min="0" value="${row.wrapuptime ?? 5}"></label>
                            <label>Toplam bekleme sınırı (sn)<input name="queue_timeout" type="number" min="0" value="${row.queue_timeout ?? 300}"><small>0 = sınırsız</small></label>
                            <label>Maksimum bekleyen<input name="maxlen" type="number" min="0" value="${row.maxlen ?? 0}"><small>0 = sınırsız</small></label>
                            <label>Servis seviyesi (sn)<input name="servicelevel" type="number" min="1" value="${row.servicelevel ?? 60}"></label>
                            <label>Kuyruk ağırlığı<input name="weight" type="number" min="0" max="100" value="${row.weight ?? 0}"></label>
                            <label>Üye cevap sonrası gecikme (sn)<input name="memberdelay" type="number" min="0" max="60" value="${row.memberdelay ?? 0}"></label>
                            <label>Bekleme müziği sınıfı<input name="musicclass" value="${row.musicclass || row.dept || ''}" placeholder="Firma kodu"></label>
                            <label>CID Ad Öneki (CID Name Prefix)<input name="cid_prefix" value="${esc(row.cid_prefix || '')}" placeholder="Kuyruk:"></label>
                        </div>
                    </fieldset>

                    <fieldset class="fieldset">
                        <legend>Ses kaydı</legend>
                        <div class="grid-2">
                            <label>Çağrı kaydı<select name="record">
                                <option value="1" ${recordEnabled ? 'selected' : ''}>Açık</option>
                                <option value="" ${!recordEnabled ? 'selected' : ''}>Kapalı</option>
                            </select></label>
                            <label>Kayıt formatı<select name="record_format">
                                <option value="wav" ${(row.record_format || 'wav') === 'wav' ? 'selected' : ''}>WAV</option>
                                <option value="gsm" ${row.record_format === 'gsm' ? 'selected' : ''}>GSM</option>
                                <option value="ulaw" ${row.record_format === 'ulaw' ? 'selected' : ''}>ULAW</option>
                            </select></label>
                        </div>
                    </fieldset>

                    <fieldset class="fieldset">
                        <legend>Üyeler</legend>
                        <input type="hidden" name="member_penalties_json">
                        <label>Manuel üye listesi
                            <textarea id="queueMemberManual" rows="5"
                                placeholder="121,1&#10;122,1&#10;123,2&#10;905307711717#,1"></textarea>
                            <small>Her satıra <strong>dahili,öncelik</strong> yazın. Dış numaranın sonuna <strong>#</strong> koyun.</small>
                        </label>
                        <div id="queueMembers" hidden></div>
                        <p class="tiny muted"><strong>Satır sırası</strong> aranma sırasıdır. Silmek için satırı kaldırın. Öncelik 1 önce; uygun değilse 2, ardından 3 aranır.</p>
                    </fieldset>

                    <fieldset class="fieldset">
                        <legend>Arayana anonslar</legend>
                        <div class="grid-2">
                            <label>Karşılama anonsu
                                <select name="announce" data-tenant-sound data-uploaded-only="1" data-empty-label="Anons yok">
                                    <option value="${esc(row.announce || '')}" selected>${esc(row.announce || 'Anons yok')}</option>
                                </select>
                            </label>
                            <label>Periyodik anons
                                <select name="periodic_announce" data-tenant-sound data-uploaded-only="1" data-empty-label="Anons yok">
                                    <option value="${esc(row.periodic_announce || '')}" selected>${esc(row.periodic_announce || 'Anons yok')}</option>
                                </select>
                            </label>
                            <label>Anons sıklığı (sn)<input name="announce_frequency" type="number" min="0" value="${row.announce_frequency ?? 60}"></label>
                            <label>Minimum anons aralığı (sn)<input name="min_announce_frequency" type="number" min="0" value="${row.min_announce_frequency ?? 15}"></label>
                            <label>Periyodik anons sıklığı (sn)<input name="periodic_announce_frequency" type="number" min="0" value="${row.periodic_announce_frequency ?? 60}"></label>
                            <label>Sıra numarası üst sınırı<input name="announce_position_limit" type="number" min="0" value="${row.announce_position_limit ?? 0}"><small>0 = tüm sıralar</small></label>
                            <label>Bekleme süresini söyle<select name="announce_holdtime">
                                <option value="no" ${(row.announce_holdtime || 'no') === 'no' ? 'selected' : ''}>Hayır</option>
                                <option value="yes" ${row.announce_holdtime === 'yes' ? 'selected' : ''}>Her anonsta</option>
                                <option value="once" ${row.announce_holdtime === 'once' ? 'selected' : ''}>Bir kez</option>
                            </select></label>
                            <label>Süre yuvarlama<select name="announce_round_seconds">
                                ${[0, 5, 10, 15, 20, 30].map((n) => `<option value="${n}" ${Number(row.announce_round_seconds ?? 10) === n ? 'selected' : ''}>${n} saniye</option>`).join('')}
                            </select></label>
                        </div>
                        <div class="checks">
                            <label><input type="checkbox" name="announce_position" ${checked(row, 'announce_position')}> Arayana sıra numarasını söyle</label>
                            <label><input type="checkbox" name="relative_periodic_announce" ${checked(row, 'relative_periodic_announce', true)}> Periyodik süreyi son anonsa göre hesapla</label>
                            <label><input type="checkbox" name="announce_to_first_user" ${checked(row, 'announce_to_first_user')}> İlk sıradaki arayana da anons yap</label>
                            <label><input type="checkbox" name="reportholdtime" ${checked(row, 'reportholdtime')}> Temsilciye bekleme süresini söyle</label>
                        </div>
                    </fieldset>

                    <fieldset class="fieldset">
                        <legend>Üye ve doluluk davranışı</legend>
                        <div class="grid-2">
                            <label>Üye yokken katılım<select name="joinempty">
                                <option value="yes" ${(row.joinempty || 'yes') === 'yes' ? 'selected' : ''}>İzin ver</option>
                                <option value="no" ${row.joinempty === 'no' ? 'selected' : ''}>İzin verme</option>
                                <option value="strict" ${row.joinempty === 'strict' ? 'selected' : ''}>Katı kontrol</option>
                            </select></label>
                            <label>Üye kalmayınca çıkış<select name="leavewhenempty">
                                <option value="no" ${(row.leavewhenempty || 'no') === 'no' ? 'selected' : ''}>Bekletmeye devam et</option>
                                <option value="yes" ${row.leavewhenempty === 'yes' ? 'selected' : ''}>Kuyruktan çıkar</option>
                                <option value="strict" ${row.leavewhenempty === 'strict' ? 'selected' : ''}>Katı kontrol</option>
                            </select></label>
                            <label>Otomatik duraklat<select name="autopause">
                                <option value="no" ${(row.autopause || 'no') === 'no' ? 'selected' : ''}>Kapalı</option>
                                <option value="yes" ${row.autopause === 'yes' ? 'selected' : ''}>Bu kuyrukta</option>
                                <option value="all" ${row.autopause === 'all' ? 'selected' : ''}>Tüm kuyruklarda</option>
                            </select></label>
                            <label>Otomatik duraklatma gecikmesi (sn)<input name="autopausedelay" type="number" min="0" value="${row.autopausedelay ?? 0}"></label>
                        </div>
                        <div class="checks">
                            <label><input type="checkbox" name="autofill" ${checked(row, 'autofill', true)}> Aynı anda boş üyelere çağrı dağıt</label>
                            <label><input type="checkbox" name="ringinuse" ${checked(row, 'ringinuse')}> Meşgul üyeyi de çaldır</label>
                            <label><input type="checkbox" name="autopausebusy" ${checked(row, 'autopausebusy')}> Meşgulde otomatik duraklat</label>
                            <label><input type="checkbox" name="autopausenoanswer" ${checked(row, 'autopausenoanswer')}> Cevap vermezse duraklat</label>
                            <label><input type="checkbox" name="autopauseunavailable" ${checked(row, 'autopauseunavailable')}> Ulaşılamazsa duraklat</label>
                            <label><input type="checkbox" name="timeoutrestart" ${checked(row, 'timeoutrestart')}> BUSY/CONGESTION sonrası süreyi sıfırla</label>
                            <label><input type="checkbox" name="shared_lastcall" ${checked(row, 'shared_lastcall')}> Son çağrı zamanını kuyruklar arasında paylaş</label>
                            <label><input type="checkbox" name="caller_transfer" ${checked(row, 'caller_transfer', true)}> Arayan aktarma yapabilsin</label>
                            <label><input type="checkbox" name="agent_transfer" ${checked(row, 'agent_transfer', true)}> Temsilci aktarma yapabilsin</label>
                        </div>
                    </fieldset>

                    <fieldset class="fieldset">
                        <legend>Süre dolunca / kuyruk kullanılamazsa</legend>
                        ${this.destPair('timeout_type', 'timeout_dest', row.timeout_type || 'hangup', row.timeout_dest || '')}
                    </fieldset>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            const form = document.getElementById('f');
            const memberBox = document.getElementById('queueMembers');
            const memberManual = document.getElementById('queueMemberManual');
            const deptInput = document.querySelector('#f [name="dept"]');
            const availableMembers = () => {
                const dept = deptInput ? deptInput.value : (row.dept || window.ASTERA.dept || '');
                return exts.filter((extension) => !dept || extension.dept === dept);
            };
            const renderMembers = () => {
                const list = availableMembers();
                const byNumber = new Map(list.map((extension) => [String(extension.exten), extension]));
                members = members.filter((member) => byNumber.has(member) || /^[0-9]{7,20}#$/.test(member));
                memberBox.innerHTML = members.map((member) =>
                    `<input type="checkbox" name="members" value="${esc(member)}" checked>`
                ).join('');
                memberManual.value = members.map((member) =>
                    `${member},${Math.max(1, Math.min(99, Number(memberPenalties[member] || 1)))}`
                ).join('\n');
                document.querySelector('#f [name="member_penalties_json"]').value = JSON.stringify(
                    members.map((member) => ({
                        member,
                        penalty: Math.max(1, Math.min(99, Number(memberPenalties[member] || 1))),
                    }))
                );
            };
            const normalizeMember = (rawValue) => {
                const value = String(rawValue || '').trim();
                if (/^[0-9]{7,20}#$/.test(value)) return value;
                return value.replace(/\D/g, '');
            };
            const applyManualMembers = () => {
                const list = availableMembers();
                const internalNumbers = new Set(list.map((extension) => String(extension.exten)));
                const nextMembers = [];
                const nextPenalties = {};
                const entries = memberManual.value.split(/\s+/).map((entry) => entry.trim()).filter(Boolean);
                for (const entry of entries) {
                    const [rawMember, rawPenalty = '1'] = entry.split(',', 2);
                    const member = normalizeMember(rawMember);
                    if (!member || (!internalNumbers.has(member) && !/^[0-9]{7,20}#$/.test(member))) {
                        this.toast('Geçersiz üye: ' + rawMember, true);
                        memberManual.focus();
                        return false;
                    }
                    if (!nextMembers.includes(member)) nextMembers.push(member);
                    nextPenalties[member] = Math.max(1, Math.min(99, Number.parseInt(rawPenalty, 10) || 1));
                }
                members = nextMembers;
                Object.keys(memberPenalties).forEach((member) => delete memberPenalties[member]);
                Object.assign(memberPenalties, nextPenalties);
                form.elements.strategy.value = 'linear';
                renderMembers();
                return true;
            };
            renderMembers();
            if (deptInput && deptInput.tagName === 'SELECT') deptInput.addEventListener('change', () => {
                members = [];
                renderMembers();
            });
            form.addEventListener('submit', (event) => {
                if (applyManualMembers()) return;
                event.preventDefault();
                event.stopImmediatePropagation();
            }, true);
            this.bindDestPairs();
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(form, 'queue_save');
        };
        document.getElementById('addQueue').onclick = () => open();
        document.querySelectorAll('[data-edit-queue]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editQueue)));
        document.querySelectorAll('[data-copy-queue]').forEach((b) => b.onclick = () => {
            const source = JSON.parse(b.dataset.copyQueue);
            const suffix = Date.now().toString().slice(-6);
            open({
                ...source,
                id: `${source.id}-kopya-${suffix}`,
                exten: '',
                name: `${source.name || source.id} Kopya`,
            }, true);
        });
        document.querySelectorAll('[data-del-queue]').forEach((b) => b.onclick = async () => {
            if (!confirm('Kuyruk silinsin mi?')) return;
            try { await this.post('queue_delete', { id: b.dataset.delQueue }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
    },

    pageRinggroups() {
        const exts = window.ASTERA.exts || [];
        const dept = window.ASTERA.dept || '';
        const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[char]);
        const open = (row = {}, isCopy = false) => {
            const editing = Boolean(row.id) && !isCopy;
            let members = [...new Set((row.members || []).map(String))];
            const memberPriorities = {};
            if (Array.isArray(row.member_priorities)) {
                row.member_priorities.forEach((item) => {
                    if (item && item.member !== undefined) {
                        memberPriorities[String(item.member)] = Number(item.priority || 1);
                    }
                });
            }
            const initialDept = row.dept || dept || (window.ASTERA.depts[0] && window.ASTERA.depts[0].id) || '';
            this.modal(`
                <h3>${editing ? 'Grup düzenle' : (isCopy ? 'Ring grup çoğalt' : 'Ring grup')}</h3>
                <form id="f" class="ringgroup-form">
                    <fieldset class="fieldset">
                        <legend>Temel ayarlar</legend>
                        ${this.deptField(initialDept)}
                        <input type="hidden" name="id" value="${esc(row.id || '')}">
                        <div class="grid-2">
                            <label>Ring grup dahili
                                <input value="${esc(row.exten || 'Kaydedildiğinde 8600’den başlayan boş numara atanır')}" readonly>
                            </label>
                            <label>Ad<input name="name" value="${esc(row.name || '')}" required></label>
                            <label>Açıklama<textarea name="description" placeholder="Grup açıklaması">${esc(row.description || '')}</textarea></label>
                            ${this.languageField(row.language || 'tr')}
                        </div>
                    </fieldset>

                    <fieldset class="fieldset">
                        <legend>Ses kaydı</legend>
                        <div class="grid-2">
                            <label>Ses kaydı
                                <select name="record">
                                    <option value="1" ${row.record !== false ? 'selected' : ''}>Açık</option>
                                    <option value="" ${row.record === false ? 'selected' : ''}>Kapalı</option>
                                </select>
                            </label>
                            <label>Kayıt formatı
                                <select name="record_format">
                                    <option value="wav" ${(row.record_format || 'wav') === 'wav' ? 'selected' : ''}>WAV</option>
                                    <option value="gsm" ${row.record_format === 'gsm' ? 'selected' : ''}>GSM</option>
                                    <option value="ulaw" ${row.record_format === 'ulaw' ? 'selected' : ''}>ULAW</option>
                                </select>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="fieldset">
                        <legend>Çalma ve davranış</legend>
                        <div class="grid-2">
                            <label>Strateji
                                <select name="strategy">
                                    <option value="ringall" ${row.strategy !== 'hunt' ? 'selected' : ''}>Tümü çalsın (Ringall)</option>
                                    <option value="hunt" ${row.strategy === 'hunt' ? 'selected' : ''}>Sırayla (Hunt)</option>
                                </select>
                            </label>
                            <label>Çalma süresi (sn)<input name="ring_time" type="number" min="5" value="${row.ring_time || 20}"></label>
                        </div>
                        <div class="checks">
                            <label><input type="checkbox" name="confirm_calls" ${row.confirm_calls ? 'checked' : ''}> Cevaplayanın 1 ile onaylamasını iste</label>
                            <label><input type="checkbox" name="skip_busy" ${row.skip_busy ? 'checked' : ''}> Meşgul üyeleri atla</label>
                        </div>
                    </fieldset>

                    <fieldset class="fieldset">
                        <legend>Anons ve arayan bilgisi</legend>
                        <div class="grid-2">
                            ${this.soundField('announcement', row.announcement || '', 'Başlangıç anonsu')}
                            <label>Müzik sınıfı<input name="music_class" value="${esc(row.music_class || row.dept || window.ASTERA.dept || '')}" placeholder="Firma kodu"></label>
                            <label>CID Ad Öneki<input name="cid_prefix" value="${esc(row.cid_prefix || '')}" placeholder="Grup:"></label>
                        </div>
                    </fieldset>

                    <fieldset class="fieldset">
                        <legend>Üyeler ve arama sırası</legend>
                        <input type="hidden" name="member_priorities_json">
                        <label>Manuel üye listesi
                            <textarea id="rgMemberManual" rows="5"
                                placeholder="121,1&#10;122,1&#10;123,2&#10;905307711717#,2"></textarea>
                            <small>Her satıra <strong>dahili,sıra</strong> yazın. Dış numaranın sonuna <strong>#</strong> koyun.</small>
                        </label>
                        <div id="rgMembers" hidden></div>
                        <p class="tiny muted">Önce sıra 1, sonra 2 ve 3 aranır. Aynı sıradaki üyelerde satır sırası korunur.</p>
                    </fieldset>

                    <fieldset class="fieldset">
                        <legend>Cevaplanmazsa</legend>
                        ${this.destPair('timeout_type', 'timeout_dest', row.timeout_type || 'hangup', row.timeout_dest || '')}
                    </fieldset>
                    
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            this.bindDestPairs();
            const form = document.getElementById('f');
            const deptSelect = form.elements.dept;
            const memberBox = document.getElementById('rgMembers');
            const memberManual = document.getElementById('rgMemberManual');
            const availableMembers = () => {
                const deptId = deptSelect ? deptSelect.value : initialDept;
                return exts.filter((extension) => !deptId || extension.dept === deptId);
            };
            const renderMembers = () => {
                const list = availableMembers();
                const byNumber = new Map(list.map((extension) => [String(extension.exten), extension]));
                members = members.filter((member) => byNumber.has(member) || /^[0-9]{7,20}#$/.test(member));
                memberBox.innerHTML = members.map((member) =>
                    `<input type="checkbox" name="members" value="${esc(member)}" checked>`
                ).join('');
                memberManual.value = members.map((member) =>
                    `${member},${Math.max(1, Math.min(99, Number(memberPriorities[member] || 1)))}`
                ).join('\n');
                form.elements.member_priorities_json.value = JSON.stringify(
                    members.map((member) => ({
                        member,
                        priority: Math.max(1, Math.min(99, Number(memberPriorities[member] || 1))),
                    }))
                );
            };
            const normalizeMember = (rawValue) => {
                const value = String(rawValue || '').trim();
                if (/^[0-9]{7,20}#$/.test(value)) return value;
                return value.replace(/\D/g, '');
            };
            const applyManualMembers = () => {
                const internalNumbers = new Set(availableMembers().map((extension) => String(extension.exten)));
                const nextMembers = [];
                const nextPriorities = {};
                const entries = memberManual.value.split(/\s+/).map((entry) => entry.trim()).filter(Boolean);
                for (const entry of entries) {
                    const [rawMember, rawPriority = '1'] = entry.split(',', 2);
                    const member = normalizeMember(rawMember);
                    if (!member || (!internalNumbers.has(member) && !/^[0-9]{7,20}#$/.test(member))) {
                        this.toast('Geçersiz üye: ' + entry, true);
                        memberManual.focus();
                        return false;
                    }
                    if (!nextMembers.includes(member)) nextMembers.push(member);
                    nextPriorities[member] = Math.max(1, Math.min(99, Number.parseInt(rawPriority, 10) || 1));
                }
                members = nextMembers;
                Object.keys(memberPriorities).forEach((member) => delete memberPriorities[member]);
                Object.assign(memberPriorities, nextPriorities);
                renderMembers();
                return true;
            };
            renderMembers();
            if (deptSelect && deptSelect.tagName === 'SELECT') {
                deptSelect.onchange = () => {
                    members = [];
                    renderMembers();
                };
            }
            form.addEventListener('submit', (event) => {
                if (applyManualMembers()) return;
                event.preventDefault();
                event.stopImmediatePropagation();
            }, true);
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(form, 'ringgroup_save');
        };
        document.getElementById('addRg').onclick = () => open();
        document.querySelectorAll('[data-edit-rg]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editRg)));
        document.querySelectorAll('[data-copy-rg]').forEach((b) => b.onclick = () => {
            const source = JSON.parse(b.dataset.copyRg);
            open({
                ...source,
                id: '',
                exten: '',
                name: `${source.name || source.id} Kopya`,
            }, true);
        });
        document.querySelectorAll('[data-del-rg]').forEach((b) => b.onclick = async () => {
            if (!confirm('Grup silinsin mi?')) return;
            try { await this.post('ringgroup_delete', { id: b.dataset.delRg }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
    },

    ivrDigitRows(digits = {}) {
        return Object.entries(digits || {}).map(([digit, info], index) => {
            return `<div class="ivr-route card">
                <label class="ivr-route-key-cell"><span>Tuşlama</span>
                    <input class="ivr-route-key" value="${digit}" maxlength="4" pattern="[0-9*#]{1,4}" placeholder="1" required>
                </label>
                ${this.destPair('ivr_route_' + index + '_type', 'ivr_route_' + index + '_dest', info.type || 'hangup', info.dest || '', true)}
                <button class="btn sm danger ivr-route-remove" type="button">Sil</button>
            </div>`;
        }).join('');
    },

    pageIvr() {
        const open = (row = {}) => {
            const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
            })[char]);
            let blockedExtensions = [...new Set((row.blocked_extensions || []).map(String))];
            this.modal(`
                <h3>${row.id ? 'IVR düzenle' : 'IVR ekle'}</h3>
                <form id="f" class="ivr-form">
                    <fieldset class="fieldset">
                        <legend>Temel bilgiler</legend>
                        ${this.deptField(row.dept)}
                        <input type="hidden" name="id" value="${row.id || ''}">
                        <div class="grid-2">
                            <label>IVR dahili
                                <input value="${row.exten || 'Kaydedildiğinde 8500’den başlayan boş numara atanır'}" readonly>
                            </label>
                            <label>IVR adı<input name="name" value="${row.name || ''}" required placeholder="Ana menü"></label>
                            ${this.languageField(row.language || 'tr')}
                        </div>
                    </fieldset>

                    <fieldset class="fieldset">
                        <legend>Karşılama anonsu</legend>
                        ${this.soundField('sound', row.sound || '', 'Yüklü ses dosyası', true)}
                        <p class="tiny muted">Arayan IVR'a girdiğinde seçilen anons dinletilir ve tuşlama beklenir.</p>
                    </fieldset>

                    <fieldset class="fieldset">
                        <legend>Menü tuşlamaları</legend>
                        <p class="tiny muted">Her tuş için hedef belirleyin. Tuşlamalar üstten alta açık biçimde gösterilir.</p>
                        <div class="ivr-route-head"><span>Tuşlama</span><span>Tür</span><span>Hedef</span><span></span></div>
                        <div id="ivrRoutes">${this.ivrDigitRows(row.digits || {})}</div>
                        <button class="btn primary sm" type="button" id="addIvrRoute">+ Tuşlama ekle</button>
                        <small class="muted">Her tuşlama 1–4 hane olabilir. Örnek: 1, 12, 1234, * veya #.</small>
                    </fieldset>

                    <fieldset class="fieldset">
                        <legend>Geçersiz tuşlama ve zaman aşımı</legend>
                        <div class="ivr-fallback-grid">
                            <div>
                                <strong>Geçersiz tuşlama (i)</strong>
                                <small class="muted">Tanımsız bir tuşa basıldığında uygulanır.</small>
                                ${this.destPair('invalid_type', 'invalid_dest', row.invalid_type || 'repeat', row.invalid_dest || '')}
                            </div>
                            <div>
                                <strong>Zaman aşımı (t)</strong>
                                <small class="muted">Hiç tuşlama yapılmadığında uygulanır.</small>
                                ${this.destPair('timeout_type', 'timeout_dest', row.timeout_type || 'hangup', row.timeout_dest || '')}
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="fieldset">
                        <legend>Doğrudan dahili tuşlama</legend>
                        <label><input type="checkbox" name="direct_dial" ${row.direct_dial !== false ? 'checked' : ''}> Arayanlar dahili numarayı doğrudan tuşlayabilsin</label>
                        <label>Yasaklı dahili numaraları
                            <textarea id="ivrBlockedExtensionsManual" rows="6"
                                placeholder="120&#10;121&#10;122">${escapeHtml(blockedExtensions.join('\n'))}</textarea>
                            <small>Her satıra bir dahili yazın. Boşluk veya virgülle de ayırabilirsiniz.</small>
                        </label>
                        <p class="muted">Yasaklı dahililerden biri tuşlandığında aşağıdaki hedefe aktarılır.</p>
                        ${this.destPair('blocked_type', 'blocked_dest', row.blocked_type || 'hangup', row.blocked_dest || '')}
                    </fieldset>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            this.bindDestPairs();
            document.getElementById('c').onclick = () => this.closeModal();
            const form = document.getElementById('f');
            const invalidTypeSelect = form.elements.invalid_type;
            invalidTypeSelect.insertAdjacentHTML('afterbegin', '<option value="repeat">IVR menüsünü tekrarla</option>');
            invalidTypeSelect.value = row.invalid_type || 'repeat';
            const blockedManual = document.getElementById('ivrBlockedExtensionsManual');
            const selectedDept = () => form.querySelector('[name="dept"]')?.value || row.dept || window.ASTERA.dept;
            const tenantDestinationItems = (type) => {
                const dept = String(selectedDept() || '');
                return ((window.ASTERA.dests || {})[type] || []).filter((item) =>
                    type === 'hangup' || String(item.id) === dept || String(item.id).startsWith(dept + '-')
                );
            };
            const renderDestinationPair = (pair, wanted = '') => {
                const typeSelect = pair.querySelector('.dest-type');
                const destinationSelect = pair.querySelector('.dest-id');
                const manual = pair.querySelector('.dest-manual');
                const isExternal = typeSelect.value === 'external';
                const isRepeat = typeSelect.value === 'repeat';
                destinationSelect.disabled = isExternal || isRepeat;
                destinationSelect.hidden = isExternal || isRepeat;
                manual.disabled = !isExternal;
                manual.hidden = !isExternal;
                if (isExternal || isRepeat) return;
                const items = tenantDestinationItems(typeSelect.value);
                destinationSelect.innerHTML = items.map((item) => {
                    const selected = String(item.id) === String(wanted) || String(item.id).endsWith('-' + wanted);
                    return `<option value="${escapeHtml(item.id)}" ${selected ? 'selected' : ''}>${escapeHtml(item.label)}</option>`;
                }).join('');
            };
            form.querySelectorAll('.dest-pair').forEach((pair) => {
                const typeSelect = pair.querySelector('.dest-type');
                const current = typeSelect.value === 'external'
                    ? (pair.querySelector('.dest-manual')?.value || '')
                    : (pair.querySelector('.dest-id')?.value || '');
                typeSelect.onchange = () => renderDestinationPair(pair);
                renderDestinationPair(pair, current);
            });
            const routes = document.getElementById('ivrRoutes');
            let routeSequence = routes.querySelectorAll('.ivr-route').length;
            const bindRoute = (route) => {
                route.querySelector('.ivr-route-remove').onclick = () => route.remove();
                const pair = route.querySelector('.dest-pair');
                const typeSelect = pair.querySelector('.dest-type');
                const current = typeSelect.value === 'external'
                    ? (pair.querySelector('.dest-manual')?.value || '')
                    : (pair.querySelector('.dest-id')?.value || '');
                typeSelect.onchange = () => renderDestinationPair(pair);
                renderDestinationPair(pair, current);
            };
            routes.querySelectorAll('.ivr-route').forEach(bindRoute);
            document.getElementById('addIvrRoute').onclick = () => {
                const index = routeSequence++;
                routes.insertAdjacentHTML('beforeend', this.ivrDigitRows({ '': { type: 'extension', dest: '' } })
                    .replaceAll('ivr_route_0_', 'ivr_route_' + index + '_'));
                bindRoute(routes.lastElementChild);
                routes.lastElementChild.querySelector('.ivr-route-key').focus();
            };
            const parseBlockedExtensions = () => {
                const dept = String(selectedDept() || '');
                const allowed = new Set((window.ASTERA.exts || [])
                    .filter((extension) => String(extension.dept) === dept)
                    .map((extension) => String(extension.exten || '')));
                const parsed = [...new Set(String(blockedManual.value || '')
                    .split(/[\s,;]+/)
                    .map((number) => number.replace(/\D/g, ''))
                    .filter(Boolean))];
                const invalid = parsed.find((number) => !allowed.has(number));
                if (invalid) {
                    this.toast(`Yasaklı dahili bu firmada kayıtlı değil: ${invalid}`, true);
                    blockedManual.focus();
                    return null;
                }
                blockedExtensions = parsed;
                blockedManual.value = blockedExtensions.join('\n');
                return blockedExtensions;
            };
            form.querySelector('[name="dept"]')?.addEventListener('change', () => {
                blockedExtensions = [];
                blockedManual.value = '';
                form.querySelectorAll('.dest-pair').forEach((pair) => renderDestinationPair(pair));
            });
            form.onsubmit = async (e) => {
                e.preventDefault();
                const blocked = parseBlockedExtensions();
                if (blocked === null) return;
                const data = Object.fromEntries(new FormData(form).entries());
                data.direct_dial = form.elements.direct_dial.checked ? '1' : '0';
                data.blocked_extensions_json = JSON.stringify(blocked);
                const digits = {};
                for (const route of routes.querySelectorAll('.ivr-route')) {
                    const digit = route.querySelector('.ivr-route-key').value.trim();
                    if (!/^[0-9*#]{1,4}$/.test(digit)) {
                        this.toast('Tuşlama 1–4 haneli olmalı ve yalnızca 0-9, * veya # içermelidir.', true);
                        return;
                    }
                    if (Object.prototype.hasOwnProperty.call(digits, digit)) {
                        this.toast('Aynı tuşlama birden fazla eklenemez: ' + digit, true);
                        return;
                    }
                    const pair = route.querySelector('.dest-pair');
                    const type = pair.querySelector('.dest-type').value;
                    const dest = type === 'external'
                        ? pair.querySelector('.dest-manual').value
                        : pair.querySelector('.dest-id').value;
                    digits[digit] = { type, dest };
                }
                try {
                    await this.post('ivr_save', { ...data, digits_json: JSON.stringify(digits) });
                    location.reload();
                } catch (err) { this.toast(err.message, true); }
            };
        };
        document.getElementById('addIvr').onclick = () => open();
        document.querySelectorAll('[data-edit-ivr]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editIvr)));
        document.querySelectorAll('[data-del-ivr]').forEach((b) => b.onclick = async () => {
            if (!confirm('IVR silinsin mi?')) return;
            try { await this.post('ivr_delete', { id: b.dataset.delIvr }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
    },

    pageTime() {
        const open = (row = {}) => {
            const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
            })[char]);
            const dayNames = { mon: 'Pzt', tue: 'Sal', wed: 'Çar', thu: 'Per', fri: 'Cum', sat: 'Cmt', sun: 'Paz' };
            const legacyTime = String(row.time || '09:00-18:00').split('-');
            const rules = Array.isArray(row.rules) && row.rules.length ? row.rules : [{
                name: 'Normal mesai', start: legacyTime[0] || '09:00', end: legacyTime[1] || '18:00',
                days: String(row.days || 'mon-fri') === 'mon-fri' ? ['mon', 'tue', 'wed', 'thu', 'fri'] : ['sat', 'sun']
            }];
            const exceptions = Array.isArray(row.exceptions) ? row.exceptions : [];
            const ruleHtml = (rule = {}) => `<div class="schedule-rule">
                <div class="grid-2">
                    <label>Ad<input data-rule-name value="${esc(rule.name || 'Çalışma aralığı')}"></label>
                    <button class="btn sm danger schedule-remove" type="button">Aralığı kaldır</button>
                </div>
                <div class="grid-2">
                    <label>Başlangıç<input data-rule-start type="time" required value="${esc(rule.start || '09:00')}"></label>
                    <label>Bitiş<input data-rule-end type="time" required value="${esc(rule.end || '18:00')}"></label>
                </div>
                <div class="checks schedule-days">${Object.entries(dayNames).map(([key, label]) =>
                    `<label><input type="checkbox" data-rule-day value="${key}" ${(rule.days || []).includes(key) ? 'checked' : ''}> ${label}</label>`
                ).join('')}</div>
                <p class="muted tiny">Bitiş başlangıçtan küçükse geceye taşan vardiya kabul edilir.</p>
            </div>`;
            const exceptionHtml = (item = {}) => `<div class="schedule-rule">
                <div class="grid-2">
                    <label>Ad<input data-exception-name value="${esc(item.name || 'Özel tarih')}"></label>
                    <button class="btn sm danger schedule-remove" type="button">İstisnayı kaldır</button>
                </div>
                <div class="grid-2">
                    <label>Başlangıç tarihi<input data-exception-from type="date" required value="${esc(item.from || '')}"></label>
                    <label>Bitiş tarihi<input data-exception-to type="date" required value="${esc(item.to || item.from || '')}"></label>
                </div>
                <div class="grid-2">
                    <label>Durum<select data-exception-state>
                        <option value="closed" ${item.state !== 'open' ? 'selected' : ''}>Kapalı hedefe gönder</option>
                        <option value="open" ${item.state === 'open' ? 'selected' : ''}>Açık hedefe gönder</option>
                    </select></label>
                    <label>Saat aralığı<input data-exception-time value="${esc(item.time || '00:00-23:59')}" placeholder="00:00-23:59"></label>
                </div>
            </div>`;
            this.modal(`
                <h3>Gelişmiş zaman koşulu</h3>
                <form id="f">
                    ${this.deptField(row.dept)}
                    <label>Kod
                        <input name="id" readonly value="${row.id || ''}" placeholder="Firma kodu + mesai sıra numarası otomatik verilecek">
                    </label>
                    ${row.id ? '' : '<p class="muted tiny">Örnek: 120-mesai-001. Firma seçimine göre kaydederken otomatik oluşturulur.</p>'}
                    <label>Ad<input name="name" value="${row.name || ''}"></label>
                    <label>Saat dilimi<select name="timezone">
                        ${['Europe/Istanbul', 'UTC', 'Europe/London', 'Europe/Berlin'].map((zone) =>
                            `<option value="${zone}" ${(row.timezone || 'Europe/Istanbul') === zone ? 'selected' : ''}>${zone}</option>`
                        ).join('')}
                    </select></label>

                    <fieldset class="fieldset">
                        <legend>Çalışma ve vardiya aralıkları</legend>
                        <div id="timeRules">${rules.map(ruleHtml).join('')}</div>
                        <button class="btn sm" id="addTimeRule" type="button">Çalışma aralığı ekle</button>
                    </fieldset>

                    <fieldset class="fieldset">
                        <legend>Türkiye resmî tatilleri</legend>
                        <label><input type="checkbox" name="turkey_holidays" ${row.turkey_holidays ? 'checked' : ''}> Ulusal ve dinî resmî tatillerde kapalı hedefi kullan</label>
                        <label><input type="checkbox" name="turkey_half_days" ${row.turkey_half_days ? 'checked' : ''}> Arefe ve 28 Ekim günlerinde 13:00 sonrası kapalı kabul et</label>
                        <p class="muted">1 Ocak, 23 Nisan, 1 Mayıs, 19 Mayıs, 15 Temmuz, 30 Ağustos, 29 Ekim ile Ramazan ve Kurban bayramları uygulanır.</p>
                    </fieldset>

                    <fieldset class="fieldset">
                        <legend>Özel tarihler ve istisnalar</legend>
                        <p class="muted">Üstten alta değerlendirilir ve haftalık programdan önceliklidir. Tatilde çalışma gibi açık istisnalar da eklenebilir.</p>
                        <div id="timeExceptions">${exceptions.map(exceptionHtml).join('')}</div>
                        <button class="btn sm" id="addTimeException" type="button">Tarih / tarih aralığı ekle</button>
                    </fieldset>

                    <h4>Koşul açıkken</h4>
                    ${this.destPair('true_type', 'true_dest', row.true_type || 'extension', row.true_dest || '')}
                    <h4>Koşul kapalıyken</h4>
                    ${this.destPair('false_type', 'false_dest', row.false_type || 'voicemail', row.false_dest || '')}
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            this.bindDestPairs();
            document.getElementById('c').onclick = () => this.closeModal();
            const form = document.getElementById('f');
            const filterTimeDestinations = () => {
                const dept = String(form.elements.dept?.value || row.dept || window.ASTERA.dept || '');
                form.querySelectorAll('.dest-pair').forEach((pair) => {
                    const typeSelect = pair.querySelector('.dest-type');
                    const destinationSelect = pair.querySelector('.dest-id');
                    const manual = pair.querySelector('.dest-manual');
                    const current = typeSelect.value === 'external' ? manual.value : destinationSelect.value;
                    const render = () => {
                        const isExternal = typeSelect.value === 'external';
                        destinationSelect.disabled = isExternal;
                        destinationSelect.hidden = isExternal;
                        manual.disabled = !isExternal;
                        manual.hidden = !isExternal;
                        if (isExternal) return;
                        const items = ((window.ASTERA.dests || {})[typeSelect.value] || []).filter((item) =>
                            typeSelect.value === 'hangup' || String(item.id) === dept || String(item.id).startsWith(dept + '-')
                        );
                        destinationSelect.innerHTML = items.map((item) =>
                            `<option value="${esc(item.id)}">${esc(item.label)}</option>`
                        ).join('');
                        if (items.some((item) => String(item.id) === String(current))) destinationSelect.value = current;
                    };
                    typeSelect.onchange = render;
                    render();
                });
            };
            filterTimeDestinations();
            form.elements.dept?.addEventListener('change', filterTimeDestinations);
            const ruleBox = document.getElementById('timeRules');
            const exceptionBox = document.getElementById('timeExceptions');
            document.getElementById('addTimeRule').onclick = () => ruleBox.insertAdjacentHTML('beforeend', ruleHtml({
                name: 'Yeni aralık', start: '09:00', end: '18:00', days: ['mon', 'tue', 'wed', 'thu', 'fri']
            }));
            document.getElementById('addTimeException').onclick = () => exceptionBox.insertAdjacentHTML('beforeend', exceptionHtml());
            form.addEventListener('click', (event) => {
                if (event.target.classList.contains('schedule-remove')) {
                    event.target.closest('.schedule-rule')?.remove();
                }
            });
            form.onsubmit = async (event) => {
                event.preventDefault();
                const data = Object.fromEntries(new FormData(form).entries());
                data.turkey_holidays = form.elements.turkey_holidays.checked ? '1' : '0';
                data.turkey_half_days = form.elements.turkey_half_days.checked ? '1' : '0';
                data.rules_json = JSON.stringify([...ruleBox.querySelectorAll('.schedule-rule')].map((item) => ({
                    name: item.querySelector('[data-rule-name]').value,
                    start: item.querySelector('[data-rule-start]').value,
                    end: item.querySelector('[data-rule-end]').value,
                    days: [...item.querySelectorAll('[data-rule-day]:checked')].map((input) => input.value)
                })));
                data.exceptions_json = JSON.stringify([...exceptionBox.querySelectorAll('.schedule-rule')].map((item) => ({
                    name: item.querySelector('[data-exception-name]').value,
                    from: item.querySelector('[data-exception-from]').value,
                    to: item.querySelector('[data-exception-to]').value,
                    state: item.querySelector('[data-exception-state]').value,
                    time: item.querySelector('[data-exception-time]').value
                })));
                try {
                    await this.post('time_save', data);
                    location.reload();
                } catch (error) { this.toast(error.message, true); }
            };
        };
        document.getElementById('addTime').onclick = () => open();
        document.querySelectorAll('[data-edit-time]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editTime)));
        document.querySelectorAll('[data-del-time]').forEach((b) => b.onclick = async () => {
            if (!confirm('Silinsin mi?')) return;
            try { await this.post('time_delete', { id: b.dataset.delTime }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
    },

    pageAnn() {
        const open = (row = {}) => {
            this.modal(`
                <h3>Duyuru</h3>
                <form id="f">
                    ${this.deptField(row.dept)}
                    <label>Kod<input name="id" required value="${row.id || ''}" ${row.id ? 'readonly' : ''}></label>
                    <label>Ad<input name="name" value="${row.name || ''}"></label>
                    ${this.languageField(row.language || 'tr')}
                    ${this.soundField('sound', row.sound || 'hello-world')}
                    ${this.destPair('dest_type', 'dest', row.dest_type || 'hangup', row.dest || '')}
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            this.bindDestPairs();
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(document.getElementById('f'), 'announcement_save');
        };
        document.getElementById('addAnn').onclick = () => open();
        document.querySelectorAll('[data-edit-ann]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editAnn)));
        document.querySelectorAll('[data-del-ann]').forEach((b) => b.onclick = async () => {
            if (!confirm('Silinsin mi?')) return;
            try { await this.post('announcement_delete', { id: b.dataset.delAnn }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
    },

    pageConf() {
        const open = (row = {}) => {
            this.modal(`
                <h3>Konferans</h3>
                <form id="f">
                    ${this.deptField(row.dept)}
                    <label>Kod<input name="id" required value="${row.id || ''}" ${row.id ? 'readonly' : ''} placeholder="toplanti"></label>
                    <label>Ad<input name="name" value="${row.name || ''}"></label>
                    <label>Dahili<input name="exten" required value="${row.exten || ''}" placeholder="8900"></label>
                    <label>PIN<input name="pin" value="${row.pin || ''}"></label>
                    <fieldset class="fieldset"><legend>Ses kaydı</legend>
                        <label>Konferans kaydı
                            <select name="record">
                                <option value="1" ${row.record ? 'selected' : ''}>Açık</option>
                                <option value="" ${!row.record ? 'selected' : ''}>Kapalı</option>
                            </select>
                        </label>
                        <label>Kayıt formatı
                            <select name="record_format">
                                <option value="wav" ${(row.record_format || 'wav') === 'wav' ? 'selected' : ''}>WAV</option>
                                <option value="gsm" ${row.record_format === 'gsm' ? 'selected' : ''}>GSM</option>
                                <option value="ulaw" ${row.record_format === 'ulaw' ? 'selected' : ''}>ULAW</option>
                            </select>
                        </label>
                        <p class="muted">Kayıt ilk katılımcıyla başlar ve son katılımcı çıktığında tamamlanır.</p>
                    </fieldset>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(document.getElementById('f'), 'conference_save');
        };
        document.getElementById('addConf').onclick = () => open();
        document.querySelectorAll('[data-edit-conf]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editConf)));
        document.querySelectorAll('[data-del-conf]').forEach((b) => b.onclick = async () => {
            if (!confirm('Silinsin mi?')) return;
            try { await this.post('conference_delete', { id: b.dataset.delConf }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
    },

    pageSsl() {
        const form = document.getElementById('sslIssueForm');
        const dnsForm = document.getElementById('sslDnsIssueForm');
        const domainPreview = document.getElementById('sslDnsDomainPreview');
        const tokenToggle = document.getElementById('sslTokenToggle');
        const output = document.getElementById('sslOutput');
        const dryRun = document.getElementById('sslDryRun');
        const updateDomainPreview = () => {
            const pbxName = dnsForm.elements.pbx_name.value.trim().toLowerCase();
            domainPreview.value = `${pbxName || '<santral-adı>'}.asterapbx.net`;
        };
        dnsForm.elements.pbx_name.addEventListener('input', updateDomainPreview);
        updateDomainPreview();
        tokenToggle.addEventListener('click', () => {
            const tokenInput = dnsForm.elements.cloudflare_token;
            const reveal = tokenInput.type === 'password';
            tokenInput.type = reveal ? 'text' : 'password';
            tokenToggle.textContent = reveal ? 'Gizle' : 'Göster';
            tokenToggle.setAttribute('aria-pressed', reveal ? 'true' : 'false');
        });
        form.onsubmit = async (event) => {
            event.preventDefault();
            const data = Object.fromEntries(new FormData(form).entries());
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            output.textContent = 'Let’s Encrypt sertifikası alınıyor…';
            try {
                const result = await this.post('ssl_issue', data);
                output.textContent = result.output || 'Sertifika yapılandırıldı.';
                this.toast('SSL sertifikası yapılandırıldı');
            } catch (error) {
                output.textContent = error.message;
                this.toast('Sertifika alınamadı', true);
            } finally {
                button.disabled = false;
            }
        };
        dnsForm.onsubmit = async (event) => {
            event.preventDefault();
            if (!confirm('DNS sertifikası Nginx ve Asterisk’e kurulacak. Sertifika değiştiyse Asterisk yeniden başlatılacak. Devam edilsin mi?')) return;
            const data = Object.fromEntries(new FormData(dnsForm).entries());
            const button = dnsForm.querySelector('button[type="submit"]');
            button.disabled = true;
            output.textContent = 'Cloudflare DNS doğrulamasıyla sertifika alınıyor…';
            try {
                const result = await this.post('ssl_dns_issue', data);
                output.textContent = result.output || `${result.domain} sertifikası yapılandırıldı.`;
                dnsForm.elements.cloudflare_token.value = '';
                this.toast('Alt alan adı ve SSL santralde etkinleştirildi');
            } catch (error) {
                output.textContent = error.message;
                this.toast('DNS sertifikası alınamadı', true);
            } finally {
                button.disabled = false;
            }
        };
        dryRun.onclick = async () => {
            dryRun.disabled = true;
            output.textContent = 'Yenileme testi çalışıyor…';
            try {
                const result = await this.post('ssl_renew_test');
                output.textContent = result.output || 'Yenileme testi başarılı.';
                this.toast('Yenileme testi başarılı');
            } catch (error) {
                output.textContent = error.message;
                this.toast('Yenileme testi başarısız', true);
            } finally {
                dryRun.disabled = false;
            }
        };
    },

    pageProvisioning() {
        const extensions = window.ASTERA.provisionExtensions || [];
        const templates = window.ASTERA.provisionTemplates || [];
        const devices = window.ASTERA.provisionDevices || [];
        const deviceKeys = window.ASTERA.provisionDeviceKeys || {};
        const scheduleSettings = window.ASTERA.provisionScheduleSettings || {};
        const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[char]);
        document.querySelectorAll('[data-copy]').forEach((button) => {
            button.onclick = async () => {
                try {
                    await navigator.clipboard.writeText(button.dataset.copy || '');
                    this.toast('Provision adresi kopyalandı');
                } catch (error) {
                    this.toast('Adres kopyalanamadı', true);
                }
            };
        });
        const templateVendorFilter = document.getElementById('provisionTemplateVendorFilter');
        const templateSearch = document.getElementById('provisionTemplateSearch');
        const filterTemplates = () => {
            const vendor = templateVendorFilter?.value || '';
            const query = (templateSearch?.value || '').trim().toLocaleLowerCase('tr-TR');
            document.querySelectorAll('[data-template-row]').forEach((row) => {
                const vendorMatch = !vendor || row.dataset.templateVendor === vendor;
                const searchMatch = !query
                    || (row.dataset.templateSearch || '').toLocaleLowerCase('tr-TR').includes(query);
                row.hidden = !(vendorMatch && searchMatch);
            });
        };
        templateVendorFilter?.addEventListener('change', filterTemplates);
        templateSearch?.addEventListener('input', filterTemplates);
        const open = (row = {}) => {
            const dept = row.dept || window.ASTERA.dept || extensions[0]?.dept || '';
            this.modal(`
                <h3>${row.id ? 'Provision cihazını düzenle' : 'Provision cihazı ekle'}</h3>
                <form id="provisionDeviceForm">
                    ${this.deptField(dept)}
                    <input type="hidden" name="id" value="${escape(row.id || '')}">
                    <div class="form-grid">
                        <label>Marka
                            <select name="vendor" required>
                                <option value="yealink" ${row.vendor === 'yealink' ? 'selected' : ''}>Yealink</option>
                                <option value="grandstream" ${row.vendor === 'grandstream' ? 'selected' : ''}>Grandstream</option>
                                <option value="gigaset" ${row.vendor === 'gigaset' ? 'selected' : ''}>Gigaset</option>
                            </select>
                        </label>
                        <label>Model
                            <input name="model" maxlength="80" value="${escape(row.model || '')}" placeholder="Örn. T43U, GRP2614, N510">
                        </label>
                        <label>Telefon şablonu
                            <select name="template_id" required></select>
                        </label>
                        <label>Hesap yuvası
                            <select name="account_slot">
                                ${Array.from({ length: 16 }, (_, index) => index + 1).map((slot) =>
                                    `<option value="${slot}" ${Number(row.account_slot || 1) === slot ? 'selected' : ''}>Account ${slot}</option>`
                                ).join('')}
                            </select>
                            <small>Yealink T46S: 1–16</small>
                        </label>
                        <label>MAC adresi
                            <input name="mac" required maxlength="17" value="${escape(row.mac || '')}" placeholder="00:11:22:33:44:55">
                        </label>
                        <label>Dahili
                            <select name="extension_id" required></select>
                        </label>
                        <label>Görünen ad
                            <input name="display_name" maxlength="120" value="${escape(row.display_name || '')}" placeholder="Boşsa abone adı kullanılır">
                        </label>
                        <label>SIP taşıma
                            <select name="transport">
                                <option value="udp" ${row.transport !== 'tcp' && row.transport !== 'tls' ? 'selected' : ''}>UDP</option>
                                <option value="tcp" ${row.transport === 'tcp' ? 'selected' : ''}>TCP</option>
                                <option value="tls" ${row.transport === 'tls' ? 'selected' : ''}>TLS</option>
                            </select>
                        </label>
                    </div>
                    <label><input type="checkbox" name="enabled" ${row.enabled ?? true ? 'checked' : ''}> Cihaz provision dosyasını yayınla</label>
                    <p class="muted tiny">Telefon fabrika ayarına döndüğünde veya yeniden başladığında bu bilgiler tekrar uygulanabilir.</p>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="cancelProvisionDevice">Vazgeç</button>
                        <button class="btn primary" type="submit">Kaydet ve dosyayı üret</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            const form = document.getElementById('provisionDeviceForm');
            const deptInput = form.querySelector('[name="dept"]');
            const extensionInput = form.querySelector('[name="extension_id"]');
            const vendorInput = form.querySelector('[name="vendor"]');
            const templateInput = form.querySelector('[name="template_id"]');
            const accountSlotInput = form.querySelector('[name="account_slot"]');
            const syncAccountSlot = () => {
                const yealink = vendorInput.value === 'yealink';
                accountSlotInput.disabled = !yealink;
                if (!yealink) accountSlotInput.value = '1';
                const template = templates.find((item) => item.id === templateInput.value);
                const accountCount = Math.max(1, Number(template?.account_count) || 1);
                [...accountSlotInput.options].forEach((option) => {
                    option.disabled = !yealink || Number(option.value) > accountCount;
                });
                if (Number(accountSlotInput.value) > accountCount) accountSlotInput.value = '1';
            };
            const renderTemplates = () => {
                const selectedDept = deptInput?.value || dept;
                const rows = templates.filter((template) =>
                    template.vendor === vendorInput.value
                    && (!template.dept || template.dept === selectedDept)
                );
                templateInput.innerHTML = rows.map((template) =>
                    `<option value="${escape(template.id)}" ${template.id === row.template_id ? 'selected' : ''}>${escape(template.name)}</option>`
                ).join('');
                if (!templateInput.value && rows.length) templateInput.value = rows[0].id;
                syncAccountSlot();
            };
            const renderExtensions = () => {
                const selectedDept = deptInput?.value || dept;
                const rows = extensions.filter((extension) => extension.dept === selectedDept);
                extensionInput.innerHTML = rows.map((extension) =>
                    `<option value="${escape(extension.id)}" ${extension.id === row.extension_id ? 'selected' : ''}>${escape(extension.exten)} · ${escape(extension.name)}</option>`
                ).join('');
                if (!rows.length) {
                    extensionInput.innerHTML = '<option value="">Bu firmada dahili yok</option>';
                }
            };
            renderExtensions();
            renderTemplates();
            if (deptInput?.tagName === 'SELECT') deptInput.addEventListener('change', () => {
                renderExtensions();
                renderTemplates();
            });
            vendorInput.addEventListener('change', renderTemplates);
            templateInput.addEventListener('change', syncAccountSlot);
            document.getElementById('cancelProvisionDevice').onclick = () => this.closeModal();
            this.formSubmit(form, 'provision_device_save');
        };
        const openTemplate = (row = {}) => {
            const dept = row.dept || window.ASTERA.dept || extensions[0]?.dept || '';
            this.modal(`
                <h3>${row.id ? 'Özel şablonu düzenle' : 'Özel şablon ekle'}</h3>
                <form id="provisionTemplateForm">
                    ${this.deptField(dept)}
                    <input type="hidden" name="id" value="${escape(row.id || '')}">
                    <div class="form-grid">
                        <label>Şablon adı
                            <input name="name" required maxlength="120" value="${escape(row.name || '')}" placeholder="Örn. T46S Satış">
                        </label>
                        <label>Marka
                            <select name="vendor">
                                <option value="yealink" ${row.vendor !== 'grandstream' && row.vendor !== 'gigaset' ? 'selected' : ''}>Yealink</option>
                                <option value="grandstream" ${row.vendor === 'grandstream' ? 'selected' : ''}>Grandstream</option>
                                <option value="gigaset" ${row.vendor === 'gigaset' ? 'selected' : ''}>Gigaset</option>
                            </select>
                        </label>
                        <label>Model
                            <input name="model" maxlength="80" value="${escape(row.model || '')}" placeholder="Örn. SIP-T46S">
                        </label>
                        <label>Hesap sayısı
                            <input name="account_count" type="number" min="1" max="32" value="${Number(row.account_count) || 1}">
                        </label>
                        <label>Tuş sayısı
                            <input name="key_count" type="number" min="0" max="120" value="${Number(row.key_count) || 0}">
                        </label>
                    </div>
                    <label>İlave üretici ayarları
                        <textarea name="config_body" rows="9" maxlength="65535" placeholder="Örn. features.dnd.allow = 1">${escape(row.config_body || '')}</textarea>
                    </label>
                    <p class="muted tiny">Değişkenler: {{pbx_host}}, {{account_slot}}, {{extension}}, {{sip_user}}, {{auth_user}}, {{display_name}}, {{mac}}</p>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="cancelProvisionTemplate">Vazgeç</button>
                        <button class="btn primary" type="submit">Şablonu kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            document.getElementById('cancelProvisionTemplate').onclick = () => this.closeModal();
            this.formSubmit(document.getElementById('provisionTemplateForm'), 'provision_template_save');
        };
        const openSchedule = () => {
            const firstDept = window.ASTERA.dept || extensions[0]?.dept || Object.keys(scheduleSettings)[0] || '';
            const initial = scheduleSettings[firstDept] || {};
            this.modal(`
                <h3>Otomatik config kontrolü</h3>
                <form id="provisionScheduleForm">
                    ${this.deptField(firstDept)}
                    <label><input type="checkbox" name="schedule_enabled" ${initial.schedule_enabled ?? true ? 'checked' : ''}> Telefonlar config dosyasını otomatik kontrol etsin</label>
                    <div class="form-grid">
                        <label>Sıklık
                            <select name="schedule_frequency">
                                <option value="daily">Her gün</option>
                                <option value="weekly">Haftada bir</option>
                            </select>
                        </label>
                        <label>Gün
                            <select name="schedule_weekday">
                                <option value="0">Pazar</option><option value="1">Pazartesi</option>
                                <option value="2">Salı</option><option value="3">Çarşamba</option>
                                <option value="4">Perşembe</option><option value="5">Cuma</option>
                                <option value="6">Cumartesi</option>
                            </select>
                        </label>
                        <label>Başlangıç saati
                            <input type="time" name="schedule_time" required value="${escape(initial.schedule_time || '03:00')}">
                        </label>
                        <label>Kontrol penceresi
                            <select name="window_minutes">
                                <option value="30">30 dakika</option>
                                <option value="60">1 saat</option>
                                <option value="120">2 saat</option>
                                <option value="180">3 saat</option>
                            </select>
                        </label>
                    </div>
                    <p class="muted tiny">Ayar firma bazındadır. Yealink belirtilen pencere içinde, Grandstream belirtilen saatte kontrol eder. Gigaset N510 günlük kontrol penceresini kullanır.</p>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="cancelProvisionSchedule">Vazgeç</button>
                        <button class="btn primary" type="submit">Kaydet ve configleri üret</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            const form = document.getElementById('provisionScheduleForm');
            const deptInput = form.querySelector('[name="dept"]');
            const enabledInput = form.querySelector('[name="schedule_enabled"]');
            const frequencyInput = form.querySelector('[name="schedule_frequency"]');
            const weekdayInput = form.querySelector('[name="schedule_weekday"]');
            const timeInput = form.querySelector('[name="schedule_time"]');
            const windowInput = form.querySelector('[name="window_minutes"]');
            const render = () => {
                const row = scheduleSettings[deptInput?.value || firstDept] || {};
                enabledInput.checked = row.schedule_enabled ?? true;
                frequencyInput.value = row.schedule_frequency || 'daily';
                weekdayInput.value = String(row.schedule_weekday ?? 0);
                timeInput.value = row.schedule_time || '03:00';
                windowInput.value = String(row.window_minutes || 60);
                weekdayInput.disabled = frequencyInput.value !== 'weekly';
            };
            render();
            deptInput?.addEventListener('change', render);
            frequencyInput.addEventListener('change', () => {
                weekdayInput.disabled = frequencyInput.value !== 'weekly';
            });
            document.getElementById('cancelProvisionSchedule').onclick = () => this.closeModal();
            this.formSubmit(form, 'provision_schedule_save');
        };
        const openKeys = (deviceId) => {
            const device = devices.find((item) => item.id === deviceId);
            if (!device) return;
            const template = templates.find((item) => item.id === device.template_id);
            const keyCount = Math.max(0, Number(template?.key_count) || 0);
            const accountCount = Math.max(1, Number(template?.account_count) || 1);
            const saved = new Map((deviceKeys[deviceId] || []).map((key) => [Number(key.key_index), key]));
            const typeOptions = [
                ['none', 'Boş'], ['line', 'Hat'], ['blf', 'BLF'],
                ['speed_dial', 'Hızlı arama'], ['transfer', 'Transfer'],
                ['park', 'Park'], ['dtmf', 'DTMF'], ['url', 'URL']
            ];
            const rows = Array.from({ length: keyCount }, (_, offset) => {
                const index = offset + 1;
                const key = saved.get(index) || {};
                const accountOptions = Array.from({ length: accountCount }, (_, accountOffset) => accountOffset + 1)
                    .map((slot) => `<option value="${slot}" ${Number(key.account_slot || device.account_slot || 1) === slot ? 'selected' : ''}>${slot}</option>`)
                    .join('');
                return `<tr data-key-row="${index}">
                    <td><strong>${index}</strong></td>
                    <td><select data-key-type>${typeOptions.map(([value, label]) =>
                        `<option value="${value}" ${key.key_type === value ? 'selected' : ''}>${label}</option>`
                    ).join('')}</select></td>
                    <td><input data-key-label maxlength="80" value="${escape(key.label || '')}" placeholder="Ekran etiketi"></td>
                    <td><input data-key-value maxlength="160" value="${escape(key.key_value || '')}" placeholder="Dahili / kod / URL"></td>
                    <td><select data-key-account>${accountOptions}</select></td>
                </tr>`;
            }).join('');
            this.modal(`
                <h3>${escape(device.model || 'Telefon')} · Programlanabilir tuşlar</h3>
                <p class="muted">${keyCount} tuş · Yalnızca bu cihaza uygulanır.</p>
                <form id="provisionKeysForm">
                    <div class="provisioning-keys-scroll">
                        <table class="provisioning-keys-table">
                            <thead><tr><th>Tuş</th><th>İşlev</th><th>Etiket</th><th>Hedef</th><th>Hesap</th></tr></thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="cancelProvisionKeys">Vazgeç</button>
                        <button class="btn primary" type="submit">Kaydet ve config üret</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            const form = document.getElementById('provisionKeysForm');
            document.getElementById('cancelProvisionKeys').onclick = () => this.closeModal();
            form.onsubmit = async (event) => {
                event.preventDefault();
                const keys = [...form.querySelectorAll('[data-key-row]')].map((row) => ({
                    key_index: Number(row.dataset.keyRow),
                    key_type: row.querySelector('[data-key-type]').value,
                    label: row.querySelector('[data-key-label]').value,
                    key_value: row.querySelector('[data-key-value]').value,
                    extension: row.querySelector('[data-key-value]').value,
                    account_slot: Number(row.querySelector('[data-key-account]').value)
                }));
                try {
                    await this.post('provision_keys_save', { id: deviceId, keys: JSON.stringify(keys) });
                    this.toast('Tuşlar kaydedildi ve config üretildi');
                    location.reload();
                } catch (error) {
                    this.toast(error.message, true);
                }
            };
        };
        document.getElementById('addProvisionDevice')?.addEventListener('click', () => {
            if (!extensions.length) return this.toast('Önce dahili abone ekleyin', true);
            open();
        });
        document.getElementById('addProvisionTemplate')?.addEventListener('click', () => openTemplate());
        document.getElementById('provisionSchedule')?.addEventListener('click', openSchedule);
        document.querySelectorAll('[data-copy-provision-template]').forEach((button) => {
            button.onclick = () => {
                const row = JSON.parse(button.dataset.copyProvisionTemplate);
                openTemplate({ ...row, id: '', dept: '', name: `${row.name} - Özel`, builtin: false });
            };
        });
        document.querySelectorAll('[data-edit-provision-template]').forEach((button) => {
            button.onclick = () => openTemplate(JSON.parse(button.dataset.editProvisionTemplate));
        });
        document.querySelectorAll('[data-delete-provision-template]').forEach((button) => {
            button.onclick = async () => {
                if (!confirm('Özel şablon silinsin mi? Bu şablonu kullanan cihazlar genel profile döner.')) return;
                try {
                    await this.post('provision_template_delete', { id: button.dataset.deleteProvisionTemplate });
                    location.reload();
                } catch (error) {
                    this.toast(error.message, true);
                }
            };
        });
        document.querySelectorAll('[data-provision-keys]').forEach((button) => {
            button.onclick = () => openKeys(button.dataset.provisionKeys);
        });
        document.querySelectorAll('[data-edit-provision]').forEach((button) => {
            button.onclick = () => open(JSON.parse(button.dataset.editProvision));
        });
        document.querySelectorAll('[data-delete-provision]').forEach((button) => {
            button.onclick = async () => {
                if (!confirm('Cihaz ve yayınlanan provision dosyası silinsin mi?')) return;
                try {
                    await this.post('provision_device_delete', { id: button.dataset.deleteProvision });
                    location.reload();
                } catch (error) {
                    this.toast(error.message, true);
                }
            };
        });
        document.getElementById('publishProvisioning')?.addEventListener('click', async (event) => {
            const button = event.currentTarget;
            button.disabled = true;
            try {
                const result = await this.post('provision_publish');
                this.toast(`${Number(result.file_count) || 0} provision dosyası üretildi`);
            } catch (error) {
                this.toast(error.message, true);
            } finally {
                button.disabled = false;
            }
        });
    },

    pageNetwork() {
        document.getElementById('networkRefresh')?.addEventListener('click', () => location.reload());
        const routeFilter = document.getElementById('networkRouteInterfaceFilter');
        if (routeFilter) {
            const applyRouteFilter = () => {
                const selected = routeFilter.value;
                let liveVisible = 0;
                let staticVisible = 0;
                document.querySelectorAll('[data-route-interface]').forEach((row) => {
                    const visible = selected === '' || row.dataset.routeInterface === selected;
                    row.hidden = !visible;
                    if (visible && row.dataset.routeKind === 'live') liveVisible += 1;
                    if (visible && row.dataset.routeKind === 'static') staticVisible += 1;
                });
                const count = document.getElementById('networkRouteVisibleCount');
                if (count) count.textContent = `${liveVisible} aktif · ${staticVisible} tanımlı`;
                const liveEmpty = document.getElementById('networkRouteFilterEmpty');
                if (liveEmpty) liveEmpty.hidden = selected === '' || liveVisible !== 0;
                const liveEmptyText = document.getElementById('networkRouteFilterEmptyText');
                if (liveEmptyText) {
                    liveEmptyText.textContent = staticVisible > 0
                        ? `Bu kartta aktif rota yok. Aşağıdaki ${staticVisible} kalıcı rota, kart aktif olmadığı için bekliyor.`
                        : 'Seçilen Ethernet kartına ait aktif veya tanımlı rota bulunamadı.';
                }
                const staticEmpty = document.getElementById('networkStaticRouteFilterEmpty');
                if (staticEmpty) staticEmpty.hidden = selected === '' || staticVisible !== 0;
            };
            routeFilter.addEventListener('change', applyRouteFilter);
            applyRouteFilter();
        }
        document.querySelectorAll('.network-interface-form').forEach((form) => {
            const method = form.querySelector('[name="method"]');
            const cidr = form.querySelector('[name="ipv4_cidr"]');
            const gateway = form.querySelector('[name="gateway"]');
            const methodHelp = form.querySelector('[data-network-method-help]');
            const safety = form.querySelector('.network-safety');
            const output = form.querySelector('.network-output');
            const saveButton = form.querySelector('[data-network-save]');
            const applyButton = form.querySelector('[data-network-apply]');
            const confirmButton = form.querySelector('[data-network-confirm]');
            const refreshMethod = () => {
                const manual = method.value === 'manual';
                cidr.disabled = !manual;
                gateway.disabled = !manual;
                cidr.required = manual;
                methodHelp.textContent = manual
                    ? 'Statik kullanım: IPv4 adresi ve prefix zorunludur. Ağ geçidi internet ve uzak ağ erişimini belirler.'
                    : 'DHCP kullanım: IPv4 adresi ve ağ geçidi modem veya DHCP sunucusundan otomatik alınır.';
            };
            const setPending = (pending) => {
                safety.classList.toggle('pending', pending);
                safety.querySelector('strong').textContent = pending
                    ? 'Onay bekleniyor'
                    : 'Erişim koruması etkin';
                safety.querySelector('span').textContent = pending
                    ? 'Yeni bağlantı çalışıyorsa 3 dakika dolmadan onaylayın.'
                    : 'Onaylanmayan değişiklik 3 dakika sonra otomatik geri alınır.';
            };
            const data = () => Object.fromEntries(new FormData(form).entries());
            const showOutput = (message) => {
                output.hidden = false;
                output.textContent = message;
            };
            method.addEventListener('change', refreshMethod);
            refreshMethod();
            saveButton.onclick = async () => {
                if (!form.reportValidity()) return;
                saveButton.disabled = true;
                try {
                    await this.post('network_setting_save', data());
                    this.toast('Ethernet ayarı kaydedildi');
                } catch (error) {
                    showOutput(error.message);
                    this.toast(error.message, true);
                } finally {
                    saveButton.disabled = false;
                }
            };
            applyButton.onclick = async () => {
                if (!form.reportValidity()) return;
                const iface = form.querySelector('[name="interface_name"]').value;
                if (!confirm(`${iface} ağ ayarı uygulanacak. IP değişirse bu bağlantı kesilebilir. Devam edilsin mi?`)) return;
                applyButton.disabled = true;
                showOutput('Ağ değişikliği güvenli biçimde zamanlanıyor…');
                try {
                    const result = await this.post('network_setting_apply', data());
                    showOutput(result.output || 'Ayar uygulanmak üzere zamanlandı.');
                    confirmButton.disabled = false;
                    setPending(true);
                    this.toast('Ayar uygulanıyor; 3 dakika içinde çalıştığını onaylayın');
                } catch (error) {
                    showOutput(error.message);
                    this.toast(error.message, true);
                } finally {
                    applyButton.disabled = false;
                }
            };
            confirmButton.onclick = async () => {
                confirmButton.disabled = true;
                try {
                    const result = await this.post('network_setting_confirm', {
                        interface_name: form.querySelector('[name="interface_name"]').value
                    });
                    showOutput(result.output || 'Yeni ağ ayarı kalıcılaştırıldı.');
                    setPending(false);
                    this.toast('Ethernet ayarı onaylandı');
                } catch (error) {
                    confirmButton.disabled = false;
                    showOutput(error.message);
                    this.toast(error.message, true);
                }
            };
        });
        const routeForm = document.getElementById('networkRouteForm');
        if (routeForm) {
            const routeOutput = document.querySelector('.network-route-output');
            const submitButton = routeForm.querySelector('button[type="submit"]');
            const cancelEdit = document.getElementById('networkRouteEditCancel');
            const routeInterface = routeForm.elements.interface_name;
            const routeDept = routeForm.elements.dept;
            const syncRouteDepartment = () => {
                const dept = routeInterface.selectedOptions[0]?.dataset.dept || '';
                if (dept && [...routeDept.options].some((option) => option.value === dept)) {
                    routeDept.value = dept;
                }
            };
            routeInterface.addEventListener('change', syncRouteDepartment);
            document.querySelectorAll('.network-interface-form').forEach((interfaceForm) => {
                const interfaceName = interfaceForm.elements.interface_name.value;
                const deptSelect = interfaceForm.elements.dept;
                deptSelect.addEventListener('change', () => {
                    const option = [...routeInterface.options].find((item) => item.value === interfaceName);
                    if (option) option.dataset.dept = deptSelect.value;
                    if (routeInterface.value === interfaceName) syncRouteDepartment();
                });
            });
            syncRouteDepartment();
            const resetRouteForm = () => {
                routeForm.reset();
                routeForm.elements.route_id.value = '';
                syncRouteDepartment();
                submitButton.textContent = 'Rotayı Kaydet ve Uygula';
                cancelEdit.hidden = true;
            };
            cancelEdit.onclick = resetRouteForm;
            document.getElementById('networkRouteAdd')?.addEventListener('click', () => {
                resetRouteForm();
                routeForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
                routeForm.elements.destination.focus({ preventScroll: true });
            });
            routeForm.onsubmit = async (event) => {
                event.preventDefault();
                if (!routeForm.reportValidity()) return;
                submitButton.disabled = true;
                routeOutput.hidden = false;
                routeOutput.textContent = 'Statik rota kaydediliyor…';
                try {
                    const result = await this.post(
                        'network_route_save',
                        Object.fromEntries(new FormData(routeForm).entries())
                    );
                    routeOutput.textContent = [result.message, result.output].filter(Boolean).join('\n');
                    this.toast(result.message || 'Statik rota kaydedildi');
                    setTimeout(() => location.reload(), 700);
                } catch (error) {
                    routeOutput.textContent = error.message;
                    this.toast(error.message, true);
                } finally {
                    submitButton.disabled = false;
                }
            };
            document.querySelectorAll('[data-network-route-edit]').forEach((button) => {
                button.onclick = () => {
                    const route = JSON.parse(button.dataset.networkRouteEdit);
                    Object.entries(route).forEach(([name, value]) => {
                        if (routeForm.elements[name]) routeForm.elements[name].value = value ?? '';
                    });
                    submitButton.textContent = 'Rotayı Güncelle ve Uygula';
                    cancelEdit.hidden = false;
                    routeForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
                };
            });
        }
        document.querySelectorAll('[data-network-route-delete]').forEach((button) => {
            button.onclick = async () => {
                if (!confirm('Bu kalıcı rota silinsin ve sistemden kaldırılsın mı?')) return;
                button.disabled = true;
                try {
                    await this.post('network_route_delete', { route_id: button.dataset.networkRouteDelete });
                    this.toast('Statik rota silindi');
                    setTimeout(() => location.reload(), 500);
                } catch (error) {
                    button.disabled = false;
                    this.toast(error.message, true);
                }
            };
        });
    },

    pageSecurity() {
        const form = document.getElementById('securityFirewallForm');
        const output = document.getElementById('securityOutput');
        const confirmButton = document.getElementById('securityConfirm');
        form.onsubmit = async (event) => {
            event.preventDefault();
            if (!confirm('Güvenlik duvarı kuralları değiştirilecek. Devam edilsin mi?')) return;
            const data = Object.fromEntries(new FormData(form).entries());
            data.public_web = form.querySelector('[name="public_web"]').checked ? '1' : '';
            const applyButton = form.querySelector('button[type="submit"]');
            applyButton.disabled = true;
            output.closest('details').open = true;
            output.textContent = 'UFW kuralları uygulanıyor…';
            try {
                const result = await this.post('security_firewall_apply', data);
                output.textContent = result.output || 'Kurallar uygulandı.';
                confirmButton.disabled = false;
                this.toast('Kurallar uygulandı; 5 dakika içinde onaylayın');
            } catch (error) {
                output.textContent = error.message;
                this.toast('Güvenlik duvarı uygulanamadı', true);
            } finally {
                applyButton.disabled = false;
            }
        };
        confirmButton.onclick = async () => {
            confirmButton.disabled = true;
            output.closest('details').open = true;
            try {
                const result = await this.post('security_firewall_confirm');
                output.textContent = result.output || 'Kurallar kalıcılaştırıldı.';
                this.toast('Güvenlik duvarı kuralları onaylandı');
            } catch (error) {
                confirmButton.disabled = false;
                output.textContent = error.message;
                this.toast('Kurallar onaylanamadı', true);
            }
        };
    },

    pageCrm(options = {}) {
        const baseUrl = options.baseUrl || 'index.php?p=crm';
        const withParam = (name, value) =>
            `${baseUrl}${baseUrl.includes('?') ? '&' : '?'}${name}=${encodeURIComponent(value)}`;
        const searchInput = document.querySelector('[data-crm-live-search]');
        if (searchInput) {
            let searchTimer;
            searchInput.addEventListener('input', () => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => {
                    const button = searchInput.form?.querySelector('button[type="submit"]');
                    if (button) {
                        button.disabled = true;
                        button.textContent = 'Aranıyor…';
                    }
                    searchInput.form?.submit();
                }, 500);
            });
            if (options.searchQuery) {
                searchInput.focus();
                searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
            }
        }
        const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[char]);
        const open = (row = {}, phone = '') => {
            this.modal(`
                <h3>${row.id ? 'Müşteri kartını düzenle' : 'Müşteri kartı ekle'}</h3>
                <form id="crmCustomerForm">
                    <input type="hidden" name="id" value="${escapeHtml(row.id || '')}">
                    ${this.deptField(row.dept)}
                    <div class="form-grid crm-form-grid">
                        <label>Firma / müşteri adı
                            <input name="company" maxlength="160" required value="${escapeHtml(row.company || '')}">
                        </label>
                        <label>Yetkili kişi
                            <input name="contact" maxlength="160" value="${escapeHtml(row.contact || '')}">
                        </label>
                        <label>Telefon
                            <input name="phone" maxlength="24" required value="${escapeHtml(row.phone || phone || '')}">
                        </label>
                        <label>İkinci telefon
                            <input name="phone_alt" maxlength="24" value="${escapeHtml(row.phone_alt || '')}">
                        </label>
                        <label>E-posta
                            <input type="email" name="email" maxlength="180" value="${escapeHtml(row.email || '')}">
                        </label>
                        <label>Adres
                            <textarea name="address" maxlength="500" rows="3">${escapeHtml(row.address || '')}</textarea>
                        </label>
                        <label class="crm-form-wide">Kart notu
                            <textarea name="notes" maxlength="3000" rows="4">${escapeHtml(row.notes || '')}</textarea>
                        </label>
                    </div>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="cancelCrmCustomer">Vazgeç</button>
                        <button class="btn primary" type="submit">Kaydet</button>
                    </div>
                </form>
            `);
            const form = document.getElementById('crmCustomerForm');
            document.getElementById('cancelCrmCustomer').onclick = () => this.closeModal();
            form.onsubmit = async (event) => {
                event.preventDefault();
                const data = Object.fromEntries(new FormData(form).entries());
                try {
                    const result = await this.post('crm_customer_save', data);
                    location.href = withParam('id', result.id);
                } catch (error) {
                    this.toast(error.message, true);
                }
            };
        };

        document.getElementById('addCrmCustomer')?.addEventListener('click', () =>
            open({}, options.prefillPhone || '')
        );
        document.querySelectorAll('[data-new-phone]').forEach((button) => {
            button.onclick = () => open({}, button.dataset.newPhone || '');
        });
        document.querySelectorAll('[data-edit-crm]').forEach((button) => {
            button.onclick = () => open(JSON.parse(button.dataset.editCrm));
        });
        document.querySelectorAll('[data-delete-crm]').forEach((button) => {
            button.onclick = async () => {
                if (!confirm('Müşteri kartı silinsin mi? Görüşme kayıtları santralden silinmez.')) return;
                try {
                    await this.post('crm_customer_delete', { id: button.dataset.deleteCrm });
                    location.href = baseUrl;
                } catch (error) {
                    this.toast(error.message, true);
                }
            };
        });
        document.querySelectorAll('[data-crm-dial]').forEach((button) => {
            button.onclick = (event) => {
                const number = String(button.dataset.crmDial || '').trim();
                if (!number) {
                    event.preventDefault();
                    return this.toast('Aranacak telefon numarası yok', true);
                }
                try {
                    if (
                        window.opener
                        && !window.opener.closed
                        && typeof window.opener.AsteraRtc?.dialFromCrm === 'function'
                    ) {
                        event.preventDefault();
                        window.opener.focus();
                        window.opener.AsteraRtc.dialFromCrm(number);
                        return;
                    }
                } catch (error) {
                    // Bağlantı yoksa link, adlandırılmış WebPhone sekmesini açar.
                }
            };
        });
        document.querySelectorAll('.crm-call-note').forEach((form) => {
            form.onsubmit = async (event) => {
                event.preventDefault();
                const button = form.querySelector('button[type="submit"]');
                button.disabled = true;
                try {
                    await this.post('crm_call_note_save', Object.fromEntries(new FormData(form).entries()));
                    this.toast('Görüşme notu kaydedildi');
                } catch (error) {
                    this.toast(error.message, true);
                } finally {
                    button.disabled = false;
                }
            };
        });
        const callToggle = document.getElementById('toggleCrmCalls');
        if (callToggle) {
            callToggle.onclick = () => {
                const extraCalls = [...document.querySelectorAll('.crm-call-extra')];
                const opening = extraCalls.some((call) => call.hidden);
                extraCalls.forEach((call) => { call.hidden = !opening; });
                callToggle.textContent = opening
                    ? 'Yalnız son 2 görüşmeyi göster'
                    : `Tüm görüşmeleri göster (${callToggle.dataset.hiddenCount})`;
            };
        }
        const targetCall = document.querySelector('.crm-call-target');
        if (targetCall) {
            requestAnimationFrame(() => {
                targetCall.scrollIntoView({ behavior: 'smooth', block: 'center' });
                const noteField = targetCall.querySelector('textarea[name="note"]');
                if (noteField) {
                    noteField.focus();
                    noteField.setSelectionRange(noteField.value.length, noteField.value.length);
                }
            });
        }
    },

    pageUrlTriggers() {
        const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[char]);
        const open = (row = {}) => {
            this.modal(`
                <h3>${row.id ? 'URL tetikleme düzenle' : 'URL tetikleme ekle'}</h3>
                <form id="urlTriggerForm">
                    ${this.deptField(row.dept)}
                    <label>Ad<input name="name" value="${escapeHtml(row.name || 'CRM')}"></label>
                    <label>URL şablonu
                        <input name="url_template" required
                            value="${escapeHtml(row.url_template || '')}"
                            placeholder="https://crm.example.com/ara?telefon={caller}">
                    </label>
                    <label>Tetikleme
                        <select name="trigger">
                            <option value="ring" ${row.trigger === 'ring' ? 'selected' : ''}>Çalarken</option>
                            <option value="answer" ${row.trigger === 'answer' ? 'selected' : ''}>Cevaplanınca</option>
                            <option value="both" ${!row.trigger || row.trigger === 'both' ? 'selected' : ''}>Çalarken + cevaplanınca</option>
                        </select>
                    </label>
                    <label>Çalışma biçimi
                        <select name="mode">
                            <option value="both" ${!row.mode || row.mode === 'both' ? 'selected' : ''}>Sunucu + WebPhone</option>
                            <option value="server" ${row.mode === 'server' ? 'selected' : ''}>Yalnız sunucu webhook’u</option>
                            <option value="browser" ${row.mode === 'browser' ? 'selected' : ''}>Yalnız WebPhone’da aç</option>
                        </select>
                    </label>
                    <label>Numara biçimi
                        <select name="number_format">
                            <option value="digits" ${!row.number_format || row.number_format === 'digits' ? 'selected' : ''}>Yalnız rakam</option>
                            <option value="e164_tr" ${row.number_format === 'e164_tr' ? 'selected' : ''}>+90 E.164</option>
                            <option value="raw" ${row.number_format === 'raw' ? 'selected' : ''}>Geldiği gibi</option>
                        </select>
                    </label>
                    <label>CRM için minimum hane
                        <input name="min_digits" type="number" min="1" max="32" value="${row.min_digits ?? 7}">
                        <small class="muted">Daha kısa numaralarda CRM açılmaz.</small>
                    </label>
                    <label><input type="checkbox" name="enabled" ${row.enabled ? 'checked' : ''}> Aktif</label>
                    <fieldset class="fieldset">
                        <legend>Test</legend>
                        <label>Örnek telefon<input name="test_number" value="905551112233"></label>
                        <button class="btn" type="button" id="testUrlTrigger">URL’yi test et</button>
                    </fieldset>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary" type="submit">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            const form = document.getElementById('urlTriggerForm');
            document.getElementById('c').onclick = () => this.closeModal();
            document.getElementById('testUrlTrigger').onclick = async () => {
                const data = Object.fromEntries(new FormData(form).entries());
                try {
                    const response = await this.post('url_trigger_test', data);
                    this.toast(`URL başarılı (${response.code})`);
                } catch (error) {
                    this.toast(error.message, true);
                }
            };
            form.onsubmit = async (event) => {
                event.preventDefault();
                const data = Object.fromEntries(new FormData(form).entries());
                data.enabled = form.elements.enabled.checked ? '1' : '';
                try {
                    await this.post('url_trigger_save', data);
                    location.reload();
                } catch (error) {
                    this.toast(error.message, true);
                }
            };
        };
        document.getElementById('addUrlTrigger').onclick = () => open();
        document.querySelectorAll('[data-edit-url-trigger]').forEach((button) => {
            button.onclick = () => open(JSON.parse(button.dataset.editUrlTrigger));
        });
        document.querySelectorAll('[data-del-url-trigger]').forEach((button) => {
            button.onclick = async () => {
                if (!confirm('URL tetikleme tanımı silinsin mi?')) return;
                try {
                    await this.post('url_trigger_delete', { id: button.dataset.delUrlTrigger });
                    location.reload();
                } catch (error) {
                    this.toast(error.message, true);
                }
            };
        });
    },

    pageBlacklist() {
        const open = () => {
            this.modal(`
                <h3>Kara liste</h3>
                <form id="f">
                    ${this.deptField()}
                    <label>Numara<input name="number" required></label>
                    <label>Not<input name="name"></label>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(document.getElementById('f'), 'blacklist_save');
        };
        document.getElementById('addBl').onclick = () => open();
        document.querySelectorAll('[data-del-bl]').forEach((b) => b.onclick = async () => {
            if (!confirm('Silinsin mi?')) return;
            try { await this.post('blacklist_delete', { id: b.dataset.delBl }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
    },

    pageBulk() {
        const form = document.getElementById('bulkForm');
        if (!form) return;
        const technology = form.querySelector('[name="technology"]');
        const syncTechnologyFields = () => {
            const selected = technology?.value || 'pjsip';
            form.querySelectorAll('[data-bulk-pjsip]').forEach((field) => {
                field.hidden = selected !== 'pjsip';
                const input = field.querySelector('[name="password"]');
                if (input) input.required = selected === 'pjsip';
            });
            form.querySelectorAll('[data-bulk-dahdi]').forEach((field) => {
                field.hidden = selected !== 'dahdi';
                const input = field.querySelector('[name="dahdi_group"]');
                if (input) input.required = selected === 'dahdi';
            });
            form.querySelectorAll('[data-bulk-custom]').forEach((field) => {
                field.hidden = selected !== 'custom';
                const input = field.querySelector('[name="custom_dial_template"]');
                if (input) input.required = selected === 'custom';
            });
        };
        technology?.addEventListener('change', syncTechnologyFields);
        syncTechnologyFields();
        form.onsubmit = async (e) => {
            e.preventDefault();
            const data = Object.fromEntries(new FormData(form).entries());
            data.password = form.querySelector('[name="password"]')?.value || '';
            data.vm_enabled = form.querySelector('[name="vm_enabled"]')?.checked ? '1' : '';
            data.outbound_enabled = form.querySelector('[name="outbound_enabled"]')?.checked ? '1' : '';
            if (!window.ASTERA.dept) return this.toast('Üstten bir firma seçin', true);
            data.dept = window.ASTERA.dept;
            try {
                this.toast('Dahililer yazılıyor…');
                await this.post('bulk_extensions', data);
                location.href = 'index.php?p=extensions';
            } catch (err) { this.toast(err.message, true); }
        };
    },

    async pageLogs() {
        const form = document.getElementById('logFilterForm');
        const fileSelect = document.getElementById('logFile');
        const queryInput = document.getElementById('logQuery');
        const linesSelect = document.getElementById('logLines');
        const logBox = document.getElementById('logBox');
        const fileMeta = document.getElementById('logFileMeta');
        const resultTitle = document.getElementById('logResultTitle');
        const resultMeta = document.getElementById('logResultMeta');
        const exportButton = document.getElementById('exportLog');
        let currentOutput = '';
        let currentFile = '';
        const formatSize = (bytes) => {
            const value = Number(bytes) || 0;
            if (value < 1024) return `${value} B`;
            if (value < 1024 ** 2) return `${(value / 1024).toFixed(1)} KB`;
            if (value < 1024 ** 3) return `${(value / 1024 ** 2).toFixed(1)} MB`;
            return `${(value / 1024 ** 3).toFixed(1)} GB`;
        };
        const fillFiles = (files, selected) => {
            const previous = selected || fileSelect.value;
            fileSelect.innerHTML = '';
            files.forEach((file) => {
                const option = document.createElement('option');
                option.value = file.name;
                option.textContent = `${file.name} · ${formatSize(file.size)}`;
                option.selected = file.name === previous;
                fileSelect.appendChild(option);
            });
        };
        const load = async () => {
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            logBox.textContent = 'Log okunuyor…';
            try {
                const r = await this.post('logs', {
                    file: fileSelect.value,
                    query: queryInput.value.trim(),
                    lines: linesSelect.value
                });
                fillFiles(r.files || [], r.file);
                currentFile = r.file || '';
                currentOutput = r.output || '';
                const selected = (r.files || []).find((file) => file.name === currentFile);
                fileMeta.textContent = selected
                    ? `${currentFile} · ${formatSize(selected.size)}`
                    : 'Log dosyası yok';
                resultTitle.textContent = r.query
                    ? `"${r.query}" arama sonucu`
                    : `${currentFile || 'Log'} · son ${r.lines || linesSelect.value} satır`;
                const visibleLines = currentOutput === '' ? 0 : currentOutput.split(/\r?\n/).length;
                resultMeta.textContent = `${visibleLines} satır gösteriliyor`;
                logBox.textContent = currentOutput || (r.query ? 'Eşleşen kayıt bulunamadı.' : 'Dosya boş.');
                exportButton.disabled = currentOutput === '';
            } catch (e) {
                currentOutput = '';
                exportButton.disabled = true;
                logBox.textContent = e.message;
                this.toast(e.message, true);
            } finally {
                button.disabled = false;
            }
        };
        form.onsubmit = (event) => {
            event.preventDefault();
            load();
        };
        document.getElementById('reloadLog').onclick = load;
        exportButton.onclick = () => {
            if (!currentOutput) return;
            const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
            const suffix = queryInput.value.trim() ? '-arama' : '';
            const filename = `${currentFile || 'asterisk-log'}${suffix}-${timestamp}.txt`;
            const blob = new Blob([`\uFEFF${currentOutput}\n`], { type: 'text/plain;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        };
        load();
    },

    pageParking() {
        const form = document.getElementById('parkForm');
        if (!form) return;
        document.getElementById('savePark').onclick = async () => {
            const data = Object.fromEntries(new FormData(form).entries());
            if (window.ASTERA.dept) data.dept = window.ASTERA.dept;
            try {
                await this.post('parking_save', data);
                this.toast('Park kaydedildi');
                location.reload();
            } catch (e) { this.toast(e.message, true); }
        };
    },

    pageFlow() {
        const open = (row = {}) => {
            this.modal(`
                <h3>Gündüz / gece</h3>
                <form id="f">
                    ${this.deptField(row.dept)}
                    <label>Kod<input name="id" required value="${row.id || ''}" ${row.id ? 'readonly' : ''} placeholder="mesai"></label>
                    <label>Ad<input name="name" value="${row.name || ''}"></label>
                    <label>Özellik kodu<input name="feature" value="${row.feature || '*28'}"></label>
                    <p class="muted">Gündüz (kapalı / 0)</p>
                    ${this.destPair('true_type', 'true_dest', row.true_type || 'extension', row.true_dest || '')}
                    <p class="muted">Gece (açık / 1)</p>
                    ${this.destPair('false_type', 'false_dest', row.false_type || 'voicemail', row.false_dest || '')}
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            this.bindDestPairs();
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(document.getElementById('f'), 'flow_save');
        };
        document.getElementById('addFlow').onclick = () => open();
        document.querySelectorAll('[data-edit-flow]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editFlow)));
        document.querySelectorAll('[data-del-flow]').forEach((b) => b.onclick = async () => {
            if (!confirm('Silinsin mi?')) return;
            try { await this.post('flow_delete', { id: b.dataset.delFlow }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
    },

    pageDisa() {
        const open = (row = {}) => {
            this.modal(`
                <h3>DISA</h3>
                <form id="f">
                    ${this.deptField(row.dept)}
                    <label>Kod<input name="id" required value="${row.id || ''}" ${row.id ? 'readonly' : ''} placeholder="disa1"></label>
                    <label>Ad<input name="name" value="${row.name || ''}"></label>
                    <label>PIN<input name="pin" value="${row.pin || ''}"></label>
                    <label>Dahili (isteğe bağlı)<input name="exten" value="${row.exten || ''}" placeholder="8901"></label>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(document.getElementById('f'), 'disa_save');
        };
        document.getElementById('addDisa').onclick = () => open();
        document.querySelectorAll('[data-edit-disa]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editDisa)));
        document.querySelectorAll('[data-del-disa]').forEach((b) => b.onclick = async () => {
            if (!confirm('Silinsin mi?')) return;
            try { await this.post('disa_delete', { id: b.dataset.delDisa }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
    },

    pagePaging() {
        const exts = window.ASTERA.exts || [];
        const open = (row = {}) => {
            const members = row.members || [];
            const mem = exts.map((e) =>
                `<label><input type="checkbox" name="members" value="${e.exten}" ${members.includes(e.exten) ? 'checked' : ''}> ${e.exten} ${e.name || ''}</label>`
            ).join('');
            this.modal(`
                <h3>Anons grubu</h3>
                <form id="f">
                    ${this.deptField(row.dept)}
                    <label>Kod<input name="id" required value="${row.id || ''}" ${row.id ? 'readonly' : ''} placeholder="anons"></label>
                    <label>Ad<input name="name" value="${row.name || ''}"></label>
                    <label>Dahili<input name="exten" required value="${row.exten || ''}" placeholder="8800"></label>
                    <label><input type="checkbox" name="duplex" ${row.duplex ? 'checked' : ''}> Çift yönlü (duplex)</label>
                    <div class="checks">${mem}</div>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(document.getElementById('f'), 'paging_save');
        };
        document.getElementById('addPage').onclick = () => open();
        document.querySelectorAll('[data-edit-page]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editPage)));
        document.querySelectorAll('[data-del-page]').forEach((b) => b.onclick = async () => {
            if (!confirm('Silinsin mi?')) return;
            try { await this.post('paging_delete', { id: b.dataset.delPage }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
    },

    pageSpeed() {
        const open = () => {
            this.modal(`
                <h3>Hızlı arama</h3>
                <form id="f">
                    ${this.deptField()}
                    <label>Kod (ör. 01)<input name="code" required placeholder="01"></label>
                    <label>Ad<input name="name"></label>
                    <label>Numara<input name="number" required placeholder="dahili veya dış numara"></label>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(document.getElementById('f'), 'speed_save');
        };
        document.getElementById('addSpeed').onclick = () => open();
        document.querySelectorAll('[data-del-speed]').forEach((b) => b.onclick = async () => {
            if (!confirm('Silinsin mi?')) return;
            try { await this.post('speed_delete', { id: b.dataset.delSpeed }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
    },

    pageCustom() {
        const open = (row = {}) => {
            this.modal(`
                <h3>Özel hedef</h3>
                <form id="f">
                    ${this.deptField(row.dept)}
                    <label>Kod<input name="id" required value="${row.id || ''}" ${row.id ? 'readonly' : ''}></label>
                    <label>Ad<input name="name" value="${row.name || ''}"></label>
                    ${this.soundField('sound', row.sound || '')}
                    <p class="muted">Goto (boş bırakılırsa alttaki hedef kullanılır)</p>
                    <label>Context<input name="goto_context" value="${row.goto_context || ''}" placeholder="int-genel"></label>
                    <label>Exten<input name="goto_exten" value="${row.goto_exten || 's'}"></label>
                    <label>Priority<input name="goto_pri" type="number" value="${row.goto_pri || 1}"></label>
                    ${this.destPair('dest_type', 'dest', row.dest_type || 'hangup', row.dest || '')}
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            this.bindDestPairs();
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(document.getElementById('f'), 'custom_save');
        };
        document.getElementById('addCust').onclick = () => open();
        document.querySelectorAll('[data-edit-cust]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editCust)));
        document.querySelectorAll('[data-del-cust]').forEach((b) => b.onclick = async () => {
            if (!confirm('Silinsin mi?')) return;
            try { await this.post('custom_delete', { id: b.dataset.delCust }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
    },

    pageContexts() {
        const open = (row = {}) => {
            this.modal(`
                <h3>${row.context_id ? 'Özel context düzenle' : 'Özel context ekle'}</h3>
                <form id="contextForm">
                    ${this.deptField(row.dept_id)}
                    <label>Context kodu
                        <input name="context_id" required value="${row.context_id || ''}"
                            ${row.context_id ? 'readonly' : ''} placeholder="entegrasyon">
                    </label>
                    <label>Açıklama<input name="description" maxlength="255"
                        value="${row.description || ''}" placeholder="CRM yönlendirmesi"></label>
                    <label>Dialplan adımları
                        <textarea name="steps_text" rows="10" required
                            placeholder="s | 1 | NoOp | Özel context&#10;s | n | Playback | custom/karsilama&#10;s | n | Hangup |">${row.steps_text || ''}</textarea>
                    </label>
                    <p class="muted">
                        Her satır: <code>extension | priority | application | veri</code>.
                        Örnek: <code>s | 1 | Dial | PJSIP/123,30</code>
                    </p>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(document.getElementById('contextForm'), 'custom_context_save');
        };

        document.getElementById('addContext').onclick = () => {
            if (!(window.ASTERA.dept || (window.ASTERA.depts || []).length)) {
                return this.toast('Önce firma ekleyin', true);
            }
            open();
        };
        document.querySelectorAll('[data-edit-context]').forEach((button) => {
            button.onclick = () => open(JSON.parse(button.dataset.editContext));
        });
        document.querySelectorAll('[data-delete-context]').forEach((button) => {
            button.onclick = async () => {
                if (!confirm('Özel context silinsin mi?')) return;
                try {
                    await this.post('custom_context_delete', {
                        context_id: button.dataset.deleteContext,
                        dept: button.dataset.contextDept
                    });
                    location.reload();
                } catch (error) {
                    this.toast(error.message, true);
                }
            };
        });
        document.querySelectorAll('[data-view-context]').forEach((button) => {
            button.onclick = async () => {
                try {
                    const result = await this.post('context_detail', { context: button.dataset.viewContext });
                    this.modal(`
                        <h3><code>${button.dataset.viewContext}</code></h3>
                        <pre class="log" id="contextDetail"></pre>
                        <div class="modal-actions">
                            <button class="btn" type="button" id="c">Kapat</button>
                        </div>
                    `);
                    document.getElementById('contextDetail').textContent = result.output || 'Boş context';
                    document.getElementById('c').onclick = () => this.closeModal();
                } catch (error) {
                    this.toast(error.message, true);
                }
            };
        });
    },

    pageSounds() {
        const bind = (id) => {
            const form = document.getElementById(id);
            if (!form) return;
            form.onsubmit = async (e) => {
                e.preventDefault();
                const selectedDept = form.querySelector('[name="dept"]')?.value || window.ASTERA.dept;
                if (!selectedDept) return this.toast('Bir firma seçin', true);
                try {
                    this.toast('Yükleniyor…');
                    const r = await this.postFile('sound_upload', form);
                    this.toast(r.output || 'Tamam');
                    location.reload();
                } catch (err) { this.toast(err.message, true); }
            };
        };
        bind('soundForm');
        bind('mohForm');
        const load = async () => {
            try {
                const r = await this.post('sound_list');
                const rows = [];
                const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
                    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
                })[char]);
                const owner = (id) => {
                    if (!id) return 'Ortak / eski dosya';
                    const dept = (window.ASTERA.depts || []).find((d) => String(d.id) === String(id));
                    return dept ? `${dept.name} (${dept.id})` : id;
                };
                const render = (file, kind, title) => {
                    const canPlay = Boolean(file.dept);
                    const src = canPlay
                        ? `sound_play.php?dept=${encodeURIComponent(file.dept)}&kind=${kind}&file=${encodeURIComponent(file.file)}`
                        : '';
                    const usage = Array.isArray(file.usage) ? file.usage : [];
                    const usageHtml = usage.map((item) => `
                        <li><strong>${escapeHtml(item.section)}</strong> · ${escapeHtml(item.name)}
                            <span>${escapeHtml(item.detail)}</span></li>
                    `).join('');
                    const status = usage.length
                        ? `<div class="sound-usage">
                            <span class="pill bad">Kullanımda · silinemez</span>
                            <ul>${usageHtml}</ul>
                        </div>`
                        : `<div class="sound-usage unused">
                            <span class="pill ok">Kullanılmıyor</span>
                            <span class="muted">Bu dosya güvenle silinebilir.</span>
                        </div>`;
                    const deleteButton = canPlay && !usage.length
                        ? `<button class="btn sm danger" type="button"
                            data-delete-sound="${encodeURIComponent(file.path)}"
                            data-sound-kind="${kind}"
                            data-sound-name="${escapeHtml(file.file)}">Sil</button>`
                        : '';
                    rows.push(`<div class="sound-row">
                        <div class="sound-file-info">
                            <div><strong>${title}</strong> <span class="muted">[${escapeHtml(owner(file.dept))}]</span></div>
                            <code>${escapeHtml(file.playback || file.file)}</code>
                        </div>
                        <div class="sound-file-tools">
                            ${canPlay ? `<audio controls preload="none" src="${src}"></audio>` : '<span class="muted">Firma bilgisi yok</span>'}
                            ${status}
                            ${deleteButton}
                        </div>
                    </div>`);
                };
                (r.files || []).forEach((file) => render(file, 'sound', 'IVR'));
                (r.moh || []).forEach((file) => render(file, 'moh', 'MOH'));
                document.getElementById('soundBox').innerHTML = rows.join('') || '<span class="muted">Dosya yok</span>';
                document.querySelectorAll('[data-delete-sound]').forEach((button) => {
                    button.onclick = async () => {
                        if (!confirm(`${button.dataset.soundName} kalıcı olarak silinsin mi?`)) return;
                        button.disabled = true;
                        try {
                            await this.post('sound_delete', {
                                path: decodeURIComponent(button.dataset.deleteSound),
                                kind: button.dataset.soundKind
                            });
                            this.toast('Ses dosyası silindi');
                            load();
                        } catch (error) {
                            button.disabled = false;
                            this.toast(error.message, true);
                        }
                    };
                });
            } catch (e) { this.toast(e.message, true); }
        };
        document.getElementById('reloadSounds').onclick = load;
        load();
    }
};

document.addEventListener('DOMContentLoaded', () => Astera.bindGlobal());
