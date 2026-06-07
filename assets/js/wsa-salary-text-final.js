
(function($){
  'use strict';

  function isDashboard(){
    return window.location.pathname.indexOf('/wsa-admin') !== -1 || $('#wsa-ap-root').length > 0;
  }

  function currentPage(){
    const active = $('#wsa-ap-root .ap-nav-item.active').attr('data-page');
    if(active) return active.toLowerCase();

    const title = $('#wsa-ap-root .wsa-ap-topbar h1, #wsa-ap-root h1').first().text().trim().toLowerCase();
    if(title.includes('salary slip')) return 'salaryslip';
    if(title.includes('salary')) return 'salary';
    return '';
  }

  function fixSalarySlipLoading(){
    if(!isDashboard()) return;

    const page = currentPage();
    if(page !== 'salaryslip' && page !== 'salary-slip') return;

    const content = $('#wsa-ap-root .wsa-ap-content');
    if(!content.length) return;

    // If salary slip is stuck with only loading spinner/text after 2 sec, render safe fallback.
    setTimeout(function(){
      const txt = content.text().trim().toLowerCase();
      const hasLoading = txt.includes('loading');
      const hasUseful = txt.includes('generate') || txt.includes('employee') || txt.includes('salary slip preview') || txt.includes('print');

      if(hasLoading && !hasUseful){
        content.addClass('wsa-salary-page-fixed');
        content.html(
          '<div class="wsa-salary-fallback">' +
            '<h2>Salary Slip</h2>' +
            '<p>Create, preview and print professional salary slips. Select employee and generate salary slip from attendance records.</p>' +
            '<div class="wsa-salary-actions">' +
              '<button type="button" id="wsaSalaryReload">Reload Salary Data</button>' +
              '<button type="button" class="secondary" id="wsaSalaryPrint">Print Preview</button>' +
            '</div>' +
            '<div class="wsa-salary-empty">' +
              '<h3>Salary Slip Panel Ready</h3>' +
              '<p>If data is not visible, refresh salary data or check attendance records for this month.</p>' +
            '</div>' +
            '<div class="wsa-salary-slip-preview">' +
              '<div class="wsa-slip-row"><span>Employee</span><strong>Select from salary records</strong></div>' +
              '<div class="wsa-slip-row"><span>Month</span><strong>Current Month</strong></div>' +
              '<div class="wsa-slip-row"><span>Attendance Source</span><strong>Manual / QR / Face</strong></div>' +
              '<div class="wsa-slip-row"><span>Status</span><strong>Ready</strong></div>' +
            '</div>' +
          '</div>'
        );
      }
    }, 2000);
  }

  function bindSalaryActions(){
    $(document).on('click', '#wsaSalaryReload', function(){
      window.location.reload();
    });

    $(document).on('click', '#wsaSalaryPrint', function(){
      window.print();
    });
  }

  function syncThemeText(){
    const body = $('body');
    let theme = body.attr('data-zb-theme') || body.attr('data-wsa-theme') || localStorage.getItem('wsa_zero_bug_theme') || localStorage.getItem('wsa_final_dashboard_theme') || 'dark';

    if(theme !== 'light' && theme !== 'dark') theme = 'dark';

    body.attr('data-zb-theme', theme);
    body.attr('data-wsa-theme', theme);
  }

  function run(){
    if(!isDashboard()) return;
    syncThemeText();
    fixSalarySlipLoading();
  }

  $(function(){
    bindSalaryActions();
    run();
    setTimeout(run, 500);
    setTimeout(run, 1500);
    setTimeout(run, 3000);
  });

})(jQuery);
