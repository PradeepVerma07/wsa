
(function($){
  'use strict';

  function isDashboardUrl(){
    return window.location.pathname.indexOf('/wsa-admin') !== -1 ||
           window.location.search.indexOf('wsa_face_view') !== -1;
  }

  function findSidebar(){
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

    for (const selector of selectors) {
      const el = $(selector).filter(function(){
        const t = $(this).text().toLowerCase();
        return (t.includes('quick mark') || t.includes('qr scanner') || t.includes('manual entry') || t.includes('salary slip')) &&
               (t.includes('dashboard') || t.includes('overview') || t.includes('management'));
      }).first();

      if (el.length) return el;
    }

    return $();
  }

  function findContent(sidebar){
    // Sibling is safest
    let parent = sidebar.parent();

    for (let i = 0; i < 6; i++) {
      const sib = parent.children().not(sidebar).filter(function(){
        const t = $(this).text().toLowerCase();
        return t.length > 50 &&
               (t.includes('dashboard') || t.includes('quick mark') || t.includes("who's inside") || t.includes('face attendance') || t.includes('inside now'));
      }).first();

      if (sib.length) return sib;
      parent = parent.parent();
    }

    // Broader fallback
    return $('.entry-content, main, #content, .site-main, .wp-block-post-content').filter(function(){
      const t = $(this).text().toLowerCase();
      return t.length > 50 && !$.contains(this, sidebar.get(0));
    }).first();
  }

  function addFaceLinks(sidebar){
    if (sidebar.find('.wsa-fsfd-sidebar-link').length) return;

    const base = window.location.origin + window.location.pathname;
    const current = new URLSearchParams(window.location.search).get('wsa_face_view');

    const face = $('<a class="wsa-fsfd-sidebar-link '+(current === 'attendance' ? 'active' : '')+'" href="'+base+'?wsa_face_view=attendance"><span>🧑‍💼</span><span>Face Attendance</span></a>');
    const reg = $('<a class="wsa-fsfd-sidebar-link '+(current === 'registration' ? 'active' : '')+'" href="'+base+'?wsa_face_view=registration"><span>📸</span><span>Face Registration</span></a>');

    let inserted = false;
    sidebar.find('a, li, button, .nav-item, .menu-item').each(function(){
      const txt = $(this).text().trim().toLowerCase();
      if (!inserted && (txt.includes('qr scanner') || txt.includes("who's inside") || txt.includes('quick mark'))) {
        $(this).after(face);
        face.after(reg);
        inserted = true;
      }
    });

    if (!inserted) sidebar.append(face).append(reg);
  }

  function renderFaceAuto(content){
    const holder = $('#wsa-fsfd-auto-render, #wsa-safe-auto-render, #wsa-safe-face-auto-render');
    if (!holder.length) return;

    const html = holder.html();
    if (html && html.trim()) {
      content.html(html);
      holder.remove();
    }
  }

  function buildLayout(){
    if (!isDashboardUrl()) return;

    const sidebar = findSidebar();
    if (!sidebar.length) return;

    const content = findContent(sidebar);
    if (!content.length) return;

    $('body').addClass('wsa-clean-dashboard-active');
    sidebar.addClass('wsa-clean-sidebar');
    content.addClass('wsa-clean-content');

    let shell = sidebar.parent();

    if (!shell.children().filter(content).length) {
      // Move only dashboard components into a clean shell inside the existing page.
      const newShell = $('<div class="wsa-clean-shell"></div>');
      sidebar.before(newShell);
      newShell.append(sidebar);
      newShell.append(content);
      shell = newShell;
    }

    shell.addClass('wsa-clean-shell');

    addFaceLinks(sidebar);
    renderFaceAuto(content);

    // remove duplicate headings
    const seen = {};
    content.find('h1').each(function(){
      const txt = $(this).text().trim();
      if (seen[txt]) $(this).hide();
      seen[txt] = true;
    });
  }

  $(function(){
    buildLayout();
    setTimeout(buildLayout, 500);
    setTimeout(buildLayout, 1200);
    setTimeout(buildLayout, 2200);
  });
})(jQuery);
