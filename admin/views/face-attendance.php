<?php defined('ABSPATH') || exit;
global $wpdb;
$shifts = $wpdb->get_results("SELECT id, name, start_time, end_time FROM {$wpdb->prefix}wsa_shifts ORDER BY name ASC");
?>

<!-- Quick Add Staff Modal -->
<div id="wsaModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9998;"></div>
<div id="wsaQuickAddModal" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:16px;padding:28px;width:460px;max-width:95vw;z-index:9999;box-shadow:0 30px 80px rgba(0,0,0,.3);">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
    <h3 style="margin:0;font-size:18px;">➕ Quick Add Staff</h3>
    <button id="wsaModalClose" style="background:none;border:none;font-size:22px;cursor:pointer;color:#64748b;">✕</button>
  </div>
  <div style="display:grid;gap:12px;">
    <div>
      <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Employee ID <span style="color:#dc2626;">*</span></label>
      <input id="wsaQEmpId" type="text" placeholder="e.g. EMP-001" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;box-sizing:border-box;">
    </div>
    <div>
      <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Full Name <span style="color:#dc2626;">*</span></label>
      <input id="wsaQName" type="text" placeholder="e.g. John Smith" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;box-sizing:border-box;">
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
      <div>
        <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Department</label>
        <input id="wsaQDept" type="text" placeholder="e.g. HR" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;box-sizing:border-box;">
      </div>
      <div>
        <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Phone</label>
        <input id="wsaQPhone" type="tel" placeholder="+1 555 …" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;box-sizing:border-box;">
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
      <div>
        <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Email</label>
        <input id="wsaQEmail" type="email" placeholder="john@co.com" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;box-sizing:border-box;">
      </div>
      <div>
        <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">PIN (4-6 digits)</label>
        <input id="wsaQPin" type="text" placeholder="1234" maxlength="6" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;box-sizing:border-box;">
      </div>
    </div>
    <div>
      <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Shift</label>
      <select id="wsaQShift" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
        <option value="">— No Shift —</option>
        <?php foreach ($shifts as $sh): ?>
          <option value="<?php echo $sh->id; ?>"><?php echo esc_html($sh->name . ' (' . substr($sh->start_time,0,5) . '–' . substr($sh->end_time,0,5) . ')'); ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (empty($shifts)): ?>
        <small style="color:#d97706;">⚠️ No shifts yet. <a href="<?php echo admin_url('admin.php?page=wsa-shifts'); ?>" target="_blank">Create shifts →</a></small>
      <?php endif; ?>
    </div>
  </div>
  <div style="display:flex;gap:10px;margin-top:20px;">
    <button id="wsaSubmitQuickStaff" class="button button-primary" style="flex:1;padding:10px;">Add Staff</button>
    <button id="wsaModalClose2" class="button" onclick="document.getElementById('wsaQuickAddModal').style.display='none';" style="padding:10px;">Cancel</button>
  </div>
  <p style="font-size:12px;color:#64748b;margin:10px 0 0;">After adding, the staff will appear in the dropdown and you can immediately capture their face.</p>
</div>
<script>
document.getElementById('wsaModalClose2').onclick = function(){document.getElementById('wsaQuickAddModal').style.display='none';document.getElementById('wsaModalOverlay').style.display='none';};
</script>

