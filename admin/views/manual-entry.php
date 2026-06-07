<?php defined('ABSPATH') || exit;
$all_staff = WSA_DB::get_all_staff(['status'=>'active']);
$saved     = isset($_GET['saved']) ? '<div class="wsa-alert wsa-alert--ok">✅ Attendance recorded successfully.</div>' : '';
$err       = isset($_GET['error']) ? '<div class="wsa-alert wsa-alert--err">❌ '.esc_html(urldecode($_GET['error'])).'</div>' : '';
$recent    = WSA_DB::get_attendance(['date_from'=>date('Y-m-d'),'date_to'=>date('Y-m-d'),'limit'=>20]);
?>
<div class="wsa-wrap">
  <?php echo $saved.$err; ?>
  <div class="wsa-page-header">
    <div>
      <h1 class="wsa-title">Manual Attendance Entry</h1>
      <p class="wsa-sub">For staff without phones or QR scanner issues</p>
    </div>
  </div>

  <div class="wsa-twocol">
    <!-- Form -->
    <div class="wsa-card">
      <h3 class="wsa-card-title">📝 Enter Attendance</h3>
      <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" class="wsa-form" id="wsa-manual-form">
        <?php wp_nonce_field('wsa_manual_action'); ?>
        <input type="hidden" name="action" value="wsa_manual_entry">

        <div class="wsa-field">
          <label>Select Employee <span class="req">*</span></label>
          <select name="staff_id" id="wsa-manual-staff" required>
            <option value="">— Choose Staff —</option>
            <?php foreach ($all_staff as $s): ?>
              <option value="<?php echo $s->id; ?>"><?php echo esc_html($s->name . ' [' . $s->employee_id . '] ' . ($s->department ? '· '.$s->department : '')); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="wsa-field">
          <label>Mark As <span class="req">*</span></label>
          <select name="entry_status" id="wsa-manual-status" required>
            <option value="PRESENT">Present / Working</option>
            <option value="ABSENT">Absent</option>
          </select>
        </div>

        <div class="wsa-field">
          <label>Date <span class="req">*</span></label>
          <input type="date" name="att_date" id="wsa-manual-date" required value="<?php echo date('Y-m-d'); ?>">
        </div>

        <!-- Existing record banner -->
        <div id="wsa-existing-record" class="wsa-existing-record" style="display:none"></div>

        <div class="wsa-field-row">
          <div class="wsa-field">
            <label>Check IN Time <span class="req wsa-present-req">*</span></label>
            <input type="time" name="login_time" id="wsa-manual-in">
          </div>
          <div class="wsa-field">
            <label>Check OUT Time</label>
            <input type="time" name="logout_time" id="wsa-manual-out">
            <small class="wsa-hint">Leave blank if still working</small>
          </div>
        </div>

        <!-- Live preview -->
        <div class="wsa-calc-preview" id="wsa-calc-preview" style="display:none">
          <div class="wsa-cp-row"><span>Total Hours</span><strong id="wsa-cp-total">—</strong></div>
          <div class="wsa-cp-row"><span>Standard Hours</span><strong id="wsa-cp-std">8h 0m</strong></div>
          <div class="wsa-cp-row"><span>Overtime</span><strong id="wsa-cp-ot" class="wsa-ot">—</strong></div>
        </div>

        <div class="wsa-field">
          <label>Notes / Reason</label>
          <textarea name="notes" rows="2" placeholder="e.g. Phone not working, system down, etc."></textarea>
        </div>

        <div class="wsa-form-btns">
          <button type="submit" class="wsa-btn wsa-btn--accent" style="flex:1">✅ Record Attendance</button>
        </div>
        <p class="wsa-hint wsa-hint--center">Manual entries will never be marked as Late. Late is only for QR scanner attendance.</p>
      </form>
      <script>
      document.addEventListener('DOMContentLoaded', function(){
        const status = document.getElementById('wsa-manual-status');
        const inputIn = document.getElementById('wsa-manual-in');
        const inputOut = document.getElementById('wsa-manual-out');
        const preview = document.getElementById('wsa-calc-preview');
        function syncManualStatus(){
          const absent = status && status.value === 'ABSENT';
          if (inputIn) { inputIn.required = !absent; inputIn.disabled = absent; if (absent) inputIn.value = ''; }
          if (inputOut) { inputOut.disabled = absent; if (absent) inputOut.value = ''; }
          if (preview && absent) preview.style.display = 'none';
        }
        if (status) { status.addEventListener('change', syncManualStatus); syncManualStatus(); }
      });
      </script>
    </div>

    <!-- Today's manual entries sidebar -->
    <div class="wsa-card">
      <h3 class="wsa-card-title">📋 Today's Entries</h3>
      <?php if (empty($recent)): ?>
        <div class="wsa-empty">No entries yet today.</div>
      <?php else: ?>
      <div class="wsa-table-wrap">
        <table class="wsa-table">
          <thead><tr><th>Name</th><th>IN</th><th>OUT</th><th>Hrs</th><th>Type</th></tr></thead>
          <tbody>
            <?php foreach ($recent as $r): ?>
            <tr>
              <td>
                <strong><?php echo esc_html($r->staff_name); ?></strong>
                <span class="wsa-muted"><?php echo esc_html($r->emp_code); ?></span>
              </td>
              <td><?php echo $r->login_time ? '<span class="wsa-time-in">'.date('h:i A',strtotime($r->login_time)).'</span>' : '—'; ?></td>
              <td><?php echo $r->logout_time ? '<span class="wsa-time-out">'.date('h:i A',strtotime($r->logout_time)).'</span>' : '<span class="wsa-badge wsa-badge--in">In</span>'; ?></td>
              <td><?php echo WSA_Attendance::fmt($r->total_hours); ?></td>
              <td><span class="wsa-type-badge wsa-type-badge--<?php echo strtolower($r->type); ?>"><?php echo $r->type; ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
