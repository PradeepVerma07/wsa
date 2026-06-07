
(function($){
  'use strict';

  function isDashboard(){
    return window.location.pathname.indexOf('/wsa-admin') !== -1 || $('#wsa-ap-root').length > 0;
  }

  function adminOffset(){
    const bar = document.getElementById('wpadminbar');
    return bar ? Math.round(bar.getBoundingClientRect().height || 32) : 0;
  }

  function applyTheme(theme){
    $('body').attr('data-zb-theme', theme);
    $('#wsa-zb-theme-switcher button').removeClass('active');
    $('#wsa-zb-theme-switcher button[data-theme="'+theme+'"]').addClass('active');
  }

  function addThemeSwitcher(){
    if($('#wsa-zb-theme-switcher').length) return;

    const switcher = $('<div id="wsa-zb-theme-switcher"><button type="button" data-theme="dark">Dark</button><button type="button" data-theme="light">Light</button></div>');
    $('body').append(switcher);

    switcher.on('click','button',function(){
      const theme = $(this).data('theme');
      localStorage.setItem('wsa_zero_bug_theme', theme);
      applyTheme(theme);
    });
  }

  function removeDuplicateFaceItems(){
    // Remove old injected links from previous fixes.
    $('.wsa-fsfd-sidebar-link').remove();

    // Keep only first real Face Attendance and Face Registration nav item.
    const seen = {};
    $('#wsa-ap-root .ap-nav .ap-nav-item').each(function(){
      const page = ($(this).attr('data-page') || '').toLowerCase();
      if(page === 'faceattendance' || page === 'faceregister'){
        if(seen[page]){
          $(this).addClass('zb-duplicate-face').remove();
        } else {
          seen[page] = true;
        }
      }
    });

    // Also remove duplicate plain face links if injected inside user/profile bottom.
    const textSeen = {};
    $('#wsa-ap-root .wsa-ap-sidebar a, #wsa-ap-root .wsa-ap-sidebar button, #wsa-ap-root .wsa-ap-sidebar li').each(function(){
      const t = $(this).text().trim().toLowerCase();
      if(t === 'face attendance' || t === 'face registration'){
        if(textSeen[t]){
          $(this).remove();
        } else {
          textSeen[t] = true;
        }
      }
    });
  }

  function fixDashboard(){
    if(!isDashboard()) return;
    if(!$('#wsa-ap-root').length) return;

    $('body').addClass('wsa-zb-active');
    document.body.style.setProperty('--zb-admin-offset', adminOffset() + 'px');

    removeDuplicateFaceItems();

    // Desktop uses a permanent sidebar; mobile uses the built-in hamburger + overlay.
    const isMobile = window.matchMedia('(max-width: 900px)').matches;
    if(isMobile){
      $('#wsa-ap-root .ap-menu-toggle').css('display','');
      $('#wsa-ap-root .wsa-ap-overlay').css('display','');
      $('#wsa-ap-root .wsa-ap-sidebar').removeClass('hidden');
    } else {
      $('#wsa-ap-root .ap-menu-toggle').hide().attr('aria-expanded','false');
      $('#wsa-ap-root .wsa-ap-overlay').removeClass('visible').hide();
      $('#wsa-ap-root .wsa-ap-sidebar').removeClass('hidden open');
      $('body').removeClass('wsa-ap-sidebar-open');
    }

    // Force stable scroll.
    $('#wsa-ap-root .wsa-ap-sidebar').css({'overflow-y':'auto','overflow-x':'hidden'});
    $('#wsa-ap-root .wsa-ap-content').css({'overflow-y':'auto','overflow-x':'hidden'});

    addThemeSwitcher();
    applyTheme(localStorage.getItem('wsa_zero_bug_theme') || 'dark');
  }

  $(function(){
    fixDashboard();
    setTimeout(fixDashboard, 250);
    setTimeout(fixDashboard, 800);
    setTimeout(fixDashboard, 1600);
  });

  $(window).on('resize', function(){
    if(document.body){
      document.body.style.setProperty('--zb-admin-offset', adminOffset() + 'px');
    }
    fixDashboard();
  });
})(jQuery);

/* Frontend salary detail recovery layer.
   Runs after the main portal script and opens the detail modal if the primary
   handler is stale, cached, or swallowed by another frontend wrapper. */
