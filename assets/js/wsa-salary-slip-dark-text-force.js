
(function($){
  'use strict';

  const css = `
    html body #wsa-ap-root .wsa-slip,
    html body #wsa-ap-root .wsa-slip *{
      color:#111827!important;
      -webkit-text-fill-color:#111827!important;
      text-shadow:none!important;
    }
    html body #wsa-ap-root .wsa-slip{
      background:#fff!important;
      border:1px solid #e5e7eb!important;
    }
    html body #wsa-ap-root .wsa-slip p,
    html body #wsa-ap-root .wsa-slip span,
    html body #wsa-ap-root .wsa-slip small,
    html body #wsa-ap-root .wsa-slip td:first-child,
    html body #wsa-ap-root .wsa-slip .wsa-slip-note{
      color:#475569!important;
      -webkit-text-fill-color:#475569!important;
    }
    html body #wsa-ap-root .wsa-slip h1,
    html body #wsa-ap-root .wsa-slip h2,
    html body #wsa-ap-root .wsa-slip h3,
    html body #wsa-ap-root .wsa-slip b,
    html body #wsa-ap-root .wsa-slip strong,
    html body #wsa-ap-root .wsa-slip td:last-child{
      color:#111827!important;
      -webkit-text-fill-color:#111827!important;
    }
    html body #wsa-ap-root .wsa-slip .wsa-slip-net strong,
    html body #wsa-ap-root .wsa-slip .total td{
      color:#16a34a!important;
      -webkit-text-fill-color:#16a34a!important;
    }
    html body #wsa-ap-root .wsa-slip .wsa-slip-box,
    html body #wsa-ap-root .wsa-slip .wsa-slip-info div{
      background:#f8fafc!important;
      color:#111827!important;
      border-color:#e5e7eb!important;
    }
    html body #wsa-ap-root .wsa-slip .wsa-slip-note{
      background:#fff7ed!important;
      color:#9a3412!important;
      -webkit-text-fill-color:#9a3412!important;
    }
  `;

  function inject(){
    let tag = document.getElementById('wsa-salary-slip-dark-force-inline');
    if(!tag){
      tag = document.createElement('style');
      tag.id = 'wsa-salary-slip-dark-force-inline';
      document.body.appendChild(tag);
    }
    tag.innerHTML = css;
  }

  function fixExistingSlips(){
    $('#wsa-ap-root .wsa-slip').each(function(){
      const slip = $(this);
      slip.css({
        'background': '#ffffff',
        'color': '#111827',
        '-webkit-text-fill-color': '#111827'
      });

      slip.find('*').css({
        'color': '#111827',
        '-webkit-text-fill-color': '#111827'
      });

      slip.find('p, span, small, td:first-child, .wsa-slip-note').css({
        'color': '#475569',
        '-webkit-text-fill-color': '#475569'
      });

      slip.find('.wsa-slip-net strong, .total td').css({
        'color': '#16a34a',
        '-webkit-text-fill-color': '#16a34a'
      });

      slip.find('.wsa-slip-note').css({
        'background': '#fff7ed',
        'color': '#9a3412',
        '-webkit-text-fill-color': '#9a3412'
      });
    });
  }

  function run(){
    inject();
    fixExistingSlips();
  }

  $(function(){
    run();
    setTimeout(run, 500);
    setTimeout(run, 1500);
    setTimeout(run, 3000);
  });

  const obs = new MutationObserver(run);
  $(function(){
    const root = document.getElementById('wsa-ap-root');
    if(root) obs.observe(root, {childList:true, subtree:true});
  });
})(jQuery);
