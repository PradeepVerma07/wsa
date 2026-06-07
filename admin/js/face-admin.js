/* ============================================================
   WSA Face Admin — v2.0 — Registration, Logs, Stats
   ============================================================ */
(function ($) {
    'use strict';

    let video, stream, captures = {}, staff = [], liveQuality = 0;
    let logsData = [];

    const ANGLES = [
        { key: 'front', label: 'Front (straight)', icon: '😐' },
        { key: 'left',  label: 'Turn Left',         icon: '↩️' },
        { key: 'right', label: 'Turn Right',         icon: '↪️' },
        { key: 'up',    label: 'Slight Up',          icon: '⬆️' },
        { key: 'down',  label: 'Slight Down',        icon: '⬇️' },
    ];

    /* ── Status helpers ──────────────────────────────────────────── */
    function msg(text, type = '') {
        const el = $('#wsaFaceRegStatus');
        el.text(text).attr('class', 'wsa-face-status-line' + (type ? ' wsa-st-' + type : ''));
    }

    function markAngle(angle) {
        $(`[data-step="${angle}"]`).addClass('done').attr('title', `${angle} — captured`);
        updateSaveButton();
    }

    function updateSaveButton() {
        const count = Object.keys(captures).length;
        const pct   = Math.round(count / ANGLES.length * 100);
        $('#wsaCaptureProgress').text(`${count}/${ANGLES.length} angles`);
        $('#wsaCaptureBar').css('width', pct + '%');
        $('#wsaSaveFace').prop('disabled', count < 3);
        if (count >= 3) $('#wsaSaveFace').text(`💾 Save Face Profile (${count} angles)`);
    }

    /* ── Fetch headers ───────────────────────────────────────────── */
    function headers() {
        return { 'Content-Type': 'application/json', 'X-WP-Nonce': wsaFaceAdmin.nonce };
    }

    function faceFetch(url, options = {}) {
        const opts = Object.assign({ credentials: 'same-origin', cache: 'no-store' }, options);
        opts.headers = Object.assign({}, options.headers || {});
        if (wsaFaceAdmin && wsaFaceAdmin.nonce && !opts.headers['X-WP-Nonce']) {
            opts.headers['X-WP-Nonce'] = wsaFaceAdmin.nonce;
        }
        return fetch(url, opts);
    }

    /* ── Model loading ───────────────────────────────────────────── */
    async function loadModels() {
        const urls = [
            wsaFaceAdmin.apiModels,
            'https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.15/model',
        ];
        for (const u of urls) {
            try {
                await faceapi.nets.tinyFaceDetector.loadFromUri(u);
                await faceapi.nets.faceLandmark68Net.loadFromUri(u);
                await faceapi.nets.faceRecognitionNet.loadFromUri(u);
                return;
            } catch (e) { /* try next */ }
        }
        throw new Error('Face AI models not found. Check model folder or CDN access.');
    }

    /* ── Camera ─────────────────────────────────────────────────── */
    async function startCamera() {
        if (stream) stream.getTracks().forEach(t => t.stop());
        video = document.getElementById('wsaFaceRegVideo');
        stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } },
            audio: false,
        });
        video.srcObject = stream;
        await video.play();
        startQualityMonitor();
    }

    /* ── Live quality monitor loop ───────────────────────────────── */
    function startQualityMonitor() {
        const canvas = document.getElementById('wsaFaceRegCanvas');
        async function tick() {
            if (!video || !video.videoWidth) { requestAnimationFrame(tick); return; }
            const det = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 })).withFaceLandmarks();
            canvas.width  = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            if (det) {
                faceapi.draw.drawDetections(canvas, [det]);
                const b = det.detection.box;
                const q = Math.max(0, Math.min(100, Math.round((b.width * b.height) / (video.videoWidth * video.videoHeight) * 520)));
                liveQuality = q;
                $('#wsaLiveQuality').text(q + '%').css('color', q >= 70 ? '#16a34a' : q >= 50 ? '#d97706' : '#dc2626');
                $('#wsaLiveQBar').css('width', q + '%').css('background', q >= 70 ? '#22c55e' : q >= 50 ? '#f59e0b' : '#ef4444');
                $('#wsaFaceGuide').text(q >= 70 ? '✅ Face quality good — ready to capture' : q >= 50 ? '⚠️ Acceptable — move closer for better quality' : '❌ Quality low — improve lighting, move closer');
            } else {
                liveQuality = 0;
                $('#wsaLiveQuality').text('—');
                $('#wsaFaceGuide').text('📷 No face detected — look at the camera');
            }
            setTimeout(() => requestAnimationFrame(tick), 500);
        }
        requestAnimationFrame(tick);
    }

    /* ── Capture one angle ───────────────────────────────────────── */
    async function captureAngle(angle) {
        msg(`Capturing ${angle}… hold still.`, '');
        const det = await faceapi
            .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.55 }))
            .withFaceLandmarks()
            .withFaceDescriptor();
        if (!det) throw new Error('No clear face detected. Look at the camera and try again.');
        const b = det.detection.box;
        const q = Math.max(0, Math.min(100, Math.round((b.width * b.height) / (video.videoWidth * video.videoHeight) * 520)));
        if (q < 45) throw new Error(`Quality too low (${q}%). Improve lighting and come closer.`);
        return { descriptor: Array.from(det.descriptor), quality: q };
    }

    /* ── Average multiple descriptors ────────────────────────────── */
    function avgDescriptors(arrs) {
        const out = new Array(128).fill(0);
        arrs.forEach(a => a.forEach((v, i) => { out[i] += v; }));
        return out.map(v => v / arrs.length);
    }

    /* ── Load staff list ─────────────────────────────────────────── */
    async function loadStaff() {
        let j = {};
        try {
            const r = await faceFetch(wsaFaceAdmin.apiStaff);
            j = await r.json();
            if (!r.ok || !j.success) throw new Error(j.message || 'Failed to load staff.');
        } catch (e) {
            $('#wsaFaceStaff').html('<option value="">Could not load staff. Refresh and try again.</option>');
            $('#wsaFaceStaffCount').text('');
            $('#wsaFaceStaffList').html('<p class="notice notice-error">Failed to load staff: ' + e.message + '</p>');
            msg('Failed to load staff dropdown: ' + e.message, 'err');
            return;
        }
        staff = j.staff || [];

        // Populate dropdown — value must be the DB id (integer primary key)
        if (!staff.length) {
            $('#wsaFaceStaff').html('<option value="">No active staff found. Add staff first.</option>');
            msg('❌ No staff in database. Go to Staff Management to add staff first.', 'err');
            return;
        }
        const options = staff.map(s => {
            const face = s.face_status === 'registered' ? ' ✅' : ' ❌';
            const dept = s.department ? ` [${s.department}]` : '';
            return `<option value="${s.id}">${s.name}${dept} (${s.employee_id || '—'})${face}</option>`;
        }).join('');
        $('#wsaFaceStaff').html('<option value="">— Select Employee —</option>' + options);

        // Render staff table
        const rows = staff.map(s => {
            const q   = s.quality_score ? `${s.quality_score}%` : '—';
            const cnt = s.capture_count ? `${s.capture_count} angles` : '—';
            const ts  = s.face_status === 'registered';
            const badge = ts ? '<span class="wsa-face-ok">✅ Registered</span>' : '<span class="wsa-face-bad">❌ Not Registered</span>';
            const today = s.today_status ? `<span class="wsa-att-badge wsa-att-${s.today_status.toLowerCase()}">${s.today_status}</span>` : '—';
            const del   = ts ? `<button class="button button-small wsa-del-face" data-id="${s.id}" data-name="${s.name}">🗑 Delete</button>` : '';
            return `<tr>
                <td><strong>${s.name}</strong></td>
                <td>${s.employee_id || '—'}</td>
                <td>${s.department || '—'}</td>
                <td>${badge}</td>
                <td>${q}</td>
                <td>${cnt}</td>
                <td>${today}</td>
                <td>${del}</td>
            </tr>`;
        }).join('');

        const reg   = staff.filter(s => s.face_status === 'registered').length;
        const unreg = staff.length - reg;
        $('#wsaFaceStaffCount').text(`${reg} registered / ${unreg} unregistered`);

        $('#wsaFaceStaffList').html(`
            <div class="wsa-staff-summary">
                <span class="wsa-badge-ok">${reg} Registered</span>
                <span class="wsa-badge-err">${unreg} Not Registered</span>
            </div>
            <div class="wsa-face-table-wrap wsa-face-table-wrap--staff">
            <table class="wsa-face-table wsa-face-table--staff">
                <thead><tr><th>Name</th><th>Employee ID</th><th>Department</th><th>Face</th><th>Quality</th><th>Angles</th><th>Today</th><th>Actions</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
            </div>`);

        // Load stats
        loadStats();
    }

    /* ── Load stats ──────────────────────────────────────────────── */
    async function loadStats() {
        try {
            const r = await faceFetch(wsaFaceAdmin.apiStats);
            const j = await r.json();
            if (!j.success) return;
            $('#wsaStatReg').text(j.registered);
            $('#wsaStatUnreg').text(j.unregistered);
            $('#wsaStatScans').text(j.today_scans);
            $('#wsaStatFails').text(j.today_failures);
            $('#wsaStatConf').text(j.avg_confidence + '%');
        } catch (_) {}
    }

    /* ── Load logs ───────────────────────────────────────────────── */
    async function loadLogs() {
        const from   = $('#wsaFaceLogFrom').val() || new Date().toISOString().slice(0, 7) + '-01';
        const to     = $('#wsaFaceLogTo').val()   || new Date().toISOString().slice(0, 10);
        const status = $('#wsaFaceLogStatus').val() || '';
        const url    = `${wsaFaceAdmin.apiLogs}?from=${from}&to=${to}&status=${status}&limit=500`;
        try {
            const r = await faceFetch(url);
            const j = await r.json();
            logsData = j.logs || [];
            const st  = j.stats || {};
            $('#wsaLogTotal').text(st.total || 0);
            $('#wsaLogSuccess').text(st.successes || 0);
            $('#wsaLogFail').text(st.failures || 0);
            $('#wsaLogConf').text((st.avg_confidence || 0) + '%');
            renderLogsTable(logsData);
        } catch (e) {
            $('#wsaFaceLogs').html('<p class="notice notice-error">Failed to load logs: ' + e.message + '</p>');
        }
    }

    function renderLogsTable(rows) {
        if (!rows.length) { $('#wsaFaceLogs').html('<p>No logs found for this period.</p>'); return; }
        const html = rows.map(l => {
            const conf  = Math.round((parseFloat(l.confidence) || 0) * 100);
            const confCls = conf >= 70 ? 'conf-hi' : conf >= 50 ? 'conf-mid' : 'conf-lo';
            const actMap  = { CHECKIN:'✅ Check-In', CHECKOUT:'🚪 Check-Out', BREAK_START:'☕ Break Start', BREAK_END:'✅ Break End', FAILED:'❌ Failed' };
            return `<tr>
                <td>${l.created_at || '—'}</td>
                <td>${l.name || '—'}</td>
                <td>${l.employee_id || '—'}</td>
                <td>${l.department || '—'}</td>
                <td>${actMap[l.action] || l.action}</td>
                <td><span class="wsa-log-status wsa-ls-${l.status}">${l.status}</span></td>
                <td><span class="wsa-log-conf ${confCls}">${conf}%</span></td>
                <td>${l.liveness_passed ? '✅' : '❌'}</td>
                <td>${l.reason || '—'}</td>
            </tr>`;
        }).join('');
        $('#wsaFaceLogs').html(`<table class="wsa-face-table wsa-face-table--logs" id="wsaFaceLogsTable">
            <thead><tr><th>Time</th><th>Name</th><th>Emp ID</th><th>Dept</th><th>Action</th><th>Status</th><th>Confidence</th><th>Liveness</th><th>Reason</th></tr></thead>
            <tbody>${html}</tbody>
        </table>`);
    }

    /* ── Export CSV ──────────────────────────────────────────────── */
    function exportCsv() {
        const header = ['Time', 'Name', 'Employee ID', 'Department', 'Action', 'Status', 'Confidence', 'Liveness', 'Reason'];
        const rows   = logsData.map(l => [
            l.created_at, l.name || '', l.employee_id || '', l.department || '',
            l.action, l.status, Math.round((parseFloat(l.confidence) || 0) * 100) + '%',
            l.liveness_passed ? 'Yes' : 'No', l.reason || ''
        ].map(v => '"' + String(v).replace(/"/g, '""') + '"').join(','));
        const csv  = [header.join(','), ...rows].join('\n');
        const a    = document.createElement('a');
        a.href     = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
        a.download = 'face-attendance-' + new Date().toISOString().slice(0, 10) + '.csv';
        a.click();
    }

    /* ── Event bindings ──────────────────────────────────────────── */

    // Capture angle button
    $(document).on('click', '.wsa-face-actions .button[data-angle]', async function (e) {
        e.preventDefault();
        const angle = $(this).data('angle');
        if (!$('#wsaFaceStaff').val()) { msg('❌ Select a staff member from the dropdown first.', 'err'); return; }
        try {
            const d = await captureAngle(angle);
            captures[angle] = d;
            markAngle(angle);
            msg(`✅ ${angle} captured — quality: ${d.quality}%`, 'ok');
        } catch (err) {
            msg('❌ ' + err.message, 'err');
        }
    });

    // Reset captures
    $(document).on('click', '#wsaResetCaptures', function () {
        captures = {};
        $('#wsaFaceProgress span').removeClass('done');
        updateSaveButton();
        msg('Captures reset. Start fresh.', '');
    });

    // Save face profile
    $(document).on('click', '#wsaSaveFace', async function (e) {
        e.preventDefault();
        const staffId = $('#wsaFaceStaff').val();
        if (!staffId || staffId === '') { msg('❌ Please select a staff member first.', 'err'); return; }
        const keys = Object.keys(captures);
        if (keys.length < 3) { msg('❌ Capture at least 3 angles before saving.', 'err'); return; }
        try {
            $(this).prop('disabled', true).text('Saving…');
            const desc    = avgDescriptors(keys.map(k => captures[k].descriptor));
            const quality = Math.round(keys.reduce((s, k) => s + captures[k].quality, 0) / keys.length);
            const r = await faceFetch(wsaFaceAdmin.apiRegister, {
                method:  'POST',
                headers: headers(),
                body:    JSON.stringify({ staff_id: parseInt(staffId, 10), descriptor: desc, angles: captures, quality_score: quality, capture_count: keys.length }),
            });
            const j = await r.json();
            if (!r.ok) throw new Error(j.message || 'Save failed.');
            msg('✅ ' + j.message, 'ok');
            captures = {};
            $('#wsaFaceProgress span').removeClass('done');
            updateSaveButton();
            await loadStaff();
        } catch (err) {
            msg('❌ ' + err.message, 'err');
        } finally {
            $('#wsaSaveFace').prop('disabled', false).text('💾 Save / Update Face Profile');
        }
    });

    // Delete face profile
    $(document).on('click', '.wsa-del-face', async function () {
        const id   = $(this).data('id');
        const name = $(this).data('name');
        if (!confirm(`Delete face profile for ${name}? They will need to be registered again.`)) return;
        const r = await faceFetch(wsaFaceAdmin.apiDelete, { method: 'POST', headers: headers(), body: JSON.stringify({ staff_id: id }) });
        const j = await r.json();
        alert(j.message || 'Done.');
        await loadStaff();
    });

    // Staff dropdown change → show preview
    $(document).on('change', '#wsaFaceStaff', function () {
        const id   = parseInt($(this).val(), 10);
        const s    = staff.find(x => parseInt(x.id, 10) === id);
        const prev = $('#wsaSelectedStaffPreview');
        if (!s) { prev.hide(); return; }
        $('#wsaPreviewName').text(s.name);
        $('#wsaPreviewEmpId').text('ID: ' + (s.employee_id || '—'));
        $('#wsaPreviewDept').text(s.department || '');
        const fs = $('#wsaPreviewFaceStatus');
        if (s.face_status === 'registered') {
            fs.text('✅ Face registered (Q: ' + (s.quality_score || '?') + '%)').css('color','#16a34a');
        } else {
            fs.text('❌ No face registered — capture below').css('color','#dc2626');
        }
        prev.show();
        // Reset captures when staff changes
        captures = {};
        $('#wsaFaceProgress span').removeClass('done');
        updateSaveButton();
        msg('Selected: ' + s.name + '. Now capture face angles below.');
    });

    // Load logs
    $(document).on('click', '#wsaLoadFaceLogs', loadLogs);
    $(document).on('click', '#wsaExportFaceLogs', exportCsv);
    $(document).on('click', '#wsaPrintFaceLogs', () => window.print());

    // Refresh stats
    $(document).on('click', '#wsaRefreshStats', loadStats);

    /* ── Quick Add Staff ─────────────────────────────────────────── */
    $(document).on('click', '#wsaQuickAddStaffBtn', function () {
        $('#wsaQuickAddModal').fadeIn(150);
    });
    $(document).on('click', '#wsaModalClose, #wsaModalOverlay', function () {
        $('#wsaQuickAddModal').fadeOut(150);
    });
    $(document).on('click', '#wsaSubmitQuickStaff', async function () {
        const empId = $('#wsaQEmpId').val().trim();
        const name  = $('#wsaQName').val().trim();
        if (!empId || !name) { alert('Employee ID and Name are required.'); return; }
        const btn = $(this);
        btn.prop('disabled', true).text('Adding…');
        try {
            const r = await faceFetch(wsaFaceAdmin.apiAddStaff, {
                method: 'POST',
                headers: headers(),
                body: JSON.stringify({
                    employee_id: empId,
                    name:        name,
                    department:  $('#wsaQDept').val().trim(),
                    phone:       $('#wsaQPhone').val().trim(),
                    email:       $('#wsaQEmail').val().trim(),
                    pin:         $('#wsaQPin').val().trim() || '1234',
                    shift_id:    $('#wsaQShift').val() || '',
                }),
            });
            const j = await r.json();
            if (!r.ok) throw new Error(j.message || 'Failed to add staff.');
            alert('✅ ' + j.message);
            $('#wsaQuickAddModal').fadeOut(150);
            // Reset modal fields
            $('#wsaQEmpId,#wsaQName,#wsaQDept,#wsaQPhone,#wsaQEmail,#wsaQPin').val('');
            // Reload staff list and select the new staff
            await loadStaff();
            if (j.staff_id) {
                $('#wsaFaceStaff').val(j.staff_id).trigger('change');
            }
            msg('✅ Staff added! Now capture face angles for ' + name, 'ok');
        } catch (err) {
            alert('❌ ' + err.message);
        } finally {
            btn.prop('disabled', false).text('Add Staff');
        }
    });

    /* ── Init ────────────────────────────────────────────────────── */
    $(async function () {
        // Set default dates
        const today = new Date().toISOString().slice(0, 10);
        const first = today.slice(0, 7) + '-01';
        $('#wsaFaceLogFrom').val(first);
        $('#wsaFaceLogTo').val(today);

        updateSaveButton();

        try {
            await loadStaff();
        } catch (e) {
            console.error('[WSA Face Admin] Staff load failed:', e);
        }

        try {
            msg('⚙️ Loading Face AI models…');
            await loadModels();
            msg('📷 Starting camera…');
            await startCamera();
            msg('✅ Camera ready. Select staff and capture face angles.');
        } catch (e) {
            msg('❌ ' + e.message, 'err');
        }

        // Load initial logs
        await loadLogs();
    });

})(jQuery);
