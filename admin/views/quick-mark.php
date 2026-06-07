<?php defined('ABSPATH') || exit; ?>
<div class="wsa-wrap">
  <div class="wsa-page-header">
    <div>
      <h1 class="wsa-title">⚡ Quick Mark</h1>
      <p class="wsa-sub">Manually check in / out / break any staff member in one click</p>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <span id="qm-last-update" class="wsa-muted" style="font-size:12px"></span>
      <button class="wsa-btn" id="qm-refresh-btn">🔄 Refresh</button>
    </div>
  </div>

  <!-- Summary bar -->
  <div class="wsa-qm-summary-bar" id="qm-summary">
    <div class="wsa-qm-sum-item wsa-qm-in"><span id="qm-count-in">—</span><small>Inside</small></div>
    <div class="wsa-qm-sum-item wsa-qm-out"><span id="qm-count-out">—</span><small>Done</small></div>
    <div class="wsa-qm-sum-item wsa-qm-break"><span id="qm-count-break">—</span><small>On Break</small></div>
    <div class="wsa-qm-sum-item wsa-qm-absent"><span id="qm-count-absent">—</span><small>Absent</small></div>
  </div>

  <!-- Toast -->
  <div id="qm-toast" class="wsa-qm-toast" style="display:none"></div>

  <!-- Filter -->
  <div class="wsa-filter-bar" style="margin-bottom:16px">
    <input type="text" id="qm-search" placeholder="🔍 Search by name or ID…" class="wsa-input" style="max-width:280px">
    <select id="qm-dept-filter" class="wsa-select" style="max-width:200px">
      <option value="">All Departments</option>
    </select>
    <select id="qm-status-filter" class="wsa-select" style="max-width:160px">
      <option value="">All Statuses</option>
      <option value="ABSENT">Absent / Not In</option>
      <option value="IN">Working</option>
      <option value="BREAK">On Break</option>
      <option value="OUT">Done</option>
    </select>
  </div>

  <!-- Staff grid -->
  <div id="qm-grid" class="wsa-qm-grid">
    <div class="wsa-qm-loading">⏳ Loading staff…</div>
  </div>
</div>

