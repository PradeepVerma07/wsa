<?php defined('ABSPATH') || exit;
$saved = isset($_GET['saved']) ? '<div class="wsa-alert wsa-alert--ok">✅ Settings saved.</div>' : '';
$scan_url  = get_permalink(get_option('wsa_scanner_page_id'));
$dash_url  = get_permalink(get_option('wsa_dashboard_page_id'));
global $wpdb; $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_attendance");
?>
<div class="wsa-wrap">
  <?php echo $saved; ?>
  <div class="wsa-page-header"><div><h1 class="wsa-title">Settings</h1></div></div>

  <div class="wsa-settings-grid">
    <div class="wsa-card">
      <h3 class="wsa-card-title">⚙️ General</h3>
      <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" class="wsa-form">
        <?php wp_nonce_field('wsa_settings_action'); ?>
        <input type="hidden" name="action" value="wsa_save_settings">
        <div class="wsa-field"><label>Company Name</label>
          <input type="text" name="wsa_company" value="<?php echo esc_attr(get_option('wsa_company',get_bloginfo('name'))); ?>"></div>
        <div class="wsa-field"><label>Duplicate Scan Block (minutes)</label>
          <input type="number" name="wsa_duplicate_mins" min="1" max="60" value="<?php echo esc_attr(get_option('wsa_duplicate_mins',3)); ?>">
          <small class="wsa-hint">Prevent same staff scanning again within this time</small></div>
        <div class="wsa-field"><label>Auto-Logout After (hours, 0=disabled)</label>
          <input type="number" name="wsa_auto_logout_hr" min="0" max="24" value="<?php echo esc_attr(get_option('wsa_auto_logout_hr',0)); ?>">
          <small class="wsa-hint">System auto marks OUT for staff who forgot to scan exit. Recommended: 8 or 9.</small></div>
        <div class="wsa-field"><label>Minimum Checkout Time (hours)</label>
          <input type="number" name="wsa_min_checkout_hrs" min="1" max="12" step="0.5" value="<?php echo esc_attr(round(get_option('wsa_min_checkout_mins',420)/60,1)); ?>">
          <small class="wsa-hint">Staff cannot check out before this many hours. Default: 7h. Set to 0 to disable.</small></div>
        <div class="wsa-field">
          <label>Daily Absent Auto-Mark</label>
          <div style="padding:10px;background:rgba(99,102,241,.07);border:1px solid rgba(99,102,241,.2);border-radius:8px;font-size:13px">
            ✅ <strong>Automatic</strong> — runs daily at midnight. Marks all staff with no scan &amp; no approved leave as <em>Absent</em>.<br>
            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=wsa_run_absent_cron&date='.date('Y-m-d',strtotime('yesterday'))),'wsa_admin'); ?>"
               class="wsa-btn wsa-btn--sm" style="margin-top:8px;display:inline-block">
               ▶ Run Now (for yesterday)
            </a>
          </div>
        </div>
        <div class="wsa-field"><label>Timezone</label>
          <select name="wsa_timezone">
            <?php $cur = get_option('wsa_timezone', wp_timezone_string());
            foreach (timezone_identifiers_list() as $tz): ?>
              <option value="<?php echo $tz; ?>" <?php selected($cur,$tz); ?>><?php echo $tz; ?></option>
            <?php endforeach; ?>
          </select>
          <small class="wsa-hint">Attendance scan time, manual entry date, QR time and salary calculations follow this timezone.</small></div>
        <div class="wsa-field-row">
          <div class="wsa-field"><label>Break Start Time</label>
            <input type="time" name="wsa_break_start_time" value="<?php echo esc_attr(get_option('wsa_break_start_time','13:00')); ?>">
          </div>
          <div class="wsa-field"><label>Break End Time</label>
            <input type="time" name="wsa_break_end_time" value="<?php echo esc_attr(get_option('wsa_break_end_time','13:30')); ?>">
          </div>
        </div>
        <p class="wsa-hint">Break is deducted only when the IN→OUT time crosses this window. Example: 09:00–19:00 deducts 30m, 09:00–12:00 deducts 0m.</p>
        <div class="wsa-form-btns"><button type="submit" class="wsa-btn wsa-btn--accent">Save Settings</button></div>
      </form>
    </div>

    <div class="wsa-card">
      <h3 class="wsa-card-title">🔗 Public Pages</h3>
      <div class="wsa-url-row">
        <label>QR Scanner Page</label>
        <?php if ($scan_url): ?>
        <div class="wsa-url-box"><code><?php echo esc_url($scan_url); ?></code><a href="<?php echo esc_url($scan_url); ?>" target="_blank" class="wsa-btn wsa-btn--xs">Open</a></div>
        <?php else: ?><p class="wsa-hint">Page not found. Try deactivating and reactivating the plugin.</p><?php endif; ?>
      </div>
      <div class="wsa-url-row" style="margin-top:16px">
        <label>Staff Dashboard Page</label>
        <?php if ($dash_url): ?>
        <div class="wsa-url-box"><code><?php echo esc_url($dash_url); ?></code><a href="<?php echo esc_url($dash_url); ?>" target="_blank" class="wsa-btn wsa-btn--xs">Open</a></div>
        <?php else: ?><p class="wsa-hint">Page not found.</p><?php endif; ?>
      </div>
      <div style="margin-top:16px;padding:12px;background:rgba(255,77,0,.06);border:1px solid rgba(255,77,0,.15);border-radius:8px">
        <p class="wsa-hint">Shortcodes: <code>[attendance_scanner]</code> · <code>[staff_dashboard]</code></p>
      </div>
    </div>

    <div class="wsa-card">
      <h3 class="wsa-card-title">📊 System Info</h3>
      <ul class="wsa-info-list">
        <li><span>Plugin Version</span><strong><?php echo WSA_VER; ?></strong></li>
        <li><span>DB Version</span><strong><?php echo WSA_DB_VER; ?></strong></li>
        <li><span>Total Staff</span><strong><?php echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_staff"); ?></strong></li>
        <li><span>Total Attendance Records</span><strong><?php echo $total_logs; ?></strong></li>
        <li><span>Total Gates</span><strong><?php echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_gates"); ?></strong></li>
        <li><span>WordPress Version</span><strong><?php echo get_bloginfo('version'); ?></strong></li>
        <li><span>PHP Version</span><strong><?php echo PHP_VERSION; ?></strong></li>
      </ul>
    </div>
  </div>
