<?php defined('ABSPATH') || exit;
global $wpdb;
$year = (int)($_GET['yr'] ?? date('Y'));
$saved   = isset($_GET['saved'])   ? '<div class="wsa-alert wsa-alert--ok">✅ Holiday saved.</div>' : '';
$deleted = isset($_GET['deleted']) ? '<div class="wsa-alert wsa-alert--ok">✅ Holiday removed.</div>' : '';
$err     = isset($_GET['error'])   ? '<div class="wsa-alert wsa-alert--err">'.esc_html(urldecode($_GET['error'])).'</div>' : '';
$holidays = WSA_DB::get_holidays($year);
?>
<div class="wsa-wrap">
  <?php echo $saved.$deleted.$err; ?>
  <div class="wsa-page-header">
    <div>
      <h1 class="wsa-title">🎌 Holidays & Weekends</h1>
      <p class="wsa-sub">Define holidays — staff absent on these dates are NOT counted as absent for salary purposes.</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center">
      <a href="?page=wsa-holidays&yr=<?php echo $year-1; ?>" class="wsa-btn">← <?php echo $year-1; ?></a>
      <strong><?php echo $year; ?></strong>
      <a href="?page=wsa-holidays&yr=<?php echo $year+1; ?>" class="wsa-btn"><?php echo $year+1; ?> →</a>
    </div>
  </div>

  <div class="wsa-twocol">
    <!-- Holiday table -->
    <div class="wsa-card">
      <div class="wsa-card-head"><h3>📅 <?php echo $year; ?> Holidays (<?php echo count($holidays); ?>)</h3></div>
      <div class="wsa-table-wrap">
        <table class="wsa-table">
          <thead><tr><th>Date</th><th>Day</th><th>Holiday Name</th><th>Type</th><th>Action</th></tr></thead>
          <tbody>
          <?php if ($holidays): foreach ($holidays as $h): ?>
          <tr>
            <td><strong><?php echo date('d M Y', strtotime($h->holiday_date)); ?></strong></td>
            <td><?php echo date('l', strtotime($h->holiday_date)); ?></td>
            <td><?php echo esc_html($h->name); ?></td>
            <td>
              <span class="wsa-badge <?php echo $h->type==='national'?'wsa-badge--in':($h->type==='weekly_off'?'wsa-badge--absent':'wsa-badge--late'); ?>">
                <?php echo ucfirst(str_replace('_',' ',$h->type)); ?>
              </span>
            </td>
            <td>
              <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=wsa_delete_holiday&id='.$h->id),'wsa_delete_holiday_'.$h->id); ?>"
                 class="wsa-btn wsa-btn--xs wsa-btn--danger"
                 onclick="return confirm('Remove this holiday?')">🗑</a>
            </td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="5" class="wsa-empty">No holidays defined for <?php echo $year; ?>. Add some below.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Add form -->
    <div class="wsa-card">
      <div class="wsa-card-head"><h3>+ Add Holiday</h3></div>
      <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" class="wsa-form">
        <?php wp_nonce_field('wsa_holiday_action'); ?>
        <input type="hidden" name="action" value="wsa_save_holiday">
        <input type="hidden" name="yr" value="<?php echo $year; ?>">
        <div class="wsa-field">
          <label>Date *</label>
          <input type="date" name="holiday_date" required>
        </div>
        <div class="wsa-field">
          <label>Holiday Name *</label>
          <input type="text" name="name" required placeholder="e.g. Diwali, Republic Day">
        </div>
        <div class="wsa-field">
          <label>Type</label>
          <select name="type">
            <option value="national">National Holiday</option>
            <option value="optional">Optional Holiday</option>
            <option value="weekly_off">Weekly Off</option>
          </select>
        </div>
        <div class="wsa-form-btns"><button type="submit" class="wsa-btn wsa-btn--accent">+ Add Holiday</button></div>
      </form>

      <!-- Weekend settings -->
      <div style="padding:0 20px 20px">
        <hr style="margin:16px 0;border-color:#f3f4f6">
        <h4 style="font-size:13px;font-weight:700;margin-bottom:12px">⚙️ Weekend Configuration</h4>
        <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" class="wsa-form" style="padding:0">
          <?php wp_nonce_field('wsa_settings_action'); ?>
          <input type="hidden" name="action" value="wsa_save_settings">
          <div class="wsa-field">
            <label>Weekly Off Day(s)</label>
            <select name="wsa_weekend_days">
              <option value="none"            <?php selected(get_option('wsa_weekend_days','sunday'),'none'); ?>>No Weekly Off (7-day work week)</option>
              <option value="sunday"          <?php selected(get_option('wsa_weekend_days','sunday'),'sunday'); ?>>Sunday Only</option>
              <option value="saturday-sunday" <?php selected(get_option('wsa_weekend_days','sunday'),'saturday-sunday'); ?>>Saturday & Sunday</option>
            </select>
            <small class="wsa-hint">Staff absent on weekly-off days are not marked absent for salary.</small>
          </div>
          <div class="wsa-form-btns"><button type="submit" class="wsa-btn wsa-btn--accent">Save Weekend Setting</button></div>
        </form>
      </div>

      <!-- Quick add national holidays -->
      <div style="padding:0 20px 20px">
        <hr style="margin:0 0 14px;border-color:#f3f4f6">
        <h4 style="font-size:13px;font-weight:700;margin-bottom:10px">⚡ Quick Add Indian National Holidays (<?php echo $year; ?>)</h4>
        <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>">
          <?php wp_nonce_field('wsa_holiday_bulk_action'); ?>
          <input type="hidden" name="action" value="wsa_add_national_holidays">
          <input type="hidden" name="yr" value="<?php echo $year; ?>">
          <button type="submit" class="wsa-btn wsa-btn--sm" onclick="return confirm('Add standard Indian national holidays for <?php echo $year; ?>?')">
            🇮🇳 Add National Holidays
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