<style>
/* Quick-mark page specific styles */
.wsa-qm-summary-bar { display:flex; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
.wsa-qm-sum-item { display:flex; flex-direction:column; align-items:center; padding:12px 20px; border-radius:10px; min-width:80px; background:var(--wsa-card-bg,#fff); }
.wsa-qm-sum-item span { font-size:28px; font-weight:700; line-height:1; }
.wsa-qm-sum-item small { font-size:11px; opacity:.7; margin-top:4px; text-transform:uppercase; letter-spacing:.05em; }
.wsa-qm-in    { border-top:3px solid #22D68A; }
.wsa-qm-out   { border-top:3px solid #6c757d; }
.wsa-qm-break { border-top:3px solid #f59e0b; }
.wsa-qm-absent{ border-top:3px solid #ef4444; }
.wsa-qm-in span   { color:#22D68A; }
.wsa-qm-out span  { color:#6c757d; }
.wsa-qm-break span{ color:#f59e0b; }
.wsa-qm-absent span{ color:#ef4444; }

.wsa-qm-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; }
.wsa-qm-card { background:var(--wsa-card-bg,#fff); border-radius:12px; padding:16px; border:1px solid #e5e7eb; transition:border-color .2s; }
.wsa-qm-card:hover { border-color:#cbd5e1; }
.wsa-qm-card.qm-status-in     { border-left:3px solid #22D68A; }
.wsa-qm-card.qm-status-out    { border-left:3px solid #6c757d; }
.wsa-qm-card.qm-status-break  { border-left:3px solid #f59e0b; }
.wsa-qm-card.qm-status-absent { border-left:3px solid #ef4444; }

.wsa-qm-card-top { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px; }
.wsa-qm-name { font-weight:700; font-size:15px; }
.wsa-qm-eid  { font-size:11px; opacity:.6; font-family:monospace; margin-top:2px; }
.wsa-qm-dept { font-size:12px; opacity:.5; margin-top:1px; }
.wsa-qm-badge { font-size:11px; font-weight:600; padding:3px 8px; border-radius:20px; white-space:nowrap; }
.wsa-qm-badge.in     { background:#d1fae5; color:#22D68A; }
.wsa-qm-badge.out    { background:#f3f4f6; color:#6c757d; }
.wsa-qm-badge.break  { background:#fef3c7;  color:#f59e0b; }
.wsa-qm-badge.absent { background:#fee2e2;   color:#ef4444; }

.wsa-qm-timer { font-size:13px; opacity:.7; margin:6px 0 10px; min-height:18px; }
.wsa-qm-actions { display:flex; gap:6px; flex-wrap:wrap; }
.wsa-qm-btn { padding:6px 12px; border-radius:7px; border:none; cursor:pointer; font-size:12px; font-weight:600; transition:.15s; }
.wsa-qm-btn:hover { opacity:.85; transform:translateY(-1px); }
.wsa-qm-btn:disabled { opacity:.4; cursor:not-allowed; transform:none; }
.wsa-qm-btn-in     { background:#22D68A; color:#000; }
.wsa-qm-btn-out    { background:#ef4444; color:#fff; }
.wsa-qm-btn-brk    { background:#f59e0b; color:#000; }
.wsa-qm-btn-resume { background:#00b4d8; color:#000; }
.wsa-qm-btn-done { background:#e5e7eb; color:#6b7280; cursor:not-allowed; }
@media (max-width:782px){ .wsa-qm-grid{grid-template-columns:1fr!important}.wsa-qm-card{padding:14px}.wsa-qm-actions{display:grid;grid-template-columns:1fr;gap:10px}.wsa-qm-btn{width:100%;padding:12px 14px;font-size:14px}.wsa-filter-bar{display:grid!important;grid-template-columns:1fr!important;gap:10px}.wsa-input,.wsa-select{max-width:100%!important;width:100%!important}.wsa-qm-summary-bar{display:grid;grid-template-columns:repeat(2,1fr)} }

.wsa-qm-toast { position:fixed; bottom:24px; right:24px; background:#fff; border:1px solid #e5e7eb; color:#1f2937; padding:12px 20px; border-radius:10px; font-size:14px; z-index:9999; box-shadow:0 4px 24px rgba(0,0,0,.15); max-width:360px; }
.wsa-qm-toast.ok  { border-left:4px solid #22D68A; }
.wsa-qm-toast.err { border-left:4px solid #ef4444; }
.wsa-qm-loading { text-align:center; padding:40px; opacity:.6; width:100%; }
.wsa-input  { background:#fff; border:1px solid #d1d5db; color:#111827; border-radius:8px; padding:8px 12px; font-size:14px; }
.wsa-select { background:#fff; border:1px solid #d1d5db; color:#111827; border-radius:8px; padding:8px 12px; font-size:14px; }
</style>

<script>
(function(){
  var ajax_url   = (typeof ajaxurl !== 'undefined') ? ajaxurl : (wsaAdmin.ajax_url || (window.location.origin + '/wp-admin/admin-ajax.php'));
  var api_url    = ajax_url + '?action=wsa_qm_status&nonce=' + encodeURIComponent(wsaAdmin.rest_nonce);
  var mark_url   = ajax_url;
  var nonce      = wsaAdmin.rest_nonce;
  var staffData  = [];
  var serverOffset = 0;
  var timers     = {};

  function nowMs() { return Date.now() + serverOffset; }
  function pad(n) { return n<10?'0'+n:''+n; }
  function fmtMs(ms) {
    var s=Math.floor(ms/1000), h=Math.floor(s/3600), m=Math.floor((s%3600)/60), sc=s%60;
    return pad(h)+':'+pad(m)+':'+pad(sc);
  }
  function normalizeStatus(v) {
    v = String(v || 'ABSENT').toUpperCase().replace(/[^A-Z_]/g,'').trim();
    if (['OUT','DONE','CHECKOUT','CHECKED_OUT','COMPLETED'].indexOf(v) !== -1) return 'OUT';
    if (['BREAK','ON_BREAK','BREAK_START'].indexOf(v) !== -1) return 'BREAK';
    if (['IN','PRESENT','CHECKIN','CHECKED_IN','WORKING'].indexOf(v) !== -1) return 'IN';
    return 'ABSENT';
  }
  function fmtTime(dt) {
    if (!dt) return '—';
    var d=new Date((dt+'').replace(' ','T'));
    var h=d.getHours(), mn=d.getMinutes(), ap=h>=12?'PM':'AM';
    return (h%12||12)+':'+pad(mn)+' '+ap;
  }
  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>\"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function toast(msg, isErr) {
    var t = document.getElementById('qm-toast');
    if (!t) return;
    t.textContent = msg;
    t.className   = 'wsa-qm-toast ' + (isErr?'err':'ok');
    t.style.display = '';
    clearTimeout(t._tid);
    t._tid = setTimeout(function(){ t.style.display='none'; }, 3500);
  }

  function loadStaff() {
    document.getElementById('qm-refresh-btn').disabled = true;
    fetch(api_url + '&_=' + Date.now(), { credentials:'same-origin', cache:'no-store' })
      .then(function(r){ if(!r.ok){ throw new Error('API error '+r.status); } return r.json(); })
      .then(function(res){
        document.getElementById('qm-refresh-btn').disabled = false;
        if (!res.success) return;
        if (res.server_ts_ms) serverOffset = res.server_ts_ms - Date.now();
        staffData = res.staff || [];
        // populate dept filter
        var depts = [...new Set(staffData.map(function(s){ return s.department; }).filter(Boolean))].sort();
        var df = document.getElementById('qm-dept-filter');
        df.innerHTML = '<option value="">All Departments</option>';
        depts.forEach(function(d){ df.innerHTML += '<option value="'+esc(d)+'">'+esc(d)+'</option>'; });
        renderGrid();
        document.getElementById('qm-last-update').textContent = 'Updated ' + new Date().toLocaleTimeString();
      })
      .catch(function(err){ console.error(err); document.getElementById('qm-grid').innerHTML='<div class="wsa-qm-loading">❌ Failed to load staff data. Please refresh.</div>'; document.getElementById('qm-refresh-btn').disabled=false; toast('Unable to load Quick Mark staff list.', true); });
  }

  function filtered() {
    var search = (document.getElementById('qm-search').value||'').toLowerCase();
    var dept   = document.getElementById('qm-dept-filter').value;
    var status = document.getElementById('qm-status-filter').value;
    return staffData.filter(function(s){
      var nm = String(s.name || '').toLowerCase();
      var eid = String(s.employee_id || '').toLowerCase();
      if (search && !nm.includes(search) && !eid.includes(search)) return false;
      if (dept && s.department !== dept) return false;
      var st = normalizeStatus(s.status);
      if (status && st !== status && !(status==='ABSENT' && (st==='ABSENT'||!st))) return false;
      return true;
    });
  }

  function updateCounts(list) {
    var counts = {IN:0,OUT:0,BREAK:0,ABSENT:0};
    staffData.forEach(function(s){ var st=normalizeStatus(s.status); if(counts[st]!==undefined) counts[st]++; else counts.ABSENT++; });
    document.getElementById('qm-count-in').textContent    = counts.IN;
    document.getElementById('qm-count-out').textContent   = counts.OUT;
    document.getElementById('qm-count-break').textContent = counts.BREAK;
    document.getElementById('qm-count-absent').textContent= counts.ABSENT;
  }

  function renderGrid() {
    // Clear old timers
    Object.keys(timers).forEach(function(k){ clearInterval(timers[k]); });
    timers = {};

    var list = filtered();
    updateCounts(list);
    var grid = document.getElementById('qm-grid');

    if (!list.length) { grid.innerHTML='<div class="wsa-qm-loading">No staff match your filter.</div>'; return; }

    grid.innerHTML = list.map(function(s){
      var status = normalizeStatus(s.status);
      var badge  = {IN:'✅ Working', OUT:'🏁 Done', BREAK:'☕ Break', ABSENT:'❌ Absent'}[status]||status;
      var bcls   = status.toLowerCase();

      // Same state logic as frontend Quick Mark:
      // ABSENT = Check In, IN = Break + Check Out, BREAK = Resume + Check Out, OUT = finished/no more action today.
      var actions = '';
      if (status==='ABSENT') {
        actions += '<button class="wsa-qm-btn wsa-qm-btn-in" data-id="'+s.id+'" data-action="checkin">✅ Check In</button>';
      } else if (status==='IN') {
        actions += '<button class="wsa-qm-btn wsa-qm-btn-brk" data-id="'+s.id+'" data-action="break_start">☕ Break</button>';
        actions += '<button class="wsa-qm-btn wsa-qm-btn-out" data-id="'+s.id+'" data-action="checkout">🚪 Check Out</button>';
      } else if (status==='BREAK') {
        actions += '<button class="wsa-qm-btn wsa-qm-btn-resume" data-id="'+s.id+'" data-action="break_end">▶️ Resume</button>';
        actions += '<button class="wsa-qm-btn wsa-qm-btn-out" data-id="'+s.id+'" data-action="checkout">🚪 Check Out</button>';
      } else if (status==='OUT') {
        actions += '<button class="wsa-qm-btn wsa-qm-btn-done" disabled>✅ Completed Today</button>';
      }

      return '<div class="wsa-qm-card qm-status-'+bcls+'" id="qm-card-'+s.id+'">'
        + '<div class="wsa-qm-card-top">'
        +   '<div><div class="wsa-qm-name">'+esc(s.name)+'</div>'
        +      '<div class="wsa-qm-eid">'+esc(s.employee_id || '—')+'</div>'
        +      '<div class="wsa-qm-dept">'+esc(s.department || '—')+'</div></div>'
        +   '<span class="wsa-qm-badge '+bcls+'">'+badge+'</span>'
        + '</div>'
        + '<div class="wsa-qm-timer" id="qm-timer-'+s.id+'">'
        +   (s.login_time ? '🟢 ' + fmtTime(s.login_time) : '')
        + '</div>'
        + '<div class="wsa-qm-actions">'+actions+'</div>'
        + '</div>';
    }).join('');

    // Wire action buttons
    grid.querySelectorAll('.wsa-qm-btn').forEach(function(btn){
      btn.addEventListener('click', function(){
        var sid    = this.dataset.id;
        var action = this.dataset.action;
        quickMark(sid, action, this);
      });
    });

    // Start live timers for IN / BREAK cards
    list.forEach(function(s){
      if ((normalizeStatus(s.status)==='IN'||normalizeStatus(s.status)==='BREAK') && s.login_ts_ms) {
        var timerEl = document.getElementById('qm-timer-'+s.id);
        if (!timerEl) return;
        timers[s.id] = setInterval(function(){
          var total = Math.max(0, nowMs() - s.login_ts_ms);
          // subtract break duration
          var breakMs = (s.worked_mins||0) < 0 ? 0 : 0; // placeholder
          // Simple: just show elapsed from login (approximate)
          timerEl.textContent = '🟢 In: '+fmtTime(new Date(s.login_ts_ms).toISOString().replace('T',' ').slice(0,19))
            + '  ⏱ '+fmtMs(Math.max(0, nowMs()-s.login_ts_ms));
        }, 1000);
      }
    });
  }

  function quickMark(staff_id, action, btnEl) {
    btnEl.disabled = true;
    var oldText = btnEl.textContent;
    btnEl.textContent = '⏳';

    fetch(mark_url, {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      credentials:'same-origin',
      cache:'no-store',
      body: new URLSearchParams({action:'wsa_qm_action', nonce:nonce, staff_id:staff_id, action_type:action, _t:Date.now()}).toString()
    })
    .then(function(r){ return r.json().catch(function(){ return {success:false,message:'Invalid server response'}; }).then(function(j){ if(!r.ok && j && !j.message) j.message='Server error '+r.status; return j; }); })
    .then(function(res){
      if (res.success) {
        toast(res.message || 'Updated successfully.', false);
        // Immediately update local card state, then reload from server so button UI changes smoothly.
        staffData = staffData.map(function(s){
          if (String(s.id) !== String(staff_id)) return s;
          var ns = normalizeStatus(res.status || (action==='checkin'?'IN':action==='checkout'?'OUT':action==='break_start'?'BREAK':action==='break_end'?'IN':s.status));
          s.status = ns;
          if (ns === 'IN' && !s.login_time) s.login_time = new Date().toISOString().slice(0,19).replace('T',' ');
          return s;
        });
        renderGrid();
        setTimeout(loadStaff, 250);
      } else {
        toast('❌ '+res.message, true);
        btnEl.disabled=false; btnEl.textContent=oldText;
      }
    })
    .catch(function(){
      toast('❌ Network error. Try again.', true);
      btnEl.disabled=false; btnEl.textContent=oldText;
    });
  }

  // Filters
  ['qm-search','qm-dept-filter','qm-status-filter'].forEach(function(id){
    var e = document.getElementById(id);
    if (e) e.addEventListener('input', function(){ renderGrid(); });
  });

  document.getElementById('qm-refresh-btn').addEventListener('click', loadStaff);

  // Auto-refresh every 30 s
  setInterval(loadStaff, 30000);

  loadStaff();
})();
</script>
