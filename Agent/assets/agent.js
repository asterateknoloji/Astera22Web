(() => {
    'use strict';
    const app = window.AGENT_APP;
    const $ = (selector) => document.querySelector(selector);
    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[char]));
    const duration = (value) => {
        const seconds = Math.max(0, Number(value) || 0);
        return [Math.floor(seconds / 3600), Math.floor(seconds % 3600 / 60), seconds % 60]
            .map((part) => String(part).padStart(2, '0')).join(':');
    };
    let data = app.snapshot;
    let busy = false;

    function notice(message, ok = true) {
        const element = $('#notice');
        element.textContent = message;
        element.className = `notice show ${ok ? 'success' : 'error'}`;
        window.setTimeout(() => element.classList.remove('show'), 5000);
    }

    function renderQueues(queues) {
        $('#queue-list').innerHTML = queues.length ? queues.map((queue) => `
            <div class="queue-item">
                <div class="queue-name"><i class="fas fa-list"></i> ${esc(queue.id)} - ${esc(queue.name)}</div>
                <div class="queue-stats">
                    <div class="queue-stat">Müsait<strong>${Number(queue.available || 0)}</strong></div>
                    <div class="queue-stat">Molada<strong>${Number(queue.paused || 0)}</strong></div>
                    <div class="queue-stat">Cevaplandı<strong>${Number(queue.answered_today || 0)}</strong></div>
                    <div class="queue-stat">Kaçan<strong>${Number(queue.abandoned_today || 0)}</strong></div>
                </div>
                <div class="queue-member-title"><span>Agent Durumları</span><b>${(queue.members || []).length}</b></div>
                <div class="queue-member-list">
                    ${(queue.members || []).length ? queue.members.map((member) => {
                        const own = String(member.extension || '') === String(data.agent?.extension || '');
                        const callDetail = ['talking', 'ringing', 'busy', 'hold'].includes(member.state)
                            ? `${esc(member.peer || 'Numara bilinmiyor')} · <span data-member-duration="${Number(member.call_age) || 0}">${duration(member.call_age)}</span>`
                            : '';
                        const breakDetail = member.state === 'break'
                            ? esc(member.break_name || member.pause_reason || 'Mola')
                            : '';
                        return `<div class="queue-member ${esc(member.state || 'unknown')}">
                            <span class="queue-member-dot"></span>
                            <span><strong>${esc(member.name || `Agent ${member.extension || ''}`)}</strong><small>${esc(member.extension || '—')}${own ? ' · Siz' : ''}</small></span>
                            <span class="queue-member-status"><b>${esc(member.label || 'Bilinmiyor')}</b>${callDetail || breakDetail ? `<small>${callDetail || breakDetail}</small>` : ''}</span>
                        </div>`;
                    }).join('') : '<div class="queue-member-empty">Kuyrukta aktif Agent yok</div>'}
                </div>
            </div>`).join('') : '<div class="empty">Atanmış kuyruk bulunamadı</div>';
    }

    function renderWaiting(calls) {
        const root = $('#waiting-calls');
        const signature = calls.map((call) => [
            call.queue, call.caller, call.position
        ].join('|')).join('~');
        if (root.dataset.signature === signature && calls.length) {
            root.querySelectorAll('[data-wait-index]').forEach((element) => {
                const call = calls[Number(element.dataset.waitIndex)];
                if (call) element.textContent = duration(call.wait_seconds);
            });
            return;
        }
        root.dataset.signature = signature;
        root.innerHTML = calls.length ? `
            <table class="data-table"><thead><tr><th>Kuyruk</th><th>Arayan</th><th>Sıra</th><th>Bekleme</th></tr></thead>
            <tbody>${calls.map((call) => `<tr><td>${esc(call.queue)} - ${esc(call.queue_name)}</td>
            <td>${esc(call.caller)}</td><td>${Number(call.position || 1)}</td>
            <td data-wait-index="${calls.indexOf(call)}">${duration(call.wait_seconds)}</td></tr>`).join('')}</tbody></table>`
            : '<div class="empty">Bekleyen çağrı yok</div>';
    }

    function renderBreaks(snapshot) {
        const root = $('#break-controls');
        if (snapshot.active_break) {
            root.innerHTML = `<div class="active-break"><span>Aktif Mola</span>
                <strong>${esc(snapshot.active_break.name)}</strong>
                <span id="active-break-duration">${duration(snapshot.active_break.duration_seconds)}</span>
                <button class="end-break" type="button" data-action="end_break">Molayı Bitir ve Hazır Ol</button></div>`;
            return;
        }
        root.innerHTML = snapshot.break_types.length
            ? `<div class="break-list">${snapshot.break_types.map((type) =>
                `<button class="break-btn" type="button" data-action="start_break" data-type="${Number(type.break_type_id)}">
                <i class="fas fa-coffee"></i> ${esc(type.name)}</button>`).join('')}</div>`
            : '<div class="empty">Tanımlı mola bulunamadı</div>';
    }

    function renderStats(stats) {
        const values = [
            ['Toplam', stats.total], ['Gelen Cvp', stats.incoming_answered],
            ['Gelen Cvps', stats.incoming_missed], ['Giden Cvp', stats.outgoing_answered],
            ['Giden Cvps', stats.outgoing_missed]
        ];
        $('#daily-stats').innerHTML = values.map(([label, value]) =>
            `<span class="stat-chip">${label}: <strong>${Number(value || 0)}</strong></span>`).join('');
    }

    function renderRecent(calls) {
        $('#recent-calls').innerHTML = calls.length ? `
            <table class="data-table"><thead><tr><th>Tarih/Saat</th><th>Telefon</th><th>Yön</th><th>Süre</th><th>Durum</th><th>Call ID</th></tr></thead>
            <tbody>${calls.map((call) => {
                const outgoing = call.direction === 'outgoing';
                const number = outgoing ? call.dst : call.src;
                return `<tr><td>${esc(call.calldate)}</td><td class="recent-number" data-number="${esc(number)}" title="Çift tıklayarak ara">${esc(number)}</td>
                    <td class="direction ${outgoing ? 'outgoing' : 'incoming'}">${outgoing ? 'Giden' : 'Gelen'}</td>
                    <td>${duration(call.billsec || call.duration)}</td><td>${esc(call.disposition)}</td><td>${esc(call.call_id)}</td></tr>`;
            }).join('')}</tbody></table>` : '<div class="empty">Henüz çağrı yok</div>';
    }

    function renderPhone(snapshot) {
        const state = snapshot.extension_status || {};
        const active = state.state === 'talking' || state.state === 'ringing';
        const callStatus = $('#call-status');
        const callDuration = $('#call-duration');
        const callButton = $('#call-btn');
        const hangupButton = $('#hangup-btn');
        const transferButton = $('#transfer-btn');
        const phoneNumber = $('#phone-number');
        if (callStatus) {
            callStatus.textContent = active
                ? `${state.label || 'Çağrı'}${state.peer ? `: ${state.peer}` : ''}`
                : (state.label || 'Hazır');
        }
        if (callDuration) callDuration.textContent = duration(state.call_age);
        if (callButton) callButton.disabled = !snapshot.phone.originate || active;
        if (hangupButton) hangupButton.disabled = !snapshot.phone.hangup;
        if (transferButton) transferButton.disabled = !snapshot.phone.transfer;
        if (phoneNumber) phoneNumber.disabled = active;
        const status = $('#agent-status');
        const statusMain = $('#agent-status-main');
        const statusDetail = $('#agent-status-detail');
        const setHeaderStatus = (className, label, detail = '') => {
            status.className = `status-badge ${className}`;
            if (statusMain) statusMain.textContent = label;
            if (statusDetail) statusDetail.textContent = detail;
        };
        if (snapshot.active_break) {
            setHeaderStatus('status-break', `Molada: ${snapshot.active_break.name}`);
        } else if (active) {
            setHeaderStatus(
                'status-busy',
                state.label || 'Meşgul',
                `${state.peer || 'Numara bilinmiyor'} · ${duration(state.call_age)}`
            );
        } else if (state.state === 'offline' || state.state === 'unknown') {
            setHeaderStatus('status-break', state.label || 'Bilinmiyor');
        } else {
            setHeaderStatus('status-online', 'Müsait');
        }

        const externalDisplay = $('#external-call-display');
        if (externalDisplay) {
            externalDisplay.dataset.state = state.state || 'unknown';
            $('#external-call-label').textContent = state.label || 'Bilinmiyor';
            $('#external-call-number').textContent = active ? (state.peer || 'Numara bilinmiyor') : '—';
            $('#external-call-duration').textContent = duration(active ? state.call_age : 0);
            $('#external-device-state').textContent = state.label || 'Bilinmiyor';
            $('#external-phone-help').textContent = state.state === 'ringing'
                ? 'Telefonunuz çalıyor. Çağrıyı Softphone veya masaüstü telefonunuzdan cevaplayın.'
                : (state.state === 'talking'
                    ? 'Görüşme devam ediyor. Numara ve konuşma süresi canlı izleniyor.'
                    : (state.state === 'offline' || state.state === 'unknown'
                        ? 'Telefon bağlantısı görünmüyor. SIP kaydını kontrol edin.'
                        : 'Telefon hazır, çağrı bekleniyor.'));
        }
    }

    function render(snapshot) {
        data = snapshot;
        renderQueues(snapshot.queues || []);
        renderWaiting(snapshot.waiting_calls || []);
        renderBreaks(snapshot);
        renderStats(snapshot.stats || {});
        renderRecent(snapshot.recent_calls || []);
        renderPhone(snapshot);
        $('#login-duration').textContent = duration(snapshot.stats.login_seconds);
        $('#break-duration').textContent = duration(snapshot.stats.break_seconds);
    }

    async function request(action, fields = {}) {
        if (busy) return;
        busy = true;
        try {
            const body = new URLSearchParams({action, csrf: app.csrf, ...fields});
            const response = await fetch('api.php', {
                method: 'POST', body, credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            const result = await response.json();
            if (!result.ok) throw new Error(result.error || 'İşlem tamamlanamadı');
            render(result.data);
            notice(result.message || 'İşlem tamamlandı.');
        } catch (error) {
            notice(error.message || 'Bağlantı hatası', false);
        } finally {
            busy = false;
        }
    }

    async function refresh() {
        if (busy || document.hidden) return;
        try {
            const response = await fetch('api.php?action=snapshot', {
                credentials: 'same-origin', cache: 'no-store'
            });
            const result = await response.json();
            if (result.ok) render(result.data);
        } catch (_) {
            // Keep the last known state; the next interval retries.
        }
    }

    $('#keypad')?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-digit]');
        if (button && !$('#phone-number').disabled) $('#phone-number').value += button.dataset.digit;
    });
    $('#call-btn')?.addEventListener('click', () => request('originate', {target: $('#phone-number').value.trim()}));
    $('#hangup-btn')?.addEventListener('click', () => request('hangup'));
    $('#transfer-btn')?.addEventListener('click', () => {
        const target = window.prompt('Transfer hedef numarası / dahili:');
        if (target !== null && target.trim() !== '') request('transfer', {target: target.trim()});
    });
    $('#phone-number')?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !$('#call-btn').disabled) {
            event.preventDefault();
            $('#call-btn').click();
        }
    });
    $('#break-controls').addEventListener('click', (event) => {
        const button = event.target.closest('[data-action]');
        if (!button) return;
        request(button.dataset.action, button.dataset.type ? {break_type_id: button.dataset.type} : {});
    });
    $('#recent-calls').addEventListener('dblclick', (event) => {
        const cell = event.target.closest('[data-number]');
        if (!cell) return;
        const legacyInput = $('#phone-number');
        const webphoneInput = $('#rtcNum');
        if (legacyInput && !legacyInput.disabled) {
            legacyInput.value = cell.dataset.number || '';
            $('#call-btn')?.click();
        } else if (webphoneInput && typeof AsteraRtc !== 'undefined') {
            webphoneInput.value = cell.dataset.number || '';
            AsteraRtc.tab('keys');
            AsteraRtc.mainAction();
        }
    });
    $('#logout-form').addEventListener('submit', () => {
        $('#logout-form button').disabled = true;
    });

    render(data);
    window.setInterval(() => {
        if (data && data.stats) {
            data.stats.login_seconds = Number(data.stats.login_seconds || 0) + 1;
            if (data.active_break) {
                data.stats.break_seconds = Number(data.stats.break_seconds || 0) + 1;
                data.active_break.duration_seconds = Number(data.active_break.duration_seconds || 0) + 1;
            }
            $('#login-duration').textContent = duration(data.stats.login_seconds);
            $('#break-duration').textContent = duration(data.stats.break_seconds);
            const active = $('#active-break-duration');
            if (active && data.active_break) active.textContent = duration(data.active_break.duration_seconds);
            if (data.extension_status && ['talking', 'ringing'].includes(data.extension_status.state)) {
                data.extension_status.call_age = Number(data.extension_status.call_age || 0) + 1;
                const callDuration = $('#call-duration');
                if (callDuration) callDuration.textContent = duration(data.extension_status.call_age);
                const externalDuration = $('#external-call-duration');
                if (externalDuration) externalDuration.textContent = duration(data.extension_status.call_age);
                const statusDetail = $('#agent-status-detail');
                if (statusDetail) {
                    statusDetail.textContent = `${data.extension_status.peer || 'Numara bilinmiyor'} · ${duration(data.extension_status.call_age)}`;
                }
            }
            (data.waiting_calls || []).forEach((call, index) => {
                call.wait_seconds = Number(call.wait_seconds || 0) + 1;
                const element = document.querySelector(`[data-wait-index="${index}"]`);
                if (element) element.textContent = duration(call.wait_seconds);
            });
            document.querySelectorAll('[data-member-duration]').forEach((element) => {
                const seconds = Number(element.dataset.memberDuration || 0) + 1;
                element.dataset.memberDuration = String(seconds);
                element.textContent = duration(seconds);
            });
        }
    }, 1000);
    window.setInterval(refresh, 5000);
})();
