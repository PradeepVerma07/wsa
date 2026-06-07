<?php
defined('ABSPATH') || exit;

global $wpdb;
$today_checkins = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_attendance WHERE att_date = CURDATE() AND type = 'FACE'");
?>
<div class="wsa-face-wrap">

  <!-- Hero -->
  <div class="wsa-face-hero">
    <span class="wsa-face-pill">🔬 AI Face Recognition Attendance</span>
    <h2><?php echo esc_html($company); ?></h2>
    <p>Stand in front of the camera. Blink or slowly nod for liveness verification. Select your action below before scanning.</p>
  </div>

  <!-- Main Grid -->
  <div class="wsa-face-grid">

    <!-- Left: Camera -->
    <div class="wsa-face-camera-card">
      <video id="wsaFaceVideo" autoplay muted playsinline></video>
      <canvas id="wsaFaceCanvas"></canvas>

      <!-- Corner brackets -->
      <div class="wsa-face-frame">
        <span></span><span></span><span></span><span></span>
      </div>

      <!-- Quality bar -->
      <div class="wsa-face-quality-wrap">
        <div id="wsaFaceQuality"></div>
      </div>

      <!-- Status -->
      <div id="wsaFaceStatus" class="wsa-face-status">⚙️ Starting Face AI…</div>

      <!-- Processing spinner -->
      <div id="wsaFaceSpinner">
        <div class="wsa-spinner-ring"></div>
        <span>Matching face…</span>
      </div>
    </div>

    <!-- Right: Result + Controls -->
    <div class="wsa-face-result-card">

      <!-- Identity -->
      <div class="wsa-face-id-row">
        <div class="wsa-face-avatar" id="wsaFaceAvatar">👤</div>
        <div>
          <h3 id="wsaFaceName">Waiting for scan</h3>
          <p id="wsaFaceMeta">No attendance marked yet</p>
          <span id="wsaFaceStatusBadge" class="wsa-status-badge wsa-sbadge-none">Not Scanned</span>
        </div>
      </div>

      <!-- Message -->
      <div id="wsaFaceMessage" class="wsa-face-msg wsa-msg-info" style="display:none;"></div>

      <!-- KPIs -->
      <div class="wsa-face-kpis">
        <div>
          <b id="wsaFaceAction">—</b>
          <span>Action</span>
        </div>
        <div>
          <b id="wsaFaceConfidence">—</b>
          <span>Confidence</span>
        </div>
        <div>
          <b id="wsaFaceTime">—</b>
          <span>Time</span>
        </div>
      </div>

      <!-- Action Selector -->
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--wsa-muted);margin-bottom:8px;">Select Action</div>
        <div class="wsa-action-buttons">
          <button class="wsa-action-btn wsa-btn-active" data-action="auto">🔄 Auto</button>
          <button class="wsa-action-btn" data-action="checkin">✅ Check-In</button>
          <button class="wsa-action-btn" data-action="break">☕ Break</button>
          <button class="wsa-action-btn" data-action="checkout">🚪 Check-Out</button>
        </div>
      </div>

      <!-- Timeline -->
      <div class="wsa-face-timeline-card">
        <h4>Today's Timeline</h4>
        <div id="wsaFaceTimeline">
          <p class="wsa-tl-empty">Scan your face to see timeline.</p>
        </div>
        <div class="wsa-hours-row" style="margin-top:10px;">
          <span class="wsa-hours-chip" id="wsaFaceTotalHours" style="display:none;"></span>
          <span class="wsa-hours-chip" id="wsaFaceBreakDur"   style="display:none;"></span>
        </div>
      </div>

      <!-- Help -->
      <div class="wsa-face-help">
        <strong>Tips:</strong> Use good lighting · Face the camera directly · One person only · Blink once for liveness.<br>
        <strong>Errors handled:</strong> Unknown face, low quality, liveness fail, multiple faces, camera denied.
      </div>

      <?php if ($today_checkins > 0): ?>
      <div style="font-size:12px;color:var(--wsa-muted);text-align:center;">
        🔢 <?php echo $today_checkins; ?> face scan(s) today
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
// Reveal message box when filled
document.addEventListener('DOMContentLoaded', function () {
  var msgEl = document.getElementById('wsaFaceMessage');
  if (!msgEl) return;
  var orig = Object.getOwnPropertyDescriptor(Node.prototype, 'textContent');
  // Show message whenever wsaFaceAction is updated (result arrives)
});
</script>
