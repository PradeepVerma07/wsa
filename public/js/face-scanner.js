/* ================================================================
   WSA Face Scanner v3.0 — Production-ready
   Fixes: DOM timing, canvas matchDimensions, liveness, CDN models
   ================================================================ */
(function () {
    'use strict';

    /* ── State ───────────────────────────────────────────────────── */
    let busy          = false;
    let lastMark      = 0;
    let liveScore     = 0;
    let prevBox       = null;
    let stream        = null;
    let modelsReady   = false;
    let currentAction = 'auto';
    let video, canvas, statusEl, qBar, spinner;

    const SCAN_COOLDOWN = 6000;
    const LIVENESS_NEED = 3;

    /* ── Boot after DOM is ready ─────────────────────────────────── */
    document.addEventListener('DOMContentLoaded', init);
    // Fallback if DOMContentLoaded already fired
    if (document.readyState !== 'loading') init();

    /* ── Get DOM refs safely ─────────────────────────────────────── */
    function refs() {
        video    = document.getElementById('wsaFaceVideo');
        canvas   = document.getElementById('wsaFaceCanvas');
        statusEl = document.getElementById('wsaFaceStatus');
        qBar     = document.getElementById('wsaFaceQuality');
        spinner  = document.getElementById('wsaFaceSpinner');
    }

    /* ── Status helpers ──────────────────────────────────────────── */
    function setStatus(msg, type) {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.className   = 'wsa-face-status' + (type ? ' wsa-status-' + type : '');
    }

    function setQuality(q) {
        if (!qBar) return;
        qBar.style.width      = Math.min(100, q) + '%';
        qBar.style.background = q >= 70 ? '#22c55e' : q >= 45 ? '#f59e0b' : '#ef4444';
    }

    function showSpinner(on) {
        if (spinner) spinner.style.display = on ? 'flex' : 'none';
    }

    /* ── Quality score ───────────────────────────────────────────── */
    function calcQuality(box, vw, vh) {
        if (!box || !vw || !vh) return 0;
        const area = (box.width * box.height) / (vw * vh);
        let q = Math.round(area * 480);
        if (box.width < 70 || box.height < 70) q = Math.min(q, 35);
        // Penalise if face is off-centre
        const cx    = box.x + box.width  / 2;
        const cy    = box.y + box.height / 2;
        const offX  = Math.abs(cx / vw - 0.5) * 2; // 0=centre, 1=edge
        const offY  = Math.abs(cy / vh - 0.5) * 2;
        q = Math.round(q * (1 - offX * 0.2) * (1 - offY * 0.2));
        return Math.max(0, Math.min(100, q));
    }

    /* ── Liveness: movement + blink via EAR ─────────────────────── */
    function checkLiveness(det) {
        if (!det || !det.landmarks) return false;
        const box = det.detection.box;

        // Movement check
        if (prevBox) {
            const move = Math.abs(box.x     - prevBox.x)
                       + Math.abs(box.y     - prevBox.y)
                       + Math.abs(box.width - prevBox.width);
            if (move > 5) liveScore++;
        }
        prevBox = { x: box.x, y: box.y, width: box.width };

        // Blink (Eye Aspect Ratio)
        try {
            const pts = det.landmarks.positions;
            const ear = (eye) => {
                const h = Math.abs(pts[eye+1].y - pts[eye+5].y)
                        + Math.abs(pts[eye+2].y - pts[eye+4].y);
                const w = Math.abs(pts[eye].x   - pts[eye+3].x);
                return w > 0 ? h / (2 * w) : 1;
            };
            if (ear(36) < 0.15 || ear(42) < 0.15) liveScore += 2;
        } catch (_) {}

        return liveScore >= LIVENESS_NEED;
    }

    /* ── Load face-api models ────────────────────────────────────── */
    async function loadModels() {
        const cdns = [
            'https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.15/model',
            'https://raw.githubusercontent.com/vladmandic/face-api/master/model',
            (typeof wsaFace !== 'undefined' ? wsaFace.apiModels : ''),
        ].filter(Boolean);

        for (const url of cdns) {
            try {
                setStatus('⚙️ Loading AI models… (' + url.split('/').slice(-3, -1).join('/') + ')');
                await Promise.all([
                    faceapi.nets.tinyFaceDetector.loadFromUri(url),
                    faceapi.nets.faceLandmark68Net.loadFromUri(url),
                    faceapi.nets.faceRecognitionNet.loadFromUri(url),
                ]);
                console.log('[WSA Face] Models loaded from', url);
                return;
            } catch (e) {
                console.warn('[WSA Face] Model CDN failed:', url, e.message);
            }
        }
        throw new Error('All face-api model sources failed. Check internet connection.');
    }

    /* ── Camera ─────────────────────────────────────────────────── */
    async function startCamera() {
        if (stream) stream.getTracks().forEach(t => t.stop());

        // Try HD first, fallback to any camera
        let constraints = [
            { video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } } },
            { video: { facingMode: 'user' } },
            { video: true },
        ];

        let err;
        for (const c of constraints) {
            try {
                stream = await navigator.mediaDevices.getUserMedia(c);
                break;
            } catch (e) { err = e; }
        }

        if (!stream) {
            if (err && err.name === 'NotAllowedError') throw new Error('Camera access denied. Click the camera icon in your browser address bar and allow access.');
            if (err && err.name === 'NotFoundError')   throw new Error('No camera found. Connect a camera and reload.');
            throw new Error('Camera error: ' + (err ? err.message : 'unknown'));
        }

        video.srcObject = stream;
        video.setAttribute('playsinline', '');
        video.setAttribute('autoplay', '');
        video.setAttribute('muted', '');

        await new Promise((res, rej) => {
            video.onloadedmetadata = res;
            video.onerror          = rej;
            setTimeout(rej, 8000, new Error('Camera timed out'));
        });
        await video.play();
    }

    /* ── API call ────────────────────────────────────────────────── */
    async function postMatch(payload) {
        const r = await fetch(wsaFace.apiMatch, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wsaFace.nonce },
            body: JSON.stringify(payload),
        });
        const j = await r.json();
        if (!r.ok && !j.success) throw new Error(j.message || 'Server error ' + r.status);
        return j;
    }

    /* ── Render result into the result card ──────────────────────── */
    function renderResult(res) {
        const staff  = res.staff  || {};
        const action = res.action || '';
        const status = res.status || '';

        const el = (id) => document.getElementById(id);
        const set = (id, val) => { const e = el(id); if (e) e.textContent = val; };

        set('wsaFaceName',       staff.name        || 'Verified');
        set('wsaFaceMeta',       [staff.employee_id, staff.department].filter(Boolean).join(' • '));
        set('wsaFaceAction',     formatAction(action));
        set('wsaFaceConfidence', (res.confidence   || 0) + '%');
        set('wsaFaceTime',       new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));

        // Message box
        const msgEl = el('wsaFaceMessage');
        if (msgEl && res.message) {
            msgEl.textContent = res.message;
            const cls = ['CHECKIN','BREAK_END','CHECKOUT','ALREADY_IN','ALREADY_OUT'].includes(action) ? 'success'
                      : action === 'BREAK_START' ? 'warn' : 'info';
            msgEl.className   = 'wsa-face-msg wsa-msg-' + cls;
            msgEl.style.display = 'block';
        }

        // Status badge
        const badgeEl = el('wsaFaceStatusBadge');
        if (badgeEl) {
            const map = { IN:'Inside', BREAK:'On Break', OUT:'Checked Out', NOT_IN:'Not In' };
            const cls = { IN:'in',     BREAK:'brk',      OUT:'out',         NOT_IN:'none'  };
            badgeEl.textContent = map[status] || status;
            badgeEl.className   = 'wsa-status-badge wsa-sbadge-' + (cls[status] || '');
        }

        // Avatar photo
        if (staff.photo) {
            const avEl = el('wsaFaceAvatar');
            if (avEl) avEl.innerHTML = `<img src="${staff.photo}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:20px;">`;
        }

        // Timeline
        renderTimeline(res.today_log || {});
        updateActionButtons(status);
    }

    function formatAction(a) {
        return { CHECKIN:'✅ Check-In', BREAK_START:'☕ Break Start', BREAK_END:'✅ Break End',
                 CHECKOUT:'🚪 Check-Out', ALREADY_IN:'Already In', ALREADY_OUT:'Complete' }[a] || (a || '—');
    }

    function renderTimeline(log) {
        const el = document.getElementById('wsaFaceTimeline');
        if (!el) return;
        const items = [];
        if (log.login_time)  items.push({ label: 'Check-In',  time: log.login_time,  cls: 'in'  });
        (log.breaks || []).forEach((b, i) => {
            items.push({ label: `Break ${i+1} Start`,                             time: b.break_start, cls: 'brk'     });
            if (b.break_end) items.push({ label: `Break ${i+1} End (${Math.round(b.duration_mins||0)} min)`, time: b.break_end, cls: 'brk-end' });
        });
        if (log.logout_time) items.push({ label: 'Check-Out', time: log.logout_time, cls: 'out' });

        el.innerHTML = items.length
            ? items.map(it => `<div class="wsa-tl-item wsa-tl-${it.cls}"><span class="wsa-tl-dot"></span><span class="wsa-tl-label">${it.label}</span><span class="wsa-tl-time">${fmtTime(it.time)}</span></div>`).join('')
            : '<p class="wsa-tl-empty">Scan your face to see today\'s attendance.</p>';

        const hoursEl = document.getElementById('wsaFaceTotalHours');
        if (hoursEl && log.total_hours != null) {
            const h = Math.floor(log.total_hours), m = Math.round((log.total_hours - h) * 60);
            hoursEl.textContent    = `${h}h ${m}m worked`;
            hoursEl.style.display  = '';
        }
        const brkEl = document.getElementById('wsaFaceBreakDur');
        if (brkEl && log.break_duration_mins) {
            brkEl.textContent   = Math.round(log.break_duration_mins) + ' min break';
            brkEl.style.display = '';
        }
    }

    function fmtTime(dt) {
        if (!dt) return '—';
        try { return new Date(dt.replace(' ','T')).toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' }); }
        catch (_) { return dt.slice(11,16); }
    }

    function updateActionButtons(status) {
        const allowed = { NOT_IN:['checkin'], IN:['break','checkout'], BREAK:['checkout'], OUT:[] };
        document.querySelectorAll('.wsa-action-btn').forEach(btn => {
            const a  = btn.dataset.action;
            const ok = !status || a === 'auto' || (allowed[status] || []).includes(a);
            btn.disabled = !ok;
            btn.classList.toggle('wsa-btn-disabled', !ok);
        });
    }

    /* ── Detection + scan loop ───────────────────────────────────── */
    let loopRunning = false;
    async function loop() {
        if (!modelsReady || !video || !video.videoWidth || !video.videoHeight) {
            requestAnimationFrame(loop);
            return;
        }

        // Cooldown display
        const remain = SCAN_COOLDOWN - (Date.now() - lastMark);
        if (remain > 0) {
            setStatus('⏳ Ready in ' + Math.ceil(remain / 1000) + 's…');
            requestAnimationFrame(loop);
            return;
        }

        if (busy) { requestAnimationFrame(loop); return; }
        busy = true;

        try {
            const vw = video.videoWidth, vh = video.videoHeight;

            // Size canvas to video
            if (canvas.width !== vw || canvas.height !== vh) {
                canvas.width = vw; canvas.height = vh;
            }
            const displaySize = { width: vw, height: vh };
            faceapi.matchDimensions(canvas, displaySize);

            const dets = await faceapi
                .detectAllFaces(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.50 }))
                .withFaceLandmarks()
                .withFaceDescriptors();

            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, vw, vh);

            if (!dets.length) {
                setStatus('📷 No face detected — step closer and face the camera.');
                setQuality(0);
                liveScore = Math.max(0, liveScore - 1);
                busy = false; requestAnimationFrame(loop); return;
            }
            if (dets.length > 1) {
                setStatus('⚠️ Multiple faces detected — one person at a time please.');
                setQuality(0);
                busy = false; requestAnimationFrame(loop); return;
            }

            const resized = faceapi.resizeResults(dets, displaySize);

            // Draw detection boxes
            faceapi.draw.drawDetections(canvas, resized);

            const det = dets[0];
            const q   = calcQuality(det.detection.box, vw, vh);
            setQuality(q);

            const live = checkLiveness(det);

            if (!live) {
                setStatus(`👁 Liveness: blink once or slowly nod… (${liveScore}/${LIVENESS_NEED})`);
                busy = false; requestAnimationFrame(loop); return;
            }
            if (q < 45) {
                setStatus(`📐 Quality ${q}% — move closer or improve lighting.`);
                busy = false; requestAnimationFrame(loop); return;
            }

            setStatus('🔍 Face matched — marking attendance…');
            showSpinner(true);
            lastMark  = Date.now();
            liveScore = 0;

            const res = await postMatch({
                descriptor:      Array.from(det.descriptor),
                quality_score:   q,
                liveness_passed: true,
                action:          currentAction,
                location:        (typeof wsaFace !== 'undefined' ? (wsaFace.location || 'Face Scanner') : 'Face Scanner'),
                device_hash:     navigator.userAgent.slice(0, 200),
            });

            renderResult(res);
            setStatus(res.message || 'Attendance marked.', res.success ? 'success' : 'error');

            // Push to live feed if on dashboard
            if (typeof wsaFaceDash !== 'undefined' && wsaFaceDash.onScan) {
                wsaFaceDash.onScan(res);
            }

        } catch (e) {
            console.error('[WSA Face]', e);
            setStatus('❌ ' + (e.message || 'Scan error — please try again.'), 'error');
        }

        showSpinner(false);
        busy = false;
        requestAnimationFrame(loop);
    }

    /* ── Action buttons ──────────────────────────────────────────── */
    function bindButtons() {
        document.querySelectorAll('.wsa-action-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                currentAction = this.dataset.action;
                document.querySelectorAll('.wsa-action-btn').forEach(b => b.classList.remove('wsa-btn-active'));
                this.classList.add('wsa-btn-active');
                lastMark  = 0;
                liveScore = 0;
                prevBox   = null;
                setStatus('👁 Look at the camera — ' + this.textContent.trim() + ' selected…');
            });
        });
    }

    /* ── Main init ───────────────────────────────────────────────── */
    async function init() {
        // Prevent double-init
        if (loopRunning) return;

        // Only run if the face scanner container exists on this page
        if (!document.getElementById('wsaFaceVideo')) return;

        loopRunning = true;
        refs();
        bindButtons();

        try {
            setStatus('⚙️ Loading Face AI models (first load may take ~10s)…');
            await loadModels();
            modelsReady = true;
            setStatus('📷 Starting camera…');
            await startCamera();
            setStatus('👁 Look at the camera — blink once to verify liveness…');
            requestAnimationFrame(loop);
        } catch (e) {
            setStatus('❌ ' + (e.message || 'Failed to start. Reload and allow camera access.'), 'error');
            console.error('[WSA Face Init]', e);

            // Show a helpful retry button
            const retryBtn = document.getElementById('wsaFaceRetry');
            if (retryBtn) retryBtn.style.display = '';
        }
    }
})();