</div>

<?php /* ── HOLIDAY MANAGEMENT ── appended to settings page */ ?>
<script>
// Tab switching for settings
document.addEventListener('DOMContentLoaded',function(){
  var tab = new URLSearchParams(location.search).get('tab');
  if(tab==='holidays'){
    document.getElementById('wsa-holidays-section').style.display='';
  }
});
</script>
    <!-- ── Holiday Management ── -->
    <div class="wsa-card" id="wsa-holidays-section">
      <h3 class="wsa-card-title">📅 Public Holidays</h3>
      <p style="font-size:13px;color:#6b7280;padding:0 20px 10px">Holidays are NOT counted as absent. Staff who don't come on holidays are correctly excluded from absent count and salary deductions.</p>

      <?php $holidays = WSA_DB::get_holidays(); ?>

      <div style="padding:0 20px 16px">
        <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
          <?php wp_nonce_field('wsa_holiday_action'); ?>
          <input type="hidden" name="action" value="wsa_add_holiday">
          <div class="wsa-field" style="min-width:160px">
            <label>Date</label>
            <input type="date" name="holiday_date" required>
          </div>
          <div class="wsa-field" style="flex:1;min-width:200px">
            <label>Holiday Name</label>
            <input type="text" name="holiday_name" placeholder="e.g. Diwali, Republic Day" required>
          </div>
          <div class="wsa-field" style="min-width:120px">
            <label>&nbsp;</label>
            <button type="submit" class="wsa-btn wsa-btn--accent">+ Add Holiday</button>
          </div>
        </form>
      </div>

      <div class="wsa-table-wrap">
        <table class="wsa-table">
          <thead><tr><th>Date</th><th>Holiday</th><th>Day</th><th>Action</th></tr></thead>
          <tbody>
          <?php if ($holidays): foreach ($holidays as $h): ?>
          <tr>
            <td><?php echo date('d M Y', strtotime($h->holiday_date)); ?></td>
            <td><strong><?php echo esc_html($h->name); ?></strong></td>
            <td><?php echo date('l', strtotime($h->holiday_date)); ?></td>
            <td>
              <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=wsa_delete_holiday&id='.$h->id),'wsa_delete_holiday_'.$h->id); ?>"
                 class="wsa-btn wsa-btn--xs wsa-btn--danger"
                 onclick="return confirm('Remove this holiday?')">🗑 Remove</a>
            </td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="4" class="wsa-empty">No holidays added yet. Add national/regional holidays to prevent false absents.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
