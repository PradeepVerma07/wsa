<?php defined('ABSPATH') || exit; ?>
<div class="wsa-display" id="wsa-display">

  <!-- ── Branding bar ── -->
  <div class="wsa-disp-brand">
    <span class="wsa-disp-logo">🏭</span>
    <span class="wsa-disp-name"><?php echo esc_html($company); ?></span>
    <span class="wsa-disp-right">
      <span class="wsa-disp-clock" id="disp-clock">--:--:--</span>
    </span>
  </div>

  <!-- ── Main QR display ── -->
  <div class="wsa-disp-body">

    <!-- QR panel -->
    <div class="wsa-qr-panel" id="qr-panel">
      <div class="wsa-qr-headline">Scan to Mark Attendance</div>
      <div class="wsa-qr-sub" id="qr-gate-label">
        <?php if ($gate): ?>
          📍 <?php echo esc_html($gate->name); ?><?php if ($gate->location): ?> — <?php echo esc_html($gate->location); ?><?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- QR image -->
      <div class="wsa-qr-frame" id="qr-frame">
        <div class="wsa-qr-loading" id="qr-loading">
          <div class="wsa-qr-spinner"></div>
          <div>Generating QR…</div>
        </div>
        <img id="qr-img" class="wsa-qr-img" src="" alt="Scan this QR code" style="display:none" crossorigin="anonymous">
        <div class="wsa-qr-overlay" id="qr-overlay" style="display:none">
          <div class="wsa-qr-overlay-icon">🔄</div>
          <div class="wsa-qr-overlay-text">Updating QR…</div>
        </div>
      </div>

      <!-- Timer bar -->
      <div class="wsa-timer-wrap">
        <div class="wsa-timer-bar-bg">
          <div class="wsa-timer-bar" id="qr-bar"></div>
        </div>
        <div class="wsa-timer-txt">
          <span>Refreshes in</span>
          <strong id="qr-secs">--</strong>
          <span>seconds</span>
        </div>
      </div>
    </div>

    <!-- Status sidebar -->
    <div class="wsa-disp-sidebar">
      <div class="wsa-disp-instructions">
        <div class="wsa-inst-title">How to mark attendance</div>
        <ol class="wsa-inst-steps">
          <li><span>📱</span><span>Open your phone camera</span></li>
          <li><span>🎯</span><span>Point at the QR code</span></li>
          <li><span>🔗</span><span>Tap the link that appears</span></li>
          <li><span>🔐</span><span>Enter your ID &amp; PIN</span></li>
          <li><span>✅</span><span>Attendance marked!</span></li>
        </ol>
      </div>

      <div class="wsa-disp-live-box">
        <div class="wsa-dlb-title">
          <span class="wsa-dot-live"></span> Currently Inside
        </div>
        <div class="wsa-dlb-count" id="disp-inside-count">—</div>
        <div class="wsa-dlb-sub">staff members</div>
      </div>

      <div class="wsa-disp-security">
        <div class="wsa-sec-row"><span>🔒</span><span>One-time use QR</span></div>
        <div class="wsa-sec-row"><span>⏱</span><span>Expires every 30 sec</span></div>
        <div class="wsa-sec-row"><span>🛡</span><span>Server-verified</span></div>
        <div class="wsa-sec-row"><span>🔄</span><span>Auto-regenerates after scan</span></div>
      </div>
    </div>

  </div><!-- .wsa-disp-body -->

  <!-- Status badge -->
  <div class="wsa-disp-status-bar" id="qr-status-bar">
    <span class="wsa-status-dot wsa-dot-green" id="qr-status-dot"></span>
    <span id="qr-status-text">QR Active — Ready to scan</span>
  </div>

</div><!-- .wsa-display -->
