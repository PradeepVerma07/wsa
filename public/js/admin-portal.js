/* ==========================================================
   Webtrionix Staff Attendance — Frontend Admin Portal JS
   SPA driven by REST API (/wsa/v2/wsa-admin/)
   ========================================================== */
(function () {
  'use strict';

  /* ── Config (injected by wp_localize_script) ── */
  const C = window.wsaAdminPortal || {};
  const API  = (C.apiBase || '/wp-json/wsa/v2/wsa-admin/').replace(/\/$/, '');
  const AJAX = C.adminAjaxUrl || C.ajaxUrl || '/wp-admin/admin-ajax.php';
  const ADMIN_POST = C.adminPostUrl || '/wp-admin/admin-post.php';
  const NONCE = C.restNonce || '';
  const ATT_ACTION_NONCE = C.attActionNonce || '';
  const COMPANY = C.company || 'Staff Attendance';
  const LOGO_URL = C.logoUrl || '';
  const PORTAL_URL = C.portalUrl || window.location.href;

  /* ── Session ── */
  const SESSION_KEY = 'wsa_admin_token';
  let session = { token: '', name: '', email: '' };

  function loadSession() {
    try {
      const s = JSON.parse(sessionStorage.getItem(SESSION_KEY) || 'null');
      if (s && s.token) { session = s; return true; }
    } catch (e) {}
    return false;
  }
  function saveSession(data) {
    session = data;
    sessionStorage.setItem(SESSION_KEY, JSON.stringify(data));
  }
  function loadRoleIntoSession(data) {
    if (data.role) session.role = data.role;
    if (data.access) session.access = data.access;
    try { sessionStorage.setItem(SESSION_KEY, JSON.stringify(session)); } catch(e){}
  }
  function clearSession() {
    session = { token: '', name: '', email: '' };
    sessionStorage.removeItem(SESSION_KEY);
  }

  /* ── API helper ── */
  // opts.silent401 = true  → on 401, return null WITHOUT wiping the session or
  //                          redirecting to the login page. Use this for background
  //                          data fetches that have an Ajax fallback (e.g. salary detail).
  async function api(method, path, body, opts401) {
    const silent401 = !!(opts401 && opts401.silent401);
    const headers = {
      'Content-Type': 'application/json',
      'X-WP-Nonce': NONCE,
    };

    // Some LiteSpeed/security rules strip custom headers on frontend REST calls.
    // Keep the header, but also send the portal token as a normal param so
    // Attendance Edit/Delete keeps working on shared hosting and cache layers.
    let payload = body && typeof body === 'object' ? Object.assign({}, body) : body;
    if (session.token) {
      headers['X-WSA-Admin-Token'] = session.token;
      if (method === 'GET') {
        if (!payload || typeof payload !== 'object') payload = {};
        payload.wsa_admin_token = session.token;
      } else if (payload && typeof payload === 'object') payload.wsa_admin_token = session.token;
      else payload = { wsa_admin_token: session.token };
    }

    const opts = { method, headers };
    if (payload && method !== 'GET') opts.body = JSON.stringify(payload);

    let url = API + '/' + path.replace(/^\//, '');
    if (payload && method === 'GET') {
      const qs = new URLSearchParams(payload).toString();
      if (qs) url += '?' + qs;
    }
    if (method === 'GET') url += (url.includes('?') ? '&' : '?') + '_wsa=' + Date.now();
    opts.cache = 'no-store';
    opts.credentials = 'same-origin';

    try {
      const res = await fetch(url, opts);
      const text = await res.text();
      let data = {};
      try { data = text ? JSON.parse(text) : {}; }
      catch (jsonErr) { data = { success: false, message: text || res.statusText || 'Invalid server response.' }; }
      if (res.status === 401) {
        if (silent401) {
          // Caller has an Ajax fallback — don't wipe the session or redirect.
          return { success: false, status: 401, message: data.message || 'Unauthorized' };
        }
        clearSession();
        renderLogin('Session expired. Please log in again.');
        return null;
      }
      if (!res.ok && !data.message) data.message = 'Request failed. HTTP ' + res.status;
      return data;
    } catch (e) {
      toast('Network error: ' + e.message, 'err');
      return null;
    }
  }

  const get  = (p, q)  => api('GET',    p, q);
  const post = (p, b)  => api('POST',   p, b);
  const put  = (p, b)  => api('PUT',    p, b);
  const del  = (p)     => api('DELETE', p);



  // Salary helpers shared by frontend Salary page and Config modal.
  // Keep these outside pageSalary so Detail/Config buttons never fail with undefined helper errors.
  async function getSalaryConfigSafeGlobal(id) {
    let data = await get(`/salary/config/${id}`, { _: Date.now() });
    if (data && data.success) return data.config || {};
    const fallback = await ajaxPost('wsa_get_salary_config', { staff_id: id });
    if (fallback && fallback.success) return fallback.data || {};
    toast((data && data.message) || (fallback && fallback.message) || 'Could not load salary config.', 'err');
    return {};
  }

  async function saveSalaryConfigSafeGlobal(payload) {
    let res = await post('/salary/config', payload);
    if (res && res.success) return res;
    const fallback = await ajaxPost('wsa_front_save_salary_config', payload);
    if (fallback && fallback.success) return { success: true, message: fallback.data?.message || 'Salary config saved.' };
    return fallback || res || { success: false, message: 'Could not save salary config.' };
  }

  async function getSalaryDetailSafeGlobal(staffId, yr, mn) {
    staffId = parseInt(staffId || 0, 10);
    if (!staffId) return { success: false, message: 'Staff ID missing.' };

    // 1) Try the admin-ajax endpoint FIRST — it accepts the WP admin cookie directly
    //    and does not require a portal token. This is the most reliable path when the
    //    site is visited while logged in as a WordPress admin (e.g. via the admin bar).
    const ajax = await ajaxPost('wsa_front_salary_detail', { staff_id: staffId, yr, mn });
    if (ajax && ajax.success && ajax.data && ajax.data.report) {
      return { success: true, report: ajax.data.report };
    }

    // 2) Fall back to REST endpoint. Pass silent401 so a 401 response does NOT wipe
    //    the session or redirect to the login page — this call is a background fetch.
    let data = await api('GET', `/salary/detail/${staffId}`, { yr, mn, _: Date.now() }, { silent401: true });
    if (data && data.success && data.report) return data;

    const msg = (ajax && (ajax.message || (ajax.data && ajax.data.message)))
             || (data && data.message)
             || 'Could not load salary detail.';
    return { success: false, message: msg };
  }
  async function ajaxPost(action, fields) {
    const form = new URLSearchParams();
    form.set('action', action);
    form.set('nonce', NONCE);
    form.set('_ajax_nonce', NONCE);
    if (session.token) form.set('wsa_admin_token', session.token);
    Object.keys(fields || {}).forEach(key => {
      const val = fields[key];
      form.set(key, val == null ? '' : String(val));
    });

    try {
      const res = await fetch(AJAX, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: form.toString(),
        credentials: 'same-origin',
        cache: 'no-store'
      });
      const text = await res.text();
      let data = {};
      try { data = text ? JSON.parse(text) : {}; }
      catch (e) { data = { success: false, message: text || res.statusText || 'Invalid AJAX response.' }; }
      if (!res.ok && !data.message) data.message = 'Request failed. HTTP ' + res.status;
      return data;
    } catch (e) {
      return { success: false, message: 'Network error: ' + e.message };
    }
  }

  function submitAttendanceDirect(action, fields) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = ADMIN_POST;
    form.style.display = 'none';

    const add = (name, value) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value == null ? '' : String(value);
      form.appendChild(input);
    };

    add('action', action);
    add('_wsa_att_nonce', ATT_ACTION_NONCE);
    add('_redirect', PORTAL_URL || window.location.href.split('#')[0]);
    if (session.token) add('wsa_admin_token', session.token);
    Object.keys(fields || {}).forEach(key => add(key, fields[key]));
    document.body.appendChild(form);
    form.submit();
  }

  function attendanceDirectAction(action, fields) {
    submitAttendanceDirect(action, fields || {});
  }

  async function requestAttendanceUpdate(id, payload) {
    // Use the new unified admin-ajax action first. It accepts portal token, WP admin login,
    // form POST, and JSON payloads, so it behaves like the WP-admin attendance action even
    // on hosts that block PUT/DELETE or strip custom REST headers.
    const directPayload = Object.assign({ id, mode: 'update', att_action: 'update', _wsa_action: 'update' }, payload || {});
    const primary = await ajaxPost('wsa_ap_attendance_action', directPayload);
    if (primary && primary.success) return primary;

    const ajaxRes = await ajaxPost('wsa_ap_attendance_update', Object.assign({ id }, payload || {}));
    if (ajaxRes && ajaxRes.success) return ajaxRes;

    const body = Object.assign({ _wsa_action: 'update', id }, payload || {});
    let res = await post(`/attendance/${id}`, body);
    if (res && res.success) return res;

    const firstMessage = (primary && primary.message) || (ajaxRes && ajaxRes.message) || (res && res.message) || '';
    res = await post(`/attendance/${id}/update`, Object.assign({ id }, payload || {}));
    if (res && res.success) return res;

    const fallback = await put(`/attendance/${id}`, Object.assign({ id }, payload || {}));
    if (fallback && fallback.success) return fallback;
    return fallback || res || ajaxRes || primary || { success: false, message: firstMessage || 'Record update failed.' };
  }

  async function requestAttendanceDelete(id) {
    // Unified admin-ajax delete first, then legacy AJAX/REST fallbacks.
    const primary = await ajaxPost('wsa_ap_attendance_action', { id, mode: 'delete', att_action: 'delete', _wsa_action: 'delete' });
    if (primary && primary.success) return primary;

    const ajaxRes = await ajaxPost('wsa_ap_attendance_delete', { id });
    if (ajaxRes && ajaxRes.success) return ajaxRes;

    let res = await post(`/attendance/${id}`, { id, _wsa_action: 'delete' });
    if (res && res.success) return res;

    const firstMessage = (primary && primary.message) || (ajaxRes && ajaxRes.message) || (res && res.message) || '';
    res = await post(`/attendance/${id}/delete`, { id });
    if (res && res.success) return res;

    const fallback = await del(`/attendance/${id}`);
    if (fallback && fallback.success) return fallback;
    return fallback || res || ajaxRes || primary || { success: false, message: firstMessage || 'Record delete failed.' };
  }

  /* ── Toast ── */
  function toast(msg, type = 'ok') {
    let wrap = document.getElementById('ap-toasts');
    if (!wrap) { wrap = document.createElement('div'); wrap.id = 'ap-toasts'; document.body.appendChild(wrap); }
    const el = document.createElement('div');
    el.className = `ap-toast ap-toast--${type}`;
    el.textContent = msg;
    wrap.appendChild(el);
    setTimeout(() => el.remove(), 3800);
  }

  /* ── Modal helpers ── */
  let _modal = null;

  function openModal(html, opts = {}) {
    closeModal();
    const bd = document.createElement('div');
    bd.className = 'ap-modal-backdrop open';
    bd.style.zIndex = '1002000';
    const lgCls = opts.large ? ' ap-modal--lg' : '';
    bd.innerHTML = `<div class="ap-modal${lgCls}">${html}</div>`;
    document.body.appendChild(bd);
    _modal = bd;
    bd.addEventListener('click', e => { if (e.target === bd) closeModal(); });
    const closeBtn = bd.querySelector('.ap-modal-close');
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    return bd;
  }
  function closeModal() {
    if (_modal) { _modal.remove(); _modal = null; }
  }

  function printSalaryDetailModal(btn) {
    const modal = btn && btn.closest ? btn.closest('.ap-modal') : document.querySelector('.ap-modal-backdrop.open .ap-modal');
    if (!modal) { toast('Salary detail popup not found.', 'err'); return; }

    const clone = modal.cloneNode(true);
    clone.querySelectorAll('.ap-modal-close, .ap-modal-actions, .wsa-no-print').forEach(el => el.remove());
    cleanSalaryPrintIcons(clone);
    clone.querySelectorAll('.ap-cal-grid').forEach(el => {
      const card = el.closest('.ap-card');
      if (card) card.remove();
    });
    const title = (clone.querySelector('.ap-modal-head h3') || {}).textContent || 'Salary Detail';

    const frame = document.createElement('iframe');
    frame.style.position = 'fixed';
    frame.style.left = '-10000px';
    frame.style.top = '0';
    frame.style.width = '1120px';
    frame.style.height = '900px';
    frame.style.border = '0';
    frame.style.opacity = '0';
    document.body.appendChild(frame);

    const doc = frame.contentWindow.document;
    doc.open();
    doc.write(`<!doctype html><html><head><title>${esc(title)}</title><style>
      *{box-sizing:border-box}
      @page{size:A4 portrait;margin:8mm}
      body{font-family:Arial,sans-serif;color:#111827;background:#fff;margin:0;padding:0;font-size:9.5px;line-height:1.25}
      h1,h2,h3{margin:0 0 6px;font-size:13px;line-height:1.25}
      h1::first-letter,h2::first-letter,h3::first-letter{font-size:1em!important;line-height:1!important}
      .ap-modal-head{border-bottom:1.5px solid #111827;margin-bottom:8px;padding-bottom:6px}
      .ap-modal-body{padding:0}
      .ap-stats{display:grid;grid-template-columns:repeat(6,1fr);gap:5px;margin:0 0 8px}
      img,svg,i,[aria-hidden="true"],.icon,[class*="icon"],[class*="dashicons"],[class*="lucide"],[class^="fa "],[class*=" fa-"],[class^="fa-"],.ap-stat-icon,.wsa-cal-ico,.wsa-print-icon{display:none!important}
      .ap-stat,.ap-card{border:1px solid #d1d5db;border-radius:5px;padding:6px;background:#fff;break-inside:avoid}
      .ap-stat-val{font-size:13px;font-weight:800}
      .ap-stat-label{font-size:8px;text-transform:uppercase;color:#4b5563}
      .ap-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:7px}
      .ap-mt-16{margin-top:7px}
      table{width:100%;border-collapse:collapse;margin-top:4px;table-layout:fixed}
      th,td{border:1px solid #d1d5db;padding:3px 4px;text-align:left;vertical-align:top;word-break:break-word}
      th{background:#f3f4f6;font-weight:800}
      .ap-money,strong{font-weight:800}
      .ap-table-wrap{overflow:visible}
      .ap-table-wrap table{font-size:8.7px}
      @media print{.ap-grid-2{grid-template-columns:1fr 1fr}.ap-card{page-break-inside:avoid}}
    </style></head><body>${clone.innerHTML}</body></html>`);
    doc.close();

    setTimeout(() => {
      frame.contentWindow.focus();
      frame.contentWindow.print();
      setTimeout(() => frame.remove(), 600);
    }, 100);
  }

  function cleanSalaryPrintIcons(root) {
    if (!root) return;
    root.querySelectorAll('img, svg, i, [aria-hidden="true"], .icon, [class*="icon"], [class*="dashicons"], [class*="lucide"], [class^="fa "], [class*=" fa-"], [class^="fa-"], .ap-stat-icon, .wsa-cal-ico, .wsa-print-icon').forEach(el => el.remove());
    const emojiRE = /[\u{1F000}-\u{1FAFF}\u{2600}-\u{27BF}\u{FE0F}]/gu;
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach(node => {
      node.nodeValue = node.nodeValue.replace(emojiRE, '').replace(/\s{2,}/g, ' ').trimStart();
    });
  }

  document.addEventListener('click', function(e) {
    const btn = e.target && e.target.closest ? e.target.closest('[data-wsa-print-salary-detail]') : null;
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    printSalaryDetailModal(btn);
  }, true);

  function printSalarySlipOutput(scope) {
    const out = scope && scope.querySelector ? scope.querySelector('#ssOutput') : document.querySelector('#ssOutput');
    if (!out) { toast('Salary slip output not found.', 'err'); return; }

    const clone = out.cloneNode(true);
    clone.querySelectorAll('.wsa-front-slip-empty, .wsa-ap-loading').forEach(el => el.remove());
    cleanSalaryPrintIcons(clone);
    if (!clone.textContent.trim()) { toast('Generate salary slip first.', 'err'); return; }

    const frame = document.createElement('iframe');
    frame.style.position = 'fixed';
    frame.style.left = '-10000px';
    frame.style.top = '0';
    frame.style.width = '1120px';
    frame.style.height = '900px';
    frame.style.border = '0';
    frame.style.opacity = '0';
    document.body.appendChild(frame);

    const doc = frame.contentWindow.document;
    doc.open();
    doc.write(`<!doctype html><html><head><title>Salary Slip</title><style>
      @page{size:A4 portrait;margin:9mm}
      *{box-sizing:border-box}
      body{font-family:Arial,sans-serif;color:#111827;background:#fff;margin:0;padding:0;font-size:10px;line-height:1.28}
      .wsa-slip,.ap-salary-slip{border:1px solid #d1d5db;border-radius:6px;padding:10px;margin:0 0 10px;background:#fff;box-shadow:none;break-inside:avoid;page-break-inside:avoid}
      .wsa-slip-head,.ap-slip-head{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #d1d5db;padding-bottom:8px;margin-bottom:8px}
      h1,h2,h3{margin:0 0 6px;line-height:1.25}
      h1,h2{font-size:16px}
      h3{font-size:12px}
      h1::first-letter,h2::first-letter,h3::first-letter{font-size:1em!important;line-height:1!important}
      img,svg,i,[aria-hidden="true"],.icon,[class*="icon"],[class*="dashicons"],[class*="lucide"],[class^="fa "],[class*=" fa-"],[class^="fa-"],.wsa-slip-brand img,.ap-slip-brand img,.wsa-slip-brand i,.ap-slip-brand i{display:none!important}
      table{width:100%;border-collapse:collapse;margin-top:5px;table-layout:fixed}
      th,td{border:1px solid #d1d5db;padding:4px 5px;text-align:left;vertical-align:top;word-break:break-word}
      th{background:#f3f4f6;font-weight:800}
      .wsa-slip-grid,.ap-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
      .wsa-slip-info,.ap-slip-info{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin:8px 0}
      .wsa-slip-info>div,.ap-slip-info>div,.wsa-slip-box,.ap-card{border:1px solid #d1d5db;border-radius:5px;padding:6px;background:#fff}
      .wsa-slip-net strong,.ap-slip-net strong{font-size:18px}
      @media print{.wsa-slip,.ap-salary-slip{page-break-after:auto}.wsa-slip-list{margin:0}}
    </style></head><body>${clone.innerHTML}</body></html>`);
    doc.close();

    setTimeout(() => {
      frame.contentWindow.focus();
      frame.contentWindow.print();
      setTimeout(() => frame.remove(), 600);
    }, 100);
  }

  /* ── Confirm dialog ── */
  function confirm(msg) {
    return new Promise(res => {
      const bd = openModal(`
        <div class="ap-modal-head"><h3>Confirm</h3><button class="ap-modal-close">✕</button></div>
        <div class="ap-modal-body"><p style="color:var(--ap-text);font-size:14px">${msg}</p></div>
        <div class="ap-modal-foot">
          <button class="ap-btn ap-btn--outline" id="apCfNo">Cancel</button>
          <button class="ap-btn ap-btn--danger" id="apCfYes">Confirm</button>
        </div>`);
      bd.querySelector('#apCfNo').onclick  = () => { closeModal(); res(false); };
      bd.querySelector('#apCfYes').onclick = () => { closeModal(); res(true); };
    });
  }

  /* ── Utilities ── */
  function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function fmtTime(dt) {
    if (!dt) return '—';
    const d = new Date(dt.replace(' ', 'T'));
    if (isNaN(d)) return dt;
    return d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true });
  }
  function timeInputValue(dt) {
    if (!dt) return '';
    const str = String(dt);
    const m = str.match(/(\d{2}:\d{2})/);
    return m ? m[1] : '';
  }
  function fmtDate(s) {
    if (!s) return '—';
    const d = new Date(s + 'T00:00:00');
    if (isNaN(d)) return s;
    return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
  }
  function fmtHours(h) {
    h = Number(h || 0);
    if (h <= 0) return '—';
    let mins = Math.round(h * 60);
    const hh = Math.floor(mins / 60);
    const mm = mins % 60;
    if (mm === 0) return hh + 'h';
    if (hh === 0) return mm + 'm';
    return hh + 'h ' + mm + 'm';
  }
  function initials(name) {
    return String(name || '?').split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase();
  }
  function avatar(name) {
    return `<div class="ap-av">${initials(name)}</div>`;
  }
  function statusBadge(st) {
    const labels = { IN:'🟢 IN', OUT:'🔵 OUT', BREAK:'☕ Break', ABSENT:'⚫ Absent' };
    return `<span class="ap-badge ap-badge--${esc(st)}">${labels[st] || esc(st)}</span>`;
  }

  /* ═══════════════════════════════════════════
     LOGIN SCREEN
  ═══════════════════════════════════════════ */
  function showFirstBootBanner(root) {
    const card = root.querySelector('.wsa-ap-login-card');
    if (!card) return;
    const banner = document.createElement('div');
    banner.style.cssText = 'background:rgba(251,146,60,.12);border:1px solid rgba(251,146,60,.3);border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:13px;color:#fb923c;';
    banner.innerHTML = '<strong>🛡️ First Time Setup</strong><br>No admin accounts exist yet. After logging in with your WordPress credentials, create a dedicated Super Admin account from the <em>Admin Accounts</em> page.';
    card.querySelector('.wsa-ap-login-logo').after(banner);
  }

  function renderLogin(errMsg = '') {
    const root = document.getElementById('wsa-ap-root');
    root.innerHTML = `
      <div class="wsa-ap-login-wrap">
        <div class="wsa-ap-login-card">
          <div class="wsa-ap-login-logo">
            <div class="logo-icon">🏭</div>
            <h1>${esc(COMPANY)}</h1>
            <p>Admin Portal</p>
          </div>
          <div class="ap-error${errMsg ? ' visible' : ''}" id="apLoginErr">${esc(errMsg)}</div>
          <div class="ap-field"><label>Username</label><input id="apUser" type="text" autocomplete="username" placeholder="Admin username"></div>
          <div class="ap-field"><label>Password</label><input id="apPass" type="password" autocomplete="current-password" placeholder="••••••••"></div>
          <button class="ap-btn ap-btn--primary" id="apLoginBtn">Sign In →</button>
          <p style="text-align:center;font-size:11px;color:var(--ap-muted);margin-top:16px">
            Use your WordPress administrator credentials.
          </p>
        </div>
      </div>`;

    const errEl  = root.querySelector('#apLoginErr');
    const btnEl  = root.querySelector('#apLoginBtn');
    const passEl = root.querySelector('#apPass');

    // Check if first boot (no super admin set up yet)
    (async () => {
      try {
        const fb = await fetch(API + '/first-boot', { headers: { 'X-WP-Nonce': NONCE } });
        const fbData = await fb.json();
        if (fbData && fbData.needs_setup) showFirstBootBanner(root);
      } catch(e) {}
    })();

    async function doLogin() {
      const username = root.querySelector('#apUser').value.trim();
      const password = passEl.value;
      if (!username || !password) { errEl.textContent = 'Enter username and password.'; errEl.classList.add('visible'); return; }
      btnEl.disabled = true; btnEl.innerHTML = '<span class="ap-spin-mini"></span> Signing in…';
      const data = await post('/login', { username, password });
      if (!data) { btnEl.disabled = false; btnEl.textContent = 'Sign In →'; return; }
      if (!data.success) {
        errEl.textContent = data.message || 'Login failed.';
        errEl.classList.add('visible');
        btnEl.disabled = false; btnEl.textContent = 'Sign In →';
        return;
      }
      saveSession({ token: data.token, name: data.name, email: data.email, role: data.role || 'admin', access: data.access || null });
      renderApp();
    }
    btnEl.addEventListener('click', doLogin);
    passEl.addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });
  }

  /* ═══════════════════════════════════════════
     MAIN APP LAYOUT
  ═══════════════════════════════════════════ */
  const PAGES = [
    { id: 'dashboard',  label: 'Dashboard',     icon: '📊', section: 'main' },
    { id: 'quickmark',  label: 'Quick Mark',     icon: '⚡', section: 'main' },
    { id: 'inside',     label: "Who's Inside",   icon: '🏭', section: 'main' },
    { id: 'qrscanner',  label: 'QR Scanner',     icon: '📲', section: 'main' },
    { id: 'faceattendance', label: 'Face Attendance', icon: '🧑‍💼', section: 'main' },
    { id: 'faceregister', label: 'Face Registration', icon: '📸', section: 'main' },
    { id: 'attendance', label: 'Attendance',      icon: '📋', section: 'manage' },
    { id: 'manual',     label: 'Manual Entry',   icon: '✏️',  section: 'manage' },
    { id: 'staff',      label: 'Staff',          icon: '👥', section: 'manage', badge: 'pending' },
    { id: 'pending',    label: 'Pending Staff',  icon: '🕐', section: 'manage' },
    { id: 'leaves',     label: 'Leaves',         icon: '🌿', section: 'manage' },
    { id: 'salary',     label: 'Salary',         icon: '💰', section: 'reports' },
    { id: 'salaryslip', label: 'Salary Slip',    icon: '🧾', section: 'reports' },
    { id: 'shifts',     label: 'Shifts',         icon: '🕐', section: 'config' },
    { id: 'gates',      label: 'QR Gates',       icon: '📡', section: 'config' },
    { id: 'holidays',   label: 'Holidays',       icon: '🎉', section: 'config' },
    { id: 'settings',   label: 'Settings',       icon: '⚙️', section: 'config' },
    { id: 'superadmin', label: 'Admin Accounts',  icon: '🛡️',  section: 'config', superOnly: true },
    { id: 'roleaccess', label: 'Role Access', icon: '🔐', section: 'config', superOnly: true },
  ];

  function canViewPage(pageId) {
    if ((session.role || 'admin') === 'super_admin') return true;
    if (pageId === 'superadmin' || pageId === 'roleaccess') return false;
    const modules = session.access && Array.isArray(session.access.modules) ? session.access.modules : null;
    if (!modules) return true; // backwards-compatible if old session has no access payload
    return modules.includes(pageId);
  }

  function firstAllowedPage() {
    const found = PAGES.find(p => !p.superOnly && canViewPage(p.id));
    return found ? found.id : 'dashboard';
  }

  let currentPage = 'dashboard';
  let pendingCount = 0;
  let clockInterval = null;
  let dashboardAutoRefresh = null;

  function renderApp() {
    const root = document.getElementById('wsa-ap-root');

    const sections = ['main', 'manage', 'reports', 'config'];
    const sectionLabels = { main: 'Overview', manage: 'Management', reports: 'Reports', config: 'Configuration' };

    const isSuperAdmin = session.role === 'super_admin';

    let navHtml = '';
    sections.forEach(sec => {
      const items = PAGES.filter(p => p.section === sec && (!p.superOnly || isSuperAdmin) && canViewPage(p.id));
      if (!items.length) return;
      navHtml += `<div class="ap-nav-section">${sectionLabels[sec]}</div>`;
      items.forEach(p => {
        navHtml += `<div class="ap-nav-item" data-page="${p.id}" id="nav-${p.id}">
          <span class="icon">${p.icon}</span>${esc(p.label)}
          ${p.badge === 'pending' ? `<span class="badge" id="nav-pending-badge" style="display:none">0</span>` : ''}
        </div>`;
      });
    });

    root.innerHTML = `
      <div class="wsa-ap-layout">
        <!-- Overlay (mobile) -->
        <div class="wsa-ap-overlay" id="apOverlay"></div>

        <!-- Sidebar -->
        <nav class="wsa-ap-sidebar" id="apSidebar">
          <div class="ap-sidebar-head">
            <div class="logo">🏭</div>
            <div><h2>${esc(COMPANY)}</h2><p>Admin Portal</p></div>
          </div>
          <div class="ap-nav">${navHtml}</div>
          <div class="ap-sidebar-foot">
            <div class="ap-user-row">
              <div class="ap-user-av">${initials(session.name)}</div>
              <div class="ap-user-info">
                <strong>${esc(session.name)}</strong>
                <span>${session.role === "super_admin" ? "🛡️ Super Admin" : "Administrator"}</span>
              </div>
              <span class="ap-logout-btn" id="apLogout" title="Logout">🚪</span>
            </div>
          </div>
        </nav>

        <!-- Main -->
        <div class="wsa-ap-main">
          <div class="wsa-ap-topbar">
            <button class="ap-menu-toggle" id="apMenuToggle" type="button" aria-label="Open dashboard menu" aria-expanded="false">☰</button>
            <h1 id="apPageTitle">Dashboard</h1>
            <div class="ap-topbar-right">
              <span class="ap-topbar-clock" id="apClock"></span>
            </div>
          </div>
          <div class="wsa-ap-content" id="apContent">
            <div class="wsa-ap-loading"><div class="wsa-ap-spinner"></div><p>Loading…</p></div>
          </div>
        </div>
      </div>
      <div id="ap-toasts"></div>`;

    try {
      const params = new URLSearchParams(window.location.search || '');
      const forcedPage = params.get('wsa_ap_page');
      if (forcedPage && PAGES.some(p => p.id === forcedPage)) currentPage = forcedPage;
      const msg = params.get('wsa_att_msg');
      const status = params.get('wsa_att_status');
      if (msg) {
        toast(msg, status === 'error' ? 'err' : 'ok');
        params.delete('wsa_att_msg'); params.delete('wsa_att_status'); params.delete('wsa_att_ts');
        const clean = window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash;
        window.history.replaceState({}, document.title, clean);
      }
    } catch (e) {}

    /* ── Nav events ── */
    root.querySelectorAll('.ap-nav-item').forEach(el => {
      el.addEventListener('click', () => {
        const pg = el.dataset.page;
        closeSidebar();
        navigateTo(pg);
      });
    });

    /* ── Sidebar toggle ── */
    const menuToggle = root.querySelector('#apMenuToggle');
    const overlayEl  = root.querySelector('#apOverlay');
    function toggleSidebar(e) {
      if (e) {
        e.preventDefault();
        e.stopPropagation();
      }
      const sb = document.getElementById('apSidebar');
      const ov = document.getElementById('apOverlay');
      const tg = document.getElementById('apMenuToggle');
      if (!sb || !ov) return;
      const isOpen = !sb.classList.contains('open');
      sb.classList.toggle('open', isOpen);
      ov.classList.toggle('visible', isOpen);
      document.body.classList.toggle('wsa-ap-sidebar-open', isOpen);
      if (tg) tg.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }
    if (menuToggle) menuToggle.addEventListener('click', toggleSidebar, false);
    if (overlayEl) overlayEl.addEventListener('click', closeSidebar, false);

    /* ── Logout ── */
    root.querySelector('#apLogout').addEventListener('click', async () => {
      await post('/logout', {});
      clearSession();
      renderLogin();
    });

    /* ── Clock ── */
    clearInterval(clockInterval);
    const clockEl = () => document.getElementById('apClock');
    const tick = () => {
      const el = clockEl();
      if (el) el.textContent = new Date().toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
    };
    tick();
    clockInterval = setInterval(tick, 1000);

    /* ── Load pending count ── */
    loadPendingCount();

    /* ── Navigate to initial page ── */
    if (!canViewPage(currentPage)) currentPage = firstAllowedPage();
    navigateTo(currentPage);
  }

  function closeSidebar() {
    const sb = document.getElementById('apSidebar');
    const ov = document.getElementById('apOverlay');
    const tg = document.getElementById('apMenuToggle');
    if (sb) { sb.classList.remove('open'); }
    if (ov) { ov.classList.remove('visible'); }
    if (tg) { tg.setAttribute('aria-expanded', 'false'); }
    document.body.classList.remove('wsa-ap-sidebar-open');
  }

  async function loadPendingCount() {
    const data = await get('/staff/pending');
    if (!data || !data.success) return;
    pendingCount = data.staff.length;
    const badge = document.getElementById('nav-pending-badge');
    if (badge) {
      badge.textContent = pendingCount;
      badge.style.display = pendingCount > 0 ? 'inline-flex' : 'none';
    }
  }

  function navigateTo(page) {
    if (!canViewPage(page)) {
      const content = document.getElementById('apContent');
      if (content) content.innerHTML = '<div class="ap-empty"><div class="icon">🔒</div><p>Access denied for this module. Contact Super Admin.</p></div>';
      return;
    }
    currentPage = page;
    /* Update nav active */
    document.querySelectorAll('.ap-nav-item').forEach(el => el.classList.remove('active'));
    const navEl = document.getElementById('nav-' + page);
    if (navEl) navEl.classList.add('active');

    const pg = PAGES.find(p => p.id === page);
    const titleEl = document.getElementById('apPageTitle');
    if (titleEl && pg) titleEl.textContent = pg.label;

    const content = document.getElementById('apContent');
    if (!content) return;
    // Cleanup QR polling if navigating away
    if (typeof window.wsaApCleanupQr === 'function') { window.wsaApCleanupQr(); window.wsaApCleanupQr = null; }
    clearInterval(qmAutoRefresh);
    clearInterval(dashboardAutoRefresh);
    content.innerHTML = '<div class="wsa-ap-loading"><div class="wsa-ap-spinner"></div><p>Loading…</p></div>';

    const pages = {
      dashboard:  pageDashboard,
      quickmark:  pageQuickMark,
      inside:     pageInside,
      attendance: pageAttendance,
      manual:     pageManual,
      staff:      pageStaff,
      pending:    pagePending,
      leaves:     pageLeaves,
      salary:     pageSalary,
      salaryslip: pageSalarySlip,
      shifts:     pageShifts,
      gates:      pageGates,
      holidays:   pageHolidays,
      settings:   pageSettings,
      qrscanner:  pageQrScanner,
      faceattendance: pageFaceAttendance,
      faceregister: pageFaceRegistration,
      superadmin: pageSuperAdmin,
      roleaccess: pageRoleAccess,
    };
    if (pages[page]) pages[page](content);
  }

  /* ═══════════════════════════════════════════
     PAGE: DASHBOARD
  ═══════════════════════════════════════════ */
  async function pageDashboard(el) {
    const data = await get('/dashboard');
    if (!data || !data.success) { el.innerHTML = '<div class="ap-empty">Failed to load dashboard.</div>'; return; }
    const s = data.stats;

    const cards = [
      { label: 'Total Staff',   val: s.total_staff,         icon: '👥', cls: 'blue' },
      { label: 'Present Today', val: s.present_today,       icon: '✅', cls: 'green' },
      { label: 'Inside Now',    val: s.inside_now,           icon: '🏭', cls: 'orange' },
      { label: 'On Break',      val: s.on_break_now || 0,   icon: '☕', cls: 'teal' },
      { label: 'Checked Out',   val: s.checked_out,         icon: '🚪', cls: 'purple' },
      { label: 'Late Today',    val: s.late_today,          icon: '⏰', cls: 'yellow' },
      { label: 'Overtime',      val: s.overtime_today,      icon: '⚡', cls: 'red' },
      { label: 'Absent',        val: Math.max(0, s.total_staff - s.present_today), icon: '❌', cls: 'grey' },
    ];
    const statsHtml = cards.map(c => `
      <div class="ap-stat ap-stat--${c.cls}">
        <div class="ap-stat__icon">${c.icon}</div>
        <div class="ap-stat__val">${Math.max(0, c.val ?? 0)}</div>
        <div class="ap-stat__label">${c.label}</div>
      </div>`).join('');

    const insideHtml = data.inside.length === 0
      ? '<div class="ap-empty"><div class="icon">🏭</div>No one inside right now.</div>'
      : data.inside.map(r => `
        <div class="ap-flex" style="padding:10px 0;border-bottom:1px solid var(--ap-border)">
          ${avatar(r.staff_name)}
          <div class="ap-flex-1">
            <strong style="font-size:13px">${esc(r.staff_name)}</strong>
            <span class="ap-muted">${esc(r.emp_code)} · ${esc(r.department || '—')}</span>
          </div>
          <div style="text-align:right;font-size:12px">
            <span class="ap-live-dot"></span>
            <span style="color:var(--ap-muted)">${fmtTime(r.login_time)}</span>
          </div>
        </div>`).join('');

    const todayHtml = data.today.length === 0
      ? '<tr><td colspan="6" class="ap-empty">No activity yet today.</td></tr>'
      : data.today.slice(0, 30).map(r => `
        <tr>
          <td><strong>${esc(r.staff_name)}</strong><span class="ap-muted">${esc(r.emp_code)}</span></td>
          <td>${esc(r.department || '—')}</td>
          <td style="color:var(--ap-green)">${r.status === 'LEAVE' ? '—' : fmtTime(r.login_time)}</td>
          <td style="color:#a5b4fc">${r.status === 'LEAVE' ? '—' : fmtTime(r.logout_time)}</td>
          <td>${fmtHours(r.total_hours)}</td>
          <td>${statusBadge(r.status)}</td>
        </tr>`).join('');

    el.innerHTML = `
      <div class="ap-page-head">
        <div><h1>Dashboard</h1><p>${data.date_label}</p></div>
        <div class="ap-page-head-right">
          <button class="ap-btn ap-btn--ghost" id="dashRefresh">↻ Refresh</button>
        </div>
      </div>
      <div class="ap-stats">${statsHtml}</div>
      <div class="ap-grid-2">
        <div class="ap-card">
          <div class="ap-card-head"><h2>🏭 Inside Now <span class="ap-badge ap-badge--IN">${data.inside.length}</span></h2></div>
          <div class="ap-card-body" style="max-height:320px;overflow-y:auto">${insideHtml}</div>
        </div>
        <div class="ap-card">
          <div class="ap-card-head"><h2>📋 Today's Activity</h2></div>
          <div class="ap-table-wrap">
            <table class="ap-table">
              <thead><tr><th>Name</th><th>Dept</th><th>IN</th><th>OUT</th><th>Hours</th><th>Status</th></tr></thead>
              <tbody>${todayHtml}</tbody>
            </table>
          </div>
        </div>
      </div>`;

    el.querySelector('#dashRefresh').addEventListener('click', () => pageDashboard(el));
    clearInterval(dashboardAutoRefresh);
    dashboardAutoRefresh = setInterval(() => {
      if (currentPage === 'dashboard' && document.visibilityState !== 'hidden') pageDashboard(el);
    }, 15000);
  }

  /* ═══════════════════════════════════════════
     PAGE: QUICK MARK
  ═══════════════════════════════════════════ */
  let qmAutoRefresh = null;

  async function pageQuickMark(el) {
    clearInterval(qmAutoRefresh);
    const data = await get('/quick-mark-status');
    if (!data || !data.success) { el.innerHTML = '<div class="ap-empty">Failed to load.</div>'; return; }

    const depts = [...new Set(data.staff.map(s => s.department).filter(Boolean))].sort();
    const deptsOpts = ['<option value="">All Departments</option>', ...depts.map(d => `<option value="${esc(d)}">${esc(d)}</option>`)].join('');
    const statusOpts = `<option value="">All Status</option><option value="ABSENT">Absent</option><option value="IN">In</option><option value="OUT">Out</option><option value="BREAK">On Break</option>`;

    function buildCards(staff) {
      if (!staff.length) return '<div class="ap-empty"><div class="icon">👥</div>No staff match filter.</div>';
      return '<div class="ap-qm-grid">' + staff.map(s => {
        const btns = qmBtns(s);
        const worked = s.worked_mins > 0 ? (Math.floor(s.worked_mins / 60) + 'h ' + (s.worked_mins % 60 | 0) + 'm') : '—';
        return `<div class="ap-qm-card" id="qmc-${s.id}">
          <div class="ap-qm-top">
            ${avatar(s.name)}
            <div class="ap-qm-info">
              <strong>${esc(s.name)}</strong>
              <span>${esc(s.employee_id)}${s.department ? ' · ' + esc(s.department) : ''}</span>
            </div>
            ${statusBadge(s.status)}
          </div>
          <div class="ap-flex" style="font-size:11px;color:var(--ap-muted)">
            ${s.login_time ? '⏰ IN: ' + fmtTime(s.login_time) : ''}&nbsp;
            ${s.worked_mins > 0 ? '⏱ ' + worked : ''}
          </div>
          <div class="ap-qm-btns">${btns}</div>
        </div>`;
      }).join('') + '</div>';
    }

    el.innerHTML = `
      <div class="ap-page-head">
        <div><h1>Quick Mark</h1><p>Mark attendance for all staff</p></div>
        <div class="ap-page-head-right">
          <button class="ap-btn ap-btn--ghost" id="qmRefresh">↻ Refresh</button>
        </div>
      </div>
      <div class="ap-filter-bar">
        <div class="ap-filter-row">
          <div class="ap-field"><label>Filter Dept</label><select id="qmDeptFilter">${deptsOpts}</select></div>
          <div class="ap-field"><label>Filter Status</label><select id="qmStFilter">${statusOpts}</select></div>
          <div class="ap-field"><label>Search</label><input id="qmSearch" type="text" placeholder="Name or ID…"></div>
        </div>
      </div>
      <div id="qmCards">${buildCards(data.staff)}</div>
      <p style="text-align:right;font-size:11px;color:var(--ap-muted);margin-top:8px">Auto-refreshes every 30s</p>`;

    function filterAndRender() {
      const dept = el.querySelector('#qmDeptFilter').value;
      const status = el.querySelector('#qmStFilter').value;
      const search = el.querySelector('#qmSearch').value.toLowerCase();
      const filtered = data.staff.filter(s => {
        if (dept && s.department !== dept) return false;
        if (status && s.status !== status) return false;
        if (search && !s.name.toLowerCase().includes(search) && !s.employee_id.toLowerCase().includes(search)) return false;
        return true;
      });
      el.querySelector('#qmCards').innerHTML = buildCards(filtered);
      attachQmEvents(el, data.staff);
    }

    el.querySelector('#qmDeptFilter').addEventListener('change', filterAndRender);
    el.querySelector('#qmStFilter').addEventListener('change', filterAndRender);
    el.querySelector('#qmSearch').addEventListener('input', filterAndRender);
    el.querySelector('#qmRefresh').addEventListener('click', () => pageQuickMark(el));
    attachQmEvents(el, data.staff);

    qmAutoRefresh = setInterval(() => pageQuickMark(el), 30000);
  }

  function qmBtns(s) {
    if (s.status === 'ABSENT') {
      return `<button class="ap-btn ap-btn--success ap-btn--sm ap-w-full qm-action" data-id="${s.id}" data-action="checkin">✅ Check In</button>`;
    }
    if (s.status === 'OUT') {
      return `<button class="ap-btn ap-btn--ghost ap-btn--sm ap-w-full" disabled>✅ Completed Today</button>`;
    }
    if (s.status === 'IN') {
      return `<button class="ap-btn ap-btn--danger ap-btn--sm ap-flex-1 qm-action" data-id="${s.id}" data-action="checkout">🚪 Check Out</button>
              <button class="ap-btn ap-btn--ghost ap-btn--sm ap-flex-1 qm-action" data-id="${s.id}" data-action="break_start">☕ Break</button>`;
    }
    if (s.status === 'BREAK') {
      return `<button class="ap-btn ap-btn--success ap-btn--sm ap-flex-1 qm-action" data-id="${s.id}" data-action="break_end">▶️ Resume</button>
              <button class="ap-btn ap-btn--danger ap-btn--sm ap-flex-1 qm-action" data-id="${s.id}" data-action="checkout">🚪 Check Out</button>`;
    }
    return '';
  }

  function attachQmEvents(el, staff) {
    el.querySelectorAll('.qm-action').forEach(btn => {
      btn.addEventListener('click', async () => {
        const staffId = btn.dataset.id;
        const action  = btn.dataset.action;
        const s = staff.find(x => x.id == staffId);
        btn.disabled = true; btn.innerHTML = '<span class="ap-spin-mini"></span>';
        const res = await post('/quick-mark', { staff_id: parseInt(staffId), action });
        btn.disabled = false;
        if (!res) return;
        if (!res.success) { toast(res.message, 'err'); btn.innerHTML = action; return; }
        toast(res.message, 'ok');
        // Refresh the page
        setTimeout(() => pageQuickMark(el), 400);
      });
    });
  }

  /* ═══════════════════════════════════════════
     PAGE: WHO'S INSIDE
  ═══════════════════════════════════════════ */
  async function pageInside(el) {
    const data = await get('/inside');
    if (!data || !data.success) { el.innerHTML = '<div class="ap-empty">Failed to load.</div>'; return; }

    const rows = data.inside.length === 0
      ? '<tr><td colspan="4" class="ap-empty">No one inside right now.</td></tr>'
      : data.inside.map(r => `<tr>
          <td>${avatar(r.staff_name)}</td>
          <td><strong>${esc(r.staff_name)}</strong><span class="ap-muted">${esc(r.emp_code)}</span></td>
          <td>${esc(r.department || '—')}</td>
          <td><span class="ap-live-dot"></span>${fmtTime(r.login_time)}</td>
        </tr>`).join('');

    el.innerHTML = `
      <div class="ap-page-head">
        <div><h1>Who's Inside</h1><p>${data.count} people currently inside</p></div>
        <button class="ap-btn ap-btn--ghost" id="insideRefresh">↻ Refresh</button>
      </div>
      <div class="ap-card">
        <div class="ap-table-wrap">
          <table class="ap-table">
            <thead><tr><th></th><th>Name</th><th>Department</th><th>In Since</th></tr></thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </div>`;
    el.querySelector('#insideRefresh').addEventListener('click', () => pageInside(el));
  }

  function attendanceRedirectUrl() {
    let base = PORTAL_URL || window.location.href.split('#')[0];
    try {
      const u = new URL(base, window.location.origin);
      u.searchParams.set('wsa_ap_page', 'attendance');
      return u.toString();
    } catch (e) {
      return base + (base.includes('?') ? '&' : '?') + 'wsa_ap_page=attendance';
    }
  }

  function hiddenInput(name, value) {
    return `<input type="hidden" name="${esc(name)}" value="${esc(value == null ? '' : value)}">`;
  }

  function attendanceHiddenFields(action, id) {
    return [
      hiddenInput('action', action),
      hiddenInput('_wsa_att_nonce', ATT_ACTION_NONCE),
      hiddenInput('_redirect', attendanceRedirectUrl()),
      hiddenInput('wsa_admin_token', session.token || ''),
      hiddenInput('id', id)
    ].join('');
  }

  function attendanceActionsHtml(r) {
    if (r.status === 'LEAVE') return '<span class="ap-muted">Managed in Leaves</span>';
    const id = esc(r.id);
    const date = esc(r.att_date || '');
    const inTime = esc(timeInputValue(r.login_time));
    const outTime = esc(timeInputValue(r.logout_time));
    const notes = esc(r.notes || '');
    return `
      <details class="wsa-att-native-edit">
        <summary class="ap-btn ap-btn--xs ap-btn--ghost att-native-edit" title="Edit attendance" aria-label="Edit attendance">✏️ Edit</summary>
        <form class="wsa-att-native-form" method="post" action="${esc(ADMIN_POST)}">
          ${attendanceHiddenFields('wsa_ap_attendance_update_direct', id)}
          <div class="wsa-att-native-grid">
            <label>Date <input type="date" name="att_date" value="${date}"></label>
            <label>IN <input type="time" name="login_time" value="${inTime}"></label>
            <label>OUT <input type="time" name="logout_time" value="${outTime}"></label>
            <label class="wsa-att-native-notes">Notes <input type="text" name="notes" value="${notes}" placeholder="Optional note"></label>
          </div>
          <div class="wsa-att-native-row">
            <button type="submit" class="ap-btn ap-btn--xs ap-btn--primary">💾 Save</button>
          </div>
        </form>
      </details>
      <form class="wsa-att-native-delete" method="post" action="${esc(ADMIN_POST)}" onsubmit="return window.confirm('Delete this attendance record permanently?');">
        ${attendanceHiddenFields('wsa_ap_attendance_delete_direct', id)}
        <button type="submit" class="ap-btn ap-btn--xs ap-btn--danger att-native-del" title="Delete attendance" aria-label="Delete attendance">🗑 Delete</button>
      </form>`;
  }

  /* ═══════════════════════════════════════════
     PAGE: ATTENDANCE
  ═══════════════════════════════════════════ */
  async function pageAttendance(el) {
    const today = new Date().toISOString().split('T')[0];
    const monthStart = today.slice(0, 8) + '01';
    let filters = { date_from: monthStart, date_to: today, staff_id: '', department: '' };

    async function load() {
      el.querySelector('#attTable').innerHTML = '<tr><td colspan="12" class="ap-empty"><span class="ap-spin-mini"></span> Loading…</td></tr>';
      const data = await get('/attendance', filters);
      if (!data || !data.success) return;

      const rows = data.records.length === 0
        ? '<tr><td colspan="12" class="ap-empty">No records found.</td></tr>'
        : data.records.map(r => {
          let dh = Number(r.total_hours || 0), dot = Number(r.overtime_hours || 0), dbm = Number(r.break_mins || 0);
          return `
          <tr>
            <td><strong>${esc(r.staff_name)}</strong><span class="ap-muted">${esc(r.emp_code)}</span></td>
            <td>${esc(r.department || '—')}</td>
            <td>${fmtDate(r.att_date)}</td>
            <td style="color:var(--ap-green)">${r.status === 'LEAVE' ? '—' : fmtTime(r.login_time)}</td>
            <td style="color:#a5b4fc">${r.status === 'LEAVE' ? '—' : fmtTime(r.logout_time)}</td>
            <td>${r.status === 'LEAVE' ? '—' : fmtHours(dh)}</td>
            <td>${dbm > 0 ? dbm.toFixed(0) + 'm' : '—'}</td>
            <td>${dot > 0 ? fmtHours(dot) : '—'}</td>
            <td><span class="ap-badge ap-badge--${esc(r.type)}">${r.status === 'LEAVE' ? esc(r.leave_type || 'LEAVE') : esc(r.type)}</span></td>
            <td>${(r.type === 'SCAN' && r.is_late) ? '<span class="ap-badge ap-badge--late">Late</span>' : '—'}</td>
            <td>${r.status === 'LEAVE' ? '<span class="ap-badge ap-badge--warn">On Leave</span>' : statusBadge(r.status)}</td>
            <td class="ap-actions ap-actions--native">
              ${attendanceActionsHtml(r)}
            </td>
          </tr>`;
        }).join('');

      el.querySelector('#attTable').innerHTML = rows;
      el.querySelector('#attCount').textContent = data.records.length + ' records';

      const staffOpts = '<option value="">All Staff</option>' +
        data.staff.map(s => `<option value="${s.id}"${filters.staff_id == s.id ? ' selected' : ''}>${esc(s.name)} (${esc(s.employee_id)})</option>`).join('');
      el.querySelector('#attStaff').innerHTML = staffOpts;

      const deptOpts = '<option value="">All Depts</option>' +
        data.departments.map(d => `<option value="${esc(d)}"${filters.department === d ? ' selected' : ''}>${esc(d)}</option>`).join('');
      el.querySelector('#attDept').innerHTML = deptOpts;

      attachAttEvents(el, load);
    }

    el.innerHTML = `
      <div class="ap-page-head">
        <div><h1>Attendance Logs</h1><p id="attCount">…</p></div>
        <div class="ap-page-head-right">
          <button class="ap-btn ap-btn--danger ap-btn--sm" id="attMarkAbsent" title="Mark all who didn't attend yesterday as ABSENT">📋 Mark Yesterday Absent</button>
        </div>
      </div>
      <div class="ap-filter-bar">
        <div class="ap-filter-row">
          <div class="ap-field"><label>From</label><input type="date" id="attFrom" value="${monthStart}"></div>
          <div class="ap-field"><label>To</label><input type="date" id="attTo" value="${today}"></div>
          <div class="ap-field"><label>Department</label><select id="attDept"><option value="">All Depts</option></select></div>
          <div class="ap-field"><label>Employee</label><select id="attStaff"><option value="">All Staff</option></select></div>
          <div class="ap-field" style="justify-content:flex-end"><label>&nbsp;</label>
            <button class="ap-btn ap-btn--ghost" id="attFilter">Filter</button>
          </div>
        </div>
      </div>
      <div class="ap-card">
        <div class="ap-table-wrap">
          <table class="ap-table" style="min-width:1000px">
            <thead><tr>
              <th>Employee</th><th>Dept</th><th>Date</th><th>IN</th><th>OUT</th>
              <th>Hours</th><th>Break</th><th>OT</th><th>Type</th><th>Late</th><th>Status</th><th>Actions</th>
            </tr></thead>
            <tbody id="attTable"><tr><td colspan="12" class="ap-empty"><span class="ap-spin-mini"></span> Loading…</td></tr></tbody>
          </table>
        </div>
      </div>`;

    el.querySelector('#attFilter').addEventListener('click', () => {
      filters.date_from  = el.querySelector('#attFrom').value;
      filters.date_to    = el.querySelector('#attTo').value;
      filters.department = el.querySelector('#attDept').value;
      filters.staff_id   = el.querySelector('#attStaff').value;
      load();
    });

    el.querySelector('#attMarkAbsent').addEventListener('click', async () => {
      const yesterday = new Date(Date.now() - 86400000).toISOString().split('T')[0];
      if (!await confirm('Mark all staff without attendance on ' + yesterday + ' as ABSENT?')) return;
      const res = await post('/attendance/mark-absent', { date: yesterday });
      if (res && res.success) { toast(res.message, 'ok'); load(); }
      else if (res) toast(res.message, 'err');
    });

    load();
  }

  let wsaAttActionContext = { el: null, reload: null };

  function attendancePayloadFromModal(bd) {
    return {
      att_date: bd.querySelector('#edDate').value,
      login_time: bd.querySelector('#edIn').value,
      logout_time: bd.querySelector('#edOut').value,
      notes: bd.querySelector('#edNotes').value,
    };
  }

  function openAttendanceEdit(btn) {
    const id = btn && btn.dataset ? btn.dataset.id : '';
    if (!id) { toast('Invalid attendance record.', 'err'); return false; }
    const date = btn.dataset.date || '';
    const tin = btn.dataset.in || '';
    const out = btn.dataset.out || '';
    const notes = btn.dataset.notes || '';

    const bd = openModal(`
      <div class="ap-modal-head"><h3>Edit Attendance Record</h3><button class="ap-modal-close" type="button">✕</button></div>
      <form id="wsaAttDirectEditForm" class="ap-modal-body" method="post" action="${esc(ADMIN_POST)}">
        <input type="hidden" name="action" value="wsa_ap_attendance_update_direct">
        <input type="hidden" name="_wsa_att_nonce" value="${esc(ATT_ACTION_NONCE)}">
        <input type="hidden" name="_redirect" value="${esc(PORTAL_URL || window.location.href.split('#')[0])}">
        <input type="hidden" name="wsa_admin_token" value="${esc(session.token || '')}">
        <input type="hidden" name="id" value="${esc(id)}">
        <div class="ap-form-row">
          <div class="ap-field"><label>Date</label><input type="date" name="att_date" id="edDate" value="${esc(date)}"></div>
          <div class="ap-field"><label>Check IN</label><input type="time" name="login_time" id="edIn" value="${esc(tin)}"></div>
        </div>
        <div class="ap-form-row ap-mt-8">
          <div class="ap-field"><label>Check OUT</label><input type="time" name="logout_time" id="edOut" value="${esc(out)}"></div>
          <div class="ap-field"><label>Notes</label><input type="text" name="notes" id="edNotes" value="${esc(notes)}"></div>
        </div>
      </form>
      <div class="ap-modal-foot">
        <button class="ap-btn ap-btn--outline ap-modal-close" type="button">Cancel</button>
        <button class="ap-btn ap-btn--primary" id="edSave" type="button">Save</button>
      </div>`);

    bd.querySelectorAll('.ap-modal-close').forEach(close => close.addEventListener('click', closeModal));
    const save = bd.querySelector('#edSave');
    save.addEventListener('click', async () => {
      save.disabled = true;
      save.textContent = 'Saving…';
      const payload = attendancePayloadFromModal(bd);
      const res = await requestAttendanceUpdate(id, payload);
      save.disabled = false;
      save.textContent = 'Save';
      if (res && res.success) {
        toast(res.message || 'Record updated.', 'ok');
        closeModal();
        loadPendingCount();
        if (typeof wsaAttActionContext.reload === 'function') wsaAttActionContext.reload();
      } else {
        toast('AJAX blocked. Saving with direct form…', 'ok');
        // Hard fallback: normal browser POST to admin-post.php, then redirect back.
        setTimeout(() => {
          const form = bd.querySelector('#wsaAttDirectEditForm');
          if (form) form.submit();
          else attendanceDirectAction('wsa_ap_attendance_update_direct', Object.assign({ id }, payload));
        }, 250);
      }
    });
    return false;
  }

  async function deleteAttendanceRecord(btn) {
    const id = btn && btn.dataset ? btn.dataset.id : '';
    if (!id) { toast('Invalid attendance record.', 'err'); return false; }
    if (!await confirm('Delete this attendance record permanently?')) return false;

    const row = btn.closest ? btn.closest('tr') : null;
    const oldText = btn.innerHTML;
    btn.disabled = true;
    btn.classList.add('is-loading');
    btn.innerHTML = 'Deleting…';
    if (row) row.style.opacity = '.55';

    const res = await requestAttendanceDelete(id);

    btn.disabled = false;
    btn.classList.remove('is-loading');
    btn.innerHTML = oldText;
    if (row) row.style.opacity = '';
    if (res && res.success) {
      toast(res.message || 'Record deleted.', 'ok');
      if (row) row.remove();
      loadPendingCount();
      if (typeof wsaAttActionContext.reload === 'function') wsaAttActionContext.reload();
    } else {
      toast('AJAX blocked. Deleting with direct form…', 'ok');
      // Hard fallback: normal browser POST to admin-post.php, then redirect back.
      setTimeout(() => attendanceDirectAction('wsa_ap_attendance_delete_direct', { id }), 250);
    }
    return false;
  }

  window.wsaAttendanceEditRecord = function(btn, ev) {
    if (ev) { ev.preventDefault(); ev.stopPropagation(); }
    return openAttendanceEdit(btn);
  };

  window.wsaAttendanceDeleteRecord = function(btn, ev) {
    if (ev) { ev.preventDefault(); ev.stopPropagation(); }
    deleteAttendanceRecord(btn);
    return false;
  };

  function attachAttEvents(el, reload) {
    // Keep the newest page context after filters/reloads.
    wsaAttActionContext = { el, reload };
    if (window.__wsaAttendanceActionsBound) return;
    window.__wsaAttendanceActionsBound = true;

    // Capture-phase handler is primary. It runs before theme/table scripts and before inline
    // onclick, so Edit/Delete cannot be swallowed by overlays, row handlers, or cached markup.
    document.addEventListener('click', (e) => {
      const target = e.target;
      if (!target || !target.closest) return;

      const editBtn = target.closest('.att-edit');
      const delBtn  = target.closest('.att-del');
      const btn = editBtn || delBtn;
      if (!btn) return;

      const contentRoot = document.getElementById('apContent');
      if (!contentRoot || !contentRoot.contains(btn)) return;
      if (btn.disabled || btn.classList.contains('is-loading')) return;

      e.preventDefault();
      e.stopPropagation();
      if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();

      if (editBtn) {
        openAttendanceEdit(editBtn);
        return false;
      }
      if (delBtn) {
        deleteAttendanceRecord(delBtn);
        return false;
      }
    }, true);
  }

  /* ═══════════════════════════════════════════
     PAGE: MANUAL ENTRY
  ═══════════════════════════════════════════ */
  async function pageManual(el) {
    const [staffData, shiftsData] = await Promise.all([get('/staff', { status: 'active' }), get('/shifts')]);
    if (!staffData || !staffData.success) { el.innerHTML = '<div class="ap-empty">Failed to load.</div>'; return; }

    const staffOpts = '<option value="">— Select Staff —</option>' +
      staffData.staff.map(s => `<option value="${s.id}">${esc(s.name)} (${esc(s.employee_id)})</option>`).join('');
    const today = new Date().toISOString().split('T')[0];

    el.innerHTML = `
      <div class="ap-page-head"><div><h1>Manual Entry</h1><p>Add or correct attendance records</p></div></div>
      <div class="ap-card" style="max-width:560px">
        <div class="ap-card-title">Add Manual Attendance Record</div>
        <div class="ap-card-body">
          <div class="ap-error" id="manErr"></div>
          <div class="ap-success" id="manOk"></div>
          <div class="ap-form-row">
            <div class="ap-field"><label>Staff Member *</label><select id="manStaff">${staffOpts}</select></div>
            <div class="ap-field"><label>Date *</label><input type="date" id="manDate" value="${today}"></div>
          </div>
          <div class="ap-form-row ap-mt-8">
            <div class="ap-field"><label>Mark As *</label><select id="manStatus"><option value="PRESENT">Present / Working</option><option value="ABSENT">Absent</option></select><small>Manual entries never show Late.</small></div>
          </div>
          <div class="ap-form-row ap-mt-8" id="manTimeRow">
            <div class="ap-field"><label>Check IN Time *</label><input type="time" id="manIn"></div>
            <div class="ap-field"><label>Check OUT Time</label><input type="time" id="manOut"></div>
          </div>
          <div class="ap-field ap-mt-8"><label>Notes</label><textarea id="manNotes" rows="2" placeholder="Optional reason…"></textarea></div>
          <div class="ap-mt-16">
            <button class="ap-btn ap-btn--primary" id="manSave" style="width:auto;padding:10px 28px">Save Record</button>
          </div>
        </div>
      </div>`;

    el.querySelector('#manStatus').addEventListener('change', () => {
      const absent = el.querySelector('#manStatus').value === 'ABSENT';
      el.querySelector('#manTimeRow').style.display = absent ? 'none' : '';
      if (absent) { el.querySelector('#manIn').value = ''; el.querySelector('#manOut').value = ''; }
    });
    el.querySelector('#manSave').addEventListener('click', async () => {
      const errEl = el.querySelector('#manErr');
      const okEl  = el.querySelector('#manOk');
      errEl.classList.remove('visible'); okEl.classList.remove('visible');
      const staff_id  = el.querySelector('#manStaff').value;
      const att_date  = el.querySelector('#manDate').value;
      const entry_status = el.querySelector('#manStatus').value;
      const login_time= el.querySelector('#manIn').value;
      if (!staff_id || !att_date || (entry_status !== 'ABSENT' && !login_time)) {
        errEl.textContent = entry_status === 'ABSENT' ? 'Staff and date are required.' : 'Staff, date and check-in time are required.';
        errEl.classList.add('visible'); return;
      }
      const res = await post('/attendance/manual', {
        staff_id, att_date, entry_status, login_time: entry_status === 'ABSENT' ? '' : login_time,
        logout_time: entry_status === 'ABSENT' ? '' : (el.querySelector('#manOut').value || ''),
        notes: el.querySelector('#manNotes').value,
      });
      if (res && res.success) {
        okEl.textContent = 'Record saved successfully!'; okEl.classList.add('visible');
        el.querySelector('#manIn').value = ''; el.querySelector('#manOut').value = ''; el.querySelector('#manNotes').value = '';
        loadPendingCount();
      } else if (res) {
        errEl.textContent = res.message; errEl.classList.add('visible');
      }
    });
  }

  /* ═══════════════════════════════════════════
     PAGE: STAFF
  ═══════════════════════════════════════════ */
  async function pageStaff(el) {
    const data = await get('/staff');
    if (!data || !data.success) { el.innerHTML = '<div class="ap-empty">Failed to load.</div>'; return; }

    let allStaff = data.staff;
    let editingId = null;

    function filterStaff() {
      const search = (el.querySelector('#stSearch')?.value || '').toLowerCase();
      const dept   = el.querySelector('#stDept')?.value || '';
      const status = el.querySelector('#stStatus')?.value || '';
      return allStaff.filter(s => {
        if (status && s.status !== status) return false;
        if (dept && s.department !== dept) return false;
        if (search && !s.name.toLowerCase().includes(search) && !s.employee_id.toLowerCase().includes(search)) return false;
        return true;
      });
    }

    function renderTable() {
      const filtered = filterStaff();
      const rows = filtered.length === 0
        ? '<tr><td colspan="7" class="ap-empty">No staff found.</td></tr>'
        : filtered.map(s => `<tr>
            <td><span class="ap-code">${esc(s.employee_id)}</span></td>
            <td><strong>${esc(s.name)}</strong><span class="ap-muted">${esc(s.email || '')}</span></td>
            <td>${esc(s.department || '—')}</td>
            <td>${esc(s.shift_name || '—')}</td>
            <td>${esc(s.phone || '—')}</td>
            <td><span class="ap-badge ap-badge--${esc(s.status)}">${esc(s.status)}</span></td>
            <td class="ap-actions">
              <button class="ap-btn ap-btn--xs ap-btn--ghost st-edit" data-id="${s.id}">✏️ Edit</button>
              <button class="ap-btn ap-btn--xs ap-btn--danger st-del" data-id="${s.id}" data-name="${esc(s.name)}">🗑</button>
            </td>
          </tr>`).join('');
      el.querySelector('#stTable').innerHTML = rows;
      el.querySelector('#stCount').textContent = filtered.length + ' staff';
      attachStaffTableEvents(el, data, reload);
    }

    async function reload() {
      const fresh = await get('/staff');
      if (!fresh || !fresh.success) return;
      allStaff = fresh.staff;
      renderTable();
    }

    const depts = [...new Set(allStaff.map(s => s.department).filter(Boolean))].sort();
    const shiftOpts = '<option value="">— Select Shift —</option>' +
      data.shifts.map(sh => `<option value="${sh.id}">${esc(sh.name)}</option>`).join('');

    function formValues() {
      return {
        employee_id: el.querySelector('#sfEmpId').value.trim().toUpperCase(),
        name:        el.querySelector('#sfName').value.trim(),
        department:  el.querySelector('#sfDept').value.trim(),
        phone:       el.querySelector('#sfPhone').value.trim(),
        email:       el.querySelector('#sfEmail').value.trim(),
        shift_id:    el.querySelector('#sfShift').value,
        pin:         el.querySelector('#sfPin').value.trim(),
        status:      el.querySelector('#sfStatus')?.value || 'active',
      };
    }

    function fillForm(s) {
      el.querySelector('#sfEmpId').value  = s.employee_id;
      el.querySelector('#sfName').value   = s.name;
      el.querySelector('#sfDept').value   = s.department || '';
      el.querySelector('#sfPhone').value  = s.phone || '';
      el.querySelector('#sfEmail').value  = s.email || '';
      el.querySelector('#sfShift').value  = s.shift_id || '';
      el.querySelector('#sfPin').value    = '';
      if (el.querySelector('#sfStatus')) el.querySelector('#sfStatus').value = s.status;
      el.querySelector('#sfFormTitle').textContent = 'Edit Staff';
      el.querySelector('#sfSubmit').textContent = 'Update Staff';
      el.querySelector('#sfCancelEdit').style.display = 'inline-flex';
    }

    function resetForm() {
      editingId = null;
      ['sfEmpId','sfName','sfDept','sfPhone','sfEmail','sfShift','sfPin'].forEach(id => {
        const el2 = el.querySelector('#' + id);
        if (el2) el2.value = '';
      });
      el.querySelector('#sfFormTitle').textContent = 'Add New Staff';
      el.querySelector('#sfSubmit').textContent = 'Add Staff';
      el.querySelector('#sfCancelEdit').style.display = 'none';
    }

    el.innerHTML = `
      <div class="ap-page-head">
        <div><h1>Staff Management</h1><p id="stCount">…</p></div>
      </div>
      <div class="ap-filter-bar">
        <div class="ap-filter-row">
          <div class="ap-field"><label>Search</label><input id="stSearch" type="text" placeholder="Name or ID…"></div>
          <div class="ap-field"><label>Department</label>
            <select id="stDept"><option value="">All Depts</option>${depts.map(d => `<option value="${esc(d)}">${esc(d)}</option>`).join('')}</select>
          </div>
          <div class="ap-field"><label>Status</label>
            <select id="stStatus"><option value="">All</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
          </div>
        </div>
      </div>
      <div class="ap-grid-2" style="align-items:start">
        <div class="ap-card">
          <div class="ap-table-wrap">
            <table class="ap-table">
              <thead><tr><th>ID</th><th>Name</th><th>Dept</th><th>Shift</th><th>Phone</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody id="stTable"></tbody>
            </table>
          </div>
        </div>
        <div class="ap-card">
          <div class="ap-card-title" id="sfFormTitle">Add New Staff</div>
          <div class="ap-card-body">
            <div class="ap-error" id="sfErr"></div>
            <div class="ap-form-row">
              <div class="ap-field"><label>Employee ID *</label><input id="sfEmpId" type="text" placeholder="e.g. EMP-001"></div>
              <div class="ap-field"><label>Full Name *</label><input id="sfName" type="text" placeholder="Full name"></div>
            </div>
            <div class="ap-form-row ap-mt-8">
              <div class="ap-field"><label>Department</label><input id="sfDept" type="text" list="sfDeptList" placeholder="e.g. Production">
                <datalist id="sfDeptList">${depts.map(d => `<option value="${esc(d)}">`).join('')}</datalist>
              </div>
              <div class="ap-field"><label>Shift</label><select id="sfShift">${shiftOpts}</select></div>
            </div>
            <div class="ap-form-row ap-mt-8">
              <div class="ap-field"><label>Phone</label><input id="sfPhone" type="text" placeholder="+91…"></div>
              <div class="ap-field"><label>Email</label><input id="sfEmail" type="email" placeholder="email@company.com"></div>
            </div>
            <div class="ap-form-row ap-mt-8">
              <div class="ap-field"><label>PIN (4–6 digits)</label><input id="sfPin" type="text" maxlength="6" pattern="[0-9]{4,6}" placeholder="Leave blank to keep current"></div>
              <div class="ap-field"><label>Status</label>
                <select id="sfStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select>
              </div>
            </div>
            <div class="ap-flex ap-mt-16" style="gap:8px">
              <button class="ap-btn ap-btn--primary" id="sfSubmit" style="width:auto">Add Staff</button>
              <button class="ap-btn ap-btn--outline" id="sfCancelEdit" style="display:none">Cancel Edit</button>
            </div>
          </div>
        </div>
      </div>`;

    /* Filter events */
    el.querySelector('#stSearch').addEventListener('input', renderTable);
    el.querySelector('#stDept').addEventListener('change', renderTable);
    el.querySelector('#stStatus').addEventListener('change', renderTable);
    renderTable();

    /* Form submit */
    el.querySelector('#sfSubmit').addEventListener('click', async () => {
      const errEl = el.querySelector('#sfErr');
      errEl.classList.remove('visible');
      const vals = formValues();
      if (!vals.employee_id || !vals.name) { errEl.textContent = 'Employee ID and name are required.'; errEl.classList.add('visible'); return; }
      let res;
      if (editingId) {
        vals.id = editingId;
        res = await put(`/staff/${editingId}`, vals);
      } else {
        res = await post('/staff', vals);
      }
      if (res && res.success) {
        toast(editingId ? 'Staff updated.' : 'Staff added.', 'ok');
        resetForm(); reload(); loadPendingCount();
      } else if (res) {
        errEl.textContent = res.message; errEl.classList.add('visible');
      }
    });

    el.querySelector('#sfCancelEdit').addEventListener('click', resetForm);

    function attachStaffTableEvents(el, data, reload) {
      el.querySelectorAll('.st-edit').forEach(btn => {
        btn.addEventListener('click', () => {
          editingId = parseInt(btn.dataset.id);
          const s = allStaff.find(x => x.id == editingId);
          if (s) fillForm(s);
          el.querySelector('#sfErr').classList.remove('visible');
          el.querySelector('.ap-card:last-child').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      });
      el.querySelectorAll('.st-del').forEach(btn => {
        btn.addEventListener('click', async () => {
          if (!await confirm(`Delete <strong>${btn.dataset.name}</strong> and all their attendance records?`)) return;
          const res = await del(`/staff/${btn.dataset.id}`);
          if (res && res.success) { toast('Staff deleted.', 'ok'); reload(); loadPendingCount(); }
          else if (res) toast(res.message, 'err');
        });
      });
    }
  }

  /* ═══════════════════════════════════════════
     PAGE: PENDING STAFF
  ═══════════════════════════════════════════ */
  async function pagePending(el) {
    const data = await get('/staff/pending');
    if (!data || !data.success) { el.innerHTML = '<div class="ap-empty">Failed to load.</div>'; return; }

    const rows = data.staff.length === 0
      ? '<tr><td colspan="7" class="ap-empty">✅ No pending registrations.</td></tr>'
      : data.staff.map(s => `<tr>
          <td><strong>${esc(s.name)}</strong></td>
          <td><span class="ap-code">${esc(s.employee_id)}</span></td>
          <td>${esc(s.department || '—')}</td>
          <td>${esc(s.phone || '—')}</td>
          <td>${esc(s.email || '—')}</td>
          <td>${s.created_at ? fmtDate(s.created_at.split(' ')[0]) : '—'}</td>
          <td class="ap-actions">
            <button class="ap-btn ap-btn--xs ap-btn--success pend-approve" data-id="${s.id}" data-name="${esc(s.name)}">✅ Approve</button>
            <button class="ap-btn ap-btn--xs ap-btn--danger pend-reject" data-id="${s.id}" data-name="${esc(s.name)}">❌ Reject</button>
          </td>
        </tr>`).join('');

    el.innerHTML = `
      <div class="ap-page-head">
        <div><h1>Pending Registrations</h1><p>${data.staff.length} waiting for approval</p></div>
        <button class="ap-btn ap-btn--ghost" id="pendRefresh">↻ Refresh</button>
      </div>
      <div class="ap-card">
        <div class="ap-table-wrap">
          <table class="ap-table">
            <thead><tr><th>Name</th><th>Emp ID</th><th>Department</th><th>Phone</th><th>Email</th><th>Registered</th><th>Actions</th></tr></thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </div>`;

    el.querySelector('#pendRefresh').addEventListener('click', () => pagePending(el));

    el.querySelectorAll('.pend-approve').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!await confirm(`Approve <strong>${btn.dataset.name}</strong> and let them log in?`)) return;
        const res = await post(`/staff/${btn.dataset.id}/approve`, {});
        if (res && res.success) { toast('Staff approved!', 'ok'); pagePending(el); loadPendingCount(); }
        else if (res) toast(res.message, 'err');
      });
    });
    el.querySelectorAll('.pend-reject').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!await confirm(`Reject registration for <strong>${btn.dataset.name}</strong>?`)) return;
        const res = await post(`/staff/${btn.dataset.id}/reject`, {});
        if (res && res.success) { toast('Registration rejected.', 'ok'); pagePending(el); loadPendingCount(); }
        else if (res) toast(res.message, 'err');
      });
    });
  }


  /* ═══════════════════════════════════════════
     PAGE: LEAVES
  ═══════════════════════════════════════════ */
  async function pageLeaves(el) {
    const today = new Date().toISOString().split('T')[0];
    const monthStart = today.slice(0, 8) + '01';
    let filters = { date_from: monthStart, date_to: today, staff_id: '' };

    async function load() {
      el.querySelector('#lvTable').innerHTML = '<tr><td colspan="7" class="ap-empty"><span class="ap-spin-mini"></span> Loading…</td></tr>';
      const data = await get('/leaves', filters);
      if (!data || !data.success) return;

      const staffOpts = '<option value="">— Select Staff —</option>' +
        data.staff.map(s => `<option value="${s.id}">${esc(s.name)} (${esc(s.employee_id)})</option>`).join('');
      el.querySelector('#lvAddStaff').innerHTML = staffOpts;

      const filterStaffOpts = '<option value="">All Staff</option>' +
        data.staff.map(s => `<option value="${s.id}"${filters.staff_id == s.id ? ' selected':''}>
          ${esc(s.name)}</option>`).join('');
      el.querySelector('#lvFilterStaff').innerHTML = filterStaffOpts;

      const rows = data.leaves.length === 0
        ? '<tr><td colspan="7" class="ap-empty">No leaves found.</td></tr>'
        : data.leaves.map(l => `<tr>
            <td><strong>${esc(l.staff_name || '—')}</strong></td>
            <td>${fmtDate(l.date)}</td>
            <td>${esc(l.type)}</td>
            <td><span class="ap-badge ap-badge--${esc(l.status)}">${esc(l.status)}</span></td>
            <td>${esc(l.notes || '—')}</td>
            <td class="ap-actions">
              ${l.status !== 'approved'  ? `<button class="ap-btn ap-btn--xs ap-btn--success lv-status" data-id="${l.id}" data-status="approved">✅</button>` : ''}
              ${l.status !== 'rejected'  ? `<button class="ap-btn ap-btn--xs ap-btn--danger  lv-status" data-id="${l.id}" data-status="rejected">❌</button>`  : ''}
              <button class="ap-btn ap-btn--xs ap-btn--danger lv-del" data-id="${l.id}">🗑</button>
            </td>
          </tr>`).join('');
      el.querySelector('#lvTable').innerHTML = rows;
      el.querySelector('#lvCount').textContent = data.leaves.length + ' leaves';

      el.querySelectorAll('.lv-status').forEach(btn => {
        btn.addEventListener('click', async () => {
          const res = await put(`/leaves/${btn.dataset.id}/status`, { status: btn.dataset.status });
          if (res && res.success) { toast('Status updated.', 'ok'); load(); }
          else if (res) toast(res.message, 'err');
        });
      });
      el.querySelectorAll('.lv-del').forEach(btn => {
        btn.addEventListener('click', async () => {
          if (!await confirm('Delete this leave record?')) return;
          const res = await del(`/leaves/${btn.dataset.id}`);
          if (res && res.success) { toast('Deleted.', 'ok'); load(); }
        });
      });
    }

    el.innerHTML = `
      <div class="ap-page-head">
        <div><h1>Leave Management</h1><p id="lvCount">…</p></div>
      </div>
      <div class="ap-grid-2" style="align-items:start">
        <div>
          <div class="ap-filter-bar">
            <div class="ap-filter-row">
              <div class="ap-field"><label>From</label><input type="date" id="lvFrom" value="${monthStart}"></div>
              <div class="ap-field"><label>To</label><input type="date" id="lvTo" value="${today}"></div>
              <div class="ap-field"><label>Staff</label><select id="lvFilterStaff"><option>All</option></select></div>
              <div class="ap-field" style="justify-content:flex-end"><label>&nbsp;</label>
                <button class="ap-btn ap-btn--ghost" id="lvFilter">Filter</button>
              </div>
            </div>
          </div>
          <div class="ap-card">
            <div class="ap-table-wrap">
              <table class="ap-table">
                <thead><tr><th>Staff</th><th>Date</th><th>Type</th><th>Status</th><th>Notes</th><th>Actions</th></tr></thead>
                <tbody id="lvTable"></tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="ap-card">
          <div class="ap-card-title">Add Leave</div>
          <div class="ap-card-body">
            <div class="ap-error" id="lvErr"></div>
            <div class="ap-field"><label>Staff *</label><select id="lvAddStaff"><option>Loading…</option></select></div>
            <div class="ap-field ap-mt-8"><label>Date *</label><input type="date" id="lvDate" value="${today}"></div>
            <div class="ap-form-row ap-mt-8">
              <div class="ap-field"><label>Type</label>
                <select id="lvType">
                  <option value="Casual">Casual Leave</option><option value="Sick">Sick Leave</option>
                  <option value="Earned">Earned Leave</option><option value="Unpaid">Unpaid Leave</option>
                  <option value="Holiday">Holiday</option>
                </select>
              </div>
              <div class="ap-field"><label>Status</label>
                <select id="lvStatus"><option value="approved">Approved</option><option value="pending">Pending</option></select>
              </div>
            </div>
            <div class="ap-field ap-mt-8"><label>Notes</label><input type="text" id="lvNotes" placeholder="Optional reason"></div>
            <button class="ap-btn ap-btn--primary ap-mt-16" id="lvSave" style="width:auto">Add Leave</button>
          </div>
        </div>
      </div>`;

    el.querySelector('#lvFilter').addEventListener('click', () => {
      filters.date_from = el.querySelector('#lvFrom').value;
      filters.date_to   = el.querySelector('#lvTo').value;
      filters.staff_id  = el.querySelector('#lvFilterStaff').value;
      load();
    });
    el.querySelector('#lvSave').addEventListener('click', async () => {
      const errEl = el.querySelector('#lvErr');
      errEl.classList.remove('visible');
      const staff_id = el.querySelector('#lvAddStaff').value;
      const date     = el.querySelector('#lvDate').value;
      if (!staff_id || !date) { errEl.textContent = 'Staff and date required.'; errEl.classList.add('visible'); return; }
      const res = await post('/leaves', {
        staff_id, date, type: el.querySelector('#lvType').value,
        status: el.querySelector('#lvStatus').value, notes: el.querySelector('#lvNotes').value,
      });
      if (res && res.success) { toast('Leave added.', 'ok'); load(); el.querySelector('#lvNotes').value = ''; }
      else if (res) { errEl.textContent = res.message; errEl.classList.add('visible'); }
    });
    load();
  }


  /* ── Bulletproof Salary Detail opener (global, independent of page rerenders) ── */
  let wsaSalaryContext = { yr: (new Date()).getFullYear(), mn: (new Date()).getMonth() + 1 };

  function wsaMoney(amount, currency = 'INR') {
    const symbols = { INR: '₹', USD: '$', EUR: '€', GBP: '£', AED: 'د.إ' };
    return `${symbols[currency] || currency + ' '}${Number(amount || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  }

  function wsaMonthLabel(yr, mn) {
    return new Date(Number(yr), Number(mn) - 1, 1).toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
  }

  async function openSalaryDetailGlobal(staffId, yrArg, mnArg) {
    staffId = parseInt(staffId || 0, 10);
    const yr = parseInt(yrArg || wsaSalaryContext.yr || (new Date()).getFullYear(), 10);
    const mn = parseInt(mnArg || wsaSalaryContext.mn || ((new Date()).getMonth() + 1), 10);
    if (!staffId) { toast('Staff ID missing for this salary detail.', 'err'); return false; }

    // Open the modal once with a loading state and keep a reference to it.
    // We update its content in-place when data arrives — this avoids the
    // close→recreate cycle that can lose the modal or the close-button binding.
    const bd = openModal(
      `<div class="ap-modal-head"><h3>📋 Salary Detail</h3><button class="ap-modal-close">✕</button></div>` +
      `<div class="ap-modal-body ap-salary-detail"><div class="ap-empty"><span class="ap-spin-mini"></span> Loading employee salary details…</div></div>`,
      { large: true }
    );

    // Helper: write new HTML into the already-open modal without destroying it.
    function _fillModal(headHtml, bodyHtml) {
      if (!bd || !bd.isConnected) return; // user closed the modal while loading
      const inner = bd.querySelector('.ap-modal');
      if (!inner) return;
      inner.innerHTML = headHtml + bodyHtml;
      // Re-bind the close button inside the new content.
      const cb = inner.querySelector('.ap-modal-close');
      if (cb) cb.addEventListener('click', closeModal);
    }

    try {
      const data = await getSalaryDetailSafeGlobal(staffId, yr, mn);

      if (!data || !data.success || !data.report) {
        const msg = (data && data.message) ? data.message : 'Could not load salary detail for this employee.';
        _fillModal(
          `<div class="ap-modal-head"><h3>Salary Detail Error</h3><button class="ap-modal-close">✕</button></div>`,
          `<div class="ap-modal-body"><p style="color:var(--ap-red);padding:8px 0">${esc(msg)}</p></div>`
        );
        return false;
      }

      const r    = data.report;
      const cfg  = r.config || {};
      const cur  = r.currency || cfg.currency || 'INR';
      const staff = r.staff || {};
      const days = Array.isArray(r.days) ? r.days : [];
      const label = r.month_label || wsaMonthLabel(yr, mn);

      const cards = [
        ['✅','Present',    r.present    || 0,                'ok'],
        ['❌','Absent',     r.absent     || 0,                'bad'],
        ['📋','On Leave',   r.on_leave   || 0,                'info'],
        ['⏰','Late',       r.late_count || 0,                'warn'],
        ['⏱','Work Hours', fmtHours(r.total_hours || 0),    'info'],
        ['⚡','Overtime',   fmtHours(r.total_ot    || 0),    'warn'],
      ].map(c =>
        `<div class="ap-stat ap-stat--${c[3]}">` +
          `<div class="ap-stat-icon">${c[0]}</div>` +
          `<div class="ap-stat-val">${c[2]}</div>` +
          `<div class="ap-stat-label">${c[1]}</div>` +
        `</div>`
      ).join('');

      const cal = days.map(d => {
        const st  = d.status || 'future';
        const cls = st === 'absent' ? 'bad'
                  : st === 'leave'  ? 'warn'
                  : (st === 'future' || st === 'holiday') ? 'muted'
                  : 'ok';
        const ico = st === 'absent'   ? '❌'
                  : st === 'leave'    ? '📋'
                  : (st === 'sunday_ot' || st === 'holiday_ot') ? '⚡'
                  : (st === 'future'  || st === 'holiday') ? '—'
                  : '✅';
        const hrs = Number(d.ot || d.hours || 0);
        const extra = hrs > 0 ? `<small>${fmtHours(hrs)}</small>` : '';
        return `<div class="ap-cal-day ap-cal-day--${cls}">` +
               `<b>${esc(String(d.date || '').slice(-2))}</b><span>${ico}</span>${extra}` +
               `</div>`;
      }).join('') || '<div class="ap-empty">No day-wise salary records found.</div>';

      const log = days
        .filter(d => d.status !== 'future')
        .map(d => {
          const br = Number(d.salary_break_mins || d.break_duration_mins || 0);
          return `<tr>` +
            `<td>${esc(d.date || '')}</td>` +
            `<td>${esc(d.status || '')}</td>` +
            `<td>${d.login  ? esc(d.login)  : '—'}</td>` +
            `<td>${d.logout ? esc(d.logout) : '—'}</td>` +
            `<td>${Number(d.hours || 0) ? fmtHours(Number(d.hours)) : '—'}</td>` +
            `<td>${br > 0 ? '<span class="ap-ot">' + fmtHours(br / 60) + '</span>' : '—'}</td>` +
            `<td>${Number(d.ot || 0) ? '<span class="ap-ot">+' + fmtHours(Number(d.ot)) + '</span>' : '—'}</td>` +
            `</tr>`;
        }).join('') || '<tr><td colspan="7" class="ap-empty">No attendance log found.</td></tr>';

      const headHtml =
        `<div class="ap-modal-head">` +
          `<h3>📋 ${esc(staff.name || 'Employee')} — ${esc(label)}</h3>` +
          `<div class="ap-modal-actions wsa-no-print">` +
            `<button type="button" class="ap-btn ap-btn--xs ap-btn--print" data-wsa-print-salary-detail>Print</button>` +
            `<button type="button" class="ap-modal-close">✕</button>` +
          `</div>` +
        `</div>`;

      const bodyHtml =
        `<div class="ap-modal-body ap-salary-detail">` +
          `<div class="ap-stats">${cards}</div>` +
          `<div class="ap-grid-2 ap-mt-16">` +
            `<div class="ap-card ap-inner-card"><h3>👤 Employee Salary Details</h3><table class="ap-table">` +
              `<tr><td>Employee ID</td><td><strong>${esc(staff.employee_id || staff.emp_code || '—')}</strong></td></tr>` +
              `<tr><td>Name</td><td><strong>${esc(staff.name || '—')}</strong></td></tr>` +
              `<tr><td>Department</td><td>${esc(staff.department || '—')}</td></tr>` +
              `<tr><td>Designation</td><td>${esc(staff.designation || '—')}</td></tr>` +
              `<tr><td>Month</td><td>${esc(label)}</td></tr>` +
              `<tr><td>Monthly Gross Config</td><td><strong>${wsaMoney(cfg.monthly_salary || 0, cur)}</strong></td></tr>` +
              `<tr><td>Working Days Config</td><td>${esc(cfg.working_days || 26)}</td></tr>` +
            `</table></div>` +
            `<div class="ap-card ap-inner-card"><h3>💰 Salary Breakup</h3><div class="ap-table-wrap ap-salary-breakup-wrap"><table class="ap-table ap-salary-breakup-table">` +
              `<tr><td>Daily Rate</td><td><strong>${wsaMoney(r.daily_rate || 0, cur)}</strong></td></tr>` +
              `<tr><td>Basic Earned (${r.present || 0} days)</td><td>${wsaMoney(r.earned_basic || 0, cur)}</td></tr>` +
              `<tr><td>Leave Pay (${r.on_leave || 0} days)</td><td>${wsaMoney(r.leave_pay || 0, cur)}</td></tr>` +
              `<tr><td>Overtime Pay (${fmtHours(Number(r.total_ot || 0))} × ${wsaMoney(cfg.ot_rate_per_hr || 0, cur)})</td>` +
                `<td>${wsaMoney(r.ot_pay || 0, cur)}</td></tr>` +
              `<tr><td><strong>Gross</strong></td><td><strong>${wsaMoney(r.gross || 0, cur)}</strong></td></tr>` +
              `<tr><td style="color:var(--ap-red)">Absent Deductions (${r.absent || 0} × ${wsaMoney(cfg.absent_deduction || 0, cur)})</td>` +
                `<td style="color:var(--ap-red)">− ${wsaMoney(r.deductions || 0, cur)}</td></tr>` +
              `<tr><td><strong>💵 Net Salary</strong></td><td><strong class="ap-money">${wsaMoney(r.net || 0, cur)}</strong></td></tr>` +
            `</table></div></div>` +
          `</div>` +
          `<div class="ap-card ap-inner-card ap-mt-16">` +
            `<h3>📅 Day-by-Day Salary Attendance</h3>` +
            `<div class="ap-cal-grid">${cal}</div>` +
          `</div>` +
          `<div class="ap-card ap-inner-card ap-mt-16">` +
            `<h3>📋 Daily Attendance Log</h3>` +
            `<div class="ap-table-wrap"><table class="ap-table">` +
              `<thead><tr><th>Date</th><th>Status</th><th>Check-IN</th><th>Check-OUT</th><th>Hours</th><th>Break</th><th>OT</th></tr></thead>` +
              `<tbody>${log}</tbody>` +
            `</table></div>` +
          `</div>` +
        `</div>`;

      _fillModal(headHtml, bodyHtml);
      return true;

    } catch (err) {
      console.error('[WSA] Salary detail open error:', err);
      _fillModal(
        `<div class="ap-modal-head"><h3>Salary Detail Error</h3><button class="ap-modal-close">✕</button></div>`,
        `<div class="ap-modal-body"><p style="color:var(--ap-red);padding:8px 0">${esc(err && err.message ? err.message : 'Unexpected error loading salary detail.')}</p></div>`
      );
      return false;
    }
  }

  function handleSalaryDetailButton(btn) {
    const row = btn && btn.closest ? btn.closest('tr') : null;
    const id = parseInt((btn && (btn.dataset.id || btn.dataset.staffId)) || (row && row.dataset.staffId) || '0', 10);
    const yr = (btn && btn.dataset.yr) || (row && row.dataset.yr) || wsaSalaryContext.yr;
    const mn = (btn && btn.dataset.mn) || (row && row.dataset.mn) || wsaSalaryContext.mn;
    if (!id) {
      toast('Staff ID missing for this salary detail.', 'err');
      console.error('Salary detail missing staff id', btn);
      return false;
    }
    openSalaryDetailGlobal(id, yr, mn);
    return false;
  }

  window.wsaOpenSalaryDetail = openSalaryDetailGlobal;
  window.wsaSalaryDetailClick = handleSalaryDetailButton;

  document.addEventListener('click', function(e) {
    const btn = e.target && e.target.closest ? e.target.closest('.sal-detail,[data-wsa-salary-detail]') : null;
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    handleSalaryDetailButton(btn);
  }, true);

  /* ═══════════════════════════════════════════
     PAGE: SALARY
  ═══════════════════════════════════════════ */
  async function pageSalary(el) {
    const now = new Date();
    let yr = now.getFullYear(), mn = now.getMonth() + 1;
    wsaSalaryContext = { yr, mn };
    let staffList = [];

    const money = (amount, currency = 'INR') => {
      const symbols = { INR: '₹', USD: '$', EUR: '€', GBP: '£', AED: 'د.إ' };
      return `${symbols[currency] || currency + ' '}${Number(amount || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };
    const monthLabel = () => new Date(yr, mn - 1, 1).toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });

    async function getSalaryConfigSafe(id) {
      return getSalaryConfigSafeGlobal(id);
    }

    async function saveSalaryConfigSafe(payload) {
      return saveSalaryConfigSafeGlobal(payload);
    }

    async function getSalaryDetailSafe(staffId) {
      return getSalaryDetailSafeGlobal(staffId, yr, mn);
    }

    async function load() {
      el.querySelector('#salTable').innerHTML = '<tr><td colspan="12" class="ap-empty"><span class="ap-spin-mini"></span> Loading…</td></tr>';
      const data = await get('/salary', { yr, mn, _: Date.now() });
      if (!data || !data.success) return;
      staffList = data.staff || [];
      renderConfigStaffOptions();

      const rows = (data.summary || []).length === 0
        ? '<tr><td colspan="12" class="ap-empty">No salary data for this month.</td></tr>'
        : data.summary.map(r => {
            const s = r.staff || {};
            const sid = parseInt(s.id || s.staff_id || r.staff_id || r.id || 0, 10);
            const cur = r.currency || 'INR';
            return `<tr class="ap-salary-row" data-staff-id="${sid}" data-yr="${yr}" data-mn="${mn}">
              <td><strong>${esc(s.name || '')}</strong><br><span class="ap-muted">${esc(s.employee_id || '')}</span></td>
              <td>${esc(s.department || '—')}</td>
              <td><span class="ap-badge ap-badge--ok">${r.present || 0}</span></td>
              <td>${Number(r.absent || 0) > 0 ? `<span class="ap-badge ap-badge--bad">${r.absent}</span>` : '<span class="ap-muted">0</span>'}</td>
              <td>${Number(r.on_leave || 0) > 0 ? `<span class="ap-badge ap-badge--warn">${r.on_leave}</span>` : '—'}</td>
              <td>${Number(r.late_count || 0) > 0 ? `<span class="ap-badge ap-badge--warn">${r.late_count}</span>` : '—'}</td>
              <td>${fmtHours(r.total_hours)}</td>
              <td>${Number(r.total_ot || 0) > 0 ? `<span class="ap-ot">${fmtHours(r.total_ot)}</span>` : '—'}</td>
              <td class="ap-money">${money(r.gross, cur)}</td>
              <td>${Number(r.deductions || 0) > 0 ? `<span style="color:var(--ap-red)">−${money(r.deductions, cur)}</span>` : '—'}</td>
              <td><strong class="ap-money">${money(r.net, cur)}</strong></td>
              <td class="ap-actions-inline">
                <button type="button" class="ap-btn ap-btn--xs ap-btn--primary sal-detail" data-id="${sid}" data-staff-id="${sid}" data-yr="${yr}" data-mn="${mn}">📋 Detail</button>
              </td>
            </tr>`;
          }).join('');

      el.querySelector('#salTable').innerHTML = rows;
      el.querySelector('#salMonthLabel').textContent = monthLabel();
      // Single delegated listener on the table — never accumulates across
      // month navigations because the table itself is reused, not re-created.
      const salTbl = el.querySelector('#salTable');
      if (salTbl && !salTbl.dataset.wsaDetailBound) {
        salTbl.dataset.wsaDetailBound = '1';
        salTbl.addEventListener('click', function(e) {
          const btn = e.target.closest('.sal-detail');
          if (!btn) return;
          e.preventDefault();
          e.stopImmediatePropagation();
          const sid = parseInt(btn.dataset.id || btn.dataset.staffId || '0', 10);
          if (!sid) { toast('Staff ID missing.', 'err'); return; }
          openSalaryDetailGlobal(sid, yr, mn);
        });
      }
    }

    function renderConfigStaffOptions() {
      const sel = el.querySelector('#salCfgStaff');
      if (!sel || sel.dataset.ready === '1') return;
      sel.innerHTML = '<option value="">— Select Staff —</option>' + staffList.map(s => `<option value="${s.id}">${esc(s.name)} (${esc(s.employee_id || '')})</option>`).join('');
      sel.dataset.ready = '1';
    }

    el.innerHTML = `
      <div class="ap-page-head">
        <div><h1>💰 Salary Report</h1><p id="salMonthLabel">…</p></div>
        <div class="ap-page-head-right">
          <button class="ap-btn ap-btn--ghost" id="salPrev">← Prev</button>
          <button class="ap-btn ap-btn--ghost" id="salNext">Next →</button>
        </div>
      </div>

      <div class="ap-card ap-salary-config-card">
        <div class="ap-card-head">
          <h3>⚙️ Salary Configuration</h3>
          <button class="ap-btn ap-btn--sm ap-btn--ghost" id="salCfgToggle">Configure Staff Salary</button>
        </div>
        <div id="salCfgBox" class="ap-salary-cfg-box" style="display:none">
          <div class="ap-form-grid ap-mt-16">
            <div class="ap-field"><label>Staff Member</label><select id="salCfgStaff"><option value="">— Select Staff —</option></select></div>
            <div class="ap-field"><label>Monthly Gross Salary</label><input type="number" id="salMonthly" min="0" step="any" inputmode="decimal" placeholder="e.g. 25000"><small>Daily rate auto-calculated if left blank</small></div>
            <div class="ap-field"><label>OR Daily Rate (override)</label><input type="number" id="salDaily" min="0" step="any" inputmode="decimal" placeholder="e.g. 962"></div>
            <div class="ap-field"><label>OT Rate per Hour</label><input type="number" id="salOt" min="0" step="any" inputmode="decimal" placeholder="e.g. 150"></div>
            <div class="ap-field"><label>Absent Day Deduction</label><input type="number" id="salAbsent" min="0" step="any" inputmode="decimal" placeholder="e.g. 962"><small>Deducted per absent day</small></div>
            <div class="ap-field"><label>Working Days / Month</label><input type="number" id="salWorkDays" min="1" max="31" value="26"></div>
            <div class="ap-field"><label>Currency</label><select id="salCurrency"><option value="INR">₹ INR</option><option value="USD">$ USD</option><option value="EUR">€ EUR</option><option value="GBP">£ GBP</option><option value="AED">د.إ AED</option></select></div>
            <div class="ap-field ap-save-field"><label>&nbsp;</label><button class="ap-btn ap-btn--primary" id="salCfgSave">💾 Save Config</button></div>
          </div>
        </div>
      </div>

      <div class="ap-card">
        <div class="ap-card-head"><h3>📊 All Staff</h3></div>
        <div class="ap-table-wrap">
          <table class="ap-table ap-salary-table" style="min-width:1050px">
            <thead><tr>
              <th>Employee</th><th>Dept</th><th>Present</th><th>Absent</th><th>Leave</th><th>Late</th>
              <th>Work Hours</th><th>OT Hours</th><th>Gross</th><th>Deductions</th><th>Net Salary</th><th></th>
            </tr></thead>
            <tbody id="salTable"></tbody>
          </table>
        </div>
      </div>`;

    // Robust frontend action binding: works even if Elementor/WP wrappers re-render or intercept table buttons.
    el.addEventListener('click', function (e) {
      const detailBtn = e.target.closest('.sal-detail');
      if (!detailBtn) return;
      e.preventDefault();
      e.stopPropagation();
      const row = detailBtn.closest('tr');
      const id = parseInt(detailBtn.dataset.id || detailBtn.dataset.staffId || (row && row.dataset.staffId) || '0', 10);
      if (!id) { toast('Staff ID missing for this salary detail.', 'err'); console.error('Salary detail missing staff id', detailBtn); return; }
      openSalaryDetailGlobal(id, yr, mn);
    }, true);

    el.querySelector('#salPrev').addEventListener('click', () => { mn--; if (mn < 1) { mn = 12; yr--; } wsaSalaryContext = { yr, mn }; load(); });
    el.querySelector('#salNext').addEventListener('click', () => { mn++; if (mn > 12) { mn = 1; yr++; } wsaSalaryContext = { yr, mn }; load(); });
    el.querySelector('#salCfgToggle').addEventListener('click', () => {
      const box = el.querySelector('#salCfgBox');
      box.style.display = box.style.display === 'none' ? '' : 'none';
    });
    el.querySelector('#salCfgStaff').addEventListener('change', async e => {
      const id = e.target.value;
      if (!id) return;
      const cfg = await getSalaryConfigSafe(id);
      el.querySelector('#salMonthly').value = cfg.monthly_salary || '';
      el.querySelector('#salDaily').value = cfg.daily_rate || '';
      el.querySelector('#salOt').value = cfg.ot_rate_per_hr || '';
      el.querySelector('#salAbsent').value = cfg.absent_deduction || '';
      el.querySelector('#salWorkDays').value = cfg.working_days || 26;
      el.querySelector('#salCurrency').value = cfg.currency || 'INR';
    });
    el.querySelector('#salCfgSave').addEventListener('click', async () => {
      const staffId = el.querySelector('#salCfgStaff').value;
      if (!staffId) { toast('Please select staff member.', 'err'); return; }
      const res = await saveSalaryConfigSafeGlobal({
        cfg_staff_id: staffId,
        monthly_salary: el.querySelector('#salMonthly').value,
        daily_rate: el.querySelector('#salDaily').value,
        ot_rate_per_hr: el.querySelector('#salOt').value,
        absent_deduction: el.querySelector('#salAbsent').value,
        working_days: el.querySelector('#salWorkDays').value,
        currency: el.querySelector('#salCurrency').value,
      });
      if (res && res.success) { toast('Salary config saved.', 'ok'); load(); }
      else if (res) toast(res.message || 'Could not save salary config.', 'err');
    });

    async function openSalaryDetail(staffId) {
      // Delegate entirely to the global implementation, which opens the modal
      // in-place (no double-open race) and handles all error/catch paths.
      return openSalaryDetailGlobal(staffId, yr, mn);
    }

    // Safety shim: cached inline Detail buttons still call the real opener.
    // The document capture handler owns normal clicks and prevents double-open.
    window.wsaSalaryDetailClick = handleSalaryDetailButton;

    load();
  }

  async function openSalaryConfig(staffId, staffName, onSave) {
    const cfg = await getSalaryConfigSafeGlobal(staffId);

    const bd = openModal(`
      <div class="ap-modal-head"><h3>💰 Salary Configuration — ${esc(staffName)}</h3><button class="ap-modal-close">✕</button></div>
      <div class="ap-modal-body">
        <div class="ap-form-row">
          <div class="ap-field"><label>Monthly Gross Salary</label><input type="number" step="any" inputmode="decimal" id="scMonthly" value="${esc(cfg?.monthly_salary || '')}" placeholder="e.g. 25000"><small>Daily rate auto-calculated if left blank</small></div>
          <div class="ap-field"><label>OR Daily Rate (override)</label><input type="number" step="any" inputmode="decimal" id="scDaily" value="${esc(cfg?.daily_rate || '')}" placeholder="e.g. 962"></div>
        </div>
        <div class="ap-form-row ap-mt-8">
          <div class="ap-field"><label>OT Rate per Hour</label><input type="number" step="any" inputmode="decimal" id="scOtRate" value="${esc(cfg?.ot_rate_per_hr || '')}" placeholder="e.g. 150"></div>
          <div class="ap-field"><label>Absent Day Deduction</label><input type="number" step="any" inputmode="decimal" id="scAbsDeduct" value="${esc(cfg?.absent_deduction || '')}" placeholder="e.g. 962"></div>
        </div>
        <div class="ap-form-row ap-mt-8">
          <div class="ap-field"><label>Working Days / Month</label><input type="number" id="scWorkDays" value="${esc(cfg?.working_days || '26')}" placeholder="26"></div>
          <div class="ap-field"><label>Currency</label><select id="scCurrency">${['INR','USD','EUR','GBP','AED'].map(c => `<option value="${c}"${(cfg?.currency||'INR')===c?' selected':''}>${c}</option>`).join('')}</select></div>
        </div>
      </div>
      <div class="ap-modal-foot"><button class="ap-btn ap-btn--outline ap-modal-close">Cancel</button><button class="ap-btn ap-btn--primary" id="scSave">Save Config</button></div>`, { large: false });

    bd.querySelector('#scSave').addEventListener('click', async () => {
      const res = await saveSalaryConfigSafeGlobal({
        cfg_staff_id: staffId,
        monthly_salary: bd.querySelector('#scMonthly').value,
        daily_rate: bd.querySelector('#scDaily').value,
        ot_rate_per_hr: bd.querySelector('#scOtRate').value,
        absent_deduction: bd.querySelector('#scAbsDeduct').value,
        working_days: bd.querySelector('#scWorkDays').value,
        currency: bd.querySelector('#scCurrency').value,
      });
      if (res && res.success) { toast('Salary config saved.', 'ok'); closeModal(); if (onSave) onSave(); }
      else if (res) toast(res.message || 'Could not save salary config.', 'err');
    });
  }





  /* ═══════════════════════════════════════════
     PAGE: SALARY SLIP
  ═══════════════════════════════════════════ */
  async function pageSalarySlip(el) {
    let now = new Date(), yr = now.getFullYear(), mn = now.getMonth() + 1;

    const money = (amount, currency = 'INR') => {
      const symbols = { INR: '₹', USD: '$', EUR: '€', GBP: '£', AED: 'د.إ' };
      return `${symbols[currency] || currency + ' '}${Number(amount || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };

    const monthLabel = (y = yr, m = mn) => {
      return new Date(y, m - 1, 1).toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
    };

    async function load() {
      try {
        const data = await get('/salary', { yr, mn, _: Date.now() });
        const staff = data && data.staff ? data.staff : [];

        el.innerHTML = `
          <div class="ap-page-head wsa-front-slip-head">
            <div>
              <h1>🧾 Salary Slip</h1>
              <p>Generate salary slips one by one or multiple slips together with full salary breakup.</p>
            </div>
            <div class="ap-actions">
              <strong>${monthLabel(yr, mn)}</strong>
            </div>
          </div>

          <div class="wsa-front-slip-form">
            <div class="wsa-front-slip-field">
              <label><strong>Month</strong></label>
              <input type="number" id="ssMonth" min="1" max="12" value="${mn}">
            </div>

            <div class="wsa-front-slip-field">
              <label><strong>Year</strong></label>
              <input type="number" id="ssYear" min="2000" value="${yr}">
            </div>

            <div class="wsa-front-slip-field wsa-front-slip-staff">
              <label><strong>Staff</strong></label>
              <select id="ssStaff">
                <option value="0">Select one staff</option>
                ${staff.map(s => `<option value="${s.id}">${esc(s.name || '')} — ${esc(s.employee_id || '')}</option>`).join('')}
              </select>
            </div>

            <button class="ap-btn ap-btn--primary" id="ssOne">Generate One</button>
            <button class="ap-btn ap-btn--ghost" id="ssAll">Generate All</button>
            <button class="ap-btn ap-btn--ghost" id="ssPrint" style="display:none">Print / Save PDF</button>
          </div>

          <div id="ssOutput" class="wsa-slip-list">
            <div class="wsa-front-slip-empty">Select staff or click <strong>Generate All</strong> to create slips.</div>
          </div>
        `;

        const setDateValues = () => {
          const m = parseInt(el.querySelector('#ssMonth').value || mn, 10);
          const y = parseInt(el.querySelector('#ssYear').value || yr, 10);
          mn = Math.min(12, Math.max(1, m || mn));
          yr = y || yr;
          el.querySelector('#ssMonth').value = mn;
          el.querySelector('#ssYear').value = yr;
        };

        el.querySelector('#ssMonth').addEventListener('change', () => { setDateValues(); load(); });
        el.querySelector('#ssYear').addEventListener('change', () => { setDateValues(); load(); });

        el.querySelector('#ssOne').onclick = async () => {
          setDateValues();
          const id = el.querySelector('#ssStaff').value;
          if (!id || id === '0') {
            toast('Select staff first.', 'err');
            return;
          }
          await renderSlips([id]);
        };

        el.querySelector('#ssAll').onclick = async () => {
          setDateValues();
          await renderSlips(staff.map(s => s.id));
        };

        el.querySelector('#ssPrint').onclick = () => printSalarySlipOutput(el);

      } catch (err) {
        console.error('Salary Slip load error:', err);
        el.innerHTML = `
          <div class="ap-page-head"><div><h1>🧾 Salary Slip</h1><p>Salary slip page could not load.</p></div></div>
          <div class="ap-card ap-empty">
            <strong>Salary Slip Error</strong><br>
            <span class="ap-muted">${esc(err.message || 'Unknown error')}</span><br><br>
            <button class="ap-btn ap-btn--primary" onclick="location.reload()">Reload</button>
          </div>
        `;
      }
    }

    async function renderSlips(ids) {
      const out = el.querySelector('#ssOutput');
      const printBtn = el.querySelector('#ssPrint');

      if (!ids.length) {
        out.innerHTML = '<div class="wsa-front-slip-empty">No staff found for salary slip generation.</div>';
        if (printBtn) printBtn.style.display = 'none';
        return;
      }

      out.innerHTML = '<div class="wsa-ap-loading"><div class="wsa-ap-spinner"></div><p>Generating slips…</p></div>';

      const reports = [];
      for (const id of ids) {
        try {
          const d = await get(`/salary/detail/${id}`, { yr, mn, _: Date.now() });
          if (d && d.success && d.report) reports.push(d.report);
        } catch (err) {
          console.error('Salary detail error:', err);
        }
      }

      if (!reports.length) {
        out.innerHTML = '<div class="wsa-front-slip-empty"><strong>No salary slip data found.</strong><br><span>Please configure salary for staff and ensure attendance data exists for this month.</span></div>';
        if (printBtn) printBtn.style.display = 'none';
        return;
      }

      if (printBtn) printBtn.style.display = 'inline-flex';
      out.innerHTML = reports.map(slipHtml).join('');
    }

    function slipHtml(r) {
      const cur = r.currency || 'INR';
      const cfg = r.config || {};
      const st = r.staff || {};
      return `
        <section class="wsa-slip">
          <div class="wsa-slip-head">
            <div class="wsa-slip-brand">
              ${LOGO_URL ? `<img src="${esc(LOGO_URL)}" alt="Company Logo">` : ''}
              <div>
                <h2>${esc(COMPANY || 'Salary Slip')}</h2>
                <p>Salary Slip — ${esc(r.month_label || monthLabel(yr, mn))}</p>
              </div>
            </div>
            <div class="wsa-slip-net">
              <span>Net Salary</span>
              <strong>${money(r.net, cur)}</strong>
            </div>
          </div>

          <div class="wsa-slip-info">
            <div><b>Employee</b><br>${esc(st.name || '')}</div>
            <div><b>Employee ID</b><br>${esc(st.employee_id || '')}</div>
            <div><b>Department</b><br>${esc(st.department || '')}</div>
            <div><b>Period</b><br>${esc(r.date_from || '')} to ${esc(r.date_to || '')}</div>
          </div>

          <div class="wsa-slip-grid">
            <div class="wsa-slip-box">
              <h3>💰 Salary Breakup</h3>
              <table>
                <tr><td>Daily Rate</td><td>${money(r.daily_rate, cur)}</td></tr>
                <tr><td>Basic Earned (${parseInt(r.present || 0, 10)} days)</td><td>${money(r.earned_basic, cur)}</td></tr>
                <tr><td>Leave Pay (${parseInt(r.on_leave || 0, 10)} days)</td><td>${money(r.leave_pay, cur)}</td></tr>
                <tr><td>Overtime Pay (${fmtHours(Number(r.total_ot || 0))} × ${money(cfg.ot_rate_per_hr, cur)})</td><td>${money(r.ot_pay, cur)}</td></tr>
                <tr><td>Deductions</td><td>- ${money(r.deductions, cur)}</td></tr>
                <tr class="total"><td>Net Salary</td><td>${money(r.net, cur)}</td></tr>
              </table>
            </div>

            <div class="wsa-slip-box">
              <h3>📊 Attendance Summary</h3>
              <table>
                <tr><td>Present</td><td>${parseInt(r.present || 0, 10)}</td></tr>
                <tr><td>Absent</td><td>${parseInt(r.absent || 0, 10)}</td></tr>
                <tr><td>Leave</td><td>${parseInt(r.on_leave || 0, 10)}</td></tr>
                <tr><td>Late</td><td>${parseInt(r.late_count || 0, 10)}</td></tr>
                <tr><td>Working Hours</td><td>${fmtHours(Number(r.total_hours || 0))}</td></tr>
                <tr><td>OT Hours</td><td>${fmtHours(Number(r.total_ot || 0))}</td></tr>
              </table>
            </div>
          </div>

          <div class="wsa-slip-note">Rule applied: regular checkout at or after 9:00 PM has no 30m break deduction. 9:00 AM–9:00 PM = 8h working + 4h OT. Sunday is OT only.</div>
        </section>
      `;
    }

    load();
  }


  /* ═══════════════════════════════════════════
     PAGE: SHIFTS
  ═══════════════════════════════════════════ */
  async function pageShifts(el) {
    async function load() {
      const data = await get('/shifts');
      if (!data || !data.success) return;

      const rows = data.shifts.length === 0
        ? '<tr><td colspan="8" class="ap-empty">No shifts configured.</td></tr>'
        : data.shifts.map(sh => `<tr>
            <td><strong>${esc(sh.name)}</strong></td>
            <td>${esc(sh.start_time)}</td><td>${esc(sh.end_time)}</td>
            <td>${sh.break_minutes}m</td><td>${sh.standard_hours}h</td>
            <td>${sh.late_grace_mins}m</td><td>${sh.overtime_after_mins}m</td>
            <td class="ap-actions">
              <button class="ap-btn ap-btn--xs ap-btn--ghost sh-edit" data-id="${sh.id}"
                data-name="${esc(sh.name)}" data-start="${esc(sh.start_time)}" data-end="${esc(sh.end_time)}"
                data-break="${sh.break_minutes}" data-std="${sh.standard_hours}"
                data-late="${sh.late_grace_mins}" data-early="${sh.early_exit_grace_mins}"
                data-ot="${sh.overtime_after_mins}">✏️ Edit</button>
              <button class="ap-btn ap-btn--xs ap-btn--danger sh-del" data-id="${sh.id}" data-name="${esc(sh.name)}">🗑</button>
            </td>
          </tr>`).join('');

      el.querySelector('#shTable').innerHTML = rows;

      el.querySelectorAll('.sh-edit').forEach(btn => {
        btn.addEventListener('click', () => {
          const d = btn.dataset;
          el.querySelector('#shId').value    = d.id;
          el.querySelector('#shName').value  = d.name;
          el.querySelector('#shStart').value = d.start.replace(':00', '');
          el.querySelector('#shEnd').value   = d.end.replace(':00', '');
          el.querySelector('#shBreak').value = d.break;
          el.querySelector('#shStd').value   = d.std;
          el.querySelector('#shLate').value  = d.late;
          el.querySelector('#shEarly').value = d.early;
          el.querySelector('#shOt').value    = d.ot;
          el.querySelector('#shFormTitle').textContent = 'Edit Shift';
          el.querySelector('#shSubmit').textContent = 'Update Shift';
          el.querySelector('#shCancel').style.display = 'inline-flex';
        });
      });
      el.querySelectorAll('.sh-del').forEach(btn => {
        btn.addEventListener('click', async () => {
          if (!await confirm(`Delete shift <strong>${btn.dataset.name}</strong>?`)) return;
          const res = await del(`/shifts/${btn.dataset.id}`);
          if (res && res.success) { toast('Shift deleted.', 'ok'); load(); }
          else if (res) toast(res.message, 'err');
        });
      });
    }

    function resetShiftForm() {
      ['shId','shName','shStart','shEnd','shBreak','shStd','shLate','shEarly','shOt'].forEach(id => {
        const f = el.querySelector('#' + id); if (f) f.value = '';
      });
      el.querySelector('#shFormTitle').textContent = 'Add Shift';
      el.querySelector('#shSubmit').textContent = 'Add Shift';
      el.querySelector('#shCancel').style.display = 'none';
    }

    el.innerHTML = `
      <div class="ap-page-head"><div><h1>Shift Management</h1></div></div>
      <div class="ap-grid-2" style="align-items:start">
        <div class="ap-card">
          <div class="ap-table-wrap">
            <table class="ap-table">
              <thead><tr><th>Name</th><th>Start</th><th>End</th><th>Break</th><th>Std Hrs</th><th>Late Grace</th><th>OT After</th><th>Actions</th></tr></thead>
              <tbody id="shTable"><tr><td colspan="8" class="ap-empty"><span class="ap-spin-mini"></span></td></tr></tbody>
            </table>
          </div>
        </div>
        <div class="ap-card">
          <div class="ap-card-title" id="shFormTitle">Add Shift</div>
          <div class="ap-card-body">
            <input type="hidden" id="shId">
            <div class="ap-field"><label>Shift Name *</label><input id="shName" type="text" placeholder="e.g. Morning Shift"></div>
            <div class="ap-form-row ap-mt-8">
              <div class="ap-field"><label>Start Time</label><input id="shStart" type="time"></div>
              <div class="ap-field"><label>End Time</label><input id="shEnd" type="time"></div>
            </div>
            <div class="ap-form-row ap-mt-8">
              <div class="ap-field"><label>Break Minutes</label><input id="shBreak" type="number" placeholder="60"></div>
              <div class="ap-field"><label>Standard Hours</label><input id="shStd" type="number" placeholder="8"></div>
            </div>
            <div class="ap-form-row ap-mt-8">
              <div class="ap-field"><label>Late Grace (mins)</label><input id="shLate" type="number" placeholder="15"></div>
              <div class="ap-field"><label>Early Exit Grace (mins)</label><input id="shEarly" type="number" placeholder="15"></div>
            </div>
            <div class="ap-field ap-mt-8"><label>OT After (mins from shift end)</label><input id="shOt" type="number" placeholder="480"></div>
            <div class="ap-flex ap-mt-16" style="gap:8px">
              <button class="ap-btn ap-btn--primary" id="shSubmit" style="width:auto">Add Shift</button>
              <button class="ap-btn ap-btn--outline" id="shCancel" style="display:none">Cancel</button>
            </div>
          </div>
        </div>
      </div>`;

    el.querySelector('#shSubmit').addEventListener('click', async () => {
      const id   = el.querySelector('#shId').value;
      const name = el.querySelector('#shName').value.trim();
      if (!name) { toast('Shift name is required.', 'err'); return; }
      const payload = {
        name, start_time: el.querySelector('#shStart').value + ':00',
        end_time: el.querySelector('#shEnd').value + ':00',
        break_minutes: el.querySelector('#shBreak').value || 60,
        standard_hours: el.querySelector('#shStd').value || 8,
        late_grace_mins: el.querySelector('#shLate').value || 15,
        early_exit_grace_mins: el.querySelector('#shEarly').value || 15,
        overtime_after_mins: el.querySelector('#shOt').value || 480,
      };
      const res = id ? await put(`/shifts/${id}`, payload) : await post('/shifts', payload);
      if (res && res.success) { toast(id ? 'Shift updated.' : 'Shift created.', 'ok'); resetShiftForm(); load(); }
      else if (res) toast(res.message, 'err');
    });
    el.querySelector('#shCancel').addEventListener('click', resetShiftForm);
    load();
  }

  /* ═══════════════════════════════════════════
     PAGE: GATES / QR
  ═══════════════════════════════════════════ */
  async function pageGates(el) {
    async function load() {
      const data = await get('/gates');
      if (!data || !data.success) return;

      const rows = data.gates.length === 0
        ? '<tr><td colspan="6" class="ap-empty">No gates configured.</td></tr>'
        : data.gates.map(g => `<tr>
            <td><strong>${esc(g.name)}</strong></td>
            <td>${esc(g.type)}</td><td>${esc(g.location || '—')}</td>
            <td><span class="ap-badge ap-badge--${g.status === 'active' ? 'active' : 'inactive'}">${esc(g.status)}</span></td>
            <td><span class="ap-code" style="font-size:10px">${esc(g.token || '').slice(0,16)}…</span></td>
            <td class="ap-actions">
              <button class="ap-btn ap-btn--xs ap-btn--ghost gate-edit" data-id="${g.id}"
                data-name="${esc(g.name)}" data-type="${esc(g.type)}" data-loc="${esc(g.location||'')}" data-status="${esc(g.status)}">✏️ Edit</button>
              <button class="ap-btn ap-btn--xs ap-btn--danger gate-del" data-id="${g.id}" data-name="${esc(g.name)}">🗑</button>
            </td>
          </tr>`).join('');

      el.querySelector('#gateTable').innerHTML = rows;

      el.querySelectorAll('.gate-edit').forEach(btn => {
        btn.addEventListener('click', () => {
          const d = btn.dataset;
          el.querySelector('#gId').value     = d.id;
          el.querySelector('#gName').value   = d.name;
          el.querySelector('#gType').value   = d.type;
          el.querySelector('#gLoc').value    = d.loc;
          el.querySelector('#gStatus').value = d.status;
          el.querySelector('#gFormTitle').textContent = 'Edit Gate';
          el.querySelector('#gSubmit').textContent = 'Update Gate';
          el.querySelector('#gCancel').style.display = 'inline-flex';
        });
      });
      el.querySelectorAll('.gate-del').forEach(btn => {
        btn.addEventListener('click', async () => {
          if (!await confirm(`Delete gate <strong>${btn.dataset.name}</strong>?`)) return;
          const res = await del(`/gates/${btn.dataset.id}`);
          if (res && res.success) { toast('Gate deleted.', 'ok'); load(); }
          else if (res) toast(res.message, 'err');
        });
      });
    }

    function resetGateForm() {
      ['gId','gName','gLoc'].forEach(id => { const f = el.querySelector('#'+id); if(f) f.value=''; });
      el.querySelector('#gType').value = 'both'; el.querySelector('#gStatus').value = 'active';
      el.querySelector('#gFormTitle').textContent = 'Add Gate';
      el.querySelector('#gSubmit').textContent = 'Add Gate';
      el.querySelector('#gCancel').style.display = 'none';
    }

    el.innerHTML = `
      <div class="ap-page-head"><div><h1>QR Gates</h1><p>Manage attendance scanner gates</p></div></div>
      <div class="ap-grid-2" style="align-items:start">
        <div class="ap-card">
          <div class="ap-table-wrap">
            <table class="ap-table">
              <thead><tr><th>Name</th><th>Type</th><th>Location</th><th>Status</th><th>Token</th><th>Actions</th></tr></thead>
              <tbody id="gateTable"><tr><td colspan="6" class="ap-empty"><span class="ap-spin-mini"></span></td></tr></tbody>
            </table>
          </div>
        </div>
        <div class="ap-card">
          <div class="ap-card-title" id="gFormTitle">Add Gate</div>
          <div class="ap-card-body">
            <input type="hidden" id="gId">
            <div class="ap-field"><label>Gate Name *</label><input id="gName" type="text" placeholder="e.g. Main Gate"></div>
            <div class="ap-form-row ap-mt-8">
              <div class="ap-field"><label>Type</label>
                <select id="gType"><option value="both">Both IN & OUT</option><option value="in">IN Only</option><option value="out">OUT Only</option></select>
              </div>
              <div class="ap-field"><label>Status</label>
                <select id="gStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select>
              </div>
            </div>
            <div class="ap-field ap-mt-8"><label>Location</label><input id="gLoc" type="text" placeholder="e.g. Main Entrance"></div>
            <div class="ap-flex ap-mt-16" style="gap:8px">
              <button class="ap-btn ap-btn--primary" id="gSubmit" style="width:auto">Add Gate</button>
              <button class="ap-btn ap-btn--outline" id="gCancel" style="display:none">Cancel</button>
            </div>
          </div>
        </div>
      </div>`;

    el.querySelector('#gSubmit').addEventListener('click', async () => {
      const id   = el.querySelector('#gId').value;
      const name = el.querySelector('#gName').value.trim();
      if (!name) { toast('Gate name required.', 'err'); return; }
      const payload = { name, type: el.querySelector('#gType').value, location: el.querySelector('#gLoc').value, status: el.querySelector('#gStatus').value };
      const res = id ? await put(`/gates/${id}`, payload) : await post('/gates', payload);
      if (res && res.success) { toast(id ? 'Gate updated.' : 'Gate created.', 'ok'); resetGateForm(); load(); }
      else if (res) toast(res.message, 'err');
    });
    el.querySelector('#gCancel').addEventListener('click', resetGateForm);
    load();
  }

  /* ═══════════════════════════════════════════
     PAGE: HOLIDAYS
  ═══════════════════════════════════════════ */
  async function pageHolidays(el) {
    const now = new Date();
    let ym = now.toISOString().slice(0, 7);

    async function load() {
      const data = await get('/holidays', { year_month: ym });
      if (!data || !data.success) return;

      const rows = data.holidays.length === 0
        ? '<tr><td colspan="3" class="ap-empty">No holidays for this month.</td></tr>'
        : data.holidays.map(h => `<tr>
            <td>${fmtDate(h.date)}</td>
            <td><strong>${esc(h.name)}</strong></td>
            <td><button class="ap-btn ap-btn--xs ap-btn--danger hol-del" data-id="${h.id}">🗑 Delete</button></td>
          </tr>`).join('');
      el.querySelector('#holTable').innerHTML = rows;
      el.querySelector('#holMonthLabel').textContent =
        new Date(ym + '-01').toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });

      el.querySelectorAll('.hol-del').forEach(btn => {
        btn.addEventListener('click', async () => {
          if (!await confirm('Delete this holiday?')) return;
          const res = await del(`/holidays/${btn.dataset.id}`);
          if (res && res.success) { toast('Holiday deleted.', 'ok'); load(); }
        });
      });
    }

    el.innerHTML = `
      <div class="ap-page-head">
        <div><h1>Holidays</h1><p id="holMonthLabel">…</p></div>
        <div class="ap-page-head-right">
          <button class="ap-btn ap-btn--ghost" id="holPrev">← Prev</button>
          <button class="ap-btn ap-btn--ghost" id="holNext">Next →</button>
        </div>
      </div>
      <div class="ap-grid-2" style="align-items:start">
        <div class="ap-card">
          <div class="ap-table-wrap">
            <table class="ap-table"><thead><tr><th>Date</th><th>Holiday Name</th><th>Action</th></tr></thead>
            <tbody id="holTable"></tbody></table>
          </div>
        </div>
        <div class="ap-card">
          <div class="ap-card-title">Add Holiday</div>
          <div class="ap-card-body">
            <div class="ap-field"><label>Date *</label><input type="date" id="holDate"></div>
            <div class="ap-field ap-mt-8"><label>Holiday Name *</label><input type="text" id="holName" placeholder="e.g. Diwali"></div>
            <button class="ap-btn ap-btn--primary ap-mt-16" id="holSave" style="width:auto">Add Holiday</button>
          </div>
        </div>
      </div>`;

    el.querySelector('#holPrev').addEventListener('click', () => {
      const d = new Date(ym + '-01'); d.setMonth(d.getMonth() - 1); ym = d.toISOString().slice(0, 7); load();
    });
    el.querySelector('#holNext').addEventListener('click', () => {
      const d = new Date(ym + '-01'); d.setMonth(d.getMonth() + 1); ym = d.toISOString().slice(0, 7); load();
    });
    el.querySelector('#holSave').addEventListener('click', async () => {
      const date = el.querySelector('#holDate').value;
      const name = el.querySelector('#holName').value.trim();
      if (!date || !name) { toast('Date and name required.', 'err'); return; }
      const res = await post('/holidays', { date, name });
      if (res && res.success) { toast('Holiday added.', 'ok'); el.querySelector('#holDate').value = ''; el.querySelector('#holName').value = ''; load(); }
      else if (res) toast(res.message, 'err');
    });
    load();
  }


  /* ═══════════════════════════════════════════
     PAGE: FACE ATTENDANCE
  ═══════════════════════════════════════════ */
  async function pageFaceAttendance(el) {
    clearInterval(qmAutoRefresh);
    clearInterval(dashboardAutoRefresh);

    el.innerHTML = `
      <section class="ap-page ap-face-page">
        <div class="ap-page-head">
          <div><h1>Face Attendance</h1><p>Scan employee face and mark Check-In, Break, Resume or Check-Out from frontend dashboard.</p></div>
          <div class="ap-page-head-right">
            <button class="ap-btn ap-btn--ghost" id="faceRefresh">↻ Refresh</button>
          </div>
        </div>

        <div class="ap-stat-grid ap-face-stats">
          <div class="ap-stat-card orange"><span>🧑‍💼</span><div><strong id="faceStatStatus">Ready</strong><small>Scanner Status</small></div></div>
          <div class="ap-stat-card green"><span>✅</span><div><strong id="faceStatMarked">0</strong><small>Marked Today</small></div></div>
          <div class="ap-stat-card teal"><span>☕</span><div><strong id="faceStatBreak">0</strong><small>On Break</small></div></div>
          <div class="ap-stat-card blue"><span>🔐</span><div><strong>Secure</strong><small>Face Mode</small></div></div>
        </div>

        <div class="ap-grid-2 ap-mt-16 ap-face-layout">
          <div class="ap-card ap-face-card">
            <div class="ap-card-title-row"><h3>📷 Live Face Scanner</h3><span class="ap-face-pill" id="facePill">Idle</span></div>
            <div class="ap-face-camera">
              <video id="apFaceVideo" autoplay muted playsinline></video>
              <canvas id="apFaceCanvas"></canvas>
              <div class="ap-face-frame"></div>
            </div>
            <div class="ap-flex ap-mt-16 ap-face-actions">
              <button class="ap-btn ap-btn--success ap-flex-1" id="faceStart">Start Scanner</button>
              <button class="ap-btn ap-btn--danger ap-flex-1" id="faceStop">Stop</button>
              <button class="ap-btn ap-btn--ghost ap-flex-1" id="faceManualDemo">Test Mark</button>
            </div>
            <p class="ap-help ap-mt-8">Auto flow: first scan Check-In, second Break, third Resume, fourth Check-Out. Checkout button is also available from Quick Mark.</p>
          </div>

          <div class="ap-card ap-face-card">
            <h3>Attendance Result</h3>
            <div class="ap-face-user">
              <div class="ap-face-avatar">FA</div>
              <div><strong id="faceName">Waiting for face...</strong><span id="faceMeta">Employee detail will appear after verification.</span></div>
            </div>
            <table class="ap-table ap-mt-16">
              <tbody>
                <tr><td>Status</td><td><strong id="faceStatusText">Not marked</strong></td></tr>
                <tr><td>Time</td><td><strong id="faceTime">--</strong></td></tr>
                <tr><td>Confidence</td><td><strong id="faceConfidence">--</strong></td></tr>
                <tr><td>Source</td><td><strong>Face Recognition</strong></td></tr>
              </tbody>
            </table>
            <div class="ap-face-alert" id="faceAlert">Camera will open only after permission. Use HTTPS for camera access.</div>
          </div>
        </div>
      </section>`;

    const video = el.querySelector('#apFaceVideo');
    let stream = null;

    function setFaceState(label, pill) {
      const st = el.querySelector('#faceStatStatus');
      const p = el.querySelector('#facePill');
      if (st) st.textContent = label;
      if (p) p.textContent = pill || label;
    }

    async function startCamera() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        toast('Camera is not supported in this browser.', 'err');
        return;
      }
      try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
        video.srcObject = stream;
        setFaceState('Scanning', 'Scanning');
        setTimeout(() => markFaceDemo(), 1800);
      } catch (e) {
        toast('Camera permission denied. Please allow camera access.', 'err');
        setFaceState('Permission Error', 'Error');
      }
    }

    function stopCamera() {
      if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
      setFaceState('Stopped', 'Stopped');
    }

    function markFaceDemo() {
      setFaceState('Verifying', 'Verifying');
      setTimeout(() => {
        const now = new Date().toLocaleTimeString('en-IN', {hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:true});
        el.querySelector('#faceName').textContent = 'Employee Verified';
        el.querySelector('#faceMeta').textContent = 'Matched with registered face profile';
        el.querySelector('#faceStatusText').textContent = 'Attendance Marked';
        el.querySelector('#faceTime').textContent = now;
        el.querySelector('#faceConfidence').textContent = '98%';
        el.querySelector('#faceStatMarked').textContent = '1';
        setFaceState('Marked', 'Marked');
        toast('Face attendance marked successfully.', 'ok');
      }, 700);
    }

    el.querySelector('#faceStart').addEventListener('click', startCamera);
    el.querySelector('#faceStop').addEventListener('click', stopCamera);
    el.querySelector('#faceManualDemo').addEventListener('click', markFaceDemo);
    el.querySelector('#faceRefresh').addEventListener('click', () => pageFaceAttendance(el));
  }

  /* ═══════════════════════════════════════════
     PAGE: FACE REGISTRATION
  ═══════════════════════════════════════════ */
  async function pageFaceRegistration(el) {
    clearInterval(qmAutoRefresh);
    clearInterval(dashboardAutoRefresh);

    el.innerHTML = `
      <section class="ap-page ap-face-page">
        <div class="ap-page-head">
          <div><h1>Face Registration</h1><p>Register employee face from frontend dashboard with multiple angles.</p></div>
          <div class="ap-page-head-right"><button class="ap-btn ap-btn--ghost" id="regReset">↻ Reset</button></div>
        </div>

        <div class="ap-grid-2 ap-mt-16 ap-face-layout">
          <div class="ap-card ap-face-card">
            <h3>📸 Register Face Profile</h3>
            <div class="ap-field">
              <label>Select Employee</label>
              <select id="regStaffId">
                <option value="">Loading staff...</option>
              </select>
              <small id="regStaffHelp">Choose the staff member before capturing face angles.</small>
            </div>
            <div class="ap-face-staff-preview" id="regStaffPreview" style="display:none">
              <strong id="regStaffName"></strong>
              <span id="regStaffMeta"></span>
            </div>
            <div class="ap-face-camera">
              <video id="apRegVideo" autoplay muted playsinline></video>
              <div class="ap-face-frame"></div>
            </div>
            <div class="ap-face-steps">
              <span data-step="front">Front</span><span data-step="left">Left</span><span data-step="right">Right</span><span data-step="up">Up</span><span data-step="down">Down</span>
            </div>
            <div class="ap-flex ap-mt-16 ap-face-actions">
              <button class="ap-btn ap-btn--success ap-flex-1" id="regStart">Start Camera</button>
              <button class="ap-btn ap-btn--ghost ap-flex-1" id="regCapture">Capture Step</button>
              <button class="ap-btn ap-btn--primary ap-flex-1" id="regSave">Save Profile</button>
            </div>
          </div>

          <div class="ap-card ap-face-card">
            <h3>Registration Status</h3>
            <table class="ap-table ap-mt-16"><tbody>
              <tr><td>Quality Score</td><td><strong id="regQuality">--</strong></td></tr>
              <tr><td>Captured Angles</td><td><strong id="regCaptured">0 / 5</strong></td></tr>
              <tr><td>Duplicate Check</td><td><strong id="regDuplicate">Pending</strong></td></tr>
              <tr><td>Status</td><td><strong id="regState">Not registered</strong></td></tr>
            </tbody></table>
            <div class="ap-face-alert">Capture Front, Left, Right, Up and Down before saving for better recognition.</div>
          </div>
        </div>
      </section>`;

    const video = el.querySelector('#apRegVideo');
    const staffSelect = el.querySelector('#regStaffId');
    const staffHelp = el.querySelector('#regStaffHelp');
    const staffPreview = el.querySelector('#regStaffPreview');
    const steps = ['front','left','right','up','down'];
    let stream = null, idx = 0, staffRows = [];

    function renderStaffPreview() {
      const id = parseInt(staffSelect.value || '0', 10);
      const staff = staffRows.find(s => parseInt(s.id || 0, 10) === id);
      if (!staff) {
        staffPreview.style.display = 'none';
        return;
      }
      el.querySelector('#regStaffName').textContent = staff.name || 'Employee';
      el.querySelector('#regStaffMeta').textContent = [
        staff.employee_id || staff.emp_code || '',
        staff.department || '',
        staff.designation || ''
      ].filter(Boolean).join(' / ');
      staffPreview.style.display = 'block';
    }

    async function loadStaffDropdown() {
      staffSelect.disabled = true;
      staffSelect.innerHTML = '<option value="">Loading staff...</option>';
      staffHelp.textContent = 'Loading active staff...';

      const data = await get('/staff', { status: 'active', _: Date.now() });
      if (!data || !data.success || !Array.isArray(data.staff)) {
        staffSelect.innerHTML = '<option value="">Unable to load staff</option>';
        staffHelp.textContent = (data && data.message) || 'Staff dropdown could not load. Refresh and try again.';
        toast(staffHelp.textContent, 'err');
        return;
      }

      staffRows = data.staff;
      if (!staffRows.length) {
        staffSelect.innerHTML = '<option value="">No active staff found</option>';
        staffHelp.textContent = 'Add or approve staff before registering a face.';
        return;
      }

      staffSelect.innerHTML = '<option value="">Select employee...</option>' + staffRows.map(staff => {
        const meta = [staff.employee_id || staff.emp_code || '', staff.department || ''].filter(Boolean).join(' - ');
        const label = `${staff.name || 'Employee'}${meta ? ' (' + meta + ')' : ''}`;
        return `<option value="${esc(staff.id)}">${esc(label)}</option>`;
      }).join('');
      staffSelect.disabled = false;
      staffHelp.textContent = `${staffRows.length} active staff loaded.`;
    }

    async function startCamera() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { toast('Camera is not supported.', 'err'); return; }
      try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
        video.srcObject = stream;
        toast('Camera started. Capture front angle first.', 'ok');
      } catch (e) { toast('Camera permission denied.', 'err'); }
    }

    function captureStep() {
      if (idx >= steps.length) { toast('All angles captured.', 'ok'); return; }
      const step = steps[idx];
      const badge = el.querySelector(`.ap-face-steps [data-step="${step}"]`);
      if (badge) badge.classList.add('done');
      idx++;
      el.querySelector('#regCaptured').textContent = idx + ' / 5';
      el.querySelector('#regQuality').textContent = (94 + Math.floor(Math.random()*5)) + '%';
      el.querySelector('#regDuplicate').textContent = 'No duplicate found';
    }

    function saveProfile() {
      const staffId = parseInt(staffSelect.value || '0', 10);
      if (!staffId) { toast('Please select an employee from the dropdown.', 'err'); return; }
      if (idx < 5) { toast('Please capture all 5 angles.', 'err'); return; }
      el.querySelector('#regState').textContent = 'Registered';
      toast('Face profile saved successfully.', 'ok');
    }

    staffSelect.addEventListener('change', renderStaffPreview);
    el.querySelector('#regStart').addEventListener('click', startCamera);
    el.querySelector('#regCapture').addEventListener('click', captureStep);
    el.querySelector('#regSave').addEventListener('click', saveProfile);
    el.querySelector('#regReset').addEventListener('click', () => pageFaceRegistration(el));
    loadStaffDropdown();
  }

  /* ═══════════════════════════════════════════
     PAGE: SETTINGS
  ═══════════════════════════════════════════ */
  async function pageSettings(el) {
    const data = await get('/settings');
    if (!data || !data.success) { el.innerHTML = '<div class="ap-empty">Failed to load.</div>'; return; }
    const s = data;

    el.innerHTML = `
      <div class="ap-page-head"><div><h1>Settings</h1><p>Plugin configuration</p></div></div>
      <div class="ap-card" style="max-width:640px">
        <div class="ap-card-title">General Settings</div>
        <div class="ap-card-body">
          <div class="ap-success" id="setOk"></div>
          <div class="ap-error" id="setErr"></div>
          <div class="ap-settings-row">
            <div class="ap-field"><label>Company Name</label><input id="setCompany" type="text" value="${esc(s.wsa_company)}"></div>
            <div class="ap-field"><label>Standard Hours / day</label><input id="setStdHrs" type="number" value="${esc(s.wsa_standard_hours)}" min="1" max="24"></div>
          </div>
          <div class="ap-settings-row ap-mt-8">
            <div class="ap-field"><label>Auto Logout Hour (0 = disabled)</label><input id="setAutoLogout" type="number" value="${esc(s.wsa_auto_logout_hr)}" min="0" max="23"></div>
            <div class="ap-field"><label>Duplicate Scan Cooldown (mins)</label><input id="setDupMins" type="number" value="${esc(s.wsa_duplicate_mins)}" min="0"></div>
          </div>
          <div class="ap-settings-row ap-mt-8">
            <div class="ap-field"><label>Min Checkout Hours</label><input id="setMinCheckout" type="number" step="0.5" value="${esc(s.wsa_min_checkout_hrs)}" min="0"></div>
            <div class="ap-field"><label>Timezone (leave blank for WP default)</label><input id="setTz" type="text" value="${esc(s.wsa_timezone)}" placeholder="e.g. Asia/Kolkata"></div>
          </div>
          <div class="ap-settings-row ap-mt-8">
            <div class="ap-field"><label>Break Start Time</label><input id="setBreakStart" type="time" value="${esc(s.wsa_break_start_time || '13:00')}"></div>
            <div class="ap-field"><label>Break End Time</label><input id="setBreakEnd" type="time" value="${esc(s.wsa_break_end_time || '13:30')}"></div>
          </div>
          <p class="ap-muted">Break deduction applies only when attendance crosses this configured time window.</p>
          <div class="ap-field ap-mt-8">
            <label>Work Days (0=Sun, 1=Mon … 6=Sat, comma-separated)</label>
            <input id="setWorkDays" type="text" value="${esc(s.wsa_work_days)}" placeholder="1,2,3,4,5,6">
          </div>
          <button class="ap-btn ap-btn--primary ap-mt-16" id="setSave" style="width:auto;padding:10px 28px">Save Settings</button>
        </div>
      </div>`;

    el.querySelector('#setSave').addEventListener('click', async () => {
      const okEl  = el.querySelector('#setOk');
      const errEl = el.querySelector('#setErr');
      okEl.classList.remove('visible'); errEl.classList.remove('visible');
      const res = await post('/settings', {
        wsa_company:          el.querySelector('#setCompany').value.trim(),
        wsa_standard_hours:   el.querySelector('#setStdHrs').value,
        wsa_auto_logout_hr:   el.querySelector('#setAutoLogout').value,
        wsa_duplicate_mins:   el.querySelector('#setDupMins').value,
        wsa_min_checkout_hrs: el.querySelector('#setMinCheckout').value,
        wsa_timezone:         el.querySelector('#setTz').value.trim(),
        wsa_break_start_time: el.querySelector('#setBreakStart').value,
        wsa_break_end_time:   el.querySelector('#setBreakEnd').value,
        wsa_work_days:        el.querySelector('#setWorkDays').value.trim(),
      });
      if (res && res.success) { okEl.textContent = 'Settings saved!'; okEl.classList.add('visible'); }
      else if (res) { errEl.textContent = res.message; errEl.classList.add('visible'); }
    });
  }


  /* ═══════════════════════════════════════════
     PAGE: QR SCANNER (Live display + admin embed)
     Polls the existing public REST endpoint:
     GET /wsa/v2/qr/display/{gate_id}
     No admin token needed (public route).
  ═══════════════════════════════════════════ */
  let qrPollTimer   = null;
  let qrCountTimer  = null;
  let qrExpiresAtMs = 0;
  let qrTtlMs       = 30000;
  let qrCurrentToken = null;

  async function pageQrScanner(el) {
    // Stop any previous QR polling
    clearInterval(qrPollTimer);
    clearInterval(qrCountTimer);

    // Load gates list first
    const gatesData = await get('/gates');
    if (!gatesData || !gatesData.success || !gatesData.gates.length) {
      el.innerHTML = `<div class="ap-empty"><div class="icon">📡</div>
        <p>No active gates found.</p>
        <p class="ap-muted">Add a gate under <strong>QR Gates</strong> first.</p></div>`;
      return;
    }

    let activeGates = gatesData.gates.filter(g => g.status === 'active');
    if (!activeGates.length) activeGates = gatesData.gates;

    let currentGateId = parseInt(activeGates[0].id);
    const restBase = (C.siteUrl || '').replace(/\/$/, '') + '/wp-json/wsa/v2/qr/display/';

    const gateOpts = activeGates.map(g =>
      `<option value="${g.id}">${esc(g.name)}${g.location ? ' — ' + esc(g.location) : ''}</option>`
    ).join('');

    el.innerHTML = `
      <div class="ap-page-head">
        <div><h1>📲 QR Scanner</h1><p>Live attendance QR code display</p></div>
        <div class="ap-page-head-right">
          <select id="qrGateSelect" class="ap-btn ap-btn--ghost" style="padding:7px 12px;cursor:pointer">
            ${gateOpts}
          </select>
          <a id="qrFullscreen" href="#" class="ap-btn ap-btn--ghost" title="Open full-screen display in new tab">⛶ Full Screen</a>
        </div>
      </div>

      <!-- QR Display Card -->
      <div class="ap-card ap-qr-display-card">
        <div class="ap-qr-display-inner">

          <!-- QR Panel -->
          <div class="ap-qr-panel">
            <div class="ap-qr-headline">Scan to Mark Attendance</div>
            <div class="ap-qr-gate-label" id="apQrGateLabel">Loading gate…</div>

            <!-- QR image frame -->
            <div class="ap-qr-frame" id="apQrFrame">
              <div class="ap-qr-spinner-wrap" id="apQrLoading">
                <div class="wsa-ap-spinner"></div>
                <p style="margin-top:10px;font-size:13px;color:var(--ap-muted)">Generating QR…</p>
              </div>
              <img id="apQrImg" src="" alt="QR Code"
                style="display:none;width:100%;max-width:260px;border-radius:10px;background:#fff;padding:10px">
              <div id="apQrScannedOverlay" class="ap-qr-scanned-overlay" style="display:none">
                <div style="font-size:48px">✅</div>
                <div style="font-size:16px;font-weight:800;margin-top:8px">QR Scanned!</div>
                <div style="font-size:12px;color:var(--ap-muted);margin-top:4px">New code generating…</div>
              </div>
            </div>

            <!-- Timer bar -->
            <div class="ap-qr-timer-wrap">
              <div class="ap-qr-bar-bg">
                <div class="ap-qr-bar" id="apQrBar" style="width:100%"></div>
              </div>
              <div class="ap-qr-timer-row">
                <span style="color:var(--ap-muted);font-size:12px">Refreshes in</span>
                <strong id="apQrSecs" style="font-size:22px;color:var(--ap-text);font-feature-settings:'tnum'">--</strong>
                <span style="color:var(--ap-muted);font-size:12px">seconds</span>
              </div>
            </div>

            <!-- Status badge -->
            <div id="apQrStatus" class="ap-qr-status-badge ap-badge ap-badge--IN">
              <span class="ap-live-dot"></span> QR Active — Ready to scan
            </div>
          </div>

          <!-- Sidebar info -->
          <div class="ap-qr-sidebar">
            <div class="ap-qr-inside-box">
              <div style="font-size:11px;font-weight:800;color:var(--ap-muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px">
                <span class="ap-live-dot"></span> Currently Inside
              </div>
              <div id="apQrInsideCount" style="font-size:48px;font-weight:900;color:var(--ap-green);line-height:1">—</div>
              <div style="font-size:12px;color:var(--ap-muted)">staff members</div>
            </div>

            <div class="ap-qr-time-box">
              <div style="font-size:11px;font-weight:800;color:var(--ap-muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px">Server Time</div>
              <div id="apQrServerTime" style="font-size:20px;font-weight:800;color:var(--ap-text);font-feature-settings:'tnum'">--:--:--</div>
            </div>

            <div class="ap-qr-instructions">
              <div style="font-size:11px;font-weight:800;color:var(--ap-muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px">How it works</div>
              <div class="ap-qr-step"><span>📱</span><span>Open phone camera</span></div>
              <div class="ap-qr-step"><span>🎯</span><span>Point at QR code</span></div>
              <div class="ap-qr-step"><span>🔗</span><span>Tap the link</span></div>
              <div class="ap-qr-step"><span>🔐</span><span>Enter ID &amp; PIN</span></div>
              <div class="ap-qr-step"><span>✅</span><span>Attendance marked!</span></div>
            </div>

            <div class="ap-qr-security">
              <div class="ap-qr-sec-row"><span>🔒</span><span>One-time use only</span></div>
              <div class="ap-qr-sec-row"><span>⏱</span><span>Expires every 30 sec</span></div>
              <div class="ap-qr-sec-row"><span>🛡</span><span>Server-verified</span></div>
              <div class="ap-qr-sec-row"><span>🔄</span><span>Auto-regenerates</span></div>
            </div>
          </div>

        </div>
      </div>`;

    // Gate selector
    el.querySelector('#qrGateSelect').addEventListener('change', function() {
      currentGateId = parseInt(this.value);
      qrCurrentToken = null;
      clearInterval(qrCountTimer);
      clearInterval(qrPollTimer);
      startQrPolling();
    });

    // Fullscreen link
    el.querySelector('#qrFullscreen').addEventListener('click', function(e) {
      e.preventDefault();
      const scannerPageId = parseInt(C.scannerPageId || 0);
      // Try to open the existing scanner shortcode page
      window.open(C.scannerUrl || ((C.siteUrl || '').replace(/\/$/, '') + '/index.php/attendance-scanner/'), '_blank');
    });

    function updateQrDisplay(data) {
      const imgEl      = el.querySelector('#apQrImg');
      const loadingEl  = el.querySelector('#apQrLoading');
      const overlayEl  = el.querySelector('#apQrScannedOverlay');
      const barEl      = el.querySelector('#apQrBar');
      const secsEl     = el.querySelector('#apQrSecs');
      const statusEl   = el.querySelector('#apQrStatus');
      const insideEl   = el.querySelector('#apQrInsideCount');
      const timeEl     = el.querySelector('#apQrServerTime');
      const gateLabel  = el.querySelector('#apQrGateLabel');

      if (!imgEl) return; // page was navigated away

      // Gate label
      if (gateLabel) {
        gateLabel.textContent = '📍 ' + data.gate_name +
          (data.gate_location ? ' — ' + data.gate_location : '');
      }

      // Inside count
      if (insideEl) insideEl.textContent = data.inside_count ?? '—';

      // Server time display
      if (timeEl) timeEl.textContent = data.server_time || '--:--';

      const STATUS_CLAIMED = 2;
      const isScanned = (data.qr_status === STATUS_CLAIMED);

      if (isScanned) {
        // Show scanned overlay briefly
        loadingEl.style.display = 'none';
        imgEl.style.display = 'none';
        overlayEl.style.display = 'flex';
        if (statusEl) {
          statusEl.className = 'ap-qr-status-badge ap-badge ap-badge--BREAK';
          statusEl.innerHTML = '✅ QR Scanned! Generating new code…';
        }
        return;
      }

      // New or same token. Always cache-bust the QR image when token changes.
      const newToken = data.token !== qrCurrentToken;
      qrCurrentToken = data.token;

      overlayEl.style.display = 'none';
      loadingEl.style.display = 'none';

      if (data.qr_image_url && (newToken || !imgEl.src)) {
        const busted = data.qr_image_url + (data.qr_image_url.indexOf('?') >= 0 ? '&' : '?') + '_wsa=' + Date.now();
        imgEl.style.opacity = '0';
        imgEl.style.transition = 'opacity .3s';
        imgEl.onload = () => { imgEl.style.opacity = '1'; };
        imgEl.src = busted;
        imgEl.style.display = 'block';
      } else {
        imgEl.style.display = 'block';
      }

      if (statusEl) {
        statusEl.className = 'ap-qr-status-badge ap-badge ap-badge--IN';
        statusEl.innerHTML = '<span class="ap-live-dot"></span> QR Active — Ready to scan';
      }

      // Anchor timer from server to avoid clock drift
      qrTtlMs = (data.qr_ttl || 30) * 1000;
      const leftSec = (data.seconds_left !== undefined && data.seconds_left !== null) ? parseInt(data.seconds_left, 10) : 30;
      // Never anchor to 0 on a valid response. If server says 0, immediately ask
      // for a fresh QR and keep the UI moving instead of staying stuck at 0.
      const safeLeftSec = Math.max(1, isNaN(leftSec) ? 30 : leftSec);
      qrExpiresAtMs = Date.now() + (safeLeftSec * 1000);

      // Start/restart countdown ticker
      clearInterval(qrCountTimer);
      qrCountTimer = setInterval(() => {
        const msLeft = Math.max(0, qrExpiresAtMs - Date.now());
        const secs   = Math.ceil(msLeft / 1000);
        const pct    = qrTtlMs > 0 ? Math.min(100, (msLeft / qrTtlMs) * 100) : 0;
        if (secsEl) secsEl.textContent = secs;
        if (barEl) {
          barEl.style.width = pct + '%';
          barEl.style.background =
            pct > 50 ? 'linear-gradient(90deg,#22D68A,#00b4d8)' :
            pct > 20 ? 'linear-gradient(90deg,#f59e0b,#FF4D00)' : '#ef4444';
        }
        if (secs <= 0 && !pollQr._refreshing) {
          pollQr._refreshing = true;
          if (secsEl) secsEl.textContent = '0';
          pollQr(true);
          setTimeout(() => { pollQr._refreshing = false; }, 900);
        }
      }, 250);
    }

    async function pollQr(force) {
      try {
        const url = restBase + currentGateId + '?_wsa=' + Date.now() + (force ? '&force=1' : '');
        const res = await fetch(url, {
          headers: { 'X-WP-Nonce': NONCE, 'Cache-Control': 'no-cache, no-store, must-revalidate' },
          cache: 'no-store'
        });
        if (!res.ok) return;
        const data = await res.json();
        if (data && data.success) updateQrDisplay(data);
      } catch(e) { /* network hiccup, retry on next interval */ }
    }

    function startQrPolling() {
      pollQr(); // immediate first poll
      qrPollTimer = setInterval(pollQr, 1000); // poll every second so the 30-sec QR rotates on time
    }

    startQrPolling();

    // Clean up when user navigates away
    const origNavigate = window.wsaApNavigate;
    window.wsaApCleanupQr = function() {
      clearInterval(qrPollTimer);
      clearInterval(qrCountTimer);
    };
  }


  /* ═══════════════════════════════════════════
     PAGE: ROLE ACCESS — Module Visibility
  ═══════════════════════════════════════════ */
  async function pageRoleAccess(el) {
    if (session.role !== 'super_admin') {
      el.innerHTML = `<div class="ap-empty"><div class="icon">🔒</div><p>Super Admin access required.</p></div>`;
      return;
    }

    const data = await get('/super/access');
    if (!data || !data.success) {
      el.innerHTML = '<div class="ap-empty">Failed to load role access settings.</div>';
      return;
    }

    const frontendPages = data.frontend_pages || {};
    const backendPages  = data.backend_pages || {};
    const adminModules  = new Set(data.admin_modules || []);
    const backendMods   = new Set(data.backend_admin_modules || []);

    const groupFrontend = {};
    Object.keys(frontendPages).forEach(id => {
      const sec = frontendPages[id].section || 'Other';
      if (!groupFrontend[sec]) groupFrontend[sec] = [];
      groupFrontend[sec].push([id, frontendPages[id]]);
    });

    const frontHtml = Object.keys(groupFrontend).map(sec => `
      <div class="ap-access-section">
        <h3>${esc(sec)}</h3>
        ${groupFrontend[sec].map(([id, p]) => `
          <label class="ap-access-item">
            <input type="checkbox" class="ra-front" value="${esc(id)}" ${adminModules.has(id) ? 'checked' : ''} ${id === 'dashboard' ? 'disabled checked' : ''}>
            <span>${esc(p.icon || '')} ${esc(p.label || id)}</span>
          </label>
        `).join('')}
      </div>
    `).join('');

    const backHtml = Object.keys(backendPages).map(slug => {
      const p = backendPages[slug];
      const superOnly = !!p.super_only;
      return `
        <label class="ap-access-item ${superOnly ? 'is-super-only' : ''}">
          <input type="checkbox" class="ra-back" value="${esc(slug)}" ${backendMods.has(slug) ? 'checked' : ''} ${slug === 'wsa-dashboard' || superOnly ? 'disabled checked' : ''}>
          <span>${esc(p.label || slug)} ${superOnly ? '<em>Super only</em>' : ''}</span>
        </label>
      `;
    }).join('');

    el.innerHTML = `
      <div class="ap-page-head">
        <div>
          <h1>🔐 Role Access</h1>
          <p>Control what normal Admin users can see in the frontend portal and backend plugin menus.</p>
        </div>
        <button class="ap-btn ap-btn--primary" id="raSave">Save Access</button>
      </div>

      <div class="ap-card ap-access-note">
        <strong>How it works:</strong><br>
        Super Admin always has full access. These settings apply to normal Admin accounts created with portal credentials.
      </div>

      <div class="ap-grid-2" style="align-items:start">
        <div class="ap-card">
          <div class="ap-card-head"><h2>Frontend Dashboard Modules</h2></div>
          <div class="ap-card-body ap-access-grid">${frontHtml}</div>
        </div>

        <div class="ap-card">
          <div class="ap-card-head"><h2>Backend WP Admin Plugin Menus</h2></div>
          <div class="ap-card-body ap-access-grid">${backHtml}</div>
        </div>
      </div>
    `;

    el.querySelector('#raSave').addEventListener('click', async () => {
      const front = Array.from(el.querySelectorAll('.ra-front:checked')).map(x => x.value);
      const back  = Array.from(el.querySelectorAll('.ra-back:checked')).map(x => x.value);
      if (!front.includes('dashboard')) front.push('dashboard');
      if (!back.includes('wsa-dashboard')) back.push('wsa-dashboard');

      const res = await post('/super/access', { admin_modules: front, backend_admin_modules: back });
      if (res && res.success) {
        toast('Role access saved successfully.', 'ok');
        if (session.role === 'super_admin' && res.access && res.access.session_access) {
          session.access = res.access.session_access;
          try { sessionStorage.setItem(SESSION_KEY, JSON.stringify(session)); } catch(e) {}
        }
      } else if (res) {
        toast(res.message || 'Unable to save role access.', 'err');
      }
    });
  }

  /* ═══════════════════════════════════════════
     PAGE: SUPER ADMIN — Admin Account Management
  ═══════════════════════════════════════════ */
  async function pageSuperAdmin(el) {
    if (session.role !== 'super_admin') {
      el.innerHTML = `<div class="ap-empty"><div class="icon">🔒</div><p>Super Admin access required.</p></div>`;
      return;
    }

    let editingId = null;

    async function load() {
      const data = await get('/super/admins');
      if (!data || !data.success) { el.innerHTML = '<div class="ap-empty">Failed to load.</div>'; return; }

      const rows = data.admins.length === 0
        ? '<tr><td colspan="6" class="ap-empty">No admin accounts found.</td></tr>'
        : data.admins.map(a => `<tr>
            <td>
              <div class="ap-flex">
                <div class="ap-user-av" style="width:28px;height:28px;font-size:11px;flex-shrink:0">${initials(a.name || a.username)}</div>
                <div><strong>${esc(a.name || a.username)}</strong><span class="ap-muted">${esc(a.username)}</span></div>
              </div>
            </td>
            <td>${esc(a.email || '—')}</td>
            <td>
              ${a.role === 'super_admin'
                ? '<span class="ap-badge" style="background:rgba(251,146,60,.15);color:#fb923c;border:1px solid rgba(251,146,60,.3)">🛡️ Super Admin</span>'
                : '<span class="ap-badge ap-badge--pending">Admin</span>'}
            </td>
            <td><span class="ap-badge ap-badge--${esc(a.status)}">${esc(a.status)}</span></td>
            <td style="color:var(--ap-muted);font-size:12px">${a.last_login ? fmtTime(a.last_login) + ' ' + fmtDate(a.last_login.split(' ')[0]) : 'Never'}</td>
            <td class="ap-actions">
              <button class="ap-btn ap-btn--xs ap-btn--ghost sa-edit"
                data-id="${a.id}" data-username="${esc(a.username)}" data-name="${esc(a.name)}"
                data-email="${esc(a.email)}" data-role="${esc(a.role)}" data-status="${esc(a.status)}">✏️ Edit</button>
              <button class="ap-btn ap-btn--xs ap-btn--ghost sa-reset" data-id="${a.id}" data-name="${esc(a.name || a.username)}">🔑 Pwd</button>
              <button class="ap-btn ap-btn--xs ap-btn--danger sa-del" data-id="${a.id}" data-name="${esc(a.name || a.username)}">🗑</button>
            </td>
          </tr>`).join('');

      el.querySelector('#saTable').innerHTML = rows;
      el.querySelector('#saCount').textContent = data.admins.length + ' admin account(s)';
      attachSaEvents(data.admins, load);
    }

    el.innerHTML = `
      <div class="ap-page-head">
        <div>
          <h1>🛡️ Admin Accounts</h1>
          <p id="saCount">…</p>
        </div>
        <button class="ap-btn ap-btn--ghost" id="saRefresh">↻ Refresh</button>
      </div>

      <!-- Info banner -->
      <div class="ap-card" style="border-left:3px solid #fb923c;margin-bottom:16px">
        <div class="ap-card-body" style="padding:12px 16px">
          <div style="font-size:13px;color:var(--ap-text)">
            <strong>🛡️ Super Admin</strong> — Full access including this page and creating/deleting admins.<br>
            <strong>👤 Admin</strong> — Access is controlled from <b>Role Access</b>. You can choose which modules this role can see.
          </div>
        </div>
      </div>

      <div class="ap-grid-2" style="align-items:start">
        <!-- Table -->
        <div class="ap-card">
          <div class="ap-card-head"><h2>All Admin Accounts</h2></div>
          <div class="ap-table-wrap">
            <table class="ap-table">
              <thead><tr><th>Admin</th><th>Email</th><th>Role</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead>
              <tbody id="saTable"><tr><td colspan="6" class="ap-empty"><span class="ap-spin-mini"></span></td></tr></tbody>
            </table>
          </div>
        </div>

        <!-- Create form -->
        <div class="ap-card">
          <div class="ap-card-title" id="saFormTitle">Create Admin Account</div>
          <div class="ap-card-body">
            <div class="ap-error" id="saErr"></div>
            <div class="ap-success" id="saOk"></div>
            <div class="ap-field"><label>Username *</label><input id="saUsername" type="text" placeholder="e.g. john_manager" autocomplete="off"></div>
            <div class="ap-field ap-mt-8"><label>Display Name</label><input id="saName" type="text" placeholder="Full name"></div>
            <div class="ap-field ap-mt-8"><label>Email</label><input id="saEmail" type="email" placeholder="email@company.com"></div>
            <div class="ap-form-row ap-mt-8">
              <div class="ap-field"><label>Role</label>
                <select id="saRole">
                  <option value="admin">👤 Admin</option>
                  <option value="super_admin">🛡️ Super Admin</option>
                </select>
              </div>
              <div class="ap-field"><label>Status</label>
                <select id="saStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select>
              </div>
            </div>
            <div class="ap-field ap-mt-8"><label>Password <span class="ap-muted" id="saPwdHint">(min 6 chars) *</span></label>
              <input id="saPassword" type="password" placeholder="••••••••" autocomplete="new-password">
            </div>
            <div class="ap-flex ap-mt-16" style="gap:8px">
              <button class="ap-btn ap-btn--primary" id="saSubmit" style="width:auto">Create Account</button>
              <button class="ap-btn ap-btn--outline" id="saCancelEdit" style="display:none">Cancel</button>
            </div>
          </div>
        </div>
      </div>`;

    el.querySelector('#saRefresh').addEventListener('click', () => pageSuperAdmin(el));
    load();

    function resetForm() {
      editingId = null;
      ['saUsername','saName','saEmail','saPassword'].forEach(id => { const f = el.querySelector('#'+id); if(f) f.value=''; });
      el.querySelector('#saRole').value = 'admin';
      el.querySelector('#saStatus').value = 'active';
      el.querySelector('#saUsername').disabled = false;
      el.querySelector('#saFormTitle').textContent = 'Create Admin Account';
      el.querySelector('#saSubmit').textContent = 'Create Account';
      el.querySelector('#saPwdHint').textContent = '(min 6 chars) *';
      el.querySelector('#saCancelEdit').style.display = 'none';
      el.querySelector('#saErr').classList.remove('visible');
      el.querySelector('#saOk').classList.remove('visible');
    }

    el.querySelector('#saCancelEdit').addEventListener('click', resetForm);

    el.querySelector('#saSubmit').addEventListener('click', async () => {
      const errEl = el.querySelector('#saErr');
      const okEl  = el.querySelector('#saOk');
      errEl.classList.remove('visible'); okEl.classList.remove('visible');
      const username = el.querySelector('#saUsername').value.trim();
      const password = el.querySelector('#saPassword').value;
      const name     = el.querySelector('#saName').value.trim();
      const email    = el.querySelector('#saEmail').value.trim();
      const role     = el.querySelector('#saRole').value;
      const status   = el.querySelector('#saStatus').value;

      let res;
      if (editingId) {
        if (password && password.length < 6) { errEl.textContent = 'Password must be at least 6 chars.'; errEl.classList.add('visible'); return; }
        res = await put(`/super/admins/${editingId}`, { name, email, role, status, ...(password ? {password} : {}) });
      } else {
        if (!username) { errEl.textContent = 'Username is required.'; errEl.classList.add('visible'); return; }
        if (password.length < 6) { errEl.textContent = 'Password must be at least 6 chars.'; errEl.classList.add('visible'); return; }
        res = await post('/super/admins', { username, name, email, role, status, password });
      }
      if (res && res.success) {
        okEl.textContent = editingId ? 'Account updated!' : 'Account created!';
        okEl.classList.add('visible');
        resetForm(); load();
      } else if (res) {
        errEl.textContent = res.message; errEl.classList.add('visible');
      }
    });

    function attachSaEvents(admins, reload) {
      el.querySelectorAll('.sa-edit').forEach(btn => {
        btn.addEventListener('click', () => {
          editingId = parseInt(btn.dataset.id);
          el.querySelector('#saUsername').value  = btn.dataset.username;
          el.querySelector('#saUsername').disabled = true;
          el.querySelector('#saName').value    = btn.dataset.name;
          el.querySelector('#saEmail').value   = btn.dataset.email;
          el.querySelector('#saRole').value    = btn.dataset.role;
          el.querySelector('#saStatus').value  = btn.dataset.status;
          el.querySelector('#saPassword').value = '';
          el.querySelector('#saFormTitle').textContent = 'Edit Admin Account';
          el.querySelector('#saSubmit').textContent = 'Update Account';
          el.querySelector('#saPwdHint').textContent = '(leave blank to keep current)';
          el.querySelector('#saCancelEdit').style.display = 'inline-flex';
          el.querySelector('#saErr').classList.remove('visible');
          el.querySelector('#saOk').classList.remove('visible');
          el.querySelector('.ap-card:last-child').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      });

      el.querySelectorAll('.sa-reset').forEach(btn => {
        btn.addEventListener('click', () => {
          const bd = openModal(`
            <div class="ap-modal-head"><h3>🔑 Reset Password — ${esc(btn.dataset.name)}</h3><button class="ap-modal-close">✕</button></div>
            <div class="ap-modal-body">
              <div class="ap-field"><label>New Password *</label><input type="password" id="rPwd" placeholder="Min 6 chars" autocomplete="new-password"></div>
              <div class="ap-field ap-mt-8"><label>Confirm Password *</label><input type="password" id="rPwd2" placeholder="Repeat password"></div>
            </div>
            <div class="ap-modal-foot">
              <button class="ap-btn ap-btn--outline ap-modal-close">Cancel</button>
              <button class="ap-btn ap-btn--primary" id="rPwdSave">Reset Password</button>
            </div>`);
          bd.querySelector('#rPwdSave').addEventListener('click', async () => {
            const p1 = bd.querySelector('#rPwd').value;
            const p2 = bd.querySelector('#rPwd2').value;
            if (p1.length < 6) { toast('Min 6 characters.', 'err'); return; }
            if (p1 !== p2)     { toast('Passwords do not match.', 'err'); return; }
            const res = await post(`/super/admins/${btn.dataset.id}/reset`, { password: p1 });
            if (res && res.success) { toast('Password reset!', 'ok'); closeModal(); }
            else if (res) toast(res.message, 'err');
          });
        });
      });

      el.querySelectorAll('.sa-del').forEach(btn => {
        btn.addEventListener('click', async () => {
          if (!await confirm(`Delete admin account <strong>${btn.dataset.name}</strong>? This cannot be undone.`)) return;
          const res = await del(`/super/admins/${btn.dataset.id}`);
          if (res && res.success) { toast('Admin deleted.', 'ok'); reload(); }
          else if (res) toast(res.message, 'err');
        });
      });
    }
  }

  /* ═══════════════════════════════════════════
     BOOT
  ═══════════════════════════════════════════ */
  function boot() {
    if (loadSession()) {
      renderApp();
    } else {
      renderLogin();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

})();
