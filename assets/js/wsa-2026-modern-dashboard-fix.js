(function($){
  'use strict';

  function detectSidebar(){
    const selectors = [
      '.wsa-sidebar',
      '.admin-sidebar',
      '.dashboard-sidebar',
      '.portal-sidebar',
      '.attendance-sidebar',
      '.wsa-dashboard-sidebar',
      'aside',
      'nav'
    ];
    for(let s of selectors){
      const el = $(s).filter(function(){
        const t = $(this).text().toLowerCase();
        return t.includes('quick mark') || t.includes('qr scanner') || t.includes('attendance');
      }).first();
      if(el.length) return el;
    }
    return $();
  }

  function detectContent(sidebar){
    if(!sidebar.length) return $();
    const parent = sidebar.parent();
    let siblings = parent.children().not(sidebar);
    let best = siblings.filter(function(){
      return $(this).text().trim().length > 50;
    }).first();

    if(best.length) return best;

    const candidates = $('.wsa-main,.wsa-content,.dashboard-content,.admin-content,.portal-content,.main-content,main,.entry-content');
    return candidates.filter(function(){
      return !$(this).is(sidebar) && $(this).text().trim().length > 50;
    }).first();
  }

  function normalizeShell(){
    const sidebar = detectSidebar();
    if(!sidebar.length) return;

    sidebar.addClass('wsa-sidebar');

    let shell = sidebar.parent();
    if(shell.children().length < 2){
      shell = sidebar.closest('div,section,main');
    }
    shell.addClass('wsa-dashboard-shell');

    const content = detectContent(sidebar);
    if(content.length){
      content.addClass('wsa-dashboard-content');
    }
  }

  function addFaceLinks(){
    const sidebar = detectSidebar();
    if(!sidebar.length || sidebar.find('.wsa-fsfd-sidebar-link').length) return;

    const base = window.location.href.split('?')[0];
    const attendance = $('<a class="wsa-fsfd-sidebar-link" href="'+base+'?wsa_face_view=attendance"><span>🧑‍💼</span><span>Face Attendance</span></a>');
    const registration = $('<a class="wsa-fsfd-sidebar-link" href="'+base+'?wsa_face_view=registration"><span>📸</span><span>Face Registration</span></a>');

    let inserted = false;
    sidebar.find('a,li,button,.nav-item,.menu-item').each(function(){
      const txt = $(this).text().trim().toLowerCase();
      if(!inserted && (txt.includes('qr scanner') || txt.includes('who') || txt.includes('quick mark'))){
        $(this).after(attendance);
        attendance.after(registration);
        inserted = true;
      }
    });

    if(!inserted){
      sidebar.append(attendance).append(registration);
    }
  }

  function renderFaceContent(){
    const holder = $('#wsa-fsfd-auto-render,#wsa-safe-face-auto-render,#wsa-safe-auto-render');
    if(!holder.length) return;
    const html = holder.html();
    if(!html || !html.trim()) return;

    const sidebar = detectSidebar();
    let content = detectContent(sidebar);
    if(!content.length) content = $('.entry-content,main,#content').first();
    if(!content.length) return;

    content.html(html);
    holder.remove();
    normalizeShell();
  }

  function fixFaceStats(){
    const page = $('.wsa-fsfd-page');
    if(!page.length) return;

    // Wrap loose status text/emoji blocks before camera into stat cards when possible
    const header = page.find('.wsa-fsfd-header').first();
    const grid = page.find('.wsa-fsfd-grid').first();
    if(header.length && grid.length && !page.find('.wsa-fsfd-stats').length){
      const stats = $('<div class="wsa-fsfd-stats"></div>');
      stats.append('<div><strong>Ready</strong><p>Scanner Status</p></div>');
      stats.append('<div><strong>0</strong><p>Marked Today</p></div>');
      stats.append('<div><strong>0</strong><p>On Break</p></div>');
      stats.append('<div><strong>Secure</strong><p>Face Mode</p></div>');
      header.after(stats);
    }
  }

  function removeDuplicateTitle(){
    const content = $('.wsa-dashboard-content,.dashboard-content,.admin-content,.portal-content,.main-content').first();
    if(!content.length) return;

    // Hide duplicated text node-like title blocks if two same H1 appear near top
    const h1s = content.find('h1');
    if(h1s.length > 1 && $(h1s[0]).text().trim() === $(h1s[1]).text().trim()){
      $(h1s[1]).hide();
    }
  }

  function run(){
    normalizeShell();
    addFaceLinks();
    renderFaceContent();
    fixFaceStats();
    removeDuplicateTitle();
  }

  $(function(){
    run();
    setTimeout(run, 400);
    setTimeout(run, 1000);
    setTimeout(run, 2200);
  });
})(jQuery);