(function(){
  'use strict';
  if (window.wsaSalaryDetailRecoveryReady) return;
  window.wsaSalaryDetailRecoveryReady = true;

  function cfg(){ return window.wsaAdminPortal || {}; }
  function esc(value){
    return String(value == null ? '' : value).replace(/[&<>"']/g, function(ch){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
    });
  }
  function token(){
    try {
      var raw = sessionStorage.getItem('wsa_admin_token') || '';
      var data = raw ? JSON.parse(raw) : {};
      return data && data.token ? data.token : '';
    } catch(e) {
      return '';
    }
  }
  function fmtHours(hours){
    hours = Number(hours || 0);
    if (hours <= 0) return '-';
    var mins = Math.round(hours * 60);
    var h = Math.floor(mins / 60);
    var m = mins % 60;
    if (!m) return h + 'h';
    if (!h) return m + 'm';
    return h + 'h ' + m + 'm';
  }
  function money(amount, currency){
    var symbols = { INR: 'Rs ', USD: '$', EUR: 'EUR ', GBP: 'GBP ', AED: 'AED ' };
    var n = Number(amount || 0);
    return (symbols[currency || 'INR'] || ((currency || '') + ' ')) +
      n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function printSalaryModal(btn){
    var modal = btn && btn.closest ? btn.closest('.ap-modal') : document.querySelector('.ap-modal-backdrop.open .ap-modal');
    if(!modal) return;
    var clone = modal.cloneNode(true);
    clone.querySelectorAll('.ap-modal-close, .ap-modal-actions, .wsa-no-print').forEach(function(el){ el.remove(); });
    cleanSalaryPrintIcons(clone);
    clone.querySelectorAll('.ap-cal-grid').forEach(function(el){
      var card = el.closest('.ap-card');
      if(card) card.remove();
    });
    var titleNode = clone.querySelector('.ap-modal-head h3');
    var title = titleNode ? titleNode.textContent : 'Salary Detail';
    var frame = document.createElement('iframe');
    frame.style.position = 'fixed';
    frame.style.left = '-10000px';
    frame.style.top = '0';
    frame.style.width = '1120px';
    frame.style.height = '900px';
    frame.style.border = '0';
    frame.style.opacity = '0';
    document.body.appendChild(frame);
    var doc = frame.contentWindow.document;
    doc.open();
    doc.write('<!doctype html><html><head><title>' + esc(title) + '</title><style>' +
      '*{box-sizing:border-box}@page{size:A4 portrait;margin:8mm}body{font-family:Arial,sans-serif;color:#111827;background:#fff;margin:0;padding:0;font-size:9.5px;line-height:1.25}' +
      'h1,h2,h3{margin:0 0 6px;font-size:13px;line-height:1.25}h1::first-letter,h2::first-letter,h3::first-letter{font-size:1em!important;line-height:1!important}.ap-modal-head{border-bottom:1.5px solid #111827;margin-bottom:8px;padding-bottom:6px}.ap-modal-body{padding:0}' +
      '.ap-stats{display:grid;grid-template-columns:repeat(6,1fr);gap:5px;margin:0 0 8px}img,svg,i,[aria-hidden="true"],.icon,[class*="icon"],[class*="dashicons"],[class*="lucide"],[class^="fa "],[class*=" fa-"],[class^="fa-"],.ap-stat-icon,.wsa-cal-ico,.wsa-print-icon{display:none!important}.ap-stat,.ap-card{border:1px solid #d1d5db;border-radius:5px;padding:6px;background:#fff;break-inside:avoid}' +
      '.ap-stat-val{font-size:13px;font-weight:800}.ap-stat-label{font-size:8px;text-transform:uppercase;color:#4b5563}.ap-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:7px}.ap-mt-16{margin-top:7px}' +
      'table{width:100%;border-collapse:collapse;margin-top:4px;table-layout:fixed}th,td{border:1px solid #d1d5db;padding:3px 4px;text-align:left;vertical-align:top;word-break:break-word}th{background:#f3f4f6;font-weight:800}.ap-money,strong{font-weight:800}.ap-table-wrap{overflow:visible}.ap-table-wrap table{font-size:8.7px}' +
      '@media print{.ap-grid-2{grid-template-columns:1fr 1fr}.ap-card{page-break-inside:avoid}}' +
      '</style></head><body>' + clone.innerHTML + '</body></html>');
    doc.close();
    setTimeout(function(){
      frame.contentWindow.focus();
      frame.contentWindow.print();
      setTimeout(function(){ frame.remove(); }, 600);
    }, 100);
  }
  function cleanSalaryPrintIcons(root){
    if(!root) return;
    root.querySelectorAll('img, svg, i, [aria-hidden="true"], .icon, [class*="icon"], [class*="dashicons"], [class*="lucide"], [class^="fa "], [class*=" fa-"], [class^="fa-"], .ap-stat-icon, .wsa-cal-ico, .wsa-print-icon').forEach(function(el){ el.remove(); });
    var emojiRE = /[\u{1F000}-\u{1FAFF}\u{2600}-\u{27BF}\u{FE0F}]/gu;
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    var nodes = [];
    while(walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach(function(node){
      node.nodeValue = node.nodeValue.replace(emojiRE, '').replace(/\s{2,}/g, ' ').trimStart();
    });
  }
  document.addEventListener('click', function(e){
    if(e.defaultPrevented) return;
    var btn = e.target && e.target.closest ? e.target.closest('[data-wsa-print-salary-detail]') : null;
    if(!btn) return;
    e.preventDefault();
    e.stopPropagation();
    printSalaryModal(btn);
  }, true);
  function openSalaryModal(html){
    closeSalaryModal();
    var bd = document.createElement('div');
    bd.className = 'ap-modal-backdrop open';
    bd.setAttribute('data-wsa-salary-fallback', '1');
    bd.style.zIndex = '1002000';
    bd.innerHTML = '<div class="ap-modal ap-modal--lg">' + html + '</div>';
    document.body.appendChild(bd);
    bd.addEventListener('click', function(e){ if (e.target === bd) closeSalaryModal(); });
    bd.querySelectorAll('.ap-modal-close').forEach(function(btn){
      btn.addEventListener('click', closeSalaryModal);
    });
    return bd;
  }
  function closeSalaryModal(){
    document.querySelectorAll('[data-wsa-salary-fallback]').forEach(function(el){ el.remove(); });
  }
  function hasSalaryModal(){
    return !!document.querySelector('.ap-modal-backdrop.open .ap-salary-detail, [data-wsa-salary-fallback]');
  }
  function readButton(btn){
    var row = btn && btn.closest ? btn.closest('tr') : null;
    var now = new Date();
    return {
      id: parseInt((btn && (btn.dataset.id || btn.dataset.staffId)) || (row && row.dataset.staffId) || '0', 10),
      yr: parseInt((btn && btn.dataset.yr) || (row && row.dataset.yr) || now.getFullYear(), 10),
      mn: parseInt((btn && btn.dataset.mn) || (row && row.dataset.mn) || (now.getMonth() + 1), 10)
    };
  }
  async function fetchSalaryDetail(info){
    var C = cfg();
    var ajaxUrl = C.adminAjaxUrl || C.ajaxUrl || '/wp-admin/admin-ajax.php';
    var restBase = (C.apiBase || '/wp-json/wsa/v2/wsa-admin/').replace(/\/$/, '');
    var sessToken = token();

    try {
      var form = new URLSearchParams();
      form.set('action', 'wsa_front_salary_detail');
      form.set('nonce', C.restNonce || '');
      form.set('_ajax_nonce', C.restNonce || '');
      form.set('staff_id', String(info.id));
      form.set('yr', String(info.yr));
      form.set('mn', String(info.mn));
      if (sessToken) form.set('wsa_admin_token', sessToken);
      var ajaxRes = await fetch(ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: form.toString()
      });
      var ajaxText = await ajaxRes.text();
      var ajaxData = ajaxText ? JSON.parse(ajaxText) : {};
      if (ajaxData && ajaxData.success && ajaxData.data && ajaxData.data.report) {
        return { success: true, report: ajaxData.data.report };
      }
    } catch(e) {}

    try {
      var qs = new URLSearchParams({ yr: String(info.yr), mn: String(info.mn), _: String(Date.now()) });
      if (sessToken) qs.set('wsa_admin_token', sessToken);
      var restRes = await fetch(restBase + '/salary/detail/' + encodeURIComponent(info.id) + '?' + qs.toString(), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-WP-Nonce': C.restNonce || '', 'X-WSA-Admin-Token': sessToken }
      });
      var restText = await restRes.text();
      var restData = restText ? JSON.parse(restText) : {};
      if (restData && restData.success && restData.report) return restData;
      return { success: false, message: (restData && restData.message) || 'Could not load salary detail.' };
    } catch(e) {
      return { success: false, message: e && e.message ? e.message : 'Could not load salary detail.' };
    }
  }
  function renderReport(report, info){
    var staff = report.staff || {};
    var cfgRow = report.config || {};
    var cur = report.currency || cfgRow.currency || 'INR';
    var label = report.month_label || (info.yr + '-' + String(info.mn).padStart(2, '0'));
    var days = Array.isArray(report.days) ? report.days : [];
    var rows = days.filter(function(d){ return d.status !== 'future'; }).map(function(d){
      var br = Number(d.salary_break_mins || d.break_duration_mins || 0);
      return '<tr>' +
        '<td>' + esc(d.date || '') + '</td>' +
        '<td>' + esc(d.status || '-') + '</td>' +
        '<td>' + esc(d.login || '-') + '</td>' +
        '<td>' + esc(d.logout || '-') + '</td>' +
        '<td>' + fmtHours(d.hours || 0) + '</td>' +
        '<td>' + (br > 0 ? fmtHours(br / 60) : '-') + '</td>' +
        '<td>' + (Number(d.ot || 0) > 0 ? '+' + fmtHours(d.ot) : '-') + '</td>' +
      '</tr>';
    }).join('') || '<tr><td colspan="7" class="ap-empty">No attendance log found.</td></tr>';

    return '<div class="ap-modal-head"><h3>Salary Detail - ' + esc(staff.name || 'Employee') + '</h3><div class="ap-modal-actions wsa-no-print"><button type="button" class="ap-btn ap-btn--xs ap-btn--print" data-wsa-print-salary-detail>Print</button><button type="button" class="ap-modal-close">x</button></div></div>' +
      '<div class="ap-modal-body ap-salary-detail">' +
        '<div class="ap-stats">' +
          '<div class="ap-stat ap-stat--ok"><div class="ap-stat-val">' + esc(report.present || 0) + '</div><div class="ap-stat-label">Present</div></div>' +
          '<div class="ap-stat ap-stat--bad"><div class="ap-stat-val">' + esc(report.absent || 0) + '</div><div class="ap-stat-label">Absent</div></div>' +
          '<div class="ap-stat ap-stat--info"><div class="ap-stat-val">' + fmtHours(report.total_hours || 0) + '</div><div class="ap-stat-label">Work Hours</div></div>' +
          '<div class="ap-stat ap-stat--warn"><div class="ap-stat-val">' + fmtHours(report.total_ot || 0) + '</div><div class="ap-stat-label">OT Hours</div></div>' +
        '</div>' +
        '<div class="ap-grid-2 ap-mt-16">' +
          '<div class="ap-card ap-inner-card"><h3>Employee Salary Details</h3><table class="ap-table">' +
            '<tr><td>Employee ID</td><td><strong>' + esc(staff.employee_id || staff.emp_code || '-') + '</strong></td></tr>' +
            '<tr><td>Name</td><td><strong>' + esc(staff.name || '-') + '</strong></td></tr>' +
            '<tr><td>Department</td><td>' + esc(staff.department || '-') + '</td></tr>' +
            '<tr><td>Month</td><td>' + esc(label) + '</td></tr>' +
            '<tr><td>Monthly Gross Config</td><td><strong>' + money(cfgRow.monthly_salary || 0, cur) + '</strong></td></tr>' +
          '</table></div>' +
          '<div class="ap-card ap-inner-card"><h3>Salary Breakup</h3><div class="ap-table-wrap ap-salary-breakup-wrap"><table class="ap-table ap-salary-breakup-table">' +
            '<tr><td>Daily Rate</td><td><strong>' + money(report.daily_rate || 0, cur) + '</strong></td></tr>' +
            '<tr><td>Basic Earned</td><td>' + money(report.earned_basic || 0, cur) + '</td></tr>' +
            '<tr><td>Leave Pay</td><td>' + money(report.leave_pay || 0, cur) + '</td></tr>' +
            '<tr><td>Overtime Pay</td><td>' + money(report.ot_pay || 0, cur) + '</td></tr>' +
            '<tr><td>Gross</td><td><strong>' + money(report.gross || 0, cur) + '</strong></td></tr>' +
            '<tr><td>Deductions</td><td>' + money(report.deductions || 0, cur) + '</td></tr>' +
            '<tr><td><strong>Net Salary</strong></td><td><strong class="ap-money">' + money(report.net || 0, cur) + '</strong></td></tr>' +
          '</table></div></div>' +
        '</div>' +
        '<div class="ap-card ap-inner-card ap-mt-16"><h3>Daily Attendance Log</h3><div class="ap-table-wrap"><table class="ap-table">' +
          '<thead><tr><th>Date</th><th>Status</th><th>Check-IN</th><th>Check-OUT</th><th>Hours</th><th>Break</th><th>OT</th></tr></thead>' +
          '<tbody>' + rows + '</tbody>' +
        '</table></div></div>' +
      '</div>';
  }
  async function fallbackOpen(info){
    if (!info.id) {
      openSalaryModal('<div class="ap-modal-head"><h3>Salary Detail Error</h3><button class="ap-modal-close">x</button></div><div class="ap-modal-body"><p style="color:var(--ap-red)">Staff ID missing for this salary detail.</p></div>');
      return;
    }
    var bd = openSalaryModal('<div class="ap-modal-head"><h3>Salary Detail</h3><button class="ap-modal-close">x</button></div><div class="ap-modal-body ap-salary-detail"><div class="ap-empty"><span class="ap-spin-mini"></span> Loading employee salary details...</div></div>');
    var data = await fetchSalaryDetail(info);
    if (!bd || !bd.isConnected) return;
    if (!data || !data.success || !data.report) {
      bd.querySelector('.ap-modal').innerHTML =
        '<div class="ap-modal-head"><h3>Salary Detail Error</h3><button class="ap-modal-close">x</button></div>' +
        '<div class="ap-modal-body"><p style="color:var(--ap-red)">' + esc((data && data.message) || 'Could not load salary detail.') + '</p></div>';
      bd.querySelectorAll('.ap-modal-close').forEach(function(btn){ btn.addEventListener('click', closeSalaryModal); });
      return;
    }
    bd.querySelector('.ap-modal').innerHTML = renderReport(data.report, info);
    bd.querySelectorAll('.ap-modal-close').forEach(function(btn){ btn.addEventListener('click', closeSalaryModal); });
  }

  document.addEventListener('click', function(e){
    var btn = e.target && e.target.closest ? e.target.closest('#wsa-ap-root .sal-detail, #wsa-ap-root [data-wsa-salary-detail]') : null;
    if (!btn) return;
    var info = readButton(btn);
    setTimeout(function(){
      if (hasSalaryModal()) return;
      if (typeof window.wsaOpenSalaryDetail === 'function') {
        window.wsaOpenSalaryDetail(info.id, info.yr, info.mn);
        setTimeout(function(){ if (!hasSalaryModal()) fallbackOpen(info); }, 120);
        return;
      }
      fallbackOpen(info);
    }, 120);
  }, true);
})();

