/* Webtrionix Staff Attendance — Staff Portal v1.0
   Handles: Login page, Register page, Staff Portal dashboard
   Auth: token stored in localStorage, sent as X-WSA-Token header
*/
(function () {
  'use strict';

  var C           = window.wsaPortal || {};
  var TOKEN_KEY   = 'wsa_staff_token';
  var STAFF_KEY   = 'wsa_staff_info';

  /* ── Helpers ── */
  function getToken()  { try { return localStorage.getItem(TOKEN_KEY) || ''; } catch(e){ return ''; } }
  function setToken(t) { try { localStorage.setItem(TOKEN_KEY, t); } catch(e){} }
  function clearAuth() { try { localStorage.removeItem(TOKEN_KEY); localStorage.removeItem(STAFF_KEY); } catch(e){} }

  function api(method, url, body) {
    var opts = { method: method, headers: { 'Content-Type': 'application/json' } };
    var tok = getToken();
    if (tok) opts.headers['X-WSA-Token'] = tok;
    opts.cache = 'no-store';
    if (body) opts.body = JSON.stringify(body);
    if (method === 'GET') {
      url += (url.indexOf('?') === -1 ? '?' : '&') + '_wsa=' + Date.now();
    }
    return fetch(url, opts).then(function(r){ return r.json(); });
  }

  function el(id) { return document.getElementById(id); }
  function hide(id) { var e=el(id); if(e) e.style.display='none'; }
  function show(id, d) { var e=el(id); if(e) e.style.display=(d||''); }
  function setText(id, v) { var e=el(id); if(e) e.textContent=v; }
  function setHtml(id, v) { var e=el(id); if(e) e.innerHTML=v; }
  function pad(n) { return n<10?'0'+n:''+n; }

  function showAlert(id, msg, isErr) {
    var e=el(id);
    if(!e) return;
    e.textContent = msg;
    e.className   = 'wsa-auth-alert ' + (isErr ? 'wsa-auth-alert--err' : 'wsa-auth-alert--ok');
    e.style.display = '';
  }

  function fmtMs(ms) {
    var s=Math.floor(ms/1000), h=Math.floor(s/3600), m=Math.floor((s%3600)/60), sc=s%60;
    return pad(h)+':'+pad(m)+':'+pad(sc);
  }
  function fmtMins(mins) {
    mins=Math.round(mins||0); var h=Math.floor(mins/60), m=mins%60;
    return h>0 ? h+'h '+m+'m' : m+'m';
  }
  function fmtMoney(n, cur) {
    if(!n) return '—';
    return (cur||'₹') + Number(n).toLocaleString('en-IN', {minimumFractionDigits:0, maximumFractionDigits:0});
  }
  function fmtTime(dt) {
    if(!dt) return '—';
    var d=new Date((dt+'').replace(' ','T'));
    if(isNaN(d)) return dt;
    var h=d.getHours(), m=d.getMinutes(), ap=h>=12?'PM':'AM';
    return (h%12||12)+':'+pad(m)+' '+ap;
  }

  /* ════════════════════════════════
     PIN EYE TOGGLE (shared)
  ════════════════════════════════ */
  function wireEye(inputId, eyeId) {
    var inp=el(inputId), eye=el(eyeId);
    if(inp && eye) eye.addEventListener('click', function(){
      inp.type = inp.type==='password' ? 'text' : 'password';
    });
  }

  /* ════════════════════════════════
     LOGIN PAGE
  ════════════════════════════════ */
  function bootLogin() {
    // If already logged in, go to portal
    if (getToken()) {
      api('GET', C.apiMe).then(function(res){
        if (res.success) { window.location.href = C.portalUrl; }
      }).catch(function(){});
    }

    wireEye('login-pin', 'login-pin-eye');

    var eidEl  = el('login-eid');
    var pinEl  = el('login-pin');
    var btnEl  = el('login-btn');
    var lblEl  = el('login-btn-label');
    var spinEl = el('login-spin');

    if (eidEl) eidEl.addEventListener('input', function(){
      var p=this.selectionStart; this.value=this.value.toUpperCase();
      try{ this.setSelectionRange(p,p); }catch(e){}
    });
    if (pinEl) pinEl.addEventListener('input', function(){
      this.value=this.value.replace(/\D/g,'');
    });

    [eidEl, pinEl].forEach(function(e){
      e && e.addEventListener('keydown', function(ev){ if(ev.key==='Enter' && btnEl) btnEl.click(); });
    });

    var gotoReg = el('goto-register');
    if (gotoReg) gotoReg.addEventListener('click', function(e){
      e.preventDefault(); window.location.href = C.registerUrl;
    });

    if (!btnEl) return;
    btnEl.addEventListener('click', function(){
      var eid = eidEl ? eidEl.value.trim().toUpperCase() : '';
      var pin = pinEl ? pinEl.value.trim() : '';
      if (!eid) { showAlert('login-alert','Please enter your Employee ID.', true); return; }
      if (!pin) { showAlert('login-alert','Please enter your PIN.', true); return; }

      hide('login-alert');
      btnEl.disabled=true; if(lblEl) lblEl.textContent='Logging in…'; if(spinEl) show('login-spin');

      api('POST', C.apiLogin, { employee_id: eid, pin: pin })
        .then(function(res){
          btnEl.disabled=false; if(lblEl) lblEl.textContent='🔐 Login'; if(spinEl) hide('login-spin');
          if (!res.success) { showAlert('login-alert', res.message||'Login failed.', true); return; }
          setToken(res.token);
          try { localStorage.setItem(STAFF_KEY, JSON.stringify(res.staff)); } catch(e){}
          window.location.href = C.portalUrl;
        })
        .catch(function(){ btnEl.disabled=false; if(lblEl) lblEl.textContent='🔐 Login'; if(spinEl) hide('login-spin'); showAlert('login-alert','Network error. Try again.', true); });
    });
  }

  /* ════════════════════════════════
     REGISTER PAGE
  ════════════════════════════════ */
  function bootRegister() {
    wireEye('reg-pin', 'reg-pin-eye');

    var eidEl  = el('reg-eid');
    var btnEl  = el('reg-btn');
    var lblEl  = el('reg-btn-label');
    var spinEl = el('reg-spin');
    var gotoL  = el('goto-login');
    var gotoL2 = el('goto-login-after-reg');

    if (eidEl) eidEl.addEventListener('input', function(){
      var p=this.selectionStart; this.value=this.value.toUpperCase();
      try{ this.setSelectionRange(p,p); }catch(e){}
    });
    if (gotoL)  gotoL.addEventListener('click',  function(e){ e.preventDefault(); window.location.href=C.loginUrl; });
    if (gotoL2) gotoL2.addEventListener('click', function(e){ e.preventDefault(); window.location.href=C.loginUrl; });

    if (!btnEl) return;
    btnEl.addEventListener('click', function(){
      var data = {
        name:        (el('reg-name')  ? el('reg-name').value.trim()  : ''),
        employee_id: (el('reg-eid')   ? el('reg-eid').value.trim().toUpperCase() : ''),
        department:  (el('reg-dept')  ? el('reg-dept').value.trim()  : ''),
        phone:       (el('reg-phone') ? el('reg-phone').value.trim() : ''),
        email:       (el('reg-email') ? el('reg-email').value.trim() : ''),
        pin:         (el('reg-pin')   ? el('reg-pin').value.trim()   : ''),
        pin_confirm: (el('reg-pin2')  ? el('reg-pin2').value.trim()  : ''),
      };
      if (!data.name)        { showAlert('reg-alert','Full name is required.', true); return; }
      if (!data.employee_id) { showAlert('reg-alert','Employee ID is required.', true); return; }
      if (!data.pin)         { showAlert('reg-alert','PIN is required.', true); return; }
      if (data.pin.length < 4) { showAlert('reg-alert','PIN must be at least 4 digits.', true); return; }
      if (data.pin !== data.pin_confirm) { showAlert('reg-alert','PINs do not match.', true); return; }

      hide('reg-alert');
      btnEl.disabled=true; if(lblEl) lblEl.textContent='Submitting…'; if(spinEl) show('reg-spin');

      api('POST', C.apiRegister, data)
        .then(function(res){
          btnEl.disabled=false; if(lblEl) lblEl.textContent='📝 Submit Registration'; if(spinEl) hide('reg-spin');
          if (!res.success) { showAlert('reg-alert', res.message||'Registration failed.', true); return; }
          hide('reg-form-wrap');
          show('reg-success');
        })
        .catch(function(){ btnEl.disabled=false; if(lblEl) lblEl.textContent='📝 Submit Registration'; if(spinEl) hide('reg-spin'); showAlert('reg-alert','Network error. Try again.', true); });
    });
  }

  /* ════════════════════════════════
     STAFF PORTAL DASHBOARD
  ════════════════════════════════ */
  var serverOffset   = 0;
  var loginTsMs      = 0;
  var breakStartTsMs = 0;
  var breakDurMs     = 0;
  var minCheckoutMs  = (C.minCheckoutMins || 420) * 60 * 1000;
  var timerInterval  = null;
  var refreshInterval = null;
  var portalData     = null;

  function nowMs() { return Date.now() + serverOffset; }

  function bootPortal() {
    var tok = getToken();
    if (!tok) {
      // No session at all — auto-redirect to login page
      if (C.loginUrl) { window.location.href = C.loginUrl; return; }
      showAuthPrompt('Please login to view your dashboard.');
      return;
    }

    // Verify session
    api('GET', C.apiMe)
      .then(function(res){
        if (!res.success) {
          clearAuth();
          if (C.loginUrl) { window.location.href = C.loginUrl; return; }
          showAuthPrompt('Your session has expired. Please login again.');
          return;
        }
        if (res.server_ts_ms) serverOffset = res.server_ts_ms - Date.now();
        startClock();
        loadDashboard();
        if (refreshInterval) clearInterval(refreshInterval);
        refreshInterval = setInterval(function(){
          if (document.visibilityState !== 'hidden') loadDashboard(true);
        }, 15000);
      })
      .catch(function(){ showAuthPrompt(); });

    // Logout button
    var logoutBtn = el('portal-logout-btn');
    if (logoutBtn) logoutBtn.addEventListener('click', function(){
      api('POST', C.apiLogout).catch(function(){});
      clearAuth();
      window.location.href = C.loginUrl;
    });

    var gotoLogin = el('portal-goto-login');
    if (gotoLogin) gotoLogin.addEventListener('click', function(e){ e.preventDefault(); window.location.href=C.loginUrl; });
  }

  function showAuthPrompt(msg) {
    hide('portal-loading');
    hide('portal-content');
    var titleEl = el('portal-auth-title');
    var msgEl   = el('portal-auth-msg');
    if (titleEl) titleEl.textContent = msg ? 'Session Expired' : 'Please Login';
    if (msgEl)   msgEl.textContent   = msg || 'Log in to view your attendance dashboard.';
    show('portal-auth-prompt');
  }

  function startClock() {
    var clockEl = el('p-clock');
    var dateEl  = el('p-date');
    var DAYS  = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    var MONTHS= ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    function tick(){
      var d=new Date(nowMs()), h=d.getHours(), m=d.getMinutes(), s=d.getSeconds(), ap=h>=12?'PM':'AM';
      if(clockEl) clockEl.textContent=(h%12||12)+':'+pad(m)+':'+pad(s)+' '+ap;
      if(dateEl)  dateEl.textContent=DAYS[d.getDay()]+', '+d.getDate()+' '+MONTHS[d.getMonth()]+' '+d.getFullYear();
    }
    tick(); setInterval(tick, 1000);
  }

  function loadDashboard(silent) {
    api('GET', C.apiDashboard)
      .then(function(res){
        hide('portal-loading');
        if (!res.success) { showAuthPrompt(); return; }
        if (res.server_ts_ms) serverOffset = res.server_ts_ms - Date.now();
        portalData = res;
        renderDashboard(res);
        show('portal-content');
      })
      .catch(function(){ hide('portal-loading'); showAuthPrompt(); });
  }

  function renderDashboard(res) {
    var s = res.staff || {};
    var t = res.today;
    var mo = res.month;

    // Header
    setText('p-name', s.name || '—');
    setText('p-meta', [s.employee_id, s.department, s.shift].filter(Boolean).join(' · '));

    var initial = (s.name||'S').charAt(0).toUpperCase();
    setText('p-avatar', initial);

    // Shift info
    var shiftInfo = '';
    if (s.shift_start && s.shift_end) {
      shiftInfo = '🕐 Shift: ' + fmtTime('1970-01-01 '+s.shift_start) + ' – ' + fmtTime('1970-01-01 '+s.shift_end);
    }
    setText('p-shift-info', shiftInfo);

    // Today's card
    renderTodayCard(t);

    // Month stats
    if (mo) {
      setText('ps-present', mo.present);
      setText('ps-absent',  mo.absent);
      setText('ps-leave',   mo.on_leave);
      setText('ps-late',    mo.late_count);
      var hh=Math.floor(mo.total_hours), hm=Math.round((mo.total_hours-hh)*60);
      setText('ps-hours', hh+'h '+hm+'m');
      var oh=Math.floor(mo.ot_hours), om=Math.round((mo.ot_hours-oh)*60);
      setText('ps-ot', oh+'h '+om+'m');
    }

    // Salary
    if (res.salary_configured && mo && mo.net > 0) {
      var cur = mo.currency === 'INR' ? '₹' : mo.currency;
      setText('p-salary-month', mo.label);
      setText('ps-gross',  fmtMoney(mo.gross, cur));
      setText('ps-deduct', '−' + fmtMoney(mo.deductions, cur));
      setText('ps-net',    fmtMoney(mo.net, cur));
      show('p-salary-card');
    } else {
      hide('p-salary-card');
    }

    // History
    renderHistory(res.history || []);
  }

  function renderTodayCard(t) {
    var statusBadge = el('p-status-badge');
    var timesEl     = el('p-times');
    var liveEl      = el('p-live-timer');
    var checkoutEl  = el('p-checkout-info');
    var breakInfoEl = el('p-break-info');
    var card        = el('p-today-card');

    if (timerInterval) { clearInterval(timerInterval); timerInterval=null; }
    loginTsMs = 0; breakStartTsMs = 0; breakDurMs = 0;

    if (!t) {
      if (statusBadge) { statusBadge.textContent='ABSENT'; statusBadge.className='wsa-ptc-status-badge wsa-status-absent'; }
      if (card)        card.className='wsa-portal-today-card wsa-today-absent';
      setText('p-live-timer', '—');
      if (checkoutEl) checkoutEl.textContent='';
      if (timesEl)    timesEl.innerHTML='';
      return;
    }

    var status = t.status;
    if (statusBadge) {
      var cls = {IN:'wsa-status-in', OUT:'wsa-status-out', BREAK:'wsa-status-break', ABSENT:'wsa-status-absent'}[status]||'';
      var label = {IN:'✅ WORKING', OUT:'🏁 DONE', BREAK:'☕ ON BREAK', ABSENT:'❌ ABSENT'}[status]||status;
      statusBadge.textContent = label;
      statusBadge.className   = 'wsa-ptc-status-badge ' + cls;
    }
    if (card) card.className='wsa-portal-today-card wsa-today-'+status.toLowerCase();

    // Times
    var timesHtml = '';
    if (t.login_time)  timesHtml += '<span>🟢 In: '+fmtTime(t.login_time)+'</span>';
    if (t.logout_time) timesHtml += '<span>🔴 Out: '+fmtTime(t.logout_time)+'</span>';
    if (timesEl) timesEl.innerHTML = timesHtml;

    if (status === 'OUT') {
      var h=Math.floor(t.total_hours), m=Math.round((t.total_hours-h)*60);
      setText('p-live-timer', h+'h '+m+'m');
      if (checkoutEl) checkoutEl.textContent='';
      if (breakInfoEl) { hide('p-break-info'); }
      return;
    }

    // Live timer setup
    loginTsMs    = t.login_ts_ms || 0;
    breakDurMs   = (t.break_duration_mins||0) * 60 * 1000;
    minCheckoutMs= (C.minCheckoutMins||420)*60*1000;

    if (status === 'BREAK' && t.break_start_ts_ms) {
      breakStartTsMs = t.break_start_ts_ms;
      if (breakInfoEl) {
        show('p-break-info');
      }
    }

    function tickTimer() {
      if (!loginTsMs) return;
      var now = nowMs();
      var totalOngoingBreak = breakDurMs;
      if (breakStartTsMs) totalOngoingBreak += Math.max(0, now - breakStartTsMs);
      var worked = Math.max(0, now - loginTsMs - totalOngoingBreak);
      if (liveEl) liveEl.textContent = fmtMs(worked);

      // Checkout unlock info
      if (checkoutEl && status !== 'BREAK') {
        var needed = minCheckoutMs;
        var rem    = Math.max(0, Math.floor((needed - worked)/1000));
        if (rem <= 0) {
          checkoutEl.textContent = '✅ Checkout available — scan QR to check out.';
          checkoutEl.className   = 'wsa-ptc-checkout-info wsa-checkout-ok';
        } else {
          var ch=Math.floor(rem/3600), cm=Math.floor((rem%3600)/60), cs=rem%60;
          checkoutEl.textContent = '🔒 Checkout in ' + (ch?ch+'h ':'')+cm+'m '+pad(cs)+'s';
          checkoutEl.className   = 'wsa-ptc-checkout-info wsa-checkout-locked';
        }
      }

      // Break duration
      if (breakInfoEl && breakStartTsMs) {
        var bSecs = Math.floor((nowMs() - breakStartTsMs)/1000);
        breakInfoEl.textContent = '☕ Break: '+pad(Math.floor(bSecs/60))+':'+pad(bSecs%60);
      }
    }
    tickTimer();
    timerInterval = setInterval(tickTimer, 1000);
  }

  function renderHistory(rows) {
    var wrap = el('p-history');
    if (!wrap) return;
    if (!rows.length) { wrap.innerHTML='<div class="wsa-portal-empty">No attendance records found.</div>'; return; }

    var statusMap = {
      IN:     { cls:'wsa-hist-in',     icon:'🟢', label:'Working'  },
      OUT:    { cls:'wsa-hist-out',    icon:'✅', label:'Done'     },
      BREAK:  { cls:'wsa-hist-break',  icon:'☕', label:'Break'    },
      ABSENT: { cls:'wsa-hist-absent', icon:'❌', label:'Absent'   },
    };

    var html = '<div class="wsa-hist-list">';
    rows.forEach(function(r){
      var sm = statusMap[r.status] || {cls:'',icon:'•',label:r.status};
      var today = r.is_today ? ' wsa-hist-today' : '';
      var lateTag  = (r.type === 'SCAN' && r.is_late) ? '<span class="wsa-hist-tag wsa-hist-late">Late</span>'  : '';
      var earlyTag = r.is_early ? '<span class="wsa-hist-tag wsa-hist-early">Early Exit</span>' : '';
      var otHtml   = (r.ot_hours > 0) ? '<span class="wsa-hist-ot">+OT: '+fmtMins(r.ot_hours*60)+'</span>' : '';
      html += '<div class="wsa-hist-row '+sm.cls+today+'">';
      html +=   '<div class="wsa-hist-day">';
      html +=     '<span class="wsa-hist-icon">'+sm.icon+'</span>';
      html +=     '<span class="wsa-hist-date">'+r.day_label+(r.is_today?' <em>(Today)</em>':'')+'</span>';
      html +=     lateTag + earlyTag;
      html +=   '</div>';
      html +=   '<div class="wsa-hist-meta">';
      html +=     '<span class="wsa-hist-time">';
      if (r.status !== 'ABSENT') html += '🟢 '+r.login_fmt + (r.logout_fmt!=='—' ? ' → 🔴 '+r.logout_fmt : ' → (In progress)');
      html +=     '</span>';
      html +=     '<span class="wsa-hist-hours">'+r.total_fmt+otHtml+'</span>';
      html +=   '</div>';
      html += '</div>';
    });
    html += '</div>';
    wrap.innerHTML = html;
  }

  /* ════════════════════════════════
     BOOT — detect page type
  ════════════════════════════════ */
  document.addEventListener('visibilitychange', function(){
    if (C.isPortal === 'yes' && document.visibilityState === 'visible' && getToken()) loadDashboard(true);
  });

  document.addEventListener('DOMContentLoaded', function(){
    if (C.isLogin    === 'yes') bootLogin();
    if (C.isRegister === 'yes') bootRegister();
    if (C.isPortal   === 'yes') bootPortal();
  });

})();
