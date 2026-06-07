/* Webtrionix Staff Attendance — Employee Attendance Page v4.1
   FIXES:
   - Clock/timer synced to server time (no browser drift)
   - Check-in time shown as real server time
   - Second scan before 7h: blocked with live countdown to unlock
   - Checkout blocked by server until minimum hours met
   - Live elapsed timer uses server timestamp, not browser Date.now()
   - OT shown after 8h worked
   - Dashboard & status live timers update every second
   - Next-day records stored fresh (att_date = today per server)
*/
(function () {
  'use strict';

  var C        = window.wsaAttend || {};
  var nonce    = C.nonce       || '';
  var claimTtl = C.claimTtl   || 180;

  // Server clock offset so client timers match server time exactly
  var serverTimeOffset = 0;
  function nowMs() { return Date.now() + serverTimeOffset; }

  /* ── Detect page type ────────────────────── */
  var app = document.getElementById('wsa-att-app');
  if (!app) return;

  var qrToken     = (app.dataset.qr || C.qrToken || '').trim();
  var isDashboard = C.isDashboard || false;

  /* ── Boot ────────────────────────────────── */
  startClock();

  if (isDashboard)  { bootStatusOnly(); }
  else if (qrToken) { bootAttendancePage(qrToken); }
  else              { show('sc-noqr'); }

  /* ════════════════════════════════════════════
     LIVE CLOCK — uses server-synced time
  ════════════════════════════════════════════ */
  function startClock() {
    var clockEl = document.getElementById('att-clock');
    var dateEl  = document.getElementById('att-date');
    var DAYS    = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    var MONTHS  = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    function tick() {
      var d  = new Date(nowMs());
      var h  = d.getHours(), m = d.getMinutes(), s = d.getSeconds();
      var ap = h >= 12 ? 'PM' : 'AM';
      h = h % 12 || 12;
      if (clockEl) clockEl.textContent = h + ':' + pad(m) + ':' + pad(s) + ' ' + ap;
      if (dateEl)  dateEl.textContent  = DAYS[d.getDay()] + ', ' + d.getDate() + ' ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear();
    }
    tick();
    setInterval(tick, 1000);
  }

  /* ════════════════════════════════════════════
     ATTENDANCE PAGE — QR in URL
  ════════════════════════════════════════════ */
  var claimKey        = null;
  var claimExpireTs   = 0;
  var sessionTimerId  = null;
  var elapsedTimerId  = null;
  var checkinServerTs = 0;   // ms epoch of check-in on server
  var minCheckoutMins = 420; // 7h default, overridden from server

  function bootAttendancePage(token) {
    show('sc-validating');

    apiPost(C.apiClaim, { token: token })
      .then(function(res) {
        if (!res.success) {
          setText('inv-title', res.expired ? '⏱ QR Expired' : '🚫 Invalid QR');
          setText('inv-msg',   res.message || 'This QR code is not valid.');
          show('sc-invalid');
          return;
        }
        claimKey      = res.claim_key;
        claimExpireTs = nowMs() + (res.ttl * 1000);
        startSessionTimer();
        show('sc-login');
        setText('login-gate-name', 'Gate access verified — ' + (res.ttl || 180) + 's to log in');
        var eid = document.getElementById('att-eid');
        if (eid) setTimeout(function(){ eid.focus(); }, 300);
      })
      .catch(function() {
        setText('inv-title', '⚠️ Network Error');
        setText('inv-msg', 'Could not validate QR. Check your connection.');
        show('sc-invalid');
      });

    wireLoginForm();
    on('noqr-status-btn', 'click', showStatusScreen);
  }

  /* ── Session countdown timer ─────────────── */
  function startSessionTimer() {
    var ringEl  = document.getElementById('qv-ring');
    var secsEl  = document.getElementById('qv-secs');
    var circumf = 100.53;

    if (sessionTimerId) clearInterval(sessionTimerId);
    sessionTimerId = setInterval(function() {
      var remaining = Math.max(0, Math.floor((claimExpireTs - nowMs()) / 1000));
      if (secsEl) secsEl.textContent = remaining;
      if (ringEl) {
        var pct    = remaining / claimTtl;
        var offset = circumf * (1 - pct);
        ringEl.style.strokeDashoffset = offset;
        ringEl.style.stroke = remaining > 60 ? '#22D68A' : remaining > 30 ? '#f59e0b' : '#ef4444';
      }
      if (remaining <= 0) {
        clearInterval(sessionTimerId);
        var exp = document.getElementById('session-expired');
        if (exp) exp.style.display = '';
      }
    }, 1000);
  }

  /* ── Wire the login form ─────────────────── */
  function wireLoginForm() {
    var eidInput  = document.getElementById('att-eid');
    var pinInput  = document.getElementById('att-pin');
    var pinEye    = document.getElementById('att-pin-eye');
    var submitBtn = document.getElementById('att-submit-btn');
    var btnLabel  = document.getElementById('att-btn-label');
    var spinEl    = document.getElementById('att-spin');
    var errEl     = document.getElementById('login-err');
    var errMsg    = document.getElementById('login-err-msg');

    if (eidInput) {
      eidInput.addEventListener('input', function() {
        var p = this.selectionStart;
        this.value = this.value.toUpperCase();
        try { this.setSelectionRange(p, p); } catch(e){}
      });
    }
    if (pinInput) {
      pinInput.addEventListener('input', function() { this.value = this.value.replace(/\D/g, ''); });
    }
    if (pinEye && pinInput) {
      pinEye.addEventListener('click', function() {
        pinInput.type = pinInput.type === 'password' ? 'text' : 'password';
      });
    }
    [eidInput, pinInput].forEach(function(el) {
      el && el.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && submitBtn) submitBtn.click();
      });
    });

    submitBtn && submitBtn.addEventListener('click', function() {
      hideEl(errEl);
      var eid = eidInput ? eidInput.value.trim().toUpperCase() : '';
      var pin = pinInput ? pinInput.value.trim()               : '';

      if (!eid) { showErr(errEl, errMsg, 'Please enter your Employee ID.'); return; }
      if (!pin) { showErr(errEl, errMsg, 'Please enter your PIN.'); return; }
      if (!claimKey) { showErr(errEl, errMsg, 'Session lost. Please scan again.'); return; }
      if (nowMs() > claimExpireTs) {
        showErr(errEl, errMsg, 'Session expired. Please scan the QR code again.');
        return;
      }

      submitBtn.disabled   = true;
      if (btnLabel) btnLabel.textContent = 'Marking…';
      if (spinEl)   spinEl.style.display = '';

      apiPost(C.apiAttend, { claim_key: claimKey, employee_id: eid, pin: pin })
        .then(function(res) {
          submitBtn.disabled   = false;
          if (btnLabel) btnLabel.textContent = '✅ Mark Attendance';
          if (spinEl)   spinEl.style.display = 'none';

          if (!res.success) {
            // ── Normal scan cooldown ──
            showErr(errEl, errMsg, res.message || 'Error. Please try again.');
            if (res.cooldown) {
              submitBtn.disabled = true;
              var wait = res.wait_secs || 300;
              var cd = setInterval(function() {
                wait--;
                if (btnLabel) btnLabel.textContent = 'Wait ' + Math.ceil(wait / 60) + ' min…';
                if (wait <= 0) {
                  clearInterval(cd);
                  submitBtn.disabled   = false;
                  if (btnLabel) btnLabel.textContent = '✅ Mark Attendance';
                }
              }, 1000);
            }
            return;
          }

          // ── Success: sync server time offset ──
          // server_timestamp is inside res.data for the attend API
          if (res.data && res.data.server_timestamp) {
            serverTimeOffset = res.data.server_timestamp - Date.now();
          }
          if (res.data && res.data.min_checkout_mins) {
            minCheckoutMins = parseInt(res.data.min_checkout_mins, 10) || 420;
          }

          claimKey = null;
          clearInterval(sessionTimerId);
          showResult(res);
        })
        .catch(function() {
          submitBtn.disabled   = false;
          if (btnLabel) btnLabel.textContent = '✅ Mark Attendance';
          if (spinEl)   spinEl.style.display = 'none';
          showErr(errEl, errMsg, 'Network error. Check your connection and try again.');
        });
    });
  }

  /* ── Early-exit live countdown on button ── */
  function startEarlyExitCountdown(errEl, errMsg, unlockAtMs, submitBtn, btnLabel) {
    showErr(errEl, errMsg, '');
    submitBtn.disabled = true;

    function tick() {
      var rem = Math.max(0, Math.floor((unlockAtMs - nowMs()) / 1000));
      var h = Math.floor(rem / 3600), m = Math.floor((rem % 3600) / 60), s = rem % 60;
      var timeStr = (h > 0 ? h + 'h ' : '') + m + 'm ' + pad(s) + 's';
      if (errMsg) errMsg.textContent = '⏳ Minimum shift not complete. Checkout unlocks in ' + timeStr;
      if (btnLabel) btnLabel.textContent = '🔒 ' + timeStr + ' remaining';
      if (rem <= 0) {
        clearInterval(t);
        submitBtn.disabled   = false;
        if (btnLabel) btnLabel.textContent = '✅ Mark Attendance';
        if (errMsg)   errMsg.textContent   = '';
        hideEl(errEl);
      }
    }
    tick();
    var t = setInterval(tick, 1000);
  }

  /* ── Show attendance result ──────────────── */
  function showResult(res) {
    var d      = res.data  || {};
    var action = res.action; // 'IN', 'OUT', 'BREAK_START', 'BREAK_END'
    var isIn   = action === 'IN';
    var isBreakStart = action === 'BREAK_START';
    var isBreakEnd   = action === 'BREAK_END';
    var isOut  = action === 'OUT';

    // ── BREAK START screen ──
    if (isBreakStart) {
      showBreakStartScreen(res, d);
      return;
    }
    // ── BREAK END / RESUME screen ──
    if (isBreakEnd) {
      showBreakEndScreen(res, d);
      return;
    }

    // ── CHECK IN / CHECK OUT ──
    var card = document.getElementById('res-card');
    if (card) {
      card.className = 'wsa-res-card ' + (isIn ? (res.is_late ? 'wsa-res-late' : 'wsa-res-in') : 'wsa-res-out');
    }

    setText('res-emoji',  isIn ? (res.is_late ? '⚠️' : '✅') : '🏁');
    setText('res-action', isIn ? (res.is_late ? 'LATE CHECK-IN' : 'CHECKED IN') : 'CHECKED OUT');
    setText('res-name',   d.name || '--');
    setText('res-dept',   [d.department, d.shift].filter(Boolean).join(' · '));

    var displayTime = isIn ? d.login_time : d.logout_time;
    setText('res-time', fmtDT(displayTime));
    setText('res-date', fmtDate(displayTime));
    setText('res-note', res.message || '');

    var statsEl   = document.getElementById('res-stats');
    var elapsedEl = document.getElementById('res-elapsed');
    var elapsedVal= document.getElementById('res-elapsed-val');

    if (isIn) {
      // FIXED: use login_ts_ms (when employee actually checked in), NOT server_timestamp (current time)
      checkinServerTs = d.login_ts_ms || loginTs(d.login_time);
      totalBreakMs    = 0; // fresh check-in, no breaks yet

      if (statsEl)   statsEl.style.display   = 'none';
      if (elapsedEl) elapsedEl.style.display = '';

      if (elapsedTimerId) clearInterval(elapsedTimerId);
      function tickElapsed() {
        var elapsed = Math.max(0, Math.floor((nowMs() - checkinServerTs) / 1000));
        var worked  = Math.max(0, elapsed - Math.floor(totalBreakMs / 1000));
        if (elapsedVal) elapsedVal.textContent = formatSecs(worked);
        var workedMins  = worked / 60;
        // FIXED: use std_mins (overtime threshold) directly; fallback to minCheckout+60 (≈8h)
        var otStartMins = d.std_mins || (minCheckoutMins + 60);
        var otNoticeEl  = document.getElementById('res-ot-notice');
        if (otNoticeEl) {
          if (workedMins >= otStartMins) {
            var otMin = Math.floor(workedMins - otStartMins);
            otNoticeEl.textContent = '⚡ Overtime: ' + Math.floor(otMin / 60) + 'h ' + (otMin % 60) + 'm';
            otNoticeEl.style.display = '';
          } else {
            otNoticeEl.style.display = 'none';
          }
        }
      }
      tickElapsed();
      elapsedTimerId = setInterval(tickElapsed, 1000);

      var checkoutInfoEl = document.getElementById('res-checkout-info');
      if (checkoutInfoEl) checkoutInfoEl.style.display = '';
      startCheckoutCountdown(checkinServerTs, minCheckoutMins);

    } else {
      if (elapsedEl) elapsedEl.style.display = 'none';
      if (statsEl)   statsEl.style.display   = '';
      setText('rs-in-time',  fmtDT(d.login_time));
      setText('rs-out-time', fmtDT(d.logout_time));
      setText('rs-hours',    d.hours_display || fmtHrs(d.total_hours));
      var breakMins = d.break_duration_mins || 0;
      var bWrap = document.getElementById('rs-break-wrap');
      if (breakMins > 0) {
        var bh = Math.floor(breakMins/60), bm = Math.round(breakMins%60);
        setText('rs-break', (bh>0?bh+'h ':'')+bm+'m break');
        if (bWrap) bWrap.style.display = '';
      } else {
        if (bWrap) bWrap.style.display = 'none';
      }
      var otWrap = document.getElementById('rs-ot-wrap');
      if (d.overtime_hours > 0) {
        setText('rs-ot', '+' + fmtHrs(d.overtime_hours));
        if (otWrap) otWrap.style.display = '';
      } else {
        if (otWrap) otWrap.style.display = 'none';
      }
    }

    var burst = document.getElementById('res-burst');
    if (burst) { burst.style.display = ''; setTimeout(function(){ burst.style.display = 'none'; }, 1500); }

    show('sc-result');
    on('res-status-btn', 'click', showStatusScreen);
  }

  /* ── Break Start screen ──────────────────── */
  var breakStartTs = 0;
  var breakTimerId = null;
  var totalBreakMs = 0;

  function showBreakStartScreen(res, d) {
    breakStartTs = d.break_start_ts_ms || d.server_ts_ms || nowMs();

    var html = '<div class="wsa-res-card wsa-res-break" id="res-card">';
    html += '<div id="res-burst" style="display:none">🎉</div>';
    html += '<div class="wsa-res-emoji">☕</div>';
    html += '<div class="wsa-res-action">ON BREAK</div>';
    html += '<div class="wsa-res-name" id="bk-name">' + esc(d.name || '--') + '</div>';
    html += '<div class="wsa-res-dept">' + esc([d.department].filter(Boolean).join(' · ')) + '</div>';
    html += '<div class="wsa-res-time" id="bk-time">' + fmtDT(d.break_start) + '</div>';
    html += '<div class="wsa-res-note">' + esc(res.message || '') + '</div>';
    html += '<div class="wsa-break-info">';
    html += '  <div class="wsa-break-row"><span>✅ Worked so far</span><strong>' + esc(d.hours_worked_display || '--') + '</strong></div>';
    html += '  <div class="wsa-break-row"><span>⏳ Checkout unlocks after</span><strong>' + esc(d.remaining_display || '--') + ' more work</strong></div>';
    html += '  <div class="wsa-break-row"><span>⏱ Break duration</span><strong><span id="bk-dur">00:00</span></strong></div>';
    html += '</div>';
    html += '<div class="wsa-break-notice">Scan QR again to resume work</div>';
    html += '<button class="wsa-att-btn wsa-att-btn--ghost" id="res-status-btn">📊 My Status</button>';
    html += '</div>';

    var app2 = document.getElementById('wsa-att-app');
    if (app2) {
      ['sc-validating','sc-invalid','sc-noqr','sc-login','sc-result','sc-status'].forEach(function(s) {
        var el = document.getElementById(s);
        if (el) el.style.display = 'none';
      });
      var breakDiv = document.getElementById('sc-break');
      if (!breakDiv) {
        breakDiv = document.createElement('div');
        breakDiv.id = 'sc-break';
        app2.appendChild(breakDiv);
      }
      breakDiv.innerHTML = html;
      breakDiv.style.display = '';
    }

    // Tick break duration
    if (breakTimerId) clearInterval(breakTimerId);
    breakTimerId = setInterval(function() {
      var secs = Math.floor((nowMs() - breakStartTs) / 1000);
      var durEl = document.getElementById('bk-dur');
      if (durEl) durEl.textContent = pad(Math.floor(secs/60)) + ':' + pad(secs%60);
    }, 1000);

    on('res-status-btn', 'click', showStatusScreen);
  }

  /* ── Break End / Resume screen ──────────── */
  function showBreakEndScreen(res, d) {
    if (breakTimerId) { clearInterval(breakTimerId); breakTimerId = null; }
    totalBreakMs = (d.total_break_mins || 0) * 60 * 1000;
    checkinServerTs = d.login_ts_ms || loginTs(d.login_time);
    minCheckoutMins = d.min_checkout_mins || minCheckoutMins;

    var card = document.getElementById('res-card');
    if (card) card.className = 'wsa-res-card wsa-res-in';

    // Remove break screen if shown
    var breakDiv = document.getElementById('sc-break');
    if (breakDiv) breakDiv.style.display = 'none';

    var html = '<div class="wsa-res-card wsa-res-in" id="res-card">';
    html += '<div id="res-burst" style="display:none">🎉</div>';
    html += '<div class="wsa-res-emoji">▶️</div>';
    html += '<div class="wsa-res-action">WORK RESUMED</div>';
    html += '<div class="wsa-res-name">' + esc(d.name || '--') + '</div>';
    html += '<div class="wsa-res-dept">' + esc([d.department].filter(Boolean).join(' · ')) + '</div>';
    html += '<div class="wsa-res-time">' + fmtDT(d.login_time) + '</div>';
    html += '<div class="wsa-res-note">' + esc(res.message || '') + '</div>';
    html += '<div class="wsa-break-info">';
    html += '  <div class="wsa-break-row"><span>✅ Worked so far</span><strong>' + esc(d.hours_worked_display || '--') + '</strong></div>';
    var bh = Math.floor((d.total_break_mins||0)/60), bm = Math.round((d.total_break_mins||0)%60);
    html += '  <div class="wsa-break-row"><span>☕ Total break time</span><strong>' + (bh>0?bh+'h ':'')+bm+'m</strong></div>';
    html += '</div>';
    html += '<div class="wsa-res-elapsed"><span>⏱ Live worked:</span><span id="res-elapsed-val" class="wsa-live-val">--:--:--</span></div>';
    html += '<div class="wsa-res-checkout-info" id="res-checkout-info"></div>';
    html += '<button class="wsa-att-btn wsa-att-btn--ghost" id="res-status-btn">📊 My Status</button>';
    html += '</div>';

    var app2 = document.getElementById('wsa-att-app');
    if (app2) {
      ['sc-validating','sc-invalid','sc-noqr','sc-login','sc-result','sc-status','sc-break'].forEach(function(s) {
        var el = document.getElementById(s);
        if (el) el.style.display = 'none';
      });
      var resumeDiv = document.getElementById('sc-resume');
      if (!resumeDiv) {
        resumeDiv = document.createElement('div');
        resumeDiv.id = 'sc-resume';
        app2.appendChild(resumeDiv);
      }
      resumeDiv.innerHTML = html;
      resumeDiv.style.display = '';
    }

    // Start live elapsed timer
    if (elapsedTimerId) clearInterval(elapsedTimerId);
    elapsedTimerId = setInterval(function() {
      var elapsed  = Math.max(0, Math.floor((nowMs() - checkinServerTs) / 1000));
      var worked   = Math.max(0, elapsed - Math.floor(totalBreakMs / 1000));
      var elVal    = document.getElementById('res-elapsed-val');
      if (elVal) elVal.textContent = formatSecs(worked);
    }, 1000);

    startCheckoutCountdown(checkinServerTs, d.min_checkout_mins || minCheckoutMins, totalBreakMs);
    on('res-status-btn', 'click', showStatusScreen);
  }

  /* Show checkout unlock countdown on result screen */
  function startCheckoutCountdown(checkinMs, minMins, knownBreakMs) {
    var infoEl = document.getElementById('res-checkout-info');
    if (!infoEl) return;
    var breakMs = knownBreakMs || totalBreakMs || 0;

    function tick() {
      var elapsed  = Math.max(0, nowMs() - checkinMs);
      var worked   = Math.max(0, elapsed - breakMs);
      var needed   = minMins * 60 * 1000;
      var rem      = Math.max(0, Math.floor((needed - worked) / 1000));
      if (rem <= 0) {
        clearInterval(t);
        infoEl.innerHTML = '✅ <strong>Checkout available</strong> — scan QR to check out.';
        infoEl.className = infoEl.className.replace('wsa-checkout-locked','') + ' wsa-checkout-unlocked';
        return;
      }
      var h = Math.floor(rem / 3600), m = Math.floor((rem % 3600) / 60), s = rem % 60;
      infoEl.textContent = '🔒 Checkout unlocks in ' + (h > 0 ? h + 'h ' : '') + m + 'm ' + pad(s) + 's';
    }
    tick();
    var t = setInterval(tick, 1000);
  }

  /* ════════════════════════════════════════════
     STATUS / HISTORY SCREEN
  ════════════════════════════════════════════ */
  function showStatusScreen() {
    show('sc-status');
    var authForm = document.getElementById('st-auth-form');
    var dataDiv  = document.getElementById('st-data');
    if (authForm) authForm.style.display = '';
    if (dataDiv)  dataDiv.style.display  = 'none';
    wireStatusForm();
  }

  var statusWired = false;
  function wireStatusForm() {
    if (statusWired) return;
    statusWired = true;

    var stEid  = document.getElementById('st-eid');
    var stPin  = document.getElementById('st-pin');
    var stErr  = document.getElementById('st-err');
    var stErrM = document.getElementById('st-err-msg');
    var stBtn  = document.getElementById('st-check-btn');
    var stBack = document.getElementById('st-back-btn');

    if (stEid) {
      stEid.addEventListener('input', function() {
        var p = this.selectionStart;
        this.value = this.value.toUpperCase();
        try { this.setSelectionRange(p, p); } catch(e){}
      });
    }

    stBtn && stBtn.addEventListener('click', function() {
      hideEl(stErr);
      var eid = stEid ? stEid.value.trim().toUpperCase() : '';
      var pin = stPin ? stPin.value.trim()               : '';
      if (!eid || !pin) { showErr(stErr, stErrM, 'Enter your ID and PIN.'); return; }

      stBtn.textContent = 'Loading…';
      stBtn.disabled    = true;

      apiPost(C.apiStatus, { employee_id: eid, pin: pin })
        .then(function(res) {
          stBtn.textContent = '🔍 Check My Status';
          stBtn.disabled    = false;
          if (!res.success) { showErr(stErr, stErrM, res.message || 'Invalid credentials.'); return; }
          // Sync server time
          if (res.server_timestamp) {
            serverTimeOffset = res.server_timestamp - Date.now();
          }
          renderStatus(res);
        })
        .catch(function() {
          stBtn.textContent = '🔍 Check My Status';
          stBtn.disabled    = false;
          showErr(stErr, stErrM, 'Network error. Try again.');
        });
    });

    stBack && stBack.addEventListener('click', function() {
      if (qrToken) show('sc-login');
      else         show('sc-noqr');
    });
  }

  function renderStatus(res) {
    var t        = res.today;
    var s        = res.staff || {};
    var minMins  = res.min_checkout_mins || 420;
    var authForm = document.getElementById('st-auth-form');
    var dataDiv  = document.getElementById('st-data');
    if (authForm) authForm.style.display = 'none';

    var html = '';

    // Profile
    html += '<div class="wsa-st-profile">';
    html += '<div class="wsa-st-av">' + (s.name || '?').substring(0, 2).toUpperCase() + '</div>';
    html += '<div><div class="wsa-st-name">' + esc(s.name || '--') + '</div>';
    html += '<div class="wsa-st-sub">' + esc(s.emp_id || '') + (s.department ? ' · ' + esc(s.department) : '') + '</div>';
    if (s.shift) html += '<div class="wsa-st-sub">🕘 ' + esc(s.shift) + '</div>';
    html += '</div></div>';

    // Status strip
    var st = !t ? 'ns' : (t.status === 'IN' ? 'in' : t.status === 'BREAK' ? 'break' : t.status === 'OUT' ? 'out' : 'ns');
    var stLabel = { ns: '⬜ Not scanned today', in: '🟢 Currently Inside', break: '☕ On Break', out: '🔵 Shift Complete' }[st];
    html += '<div class="wsa-st-strip wsa-st-strip--' + st + '">' + stLabel + '</div>';

    if (t) {
      var loginMs    = t.login_ts_ms || t.login_timestamp || loginTs(t.login_time);
      var breakMs    = (t.break_duration_mins || 0) * 60 * 1000;
      var isActive   = t.status === 'IN' || t.status === 'BREAK';
      var isOnBreak  = t.status === 'BREAK';
      var minWork    = t.min_checkout_mins || minCheckoutMins || 420;
      var workedFmt  = t.worked_mins_so_far ? fmtHrs(t.worked_mins_so_far / 60) : '—';

      html += '<div class="wsa-st-rows">';
      html += stRow('Check-IN',  t.login_time  ? fmtDT(t.login_time)  : '—', t.is_late ? 'red' : 'green');
      html += stRow('Check-OUT', t.logout_time ? fmtDT(t.logout_time) : (isActive ? '<em>Still working</em>' : '—'), '');
      if (t.break_duration_mins > 0) {
        var bh = Math.floor(t.break_duration_mins/60), bm2 = Math.round(t.break_duration_mins%60);
        html += stRow('Break Time', (bh>0?bh+'h ':'')+(bm2)+'m', '');
      }
      html += stRow('Worked Hours', isActive
        ? '<span id="st-live-hrs">'+workedFmt+'</span>'
        : fmtHrs(t.total_hours), '');
      html += stRow('Overtime', t.overtime_hours > 0 ? '+' + fmtHrs(t.overtime_hours) : '—', t.overtime_hours > 0 ? 'ot' : '');
      html += '</div>';

      if (isOnBreak) {
        var bStartMs = t.break_start_ts_ms || loginTs(t.break_start);
        html += '<div class="wsa-st-break-active">';
        html += '<div class="wsa-st-live-label">☕ On Break — Duration</div>';
        html += '<div class="wsa-st-live-val" id="st-break-timer">00:00</div>';
        html += '</div>';
        html += '<div class="wsa-break-notice">Scan QR again to resume work</div>';
        html += '<div class="wsa-st-checkout-info" id="st-checkout-info">Working…</div>';
        // We'll start break timer below
        setTimeout(function() {
          var bt = setInterval(function() {
            var s2 = Math.floor((nowMs() - bStartMs) / 1000);
            var bEl = document.getElementById('st-break-timer');
            if (bEl) bEl.textContent = pad(Math.floor(s2/60)) + ':' + pad(s2%60);
          }, 1000);
        }, 0);
      } else if (t.status === 'IN') {
        html += '<div class="wsa-st-live">';
        html += '<div class="wsa-st-live-label">⏱ Live Worked Time</div>';
        html += '<div class="wsa-st-live-val" id="st-live-timer">00:00:00</div>';
        html += '</div>';
        html += '<div class="wsa-st-checkout-info" id="st-checkout-info">Loading…</div>';
        html += '<div class="wsa-st-ot-notice" id="st-ot-notice" style="display:none"></div>';
      }
    } else {
      html += '<div class="wsa-empty-msg">No attendance record for today.</div>';
    }

    html += '<button class="wsa-att-btn wsa-att-btn--ghost" id="st-reset-btn" style="margin-top:16px">← Back</button>';

    if (dataDiv) { dataDiv.innerHTML = html; dataDiv.style.display = ''; }

    var resetBtn = document.getElementById('st-reset-btn');
    if (resetBtn) {
      resetBtn.addEventListener('click', function() {
        if (dataDiv)  dataDiv.style.display  = 'none';
        if (authForm) authForm.style.display = '';
        statusWired = false;
      });
    }

    // Start all live timers at 1-second precision
    if (t && (t.status === 'IN' || t.status === 'BREAK')) {
      var loginMs2  = t.login_ts_ms || t.login_timestamp || loginTs(t.login_time);
      var bkMs2     = (t.break_duration_mins || 0) * 60 * 1000;
      var minWork2  = (t.min_checkout_mins || 420);

      setInterval(function() {
        var now2    = nowMs();
        var elapsed = Math.max(0, now2 - loginMs2);
        // Add ongoing break time if on break
        var extraBreak = 0;
        if (t.status === 'BREAK' && t.break_start_ts_ms) {
          extraBreak = Math.max(0, now2 - t.break_start_ts_ms);
        }
        var worked  = Math.max(0, elapsed - bkMs2 - extraBreak);
        var workedS = Math.floor(worked / 1000);

        // Live timer HH:MM:SS (worked time)
        var timerEl = document.getElementById('st-live-timer');
        if (timerEl) timerEl.textContent = formatSecs(workedS);

        // Live hours in row
        var hrsEl = document.getElementById('st-live-hrs');
        if (hrsEl) hrsEl.textContent = formatSecs(workedS);

        // Checkout unlock countdown
        var infoEl = document.getElementById('st-checkout-info');
        if (infoEl) {
          var needed = minWork2 * 60;
          var rem    = Math.max(0, needed - workedS);
          if (rem <= 0) {
            infoEl.innerHTML = '✅ <strong>Checkout available</strong> — scan QR to check out.';
          } else {
            var h3 = Math.floor(rem/3600), m3 = Math.floor((rem%3600)/60), s3 = rem%60;
            infoEl.textContent = '🔒 Checkout unlocks in ' + (h3>0?h3+'h ':'')+m3+'m '+pad(s3)+'s';
          }
        }

        // OT notice
        var otStartS = minWork2 * 60;
        if (workedS >= otStartS) {
          var otEl = document.getElementById('st-ot-notice');
          if (otEl) {
            var otSecs2 = workedS - otStartS;
            var otH2 = Math.floor(otSecs2/3600), otM2 = Math.floor((otSecs2%3600)/60);
            otEl.textContent = '⚡ Overtime: ' + (otH2>0?otH2+'h ':'')+otM2+'m';
            otEl.style.display = '';
          }
        }
      }, 1000);
    }
  }

  function bootStatusOnly() {
    show('sc-status');
    wireStatusForm();
  }

  /* ════════════════════════════════════════════
     UTILITIES
  ════════════════════════════════════════════ */
  function show(id) {
    // Include dynamic screens sc-break and sc-resume so they're hidden when switching
    ['sc-validating','sc-invalid','sc-noqr','sc-login','sc-result','sc-status','sc-break','sc-resume'].forEach(function(s) {
      var el = document.getElementById(s);
      if (el) el.style.display = s === id ? '' : 'none';
    });
  }

  function apiPost(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
      body: JSON.stringify(body),
    }).then(function(r) { return r.json(); });
  }

  function on(id, ev, fn)   { var el = document.getElementById(id); if (el) el.addEventListener(ev, fn); }
  function setText(id, val) { var el = document.getElementById(id); if (el) el.innerHTML = val; }
  function hideEl(el)       { if (el) el.style.display = 'none'; }
  function showErr(el, msgEl, msg) { if (msgEl) msgEl.textContent = msg; if (el) el.style.display = 'flex'; }
  function pad(n)           { return n < 10 ? '0' + n : '' + n; }
  function esc(s)           { return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  function formatSecs(s) {
    if (s < 0) s = 0;
    return pad(Math.floor(s / 3600)) + ':' + pad(Math.floor((s % 3600) / 60)) + ':' + pad(s % 60);
  }
  function fmtDT(dt) {
    if (!dt) return '—';
    var d = new Date((dt + '').replace(' ', 'T'));
    if (isNaN(d)) return dt;
    var h = d.getHours(), m = d.getMinutes(), s = d.getSeconds(), ap = h >= 12 ? 'PM' : 'AM';
    return (h % 12 || 12) + ':' + pad(m) + ':' + pad(s) + ' ' + ap;
  }
  function fmtDate(dt) {
    if (!dt) return '';
    var d = new Date((dt + '').replace(' ', 'T'));
    if (isNaN(d)) return '';
    var DAYS   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return DAYS[d.getDay()] + ', ' + d.getDate() + ' ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear();
  }
  function fmtHrs(h) {
    if (!h || h <= 0) return '—';
    var hr = Math.floor(h), mn = Math.round((h - hr) * 60);
    return hr + 'h ' + mn + 'm';
  }
  function loginTs(dt) {
    if (!dt) return Date.now();
    var d = new Date((dt + '').replace(' ', 'T'));
    return isNaN(d) ? Date.now() : d.getTime();
  }
  function stRow(label, val, cls) {
    return '<div class="wsa-srow"><span class="slabel">' + label + '</span>' +
           '<span class="svalue ' + (cls || '') + '">' + val + '</span></div>';
  }
})();
