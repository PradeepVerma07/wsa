
(function($){
  'use strict';

  const salaryCss = `
    html body #wsa-ap-root .wsa-front-slip-form,
    html body #wsa-ap-root .wsa-front-slip-empty,
    html body #wsa-ap-root #ssOutput .wsa-front-slip-empty{
      background:#fff!important;
      border:1px solid #e5e7eb!important;
      color:#111827!important;
      -webkit-text-fill-color:#111827!important;
    }
    html body #wsa-ap-root .wsa-front-slip-form *,
    html body #wsa-ap-root .wsa-front-slip-empty *,
    html body #wsa-ap-root #ssOutput .wsa-front-slip-empty *{
      color:#111827!important;
      -webkit-text-fill-color:#111827!important;
      text-shadow:none!important;
    }
    html body #wsa-ap-root .wsa-front-slip-form input,
    html body #wsa-ap-root .wsa-front-slip-form select,
    html body #wsa-ap-root #ssMonth,
    html body #wsa-ap-root #ssYear,
    html body #wsa-ap-root #ssStaff{
      background:#f8fafc!important;
      color:#111827!important;
      -webkit-text-fill-color:#111827!important;
      border:1px solid #cbd5e1!important;
      caret-color:#111827!important;
    }
    html body #wsa-ap-root .wsa-front-slip-form option{
      background:#fff!important;
      color:#111827!important;
    }
    html body #wsa-ap-root .wsa-front-slip-form #ssOne,
    html body #wsa-ap-root .wsa-front-slip-form .ap-btn--primary{
      background:#e95522!important;
      border-color:#e95522!important;
      color:#fff!important;
      -webkit-text-fill-color:#fff!important;
    }
    html body #wsa-ap-root .wsa-front-slip-form #ssAll,
    html body #wsa-ap-root .wsa-front-slip-form .ap-btn--ghost{
      background:#fff!important;
      border:1px solid #cbd5e1!important;
      color:#111827!important;
      -webkit-text-fill-color:#111827!important;
      opacity:1!important;
    }
    html body #wsa-ap-root .wsa-front-slip-form #ssPrint{
      background:#111827!important;
      border:1px solid #111827!important;
      color:#fff!important;
      -webkit-text-fill-color:#fff!important;
      opacity:1!important;
    }
    html body #wsa-ap-root .wsa-front-slip-form button:disabled{
      background:#f1f5f9!important;
      border-color:#cbd5e1!important;
      color:#64748b!important;
      -webkit-text-fill-color:#64748b!important;
      opacity:1!important;
    }
  `;

  function inject(){
    let tag = document.getElementById('wsa-salary-form-text-force-inline');
    if(!tag){
      tag = document.createElement('style');
      tag.id = 'wsa-salary-form-text-force-inline';
      document.body.appendChild(tag);
    }
    tag.innerHTML = salaryCss;
  }

  function directFix(){
    const scope = $('#wsa-ap-root .wsa-front-slip-form, #wsa-ap-root .wsa-front-slip-empty');
    scope.css({
      background:'#ffffff',
      color:'#111827',
      '-webkit-text-fill-color':'#111827',
      borderColor:'#e5e7eb'
    });

    scope.find('*').css({
      color:'#111827',
      '-webkit-text-fill-color':'#111827',
      textShadow:'none'
    });

    $('#wsa-ap-root #ssMonth, #wsa-ap-root #ssYear, #wsa-ap-root #ssStaff').css({
      background:'#f8fafc',
      color:'#111827',
      '-webkit-text-fill-color':'#111827',
      borderColor:'#cbd5e1',
      caretColor:'#111827'
    });

    $('#wsa-ap-root .wsa-front-slip-form #ssOne').css({
      background:'#e95522',
      borderColor:'#e95522',
      color:'#ffffff',
      '-webkit-text-fill-color':'#ffffff'
    });

    $('#wsa-ap-root .wsa-front-slip-form #ssAll').css({
      background:'#ffffff',
      borderColor:'#cbd5e1',
      color:'#111827',
      '-webkit-text-fill-color':'#111827',
      opacity:1
    });

    $('#wsa-ap-root .wsa-front-slip-form #ssPrint').css({
      background:'#111827',
      borderColor:'#111827',
      color:'#ffffff',
      '-webkit-text-fill-color':'#ffffff',
      opacity:1
    });

    $('#wsa-ap-root .wsa-front-slip-form button:disabled').css({
      background:'#f1f5f9',
      borderColor:'#cbd5e1',
      color:'#64748b',
      '-webkit-text-fill-color':'#64748b',
      opacity:1
    });
  }

  function run(){
    inject();
    directFix();
  }

  $(function(){
    run();
    setTimeout(run, 300);
    setTimeout(run, 900);
    setTimeout(run, 1800);
    setTimeout(run, 3500);

    const root = document.getElementById('wsa-ap-root');
    if(root){
      const obs = new MutationObserver(run);
      obs.observe(root, {childList:true, subtree:true});
    }
  });
})(jQuery);
