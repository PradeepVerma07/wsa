/* ════════════════════════════════════════════════════════════
   Webtrionix Staff Attendance — Wall Display Scanner v4.4.3
   FIXED: QR timer always counts 30→0 correctly.
   
   Timer logic:
   1. Server sends seconds_left (integer) + qr_ttl (integer).
   2. On ANY response (new token OR same token), we re-anchor:
        expiresAtMs = serverTsMs + (seconds_left × 1000)
      using server_ts_ms (server's own ms timestamp) to avoid
      client/server clock skew entirely.
   3. 250ms tick keeps bar + counter smooth.
   4. qrTtl always taken from server response (authoritative).
════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var C    = window.wsaScanner || {};
  var api  = C.apiDisplay || '';
  var nonce= C.nonce      || '';

  /* Will be overwritten on first server response */
  var qrTtl        = parseInt(C.qrTtl, 10) || 30;
  var currentToken = null;
  var expiresAtMs  = 0;       // absolute client ms when QR expires
  var countInterval= null;

  /* ── DOM refs ── */
  var qrImg      = document.getElementById('qr-img');
  var qrLoading  = document.getElementById('qr-loading');
  var qrOverlay  = document.getElementById('qr-overlay');
  var qrBar      = document.getElementById('qr-bar');
  var qrSecsEl   = document.getElementById('qr-secs');
  var statusDot  = document.getElementById('qr-status-dot');
  var statusText = document.getElementById('qr-status-text');
  var insideEl   = document.getElementById('disp-inside-count');
  var clockEl    = document.getElementById('disp-clock');

  /* ════════════════════════════
     LIVE CLOCK
  ════════════════════════════ */
  (function startClock() {
    function tick() {
      var d = new Date(), h = d.getHours(), m = d.getMinutes(), s = d.getSeconds();
      var ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12;
      if (clockEl) clockEl.textContent = h + ':' + p2(m) + ':' + p2(s) + ' ' + ap;
    }
    tick(); setInterval(tick, 1000);
  })();

  /* ════════════════════════════
     COUNTDOWN — 250ms tick
     Pure Date.now() arithmetic.
  ════════════════════════════ */
  function startCountdown() {
    if (countInterval) clearInterval(countInterval);
    drawBar();
    countInterval = setInterval(drawBar, 250);
  }

  function drawBar() {
    var now    = Date.now();
    var msLeft = Math.max(0, expiresAtMs - now);
    var secsLeft = Math.ceil(msLeft / 1000);   // ceil so we show 30 not 29 at start
    var total  = qrTtl * 1000;
    var pct    = total > 0 ? Math.min(100, (msLeft / total) * 100) : 0;

    if (qrSecsEl) qrSecsEl.textContent = secsLeft;

    // When countdown reaches 0, ask server immediately for a fresh QR.
    // The server will force-generate if the token has expired.
    if (secsLeft <= 0 && !drawBar._refreshing) {
      drawBar._refreshing = true;
      setStatus('loading');
      fetchQr();
      setTimeout(function(){ drawBar._refreshing = false; }, 1200);
    }

    if (qrBar) {
      qrBar.style.width = pct + '%';
      qrBar.style.background =
        pct > 50 ? 'linear-gradient(90deg,#22D68A,#00b4d8)' :
        pct > 20 ? 'linear-gradient(90deg,#f59e0b,#FF4D00)' :
                   '#ef4444';
    }
  }

  /* ════════════════════════════
     ANCHOR TIMER FROM SERVER
     Uses server_ts_ms to eliminate
     client/server clock skew.
  ════════════════════════════ */
  function anchorTimer(res) {
    var sl = parseInt(res.seconds_left, 10);
    if (isNaN(sl) || sl < 0) sl = qrTtl;

    /*
     * server_ts_ms = server's microtime(true)*1000 at response build time.
     * We use it as the reference point to reconstruct the absolute expiry
     * in CLIENT time, avoiding any server↔client clock difference.
     *
     * formula:  expiresAtMs = Date.now() + seconds_left_ms
     * (Date.now() ≈ serverTsMs since network latency is ~ms;
     *  but using Date.now() directly is even safer — no clock skew issue)
     */
    expiresAtMs = Date.now() + (sl * 1000);

    /* Keep qrTtl in sync with what server actually uses */
    if (res.qr_ttl && res.qr_ttl > 0) {
      qrTtl = parseInt(res.qr_ttl, 10);
    }
  }

  /* ════════════════════════════
     FETCH QR — every 3 seconds
  ════════════════════════════ */
  function fetchQr() {
    if (!api) return;
    fetch(api + '?_=' + Date.now(), {
      headers: { 'X-WP-Nonce': nonce, 'Cache-Control': 'no-cache' },
      cache: 'no-store'
    })
      .then(function (r) { return r.json(); })
      .then(handleResponse)
      .catch(function () { setStatus('error'); });
  }

  function handleResponse(res) {
    if (!res || !res.success) return;

    /* ── Inside count ── */
    if (insideEl && res.inside_count !== undefined) {
      insideEl.textContent = res.inside_count;
    }

    var token  = res.token;
    var status = res.qr_status; // 0=open, 2=claimed, 1=used

    /* ── Claimed: show badge, countdown keeps running, new token coming ── */
    if (status === 2) {
      setStatus('claimed');
      /* Keep bar ticking while new QR is being generated */
      anchorTimer(res);
      if (!countInterval) startCountdown();
      return;
    }

    /* ── NEW token → swap image + re-anchor timer ── */
    if (token !== currentToken) {
      currentToken = token;
      anchorTimer(res);          // set expiresAtMs BEFORE swapQrImage
      startCountdown();          // start ticking immediately
      swapQrImage(res.qr_image_url);
      setStatus('loading');
      return;
    }

    /*
     * ── SAME token ──
     * Always re-anchor from server seconds_left.
     * This handles: page refresh mid-cycle, tab coming back from sleep,
     * or any drift > 0. The bar will NOT jump because anchorTimer uses
     * the actual remaining seconds_left, not the full TTL.
     */
    anchorTimer(res);

    /* Make sure countdown interval is running (e.g. page just loaded) */
    if (!countInterval) {
      startCountdown();
    }
  }

  /* ════════════════════════════
     SWAP QR IMAGE
     Old QR visible until new loads.
  ════════════════════════════ */
  function swapQrImage(url) {
    if (!url) return;

    if (qrOverlay) { qrOverlay.style.display = ''; qrOverlay.style.opacity = '0.55'; }

    /* Compute the cache-busted URL ONCE and use it for both the preload
       and the final src assignment — otherwise the browser preloads
       url+timestamp but then gets a cache miss when qrImg.src = url (different URL),
       firing a second request and causing the QR to flash blank. */
    var bustedUrl = url + '&_=' + Date.now();

    var img = new Image();
    img.crossOrigin = 'anonymous';

    img.onload = function () {
      if (qrImg) {
        qrImg.src              = bustedUrl;
        qrImg.style.display    = '';
        qrImg.style.opacity    = '0';
        qrImg.style.transition = 'opacity 0.35s ease';
        setTimeout(function () { if (qrImg) qrImg.style.opacity = '1'; }, 20);
      }
      if (qrLoading) qrLoading.style.display = 'none';
      if (qrOverlay) qrOverlay.style.display  = 'none';
      setStatus('active');
    };

    img.onerror = function () {
      if (qrOverlay) qrOverlay.style.display = 'none';
      currentToken = null;   /* force re-fetch next poll */
      setStatus('error');
    };

    img.src = bustedUrl;
  }

  /* ════════════════════════════
     STATUS BADGE
  ════════════════════════════ */
  function setStatus(state) {
    var map = {
      active : { cls: 'wsa-dot-green',  txt: 'QR Active — Ready to scan'        },
      claimed: { cls: 'wsa-dot-yellow', txt: 'QR Scanned — New code generating…' },
      loading: { cls: 'wsa-dot-yellow', txt: 'Updating QR…'                      },
      error  : { cls: 'wsa-dot-red',    txt: 'Connection issue — retrying…'      },
    };
    var s = map[state] || map.active;
    if (statusDot)  statusDot.className    = 'wsa-status-dot ' + s.cls;
    if (statusText) statusText.textContent = s.txt;
  }

  /* ════════════════════════════
     BOOT
  ════════════════════════════ */
  if (qrImg)    { qrImg.style.display = 'none'; }          /* hidden until first QR loads */
  if (qrLoading){ qrLoading.style.display = ''; }           /* show spinner on first load  */
  if (qrOverlay){ qrOverlay.style.display = 'none'; }

  /* Show "--" until first fetch */
  if (qrSecsEl) qrSecsEl.textContent = '…';

  fetchQr();                       /* immediate first fetch */
  setInterval(fetchQr, 1000);      /* poll every second so QR rotates exactly at 30s */

  function p2(n) { return n < 10 ? '0' + n : '' + n; }

})();