<div class="wrap wsa-face-admin">
  <h1>🔬 Face Recognition Attendance</h1>
  <p class="description">Register staff faces, monitor live quality, prevent duplicates, and review all face attendance logs.</p>

  <div class="notice notice-info inline" style="border-radius:10px;">
    <p><strong>How it works:</strong>
      1) Select staff below →
      2) Allow camera →
      3) Capture <strong>Front, Left, Right, Up, Down</strong> angles →
      4) Click <strong>Save Face Profile</strong> →
      5) Staff use the public <strong>[wsa_face_scanner]</strong> page to check in/break/out automatically.
      <br>Auto mode: 1st scan = Check-In · 2nd scan = Break Start · 3rd scan = Break End · 4th scan = Check-Out.
    </p>
  </div>

  <!-- Stats Row -->
  <div class="wsa-face-stats-row">
    <div class="wsa-stat-box cyan">
      <b id="wsaStatReg">—</b>
      <span>Registered</span>
    </div>
    <div class="wsa-stat-box red">
      <b id="wsaStatUnreg">—</b>
      <span>Unregistered</span>
    </div>
    <div class="wsa-stat-box green">
      <b id="wsaStatScans">—</b>
      <span>Today's Scans</span>
    </div>
    <div class="wsa-stat-box amber">
      <b id="wsaStatFails">—</b>
      <span>Today's Failures</span>
    </div>
    <div class="wsa-stat-box cyan">
      <b id="wsaStatConf">—</b>
      <span>Avg Confidence</span>
    </div>
    <div style="display:flex;align-items:center;">
      <button class="button" id="wsaRefreshStats">🔄 Refresh Stats</button>
    </div>
  </div>

  <!-- Registration + Staff List -->
  <div class="wsa-face-admin-grid">

    <!-- Registration Panel -->
    <div class="postbox wsa-face-box">
      <h2 class="hndle">📸 Face Registration</h2>
      <div class="inside">

        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px;">
          <label for="wsaFaceStaff" style="margin:0;font-weight:700;">Select Employee</label>
          <a href="<?php echo admin_url('admin.php?page=wsa-staff'); ?>"
             class="button button-small" target="_blank" style="text-decoration:none;">➕ Full Staff Form</a>
          <button class="button button-small" id="wsaQuickAddStaffBtn" style="background:#16a34a;color:#fff;border-color:#16a34a;">⚡ Quick Add Here</button>
        </div>
        <select id="wsaFaceStaff" class="regular-text" style="width:100%;margin-bottom:6px;padding:8px;font-size:14px;border-radius:8px;border:1px solid #d1d5db;">
          <option value="">Loading staff…</option>
        </select>
        <p id="wsaFaceStaffCount" style="font-size:12px;color:#64748b;margin:0 0 10px;"></p>
        <div id="wsaSelectedStaffPreview" style="display:none;padding:10px 12px;background:#f0fdf4;border:1px solid #86efac;border-radius:10px;margin-bottom:10px;font-size:13px;">
          <strong id="wsaPreviewName"></strong> · <span id="wsaPreviewEmpId" style="color:#64748b;"></span> · <span id="wsaPreviewDept" style="color:#64748b;"></span>
          <span id="wsaPreviewFaceStatus" style="margin-left:8px;font-weight:700;"></span>
        </div>

        <!-- Camera -->
        <div class="wsa-face-camera-card admin-card">
          <video id="wsaFaceRegVideo" autoplay muted playsinline></video>
          <canvas id="wsaFaceRegCanvas"></canvas>
          <div class="wsa-face-frame">
            <span></span><span></span><span></span><span></span>
          </div>
        </div>

        <!-- Live quality -->
        <div class="wsa-live-quality-row" style="margin-top:12px;">
          <span style="font-size:12px;font-weight:700;color:#374151;">Live Quality:</span>
          <div class="wsa-lq-bar-wrap"><div id="wsaLiveQBar"></div></div>
          <span id="wsaLiveQuality" style="font-size:13px;font-weight:800;min-width:40px;">—</span>
        </div>
        <p id="wsaFaceGuide" style="margin:0 0 10px;">📷 No face detected — look at the camera</p>

        <!-- Status -->
        <p id="wsaFaceRegStatus" class="wsa-face-status-line">Camera initialising…</p>

        <!-- Progress -->
        <div class="wsa-capture-progress-wrap">
          <div style="display:flex;justify-content:space-between;font-size:12px;font-weight:600;color:#374151;">
            <span>Angles captured</span>
            <span id="wsaCaptureProgress">0/5</span>
          </div>
          <div class="wsa-pbar-track"><div class="wsa-pbar-fill" id="wsaCaptureBar" style="width:0%"></div></div>
        </div>

        <div class="wsa-face-capture-progress" id="wsaFaceProgress" style="margin-top:10px;">
          <span data-step="front">Front</span>
          <span data-step="left">Left</span>
          <span data-step="right">Right</span>
          <span data-step="up">Up</span>
          <span data-step="down">Down</span>
        </div>

        <!-- Actions -->
        <div class="wsa-face-actions" style="margin-top:14px;">
          <button class="button" data-angle="front">📸 Front</button>
          <button class="button" data-angle="left">↩️ Left</button>
          <button class="button" data-angle="right">↪️ Right</button>
          <button class="button" data-angle="up">⬆️ Up</button>
          <button class="button" data-angle="down">⬇️ Down</button>
        </div>
        <div class="wsa-face-actions" style="margin-top:8px;">
          <button class="button button-primary" id="wsaSaveFace" disabled>💾 Save / Update Face Profile</button>
          <button class="button" id="wsaResetCaptures">🗑 Reset Captures</button>
        </div>

        <p style="font-size:12px;color:#64748b;margin-top:12px;line-height:1.6;">
          <strong>Tips:</strong> Use bright, even lighting. Face the camera directly for Front, then slowly rotate. 
          Minimum 3 angles required; all 5 recommended for highest accuracy.
          Quality score above 70% is ideal.
        </p>
      </div>
    </div>

    <!-- Staff List -->
    <div class="postbox wsa-face-box">
      <h2 class="hndle">👥 Staff Face Status</h2>
      <div class="inside">
        <div id="wsaFaceStaffList">
          <p style="color:#64748b;font-size:13px;">Loading staff list…</p>
        </div>
      </div>
    </div>
  </div>

  <!-- Logs Panel -->
  <div class="postbox wsa-face-box" style="max-width:100%;margin-top:20px;">
    <h2 class="hndle">📋 Face Attendance Logs</h2>
    <div class="inside">

      <!-- Log stats -->
      <div class="wsa-log-stats-row">
        <div class="wsa-log-stat">
          <b id="wsaLogTotal">—</b>
          <span>Total</span>
        </div>
        <div class="wsa-log-stat" style="color:#16a34a;">
          <b id="wsaLogSuccess" style="color:#16a34a;">—</b>
          <span>Successful</span>
        </div>
        <div class="wsa-log-stat">
          <b id="wsaLogFail" style="color:#dc2626;">—</b>
          <span>Failed</span>
        </div>
        <div class="wsa-log-stat">
          <b id="wsaLogConf">—</b>
          <span>Avg Confidence</span>
        </div>
      </div>

      <!-- Filters -->
      <div class="wsa-log-filters">
        <div>
          <label>From Date</label>
          <input type="date" id="wsaFaceLogFrom">
        </div>
        <div>
          <label>To Date</label>
          <input type="date" id="wsaFaceLogTo">
        </div>
        <div>
          <label>Status</label>
          <select id="wsaFaceLogStatus">
            <option value="">All</option>
            <option value="success">Success</option>
            <option value="failed">Failed</option>
          </select>
        </div>
        <div style="display:flex;gap:8px;padding-top:18px;flex-wrap:wrap;">
          <button class="button button-primary" id="wsaLoadFaceLogs">🔍 Load Logs</button>
          <button class="button" id="wsaExportFaceLogs">⬇️ Export CSV</button>
          <button class="button" id="wsaPrintFaceLogs">🖨 Print</button>
        </div>
      </div>

      <div class="wsa-face-table-wrap">
        <div id="wsaFaceLogs">
          <p style="padding:20px;color:#64748b;text-align:center;">Click "Load Logs" to view face attendance logs.</p>
        </div>
      </div>
    </div>
  </div>
</div>
