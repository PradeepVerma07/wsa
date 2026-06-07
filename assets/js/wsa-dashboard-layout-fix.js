(function($){
  'use strict';

  function findSidebar(){
    const selectors = [
      '.wsa-sidebar',
      '.dashboard-sidebar',
      '.admin-sidebar',
      '.portal-sidebar',
      '.attendance-sidebar',
      '.wsa-dashboard-sidebar',
      'aside',
      'nav'
    ];

    for(let i=0; i<selectors.length; i++){
      const el = $(selectors[i]).first();
      if(el.length && el.text().toLowerCase().indexOf('quick mark') !== -1){
        return el;
      }
    }
    return null;
  }

  function addFaceSidebar(){
    const sidebar = findSidebar();
    if(!sidebar || $('.wsa-fsfd-sidebar-link').length) return;

    const currentUrl = window.location.href.split('?')[0];
    const faceAttendanceUrl = currentUrl + '?wsa_face_view=attendance';
    const faceRegistrationUrl = currentUrl + '?wsa_face_view=registration';

    const item1 = $('<a class="wsa-fsfd-sidebar-link" href="'+faceAttendanceUrl+'"><span>🧑‍💼</span><span>Face Attendance</span></a>');
    const item2 = $('<a class="wsa-fsfd-sidebar-link" href="'+faceRegistrationUrl+'"><span>📸</span><span>Face Registration</span></a>');

    let inserted = false;
    sidebar.find('a, li, button, .nav-item, .menu-item').each(function(){
      const text = $(this).text().trim().toLowerCase();
      if(!inserted && (text.indexOf('qr scanner') !== -1 || text.indexOf('quick mark') !== -1)){
        $(this).after(item1);
        item1.after(item2);
        inserted = true;
      }
    });

    if(!inserted){
      sidebar.append(item1).append(item2);
    }
  }

  function fixDashboardShell(){
    const sidebar = findSidebar();
    if(!sidebar) return;

    let parent = sidebar.parent();
    for(let i=0; i<5; i++){
      if(parent.children().length >= 2){
        parent.addClass('wsa-dashboard-shell');
        break;
      }
      parent = parent.parent();
    }

    const contentCandidates = parent.children().not(sidebar);
    contentCandidates.each(function(){
      if($(this).text().trim().length > 20){
        $(this).addClass('wsa-dashboard-content');
      }
    });
  }

  function restoreCheckoutButton(){
    $('.quick-mark, .wsa-quick-mark, .attendance-actions, .staff-actions').each(function(){
      const card = $(this);
      const text = card.text().toLowerCase();
      const hasCheckout = text.indexOf('check out') !== -1 || text.indexOf('checkout') !== -1;
      const hasBreak = text.indexOf('break') !== -1;
      const hasIn = text.indexOf('in') !== -1;

      if(hasBreak && !hasCheckout){
        const btn = $('<button type="button" class="wsa-btn wsa-btn-checkout checkout">🚪 Check Out</button>');
        card.find('button, .button').last().after(btn);
      }
    });
  }

  function autoRenderFaceDashboard(){
    const holder = $('#wsa-fsfd-auto-render, #wsa-safe-auto-render');
    if(!holder.length) return;

    const html = holder.html();
    if(!html || !html.trim()) return;

    let target = $('.wsa-dashboard-content, .dashboard-content, .admin-content, .portal-content, .main-content, main, .entry-content').first();
    if(!target.length) target = $('body');

    target.html(html);
    holder.remove();
  }

  function runFixes(){
    addFaceSidebar();
    fixDashboardShell();
    restoreCheckoutButton();
    autoRenderFaceDashboard();
  }

  $(function(){
    runFixes();
    setTimeout(runFixes, 500);
    setTimeout(runFixes, 1200);
    setTimeout(runFixes, 2500);
  });
})(jQuery);