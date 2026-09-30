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
        this.toast('Santrale yazılıyor…');
        try {
            await this.post('apply');
            location.reload();
        } catch (e) {
            this.toast(e.message, true);
        }
    },

    bindGlobal() {
        if (window.ASTERA?.crmPopup) return;
        this.post('sound_languages').then((r) => {
            window.ASTERA.languages = r.languages || ['en'];
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
        setInterval(() => this.refreshStatus(), 5000);
    },

    async refreshStatus() {
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
        const regs = s.registered || [];
        document.getElementById('regList').innerHTML = regs.length
            ? regs.map((x) => `<div class="row"><code>${x}</code><span class="pill ok">online</span></div>`).join('')
            : '<div class="muted">Kayıtlı telefon yok.</div>';
        const ch = s.channels || [];
        const escapeStatus = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[char]);
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
        return ['ulaw', 'alaw', 'gsm', 'g726', 'g722', 'opus'].map((c) =>
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
            const codecs = [...form.querySelectorAll('[name="codecs"]:checked')].map((i) => i.value);
            const members = [...form.querySelectorAll('[name="members"]:checked')].map((i) => i.value);
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
                await this.post(action, { ...data, ...extra, codecs, members });
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

    languageField(value = 'en', label = 'Anons dili') {
        const id = `languages-${Math.random().toString(36).slice(2)}`;
        const languages = window.ASTERA.languages || ['en', 'tr'];
        const options = languages.map((lang) => `<option value="${lang}">`).join('');
        return `<label>${label}<input name="language" list="${id}" value="${value || 'en'}" placeholder="tr"><datalist id="${id}" data-language-list>${options}</datalist></label>`;
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
                    input.innerHTML = '<option value="">Ses seçin</option>'
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

    async postFile(action, form) {
        const fd = new FormData(form);
        fd.set('action', action);
        fd.set('csrf', window.ASTERA.csrf);
        if (window.ASTERA.dept) fd.set('dept', window.ASTERA.dept);
        const r = await fetch('api.php', { method: 'POST', body: fd });
        const json = await r.json().catch(() => ({ ok: false, error: 'Yanıt okunamadı' }));
        if (!r.ok || json.ok === false) throw new Error(json.error || json.output || 'Yükleme başarısız');
        return json;
    },

    destPair(typeName, destName, type = 'extension', dest = '') {
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
        return `<div class="dest-pair grid-2">
            <label>Tür<select name="${typeName}" class="dest-type">${typeOpts}</select></label>
            <label>Hedef
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
                    <label>Firma paneli parolası<input name="panel_pass" value="" placeholder="${row.panel_pass ? 'boş = değişmesin' : ''}"></label>
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
        const open = (row = {}) => {
            const deptId = row.dept || window.ASTERA.dept || '';
            const dept = (window.ASTERA.depts || []).find((item) => item.id === deptId) || {};
            const recordEnabled = row.record ?? dept.record ?? true;
            this.modal(`
                <div class="extension-modal-head">
                    <span class="extension-avatar">${row.exten || '+'}</span>
                    <div>
                        <h3>${row.exten ? 'Abone düzenle' : 'Abone ekle'}</h3>
                        <p>${row.exten ? `${row.exten} numaralı abonenin kimlik, çağrı ve SIP ayarları` : 'Yeni abonenin kimlik, çağrı ve SIP ayarları'}</p>
                    </div>
                </div>
                <form id="f" class="extension-form">
                    <div class="extension-section-title">Kimlik ve erişim</div>
                    ${this.deptField(row.dept)}
                    <label>Dahili<input name="exten" required value="${row.exten || ''}" ${row.exten ? 'readonly' : ''}></label>
                    <label>Ad<input name="name" value="${row.name || ''}"></label>
                    <label>SIP kullanıcı<input name="sipuser" value="${row.sipuser || ''}" placeholder="boş = dahili no"></label>
                    <label>Auth kullanıcı<input name="authuser" value="${row.authuser || row.sipuser || ''}" placeholder="softphone Authentication ID"></label>
                    <label>Parola<input name="password" required value="${row.password || ''}"></label>
                    <label>Eşzamanlı kayıt<input name="max_contacts" type="number" min="1" value="${row.max_contacts || 1}"></label>
                    <label>Çalma süresi (sn)<input name="ringtime" type="number" min="8" value="${row.ringtime || 30}"></label>
                    <label>Giden CID<input name="cid_num" value="${row.cid_num || ''}" placeholder="firma CID yoksa"></label>
                    <div class="extension-section-title">Çağrı özellikleri</div>
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
                    <fieldset class="fieldset"><legend>Gelen kutusu</legend>
                        <label><input type="checkbox" name="vm_enabled" ${row.vm_enabled ? 'checked' : ''}> Voicemail açık</label>
                        <label>PIN<input name="vm_pin" value="${row.vm_pin || '1234'}"></label>
                        <label>E-posta<input name="vm_email" value="${row.vm_email || ''}"></label>
                    </fieldset>
                    <label class="extension-wide">Follow me (dahililer, virgül)<input name="followme" value="${row.followme || ''}" placeholder="121,122"></label>
                    <fieldset class="fieldset extension-wide">
                        <legend>Cevaplanmazsa / meşgulse</legend>
                        <p class="muted">Çalma süresi dolduğunda veya abone meşgul/ulaşılamaz olduğunda seçilen hedefe aktarılır.</p>
                        ${this.destPair('fallback_type', 'fallback_dest', row.fallback_type || 'hangup', row.fallback_dest || '')}
                    </fieldset>
                    <div class="extension-section-title">Bağlantı ve cihaz</div>
                    <fieldset class="fieldset"><legend>WebRTC</legend>
                        <label><input type="checkbox" name="webrtc" ${row.webrtc ? 'checked' : ''}> Tarayıcı telefonu (WSS / WS)</label>
                        <p class="muted">Açıkken bu abone tarayıcıdan kaydolur. Klasik UDP softphone aynı abonede genelde çalışmaz; ayrı dahili kullanın. Opus otomatik eklenir.</p>
                    </fieldset>
                    <fieldset class="fieldset"><legend>SIP</legend>
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
                        ${this.languageField(row.language || 'en', 'Abone dili')}
                        <label>Qualify (sn, 0=kapalı)<input name="qualify" type="number" min="0" value="${row.qualify ?? 0}"></label>
                    </fieldset>
                    <fieldset class="fieldset extension-wide"><legend>Ses kodekleri</legend>
                        <div class="checks">${this.codecChecks(row.codecs || ['alaw','ulaw'])}</div>
                    </fieldset>
                    <div class="modal-actions extension-wide">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `, { closeOnBackdrop: false });
            this.bindDestPairs();
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(
                document.getElementById('f'),
                'extension_save',
                {},
                { skipUnchanged: Boolean(row.exten) }
            );
        };
        document.getElementById('addExt').onclick = () => {
            if (!(window.ASTERA.dept || (window.ASTERA.depts || []).length)) return this.toast('Önce firma ekleyin', true);
            open();
        };
        document.querySelectorAll('[data-edit-ext]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editExt)));
        document.querySelectorAll('[data-del-ext]').forEach((b) => b.onclick = async () => {
            if (!confirm('Abone silinsin mi?')) return;
            try { await this.post('extension_delete', { id: b.dataset.delExt }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
        const search = document.getElementById('extSearch');
        const selectAll = document.getElementById('selectAllExt');
        const deleteSelected = document.getElementById('deleteSelectedExt');
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

    pageTrunks() {
        const open = (row = {}) => {
            this.modal(`
                <h3>${row.id ? 'Trunk düzenle' : 'Trunk ekle'}</h3>
                <form id="f" class="trunk-form">
                    ${this.deptField(row.dept)}
                    <label>Kimlik<input name="id" required value="${row.id || ''}" ${row.id ? 'readonly' : ''} placeholder="tt-acme"></label>
                    <label>Görünen ad<input name="name" value="${row.name || ''}"></label>
                    <label>Tür
                        <select name="type">
                            <option value="register" ${row.type !== 'peer' ? 'selected' : ''}>Kayıtlı (username/password)</option>
                            <option value="peer" ${row.type === 'peer' ? 'selected' : ''}>IP eş (peer)</option>
                        </select>
                    </label>
                    <p class="muted"><strong>Register trunk:</strong> 908503338008 master PJSIP şablonu kullanılır. Yalnız trunk kimliği, kullanıcı, parola ve sunucu bilgileri değişir.</p>
                    <label>Sunucu / IP<input name="host" required value="${row.host || ''}"></label>
                    <label>Port<input name="port" type="number" value="${row.port || 5060}"></label>
                    <label>Kullanıcı<input name="username" value="${row.username || ''}"></label>
                    <label>Parola<input name="password" value="${row.password || ''}"></label>
                    <label>From user<input name="from_user" value="${row.from_user || ''}"></label>
                    <label>From domain<input name="from_domain" value="${row.from_domain || ''}"></label>

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
                    <label>Qualify sıklığı (sn)<input name="qualify_frequency" type="number" min="0" value="${row.qualify_frequency ?? 60}"></label>
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
            `);
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(document.getElementById('f'), 'trunk_save');
        };
        document.getElementById('addTrunk').onclick = () => open();
        document.querySelectorAll('[data-edit-trunk]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editTrunk)));
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

    pageOutbound() {
        const trunks = window.ASTERA.trunks || [];
        const fillTrunks = (sel, dept, current) => {
            const list = trunks.filter((t) => !dept || t.dept === dept);
            sel.innerHTML = list.map((t) =>
                `<option value="${t.id}" ${t.id === current ? 'selected' : ''}>${t.name || t.id}</option>`
            ).join('');
            return list.length;
        };
        const open = (row = {}) => {
            const deptNow = row.dept || window.ASTERA.dept || (trunks[0] && trunks[0].dept) || '';
            this.modal(`
                <h3>${row.id ? 'Kural düzenle' : 'Giden kural'}</h3>
                <form id="f">
                    ${this.deptField(deptNow)}
                    <input type="hidden" name="id" value="${row.id || ''}">
                    <label>Ad<input name="name" value="${row.name || ''}"></label>
                    <label>Kalıp<input name="pattern" required value="${row.pattern || '_0X.'}"></label>
                    <label>Baştan silinecek hane<input name="strip" type="number" min="0" value="${row.strip ?? 1}"></label>
                    <label>Önek<input name="prefix" value="${row.prefix || '90'}" placeholder="90"></label>
                    <label>Trunk<select name="trunk" required></select></label>
                    <label>PIN (boş = yok)<input name="pin" value="${row.pin || ''}"></label>
                    <p class="tiny muted">Türkiye cep: kalıp <code>_0X.</code>, sil 1, önek <code>90</code>. 0532… tuşlanır.</p>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `);
            const trunkSel = document.querySelector('[name="trunk"]');
            const deptInp = document.querySelector('[name="dept"]');
            const refresh = () => {
                const d = deptInp ? deptInp.value : deptNow;
                if (!fillTrunks(trunkSel, d, row.trunk || '')) {
                    this.toast('Bu firmada trunk yok', true);
                }
            };
            refresh();
            if (deptInp && deptInp.tagName === 'SELECT') deptInp.onchange = refresh;
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(document.getElementById('f'), 'outbound_save');
        };
        document.getElementById('addOut').onclick = () => {
            if (!trunks.length) return this.toast('Önce bir firmaya trunk ekleyin. Trunk Astera’da.', true);
            open();
        };
        document.querySelectorAll('[data-edit-out]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editOut)));
        document.querySelectorAll('[data-del-out]').forEach((b) => b.onclick = async () => {
            if (!confirm('Kural silinsin mi?')) return;
            try { await this.post('outbound_delete', { id: b.dataset.delOut }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
    },

    pageInbound() {
        const open = (row = {}) => {
            this.modal(`
                <h3>${row.id ? 'Kural düzenle' : 'Gelen kural'}</h3>
                <form id="f">
                    ${this.deptField(row.dept)}
                    <input type="hidden" name="id" value="${row.id || ''}">
                    <label>Ad<input name="name" value="${row.name || ''}"></label>
                    <label>DID / kalıp<input name="did" required value="${row.did || '_X.'}"></label>
                    ${this.destPair('dest_type', 'dest', row.dest_type || 'extension', row.dest || '')}
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `);
            this.bindDestPairs();
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(document.getElementById('f'), 'inbound_save');
        };
        document.getElementById('addIn').onclick = () => open();
        document.querySelectorAll('[data-edit-in]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editIn)));
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
        const sounds = (window.ASTERA.sounds || []).map((s) => `<option value="${s}">`).join('');
        const open = (row = {}) => {
            const members = row.members || [];
            const deptId = row.dept || window.ASTERA.dept || '';
            const dept = (window.ASTERA.depts || []).find((item) => item.id === deptId) || {};
            const recordEnabled = row.record ?? dept.record ?? true;
            const st = Object.entries(strategies).map(([k, v]) =>
                `<option value="${k}" ${row.strategy === k ? 'selected' : ''}>${v}</option>`
            ).join('');
            const localId = row.id ? String(row.id).split('-').slice(1).join('-') || row.id : '';
            this.modal(`
                <h3>${row.id ? 'Kuyruk düzenle' : 'Kuyruk ekle'}</h3>
                <form id="f" class="queue-form">
                    ${this.deptField(row.dept)}
                    <fieldset class="fieldset">
                        <legend>Temel ayarlar</legend>
                        <div class="grid-2">
                            <label>Kimlik<input name="id" required value="${row.id || localId}" ${row.id ? 'readonly' : ''} placeholder="destek"></label>
                            <label>Kuyruk adı<input name="name" value="${row.name || ''}" placeholder="Satış kuyruğu"></label>
                            ${this.languageField(row.language || 'en')}
                            <label>Dahili numarası<input name="exten" required value="${row.exten || ''}" placeholder="8001"></label>
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
                        <div class="checks queue-members" id="queueMembers"></div>
                    </fieldset>

                    <fieldset class="fieldset">
                        <legend>Arayana anonslar</legend>
                        <div class="grid-2">
                            <label>Karşılama anonsu<input name="announce" data-tenant-sound list="queueSounds" value="${row.announce || ''}" placeholder="custom/firma/karsilama"></label>
                            <label>Periyodik anons<input name="periodic_announce" data-tenant-sound list="queueSounds" value="${row.periodic_announce || ''}" placeholder="queue-periodic-announce"></label>
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
                        <datalist id="queueSounds">${sounds}</datalist>
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
            `);
            const memberBox = document.getElementById('queueMembers');
            const deptInput = document.querySelector('#f [name="dept"]');
            const renderMembers = () => {
                const dept = deptInput ? deptInput.value : (row.dept || window.ASTERA.dept || '');
                const list = exts.filter((e) => !dept || e.dept === dept);
                memberBox.innerHTML = list.length
                    ? list.map((e) => `<label><input type="checkbox" name="members" value="${e.exten}" ${members.includes(String(e.exten)) ? 'checked' : ''}> ${e.exten} ${e.name || ''}</label>`).join('')
                    : '<span class="muted">Bu firmada abone yok.</span>';
            };
            renderMembers();
            if (deptInput && deptInput.tagName === 'SELECT') deptInput.addEventListener('change', renderMembers);
            this.bindDestPairs();
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(document.getElementById('f'), 'queue_save');
        };
        document.getElementById('addQueue').onclick = () => open();
        document.querySelectorAll('[data-edit-queue]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editQueue)));
        document.querySelectorAll('[data-del-queue]').forEach((b) => b.onclick = async () => {
            if (!confirm('Kuyruk silinsin mi?')) return;
            try { await this.post('queue_delete', { id: b.dataset.delQueue }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
    },

    pageRinggroups() {
        const exts = window.ASTERA.exts || [];
        const dept = window.ASTERA.dept || '';
        const open = (row = {}) => {
            const members = row.members || [];
            const initialDept = row.dept || dept || (window.ASTERA.depts[0] && window.ASTERA.depts[0].id) || '';
            const memberOptions = (deptId, selected = []) => {
                const html = exts
                    .filter((e) => e.dept === deptId)
                    .map((e) =>
                        `<label><input type="checkbox" name="members" value="${e.exten}" ${selected.includes(e.exten) ? 'checked' : ''}> ${e.exten} ${e.name || ''}</label>`
                    ).join('');
                return html || '<span class="muted">Bu firmaya ait abone yok.</span>';
            };
            this.modal(`
                <h3>${row.id ? 'Grup düzenle' : 'Ring grup'}</h3>
                <form id="f" class="ringgroup-form">
                    ${this.deptField(initialDept)}
                    <label>Kimlik<input name="id" required value="${row.id || ''}" ${row.id ? 'readonly' : ''} placeholder="satis"></label>
                    <label>Ad<input name="name" value="${row.name || ''}"></label>
                    <label>Açıklama<textarea name="description" placeholder="Grup açıklaması">${row.description || ''}</textarea></label>
                    ${this.languageField(row.language || 'en')}
                    <label>Dahili<input name="exten" required value="${row.exten || ''}" placeholder="8100"></label>

                    <h4>Ses Kaydı</h4>
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
                    <p class="muted">WAV en uyumlu, GSM daha az yer kaplar, ULAW sıkıştırmasız telefon kalitesidir.</p>
                    
                    <h4>Çalma Stratejisi</h4>
                    <label>Strateji
                        <select name="strategy">
                            <option value="ringall" ${row.strategy !== 'hunt' ? 'selected' : ''}>Tümü çalsın (Ringall)</option>
                            <option value="hunt" ${row.strategy === 'hunt' ? 'selected' : ''}>Sırayla (Hunt)</option>
                        </select>
                    </label>
                    <label>Çalma süresi (sn)<input name="ring_time" type="number" value="${row.ring_time || 20}" placeholder="20"></label>
                    <p class="muted">Her üye için maksimum çalma süresi (hunt stratejisinde her üye için)</p>
                    
                    <h4>Arama Onayı</h4>
                    <label><input type="checkbox" name="confirm_calls" ${row.confirm_calls ? 'checked' : ''}> Arama onayı iste</label>
                    <p class="muted">Üye cevapladığında 1'e basarak onaylamalı</p>
                    
                    <h4>Anons ve Müzik</h4>
                    ${this.soundField('announcement', row.announcement || '', 'Başlangıç anonsu')}
                    <label>Müzik sınıfı<input name="music_class" value="${row.music_class || row.dept || window.ASTERA.dept || ''}" placeholder="Firma kodu"></label>
                    
                    <h4>Arayan Kimliği</h4>
                    <label>CID Ad Öneki<input name="cid_prefix" value="${row.cid_prefix || ''}" placeholder="Grup:"></label>
                    <p class="muted">Örn: "Satış:" → üye telefonunda "Satış: Müşteri Adı" görür</p>
                    
                    <h4>Davranış</h4>
                    <label><input type="checkbox" name="skip_busy" ${row.skip_busy ? 'checked' : ''}> Meşgul üyeleri atla</label>
                    <p class="muted">Meşgul veya kullanılamaz üyelere arama yapmaz</p>
                    
                    <h4>Üyeler</h4>
                    <div class="checks" id="rgMembers">${memberOptions(initialDept, members)}</div>
                    
                    <h4>Cevaplanmazsa</h4>
                    ${this.destPair('timeout_type', 'timeout_dest', row.timeout_type || 'hangup', row.timeout_dest || '')}
                    
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `);
            this.bindDestPairs();
            const form = document.getElementById('f');
            const deptSelect = form.elements.dept;
            if (deptSelect && deptSelect.tagName === 'SELECT') {
                deptSelect.onchange = () => {
                    document.getElementById('rgMembers').innerHTML = memberOptions(deptSelect.value);
                };
            }
            document.getElementById('c').onclick = () => this.closeModal();
            this.formSubmit(form, 'ringgroup_save');
        };
        document.getElementById('addRg').onclick = () => open();
        document.querySelectorAll('[data-edit-rg]').forEach((b) => b.onclick = () => open(JSON.parse(b.dataset.editRg)));
        document.querySelectorAll('[data-del-rg]').forEach((b) => b.onclick = async () => {
            if (!confirm('Grup silinsin mi?')) return;
            try { await this.post('ringgroup_delete', { id: b.dataset.delRg }); location.reload(); }
            catch (e) { this.toast(e.message, true); }
        });
    },

    ivrDigitRows(digits = {}) {
        return '0123456789*#'.split('').map((d) => {
            const info = digits[d] || {};
            return `<div class="grid-2"><strong>${d}</strong>${this.destPair('d' + d + '_type', 'd' + d + '_dest', info.type || 'hangup', info.dest || '')}</div>`;
        }).join('');
    },

    pageIvr() {
        const open = (row = {}) => {
            const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
            })[char]);
            const initiallyBlocked = new Set((row.blocked_extensions || []).map(String));
            this.modal(`
                <h3>${row.id ? 'IVR düzenle' : 'IVR ekle'}</h3>
                <form id="f">
                    ${this.deptField(row.dept)}
                    <label>Kod<input name="id" required value="${row.id || ''}" ${row.id ? 'readonly' : ''} placeholder="karsilama"></label>
                    <label>Ad<input name="name" value="${row.name || ''}"></label>
                    ${this.languageField(row.language || 'en')}
                    ${this.soundField('sound', row.sound || '', 'Yüklü ses dosyası', true)}
                    <p class="muted">Zaman aşımı hedefi</p>
                    ${this.destPair('timeout_type', 'timeout_dest', row.timeout_type || 'hangup', row.timeout_dest || '')}
                    <fieldset class="fieldset">
                        <legend>Dahili tuşlama</legend>
                        <label><input type="checkbox" name="direct_dial" ${row.direct_dial !== false ? 'checked' : ''}> Arayanlar dahili numarayı doğrudan tuşlayabilsin</label>
                        <label>Tuşlanması yasak dahililer
                            <select name="blocked_extensions" id="ivrBlockedExtensions" multiple size="6"></select>
                        </label>
                        <p class="muted">Yasaklı dahililerden biri tuşlandığında aşağıdaki hedefe aktarılır.</p>
                        ${this.destPair('blocked_type', 'blocked_dest', row.blocked_type || 'hangup', row.blocked_dest || '')}
                    </fieldset>
                    <fieldset class="fieldset"><legend>Tuşlar</legend>${this.ivrDigitRows(row.digits || {})}</fieldset>
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `);
            this.bindDestPairs();
            document.getElementById('c').onclick = () => this.closeModal();
            const form = document.getElementById('f');
            const blockedSelect = document.getElementById('ivrBlockedExtensions');
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
                destinationSelect.disabled = isExternal;
                destinationSelect.hidden = isExternal;
                manual.disabled = !isExternal;
                manual.hidden = !isExternal;
                if (isExternal) return;
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
            const renderBlockedExtensions = () => {
                const dept = selectedDept();
                blockedSelect.innerHTML = (window.ASTERA.exts || [])
                    .filter((extension) => String(extension.dept) === String(dept))
                    .map((extension) => {
                        const number = String(extension.exten || '');
                        const selected = initiallyBlocked.has(number) ? 'selected' : '';
                        return `<option value="${escapeHtml(number)}" ${selected}>${escapeHtml(number)} — ${escapeHtml(extension.name || '')}</option>`;
                    }).join('');
            };
            form.querySelector('[name="dept"]')?.addEventListener('change', () => {
                initiallyBlocked.clear();
                renderBlockedExtensions();
                form.querySelectorAll('.dest-pair').forEach((pair) => renderDestinationPair(pair));
            });
            renderBlockedExtensions();
            form.onsubmit = async (e) => {
                e.preventDefault();
                const data = Object.fromEntries(new FormData(form).entries());
                data.direct_dial = form.elements.direct_dial.checked ? '1' : '0';
                data.blocked_extensions_json = JSON.stringify(
                    [...blockedSelect.selectedOptions].map((option) => option.value)
                );
                const digits = {};
                '0123456789*#'.split('').forEach((d) => {
                    const t = data['d' + d + '_type'];
                    const dest = data['d' + d + '_dest'];
                    if (t && t !== 'hangup') digits[d] = { type: t, dest };
                });
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
            `);
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
                    ${this.languageField(row.language || 'en')}
                    ${this.soundField('sound', row.sound || 'hello-world')}
                    ${this.destPair('dest_type', 'dest', row.dest_type || 'hangup', row.dest || '')}
                    <div class="modal-actions">
                        <button class="btn" type="button" id="c">Vazgeç</button>
                        <button class="btn primary">Kaydet</button>
                    </div>
                </form>
            `);
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
            `);
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
        const output = document.getElementById('sslOutput');
        const dryRun = document.getElementById('sslDryRun');
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
            `);
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
            `);
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
        form.onsubmit = async (e) => {
            e.preventDefault();
            const data = Object.fromEntries(new FormData(form).entries());
            data.vm_enabled = form.querySelector('[name="vm_enabled"]')?.checked ? '1' : '';
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
        const load = async () => {
            try {
                const r = await this.post('logs');
                document.getElementById('logBox').textContent = r.output || 'boş';
            } catch (e) { this.toast(e.message, true); }
        };
        document.getElementById('reloadLog').onclick = load;
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
            `);
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
            `);
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
            `);
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
            `);
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
            `);
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
                    rows.push(`<div class="sound-row">
                        <div><strong>${title}</strong> <span class="muted">[${escapeHtml(owner(file.dept))}]</span><br>
                        <code>${escapeHtml(file.playback || file.file)}</code></div>
                        ${canPlay ? `<audio controls preload="none" src="${src}"></audio>` : '<span class="muted">Firma bilgisi yok</span>'}
                    </div>`);
                };
                (r.files || []).forEach((file) => render(file, 'sound', 'IVR'));
                (r.moh || []).forEach((file) => render(file, 'moh', 'MOH'));
                document.getElementById('soundBox').innerHTML = rows.join('') || '<span class="muted">Dosya yok</span>';
            } catch (e) { this.toast(e.message, true); }
        };
        document.getElementById('reloadSounds').onclick = load;
        load();
    }
};

document.addEventListener('DOMContentLoaded', () => Astera.bindGlobal());