/* WSA 5.6.5 final mobile sidebar reliability layer
   IMPORTANT: the main admin-portal.js already owns the hamburger toggle.
   This layer must NOT toggle the button again, otherwise the sidebar opens
   and closes on the same tap. It only normalizes initial/resize state and
   closes sidebar from overlay/nav taps. */
(function($){
  'use strict';
  function isMobile(){ return window.matchMedia('(max-width: 900px)').matches; }
  function root(){ return document.getElementById('wsa-ap-root'); }

  function closeMobileSidebar(){
    const r = root(); if(!r) return;
    const sb = r.querySelector('#apSidebar, .wsa-ap-sidebar');
    const ov = r.querySelector('#apOverlay, .wsa-ap-overlay');
    const tg = r.querySelector('#apMenuToggle, .ap-menu-toggle');
    if(sb) sb.classList.remove('open');
    if(ov) ov.classList.remove('visible');
    if(tg) tg.setAttribute('aria-expanded','false');
    document.body.classList.remove('wsa-ap-sidebar-open');
  }

  function normalizeMobileSidebar(){
    const r = root(); if(!r) return;
    const sb = r.querySelector('#apSidebar, .wsa-ap-sidebar');
    const ov = r.querySelector('#apOverlay, .wsa-ap-overlay');
    const tg = r.querySelector('#apMenuToggle, .ap-menu-toggle');
    if(!isMobile()){
      closeMobileSidebar();
      if(sb) sb.style.transform = '';
      if(ov) ov.style.display = '';
      return;
    }
    if(tg){
      tg.style.display = 'inline-flex';
      tg.setAttribute('type','button');
      if(!tg.hasAttribute('aria-label')) tg.setAttribute('aria-label','Open dashboard menu');
      tg.setAttribute('aria-expanded', sb && sb.classList.contains('open') ? 'true' : 'false');
    }
    if(sb && !sb.classList.contains('open')){
      document.body.classList.remove('wsa-ap-sidebar-open');
      if(ov) ov.classList.remove('visible');
    }
  }

  $(document)
    .off('click.wsaZbMobileSidebar')
    .on('click.wsaZbMobileSidebar', '#wsa-ap-root .wsa-ap-overlay, #wsa-ap-root .ap-nav-item', function(){
      if(isMobile()) closeMobileSidebar();
    });

  $(function(){
    normalizeMobileSidebar();
    setTimeout(normalizeMobileSidebar, 250);
    setTimeout(normalizeMobileSidebar, 900);
  });

  $(window).off('resize.wsaZbMobileSidebar').on('resize.wsaZbMobileSidebar', function(){
    normalizeMobileSidebar();
  });
})(jQuery);
