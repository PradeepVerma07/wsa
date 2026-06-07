/* Webtrionix Staff Attendance – Admin JS v4.3 Production
   FIXED: Real-time "who's inside" using server_ts_ms offset
   FIXED: Stats live-refresh every 30s
   NEW: Salary preview, manual entry calc, notifications */
/* Expose syncOffset globally so inline page scripts can call it */
window.syncOffset = null;

(function ($) {
  'use strict';

  /* ══════════════════════════════════════════════
     SERVER TIME OFFSET — keeps all timers accurate
     even if admin's computer clock is wrong
  ══════════════════════════════════════════════ */
  var serverOffset = 0; // ms diff between server and client

  function syncOffset(serverTsMs) {
    if (serverTsMs && serverTsMs > 1000000000000) {
      serverOffset = serverTsMs - Date.now();
    }
  }
  // Expose globally
  window.syncOffset = syncOffset;
  // Apply any pre-loaded server timestamp (set by inline script before admin.js loaded)
  if (window._wsaInitServerTs) syncOffset(window._wsaInitServerTs);

  function serverNow() { return Date.now() + serverOffset; }

  function pad2(n) { return n < 10 ? '0' + n : '' + n; }

  function formatElapsed(secs) {
    secs = Math.max(0, secs);
    return pad2(Math.floor(secs / 3600)) + ':' + pad2(Math.floor((secs % 3600) / 60)) + ':' + pad2(secs % 60);
  }

  function fmtTime(dt) {
    if (!dt) return '—';
    var d = new Date((dt + '').replace(' ', 'T'));
    if (isNaN(d)) return dt;
    var h = d.getHours(), m = d.getMinutes(), ap = h >= 12 ? 'PM' : 'AM';
    return (h % 12 || 12) + ':' + pad2(m) + ' ' + ap;
  }

  function esc(s) {
    return $('<div>').text(s || '').html();
  }

  /* ══════════════════════════════════════════════
     1. SERVER CLOCK (dashboard header)
  ══════════════════════════════════════════════ */
  var serverClockEl = document.getElementById('wsa-server-time');
  if (serverClockEl) {
    setInterval(function () {
      var now = new Date(serverNow());
      var h = now.getHours(), m = now.getMinutes(), s = now.getSeconds(), ap = h >= 12 ? 'PM' : 'AM';
      serverClockEl.textContent = (h % 12 || 12) + ':' + pad2(m) + ':' + pad2(s) + ' ' + ap;
    }, 1000);
  }

  /* ══════════════════════════════════════════════
     2. WHO'S INSIDE — real-time tick (1s) + auto-refresh (15s)
     FIXED: uses login_ts_ms from server, computes elapsed correctly
  ══════════════════════════════════════════════ */
  var insideGrid    = document.getElementById('wsa-inside-grid');
  var insideRows    = [];    // cached rows with login_ts_ms
  var timerInterval = null;

  /* Tick all timers every second using server-synced time */
  function tickInsideTimers() {
    var now = serverNow();
    document.querySelectorAll('.wsa-live-timer[data-login-ms]').forEach(function (el) {
      var loginMs      = parseInt(el.dataset.loginMs, 10);
      if (!loginMs) return;
      var breakMs      = parseInt(el.dataset.breakMs || 0, 10);
      var onBreak      = el.dataset.onBreak === '1';
      var breakStartTs = parseInt(el.dataset.breakStartTs || 0, 10);
      // Add ongoing break so worked time excludes current break
      if (onBreak && breakStartTs) { breakMs += Math.max(0, now - breakStartTs); }
      var elapsed = Math.max(0, now - loginMs);
      var worked  = Math.max(0, elapsed - breakMs);
      var secs    = Math.floor(worked / 1000);
      el.textContent = formatElapsed(secs);
    });
  }

  function buildInsideCard(p) {
    var av        = (p.name || '?').substring(0, 2).toUpperCase();
    var isBreak   = p.on_break || p.status === 'BREAK';
    var breakMs   = (p.break_duration_mins || 0) * 60 * 1000;
    var bStartTs  = p.break_start ? new Date(p.break_start.replace(' ','T')).getTime() : 0;
    var cardCls   = 'wsa-inside-card' + (isBreak ? ' wsa-card-on-break' : '');
    var breakBadge= isBreak ? '<span class="wsa-badge wsa-badge--break">☕ Break</span>' : '';
    var breakInfo = (isBreak && p.break_start)
      ? ' <small style="color:#f59e0b">· Break since ' + (p.break_start.split(' ')[1]||'').substring(0,5) + '</small>' : '';
    return '<div class="' + cardCls + '">' +
      '<div class="wsa-ic-avatar">' + av + '</div>' +
      '<div class="wsa-ic-body">' +
        '<div class="wsa-ic-name">' + esc(p.name) + ' ' + breakBadge + '</div>' +
        '<div class="wsa-ic-meta">' + esc(p.emp_id) + ' · ' + esc(p.department || 'No Dept') + '</div>' +
        '<div class="wsa-ic-time"><span class="wsa-ic-label">IN:</span><strong>' + esc(p.login_fmt) + '</strong></div>' +
        '<div class="wsa-ic-timer">' +
          '<div class="wsa-live-dot"></div>' +
          // data-login-ms is the SERVER unix ms — JS uses this with offset
          '<span class="wsa-live-timer" data-login-ms="' + (p.login_ts_ms||0) + '" data-break-ms="' + Math.round(breakMs) + '" data-on-break="' + (isBreak?"1":"0") + '" data-break-start-ts="' + (bStartTs||0) + '">' +
            formatElapsed(p.worked_secs || p.elapsed_secs || 0) +
          '</span>' +
        '</div>' +
      '</div></div>';
  }

  function refreshInside() {
    if (!insideGrid && !document.getElementById('wsa-inside-count')) return;
    $.ajax({
      url    : wsaAdmin.rest_url + 'admin/inside',
      headers: { 'X-WP-Nonce': wsaAdmin.rest_nonce },
      success: function (res) {
        if (!res.success) return;

        // ← CRITICAL: sync server offset from this response
        syncOffset(res.server_ts_ms);

        var ts = document.getElementById('wsa-last-refresh');
        if (ts) ts.textContent = 'Live · ' + res.server_time;

        var cnt   = document.getElementById('wsa-inside-count');
        var badge = document.getElementById('wsa-inside-count-badge');
        if (cnt)   cnt.textContent   = res.count;
        if (badge) badge.textContent = res.count + ' Inside';

        if (insideGrid) {
          if (res.count === 0) {
            insideGrid.innerHTML = '<div class="wsa-empty-full">No staff currently inside the factory.</div>';
          } else {
            insideGrid.innerHTML = res.data.map(buildInsideCard).join('');
          }
        }
      }
    });
  }

  // Boot timers immediately
  if (timerInterval) clearInterval(timerInterval);
  timerInterval = setInterval(tickInsideTimers, 1000);

  // First fetch right away, then every 15s
  if (insideGrid || document.getElementById('wsa-inside-count')) {
    refreshInside();
    setInterval(refreshInside, 15000);
    $('#wsa-refresh-inside').on('click', refreshInside);
  }

  /* ══════════════════════════════════════════════
     3. DASHBOARD STATS AUTO-REFRESH (30s)
  ══════════════════════════════════════════════ */
  function refreshStats() {
    $.post(wsaAdmin.ajaxurl, { action: 'wsa_live_stats', nonce: wsaAdmin.nonce }, function (res) {
      if (!res.success) return;
      var d = res.data;
      var map = {
        'wsa-s-total-staff'   : d.total_staff,
        'wsa-s-present-today' : d.present_today,
        'wsa-s-inside-now'    : d.inside_now,
        'wsa-s-checked-out'   : d.checked_out,
        'wsa-s-late-today'    : d.late_today,
        'wsa-s-overtime'      : d.overtime_today,
        'wsa-s-manual-entry'  : d.manual_today,
        'wsa-s-absent'        : Math.max(0, d.total_staff - d.present_today),
      };
      Object.keys(map).forEach(function (id) {
        var el = document.getElementById(id);
        if (el && map[id] !== undefined) el.textContent = map[id];
      });
    });
  }
  if ($('.wsa-stats').length) {
    refreshStats();
    setInterval(refreshStats, 30000);
  }

  /* ══════════════════════════════════════════════
     4. MANUAL ENTRY — live calculation preview
  ══════════════════════════════════════════════ */
  function calcManualPreview() {
    var inVal  = $('#wsa-manual-in').val();
    var outVal = $('#wsa-manual-out').val();
    var prev   = $('#wsa-calc-preview');
    if (!inVal || !outVal) { prev.hide(); return; }

    var inTs  = new Date('2000-01-01T' + inVal);
    var outTs = new Date('2000-01-01T' + outVal);
    if (outTs <= inTs) { prev.hide(); return; }

    var mins   = (outTs - inTs) / 60000;
    var brk    = parseInt($('#wsa-manual-break').val() || 0);
    var worked = Math.max(0, mins - brk);
    var std    = parseInt($('#wsa-manual-shift-std').val() || 480);
    var ot     = Math.max(0, worked - std);
    var fmtMin = function (m) { return Math.floor(m / 60) + 'h ' + Math.round(m % 60) + 'm'; };

    $('#wsa-cp-total').text(fmtMin(worked));
    $('#wsa-cp-ot').text(ot > 0 ? fmtMin(ot) : '0');
    prev.show();
  }
  $('#wsa-manual-in, #wsa-manual-out, #wsa-manual-break').on('change', calcManualPreview);

  /* Existing record check */
  function checkExistingRecord() {
    var staffId = $('#wsa-manual-staff').val();
    var date    = $('#wsa-manual-date').val();
    if (!staffId || !date) return;
    $.post(wsaAdmin.ajaxurl, {
      action: 'wsa_get_staff_status', nonce: wsaAdmin.nonce,
      staff_id: staffId, date: date,
    }, function (res) {
      var note = $('#wsa-existing-note');
      if (res.success && res.data.record) {
        var rec = res.data.record;
        note.text('⚠️ Record exists: ' + rec.status + (rec.login_time ? ' · IN: '+fmtTime(rec.login_time):'') + (rec.logout_time?' · OUT: '+fmtTime(rec.logout_time):'')).show();
      } else { note.hide(); }
    });
  }
  $('#wsa-manual-staff, #wsa-manual-date').on('change', checkExistingRecord);

  /* ══════════════════════════════════════════════
     5. SALARY CONFIG — load existing when staff selected
  ══════════════════════════════════════════════ */
  $('#sal-cfg-staff').on('change', function () {
    var sid = $(this).val();
    if (!sid) return;
    $.post(wsaAdmin.ajaxurl, {
      action: 'wsa_get_salary_config', nonce: wsaAdmin.nonce, staff_id: sid,
    }, function (res) {
      if (!res.success || !res.data) return;
      var c = res.data;
      $('#sal-monthly').val(c.monthly_salary > 0 ? c.monthly_salary : '');
      $('#sal-daily').val(c.daily_rate > 0 ? c.daily_rate : '');
      $('[name=ot_rate_per_hr]').val(c.ot_rate_per_hr > 0 ? c.ot_rate_per_hr : '');
      $('[name=absent_deduction]').val(c.absent_deduction > 0 ? c.absent_deduction : '');
      $('[name=working_days]').val(c.working_days || 26);
      $('[name=currency]').val(c.currency || 'INR');
    });
  });

  /* Toggle salary config form */
  $('#wsa-toggle-sal-cfg').on('click', function () {
    var form = $('#wsa-sal-cfg-form');
    form.slideToggle(200);
    $(this).text(form.is(':visible') ? 'Close Configuration' : 'Configure Staff Salary');
  });

  /* ══════════════════════════════════════════════
     6. STAFF FORM — department autocomplete
  ══════════════════════════════════════════════ */
  $('#wsa-toggle-form').on('click', function () {
    $('#wsa-staff-form').slideToggle(200);
    $(this).text($('#wsa-staff-form').is(':visible') ? 'Cancel' : '+ Add Staff');
  });

  /* ══════════════════════════════════════════════
     7. CONFIRM DELETES (attendance rows)
  ══════════════════════════════════════════════ */
  $(document).on('click', '.wsa-del-att', function (e) {
    if (!confirm('Delete this attendance record permanently?')) { e.preventDefault(); }
  });

  /* ══════════════════════════════════════════════
     8. INLINE EDIT ATTENDANCE RECORD
  ══════════════════════════════════════════════ */
  $(document).on('click', '.wsa-edit-rec', function () {
    var btn    = $(this);
    var id     = btn.data('id');
    var inV    = btn.data('in');
    var outV   = btn.data('out');
    var notes  = btn.data('notes') || '';
    var row    = btn.closest('tr');

    var html = '<td colspan="11" style="background:#fffbeb;padding:16px">' +
      '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">' +
      '<div class="wsa-field" style="min-width:120px"><label>IN Time</label><input type="time" id="edt-in" value="' + inV + '"></div>' +
      '<div class="wsa-field" style="min-width:120px"><label>OUT Time</label><input type="time" id="edt-out" value="' + outV + '"></div>' +
      '<div class="wsa-field" style="flex:1;min-width:200px"><label>Notes</label><input type="text" id="edt-notes" value="' + esc(notes) + '" placeholder="Optional note"></div>' +
      '<button class="wsa-btn wsa-btn--accent" id="edt-save" data-id="' + id + '">💾 Save</button>' +
      '<button class="wsa-btn" id="edt-cancel">Cancel</button>' +
      '</div></td>';

    var editRow = $('<tr class="wsa-edit-row">').html(html);
    row.after(editRow);
    btn.hide();

    $('#edt-cancel').on('click', function () { editRow.remove(); btn.show(); });

    $('#edt-save').on('click', function () {
      var payload = { login_time: $('#edt-in').val(), logout_time: $('#edt-out').val(), notes: $('#edt-notes').val() };
      $.ajax({
        url    : wsaAdmin.rest_url + 'admin/record/' + id,
        method : 'PUT',
        headers: { 'X-WP-Nonce': wsaAdmin.rest_nonce, 'Content-Type': 'application/json' },
        data   : JSON.stringify(payload),
        success: function (res) {
          if (res.success) { location.reload(); }
          else alert('Error: ' + (res.message || 'Save failed.'));
        },
      });
    });
  });

  /* ══════════════════════════════════════════════
     9. TOAST NOTIFICATIONS
  ══════════════════════════════════════════════ */
  window.wsaToast = function (msg, type) {
    var el = $('<div class="wsa-toast wsa-toast--' + (type||'ok') + '">' + msg + '</div>');
    $('body').append(el);
    setTimeout(function () { el.addClass('wsa-toast--show'); }, 10);
    setTimeout(function () { el.removeClass('wsa-toast--show'); setTimeout(function(){el.remove();},400); }, 3500);
  };

  /* Inject toast styles if not already present */
  if (!document.getElementById('wsa-toast-style')) {
    $('head').append('<style id="wsa-toast-style">' +
      '.wsa-toast{position:fixed;bottom:24px;right:24px;z-index:99999;padding:13px 20px;border-radius:10px;font-size:14px;font-weight:600;box-shadow:0 6px 20px rgba(0,0,0,.15);transform:translateY(16px);opacity:0;transition:all .35s;pointer-events:none}' +
      '.wsa-toast--show{transform:none;opacity:1}' +
      '.wsa-toast--ok{background:#10b981;color:#fff}' +
      '.wsa-toast--err{background:#dc2626;color:#fff}' +
      '.wsa-toast--warn{background:#f59e0b;color:#fff}' +
    '</style>');
  }

})(jQuery);
