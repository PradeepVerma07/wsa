<?php
defined('ABSPATH') || exit;
global $wpdb;

$total_staff  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_staff WHERE status='active'");
$face_reg     = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_face_profiles WHERE status='registered'");
$today_in     = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_attendance WHERE att_date=CURDATE() AND status='IN'");
$today_out    = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_attendance WHERE att_date=CURDATE() AND status='OUT'");
$today_break  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_attendance WHERE att_date=CURDATE() AND status='BREAK'");
$today_scans  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_face_logs WHERE DATE(created_at)=CURDATE() AND status='success'");

$recent_scans = $wpdb->get_results("
    SELECT l.action, l.confidence, l.created_at, l.quality_score,
           s.name, s.employee_id, s.department, s.photo_url
    FROM {$wpdb->prefix}wsa_face_logs l
    LEFT JOIN {$wpdb->prefix}wsa_staff s ON s.id=l.staff_id
    WHERE l.status='success' AND DATE(l.created_at)=CURDATE()
    ORDER BY l.created_at DESC LIMIT 30
");

function wsa_action_label($a) {
    return ['CHECKIN'=>'✅ Check-In','BREAK_START'=>'☕ Break','BREAK_END'=>'✅ Break End','CHECKOUT'=>'🚪 Check-Out'][$a] ?? $a;
}
function wsa_fmt_time($dt) {
    if (!$dt) return '—';
    return date('h:i A', strtotime($dt));
}
?>

<div class="wsafd-wrap">

  <!-- ── Live Scan Notification (injected by JS) ── -->
  <div id="wsafdLiveAlert" class="wsafd-live-alert" style="display:none;">
    <span id="wsafdLiveIcon">✅</span>
    <div>
      <strong id="wsafdLiveName">—</strong>
      <span id="wsafdLiveMsg" style="margin-left:8px;"></span>
    </div>
    <span id="wsafdLiveTime" style="margin-left:auto;opacity:.7;font-size:13px;"></span>
  </div>

  <!-- ── Hero ── -->
  <div class="wsafd-hero">
    <div class="wsafd-hero-left">
      <span class="wsafd-pill">🔬 AI Face Attendance</span>
      <h1><?php echo esc_html($company); ?></h1>
      <p><?php echo date('l, F j, Y'); ?></p>
    </div>
    <div class="wsafd-clock-box">
      <div class="wsafd-clock" id="wsafdClock">00:00:00</div>
      <div class="wsafd-date-sub" id="wsafdDate"></div>
    </div>
  </div>

  <!-- ── Stats Row ── -->
  <div class="wsafd-stats">
    <div class="wsafd-stat wsafd-stat--green">
      <span class="wsafd-stat-icon">🟢</span>
      <b id="wsafdStatIn"><?php echo $today_in; ?></b>
      <span>Currently In</span>
    </div>
    <div class="wsafd-stat wsafd-stat--amber">
      <span class="wsafd-stat-icon">☕</span>
      <b id="wsafdStatBreak"><?php echo $today_break; ?></b>
      <span>On Break</span>
    </div>
    <div class="wsafd-stat wsafd-stat--muted">
      <span class="wsafd-stat-icon">🚪</span>
      <b id="wsafdStatOut"><?php echo $today_out; ?></b>
      <span>Checked Out</span>
    </div>
    <div class="wsafd-stat wsafd-stat--blue">
      <span class="wsafd-stat-icon">📊</span>
      <b id="wsafdStatScans"><?php echo $today_scans; ?></b>
      <span>Total Scans</span>
    </div>
    <div class="wsafd-stat wsafd-stat--cyan">
      <span class="wsafd-stat-icon">👥</span>
      <b><?php echo $face_reg; ?>/<?php echo $total_staff; ?></b>
      <span>Faces Registered</span>
    </div>
  </div>

  <!-- ── Main Layout ── -->
  <div class="wsafd-main">

    <!-- Left: Camera Panel -->
    <div class="wsafd-camera-panel">
      <div class="wsafd-panel-header">
        <h2>📸 Face Scanner</h2>
        <span class="wsafd-live-dot"></span><span style="font-size:12px;color:#4ade80;">Live</span>
      </div>

      <!-- Camera -->
      <div class="wsa-face-camera-card">
        <video id="wsaFaceVideo" autoplay muted playsinline></video>
        <canvas id="wsaFaceCanvas"></canvas>
        <div class="wsa-face-frame">
          <span></span><span></span><span></span><span></span>
        </div>
        <div class="wsa-face-quality-wrap"><div id="wsaFaceQuality"></div></div>
        <div id="wsaFaceStatus" class="wsa-face-status">⚙️ Initialising Face AI…</div>
        <div id="wsaFaceSpinner">
          <div class="wsa-spinner-ring"></div>
          <span>Matching face…</span>
        </div>
        <button id="wsaFaceRetry" onclick="window.location.reload()" class="wsafd-retry-btn" style="display:none;">
          🔄 Retry Camera
        </button>
      </div>

      <!-- Action Buttons -->
      <div class="wsafd-action-row">
        <button class="wsa-action-btn wsa-btn-active" data-action="auto">🔄 Auto</button>
        <button class="wsa-action-btn" data-action="checkin">✅ Check-In</button>
        <button class="wsa-action-btn" data-action="break">☕ Break</button>
        <button class="wsa-action-btn" data-action="checkout">🚪 Check-Out</button>
      </div>

      <!-- Result card (compact) -->
      <div class="wsafd-result-compact" id="wsafdResult" style="display:none;">
        <div id="wsaFaceAvatar" class="wsa-face-avatar wsafd-avatar-sm">👤</div>
        <div class="wsafd-result-info">
          <strong id="wsaFaceName">—</strong>
          <span id="wsaFaceMeta"></span>
        </div>
        <span id="wsaFaceStatusBadge" class="wsa-status-badge wsa-sbadge-none"></span>
      </div>

      <div id="wsaFaceMessage" class="wsa-face-msg wsa-msg-info" style="display:none;margin-top:10px;"></div>

      <!-- KPIs -->
      <div class="wsa-face-kpis wsafd-kpis">
        <div><b id="wsaFaceAction">—</b><span>Action</span></div>
        <div><b id="wsaFaceConfidence">—</b><span>Confidence</span></div>
        <div><b id="wsaFaceTime">—</b><span>Time</span></div>
      </div>
    </div>

    <!-- Right: Feed + Timeline -->
    <div class="wsafd-right-panel">

      <!-- Today's Timeline (after scan) -->
      <div class="wsafd-card wsafd-timeline-card">
        <h3>🕐 Today's Timeline</h3>
        <div id="wsaFaceTimeline">
          <p class="wsa-tl-empty">Scan your face to see your attendance timeline.</p>
        </div>
        <div class="wsa-hours-row" style="margin-top:10px;">
          <span class="wsa-hours-chip" id="wsaFaceTotalHours" style="display:none;"></span>
          <span class="wsa-hours-chip" id="wsaFaceBreakDur"   style="display:none;"></span>
        </div>
      </div>

      <!-- Live Feed -->
      <div class="wsafd-card wsafd-feed-card">
        <div class="wsafd-feed-header">
          <h3>📋 Today's Face Scans</h3>
          <span class="wsafd-badge-count" id="wsafdFeedCount"><?php echo $today_scans; ?></span>
        </div>
        <div class="wsafd-feed-list" id="wsafdFeedList">
          <?php if ($recent_scans): ?>
            <?php foreach ($recent_scans as $s): ?>
            <div class="wsafd-feed-item">
              <div class="wsafd-feed-avatar">
                <?php if (!empty($s->photo_url)): ?>
                  <img src="<?php echo esc_url($s->photo_url); ?>" alt="">
                <?php else: ?>
                  <span>👤</span>
                <?php endif; ?>
              </div>
              <div class="wsafd-feed-info">
                <strong><?php echo esc_html($s->name ?: 'Unknown'); ?></strong>
                <span><?php echo esc_html($s->department ?: $s->employee_id ?: ''); ?></span>
              </div>
              <div class="wsafd-feed-right">
                <span class="wsafd-feed-action"><?php echo wsa_action_label($s->action); ?></span>
                <span class="wsafd-feed-time"><?php echo wsa_fmt_time($s->created_at); ?></span>
              </div>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="wsafd-feed-empty">
              <p>No face scans yet today.</p>
              <p>Staff can scan their face above to check in.</p>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Quick guide -->
      <div class="wsafd-card wsafd-guide-card">
        <h3>📖 How It Works</h3>
        <div class="wsafd-steps">
          <div class="wsafd-step"><span>1</span><p><strong>Face camera</strong> — look directly at camera in good lighting</p></div>
          <div class="wsafd-step"><span>2</span><p><strong>Blink once</strong> — liveness check to prevent photo spoofing</p></div>
          <div class="wsafd-step"><span>3</span><p><strong>Auto-detected</strong> — attendance marked instantly. No buttons needed on Auto mode.</p></div>
          <div class="wsafd-step"><span>4</span><p><strong>Cycle:</strong> Check-In → Break → Break End → Check-Out (or choose manually)</p></div>
        </div>
        <p style="font-size:12px;color:var(--wsa-muted);margin-top:10px;">Not registered yet? Contact your administrator to register your face.</p>
      </div>

    </div>
  </div>
</div>

<script>
/* ── Dashboard JS ───────────────────────────────────────────── */
(function () {
    // Clock
    function tick() {
        var now = new Date();
        var c = document.getElementById('wsafdClock');
        var d = document.getElementById('wsafdDate');
        if (c) c.textContent = now.toLocaleTimeString([], { hour:'2-digit', minute:'2-digit', second:'2-digit' });
        if (d) d.textContent = now.toLocaleDateString([], { weekday:'long', month:'long', day:'numeric', year:'numeric' });
    }
    tick(); setInterval(tick, 1000);

    // Live alert banner
    function showAlert(res) {
        var staff  = res.staff  || {};
        var el     = document.getElementById('wsafdLiveAlert');
        var nameEl = document.getElementById('wsafdLiveName');
        var msgEl  = document.getElementById('wsafdLiveMsg');
        var timeEl = document.getElementById('wsafdLiveTime');
        if (!el) return;
        var icons = { CHECKIN:'✅', BREAK_START:'☕', BREAK_END:'✅', CHECKOUT:'🚪' };
        document.getElementById('wsafdLiveIcon').textContent = icons[res.action] || '📋';
        nameEl.textContent = staff.name || 'Staff';
        msgEl.textContent  = res.message || res.action || '';
        timeEl.textContent = new Date().toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' });
        el.style.display = 'flex';
        el.classList.add('wsafd-alert-show');
        // Prepend to feed
        prependFeedItem(res);
        // Update stat counters
        refreshStats();
        setTimeout(function () { el.style.display = 'none'; el.classList.remove('wsafd-alert-show'); }, 5000);
    }

    function prependFeedItem(res) {
        var list = document.getElementById('wsafdFeedList');
        if (!list) return;
        var staff = res.staff || {};
        var actionMap = { CHECKIN:'✅ Check-In', BREAK_START:'☕ Break', BREAK_END:'✅ Break End', CHECKOUT:'🚪 Check-Out' };
        var html = '<div class="wsafd-feed-item wsafd-feed-new">' +
            '<div class="wsafd-feed-avatar">' + (staff.photo ? '<img src="'+staff.photo+'" alt="">' : '<span>👤</span>') + '</div>' +
            '<div class="wsafd-feed-info"><strong>' + (staff.name||'—') + '</strong><span>' + (staff.department||staff.employee_id||'') + '</span></div>' +
            '<div class="wsafd-feed-right"><span class="wsafd-feed-action">' + (actionMap[res.action]||res.action||'—') + '</span>' +
            '<span class="wsafd-feed-time">' + new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}) + '</span></div>' +
            '</div>';
        // Remove empty state
        var empty = list.querySelector('.wsafd-feed-empty');
        if (empty) empty.remove();
        list.insertAdjacentHTML('afterbegin', html);
        // Update count
        var cnt = list.querySelectorAll('.wsafd-feed-item').length;
        var cntEl = document.getElementById('wsafdFeedCount');
        if (cntEl) cntEl.textContent = cnt;
    }

    function refreshStats() {
        if (typeof wsaFace === 'undefined') return;
        fetch(wsaFace.apiDashboard || '', { headers:{'X-WP-Nonce': wsaFace.nonce} })
            .then(function(r){ return r.json(); })
            .then(function(j){
                if (!j.success) return;
                var map = { wsafdStatIn: j.currently_in, wsafdStatBreak: j.on_break, wsafdStatOut: j.checked_out, wsafdStatScans: j.total_face_scans };
                Object.keys(map).forEach(function(id){ var el=document.getElementById(id); if(el) el.textContent=map[id]; });
            }).catch(function(){});
    }

    // Show result card when face scanner returns a result
    var origRender = window.__wsaFaceRenderHook;
    window.wsaFaceDash = { onScan: showAlert };

    // Show result compact card
    document.addEventListener('wsaFaceScan', function(e) { showAlert(e.detail); });

    // Show wsafdResult when wsaFaceName gets a value
    var nameEl = document.getElementById('wsaFaceName');
    if (nameEl) {
        var obs = new MutationObserver(function () {
            var r = document.getElementById('wsafdResult');
            if (r && nameEl.textContent && nameEl.textContent !== '—') r.style.display = 'flex';
        });
        obs.observe(nameEl, { childList: true, characterData: true, subtree: true });
    }

    // Auto-refresh stats every 30s
    setInterval(refreshStats, 30000);
})();
</script>
