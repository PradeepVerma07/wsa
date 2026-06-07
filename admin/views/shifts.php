<?php defined('ABSPATH') || exit;
// SHIFTS VIEW
$edit_id = absint($_GET['edit'] ?? 0);
global $wpdb;
$sh = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wsa_shifts WHERE id=%d",$edit_id)) : null;
$shifts = WSA_DB::get_shifts();
$saved  = isset($_GET['saved'])   ? '<div class="wsa-alert wsa-alert--ok">Shift saved.</div>'   : '';
$del    = isset($_GET['deleted']) ? '<div class="wsa-alert wsa-alert--ok">Shift deleted.</div>' : '';
?>
<div class="wsa-wrap">
  <?php echo $saved.$del; ?>
  <div class="wsa-page-header">
    <div><h1 class="wsa-title">Shift Management</h1></div>
    <button class="wsa-btn wsa-btn--accent" id="wsa-toggle-shift-form">+ Add Shift</button>
  </div>
  <div class="wsa-twocol">
    <div class="wsa-card">
      <div class="wsa-table-wrap">
        <table class="wsa-table">
          <thead><tr><th>Name</th><th>Start</th><th>End</th><th>Break</th><th>Std Hrs</th><th>OT After</th><th>Late Grace</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($shifts as $s): ?>
            <tr>
              <td><strong><?php echo esc_html($s->name); ?></strong></td>
              <td><?php echo date('h:i A',strtotime($s->start_time)); ?></td>
              <td><?php echo date('h:i A',strtotime($s->end_time)); ?></td>
              <td><?php echo $s->break_minutes; ?>m</td>
              <td><?php echo $s->standard_hours; ?>h</td>
              <td><?php echo floor($s->overtime_after_mins/60).'h '.($s->overtime_after_mins%60).'m'; ?></td>
              <td><?php echo $s->late_grace_mins; ?>m</td>
              <td class="wsa-actions">
                <a href="<?php echo admin_url('admin.php?page=wsa-shifts&edit='.$s->id); ?>" class="wsa-btn wsa-btn--xs">✏️</a>
                <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=wsa_delete_shift&id='.$s->id),'wsa_delete_shift_'.$s->id); ?>"
                   class="wsa-btn wsa-btn--xs wsa-btn--danger" onclick="return confirm('Delete shift?')">🗑</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="wsa-card" id="wsa-shift-form-card" <?php echo !$edit_id ? 'style="display:none"' : ''; ?>>
      <h3 class="wsa-card-title"><?php echo $edit_id ? 'Edit Shift' : 'Add Shift'; ?></h3>
      <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" class="wsa-form">
        <?php wp_nonce_field('wsa_shift_action'); ?>
        <input type="hidden" name="action"      value="wsa_save_shift">
        <input type="hidden" name="shift_db_id" value="<?php echo $edit_id; ?>">
        <div class="wsa-field"><label>Shift Name</label><input type="text" name="name" required placeholder="e.g. Morning Shift" value="<?php echo esc_attr($sh->name ?? ''); ?>"></div>
        <div class="wsa-field-row">
          <div class="wsa-field"><label>Start Time</label><input type="time" name="start_time" required value="<?php echo esc_attr($sh->start_time ?? '09:00'); ?>"></div>
          <div class="wsa-field"><label>End Time</label><input type="time" name="end_time" required value="<?php echo esc_attr($sh->end_time ?? '18:00'); ?>"></div>
        </div>
        <div class="wsa-field"><label>Break Duration (minutes)</label><input type="number" name="break_minutes" min="0" max="180" value="<?php echo esc_attr($sh->break_minutes ?? 60); ?>"></div>
        <div class="wsa-field"><label>Standard Hours</label><input type="number" name="standard_hours" min="1" max="24" value="<?php echo esc_attr($sh->standard_hours ?? 8); ?>"></div>
        <div class="wsa-field"><label>Overtime after (minutes total work)</label><input type="number" name="overtime_after_mins" min="60" value="<?php echo esc_attr($sh->overtime_after_mins ?? 480); ?>"><small class="wsa-hint">480 = 8h</small></div>
        <div class="wsa-field-row">
          <div class="wsa-field"><label>Late grace (mins)</label><input type="number" name="late_grace_mins" min="0" value="<?php echo esc_attr($sh->late_grace_mins ?? 15); ?>"></div>
          <div class="wsa-field"><label>Early exit grace (mins)</label><input type="number" name="early_exit_grace_mins" min="0" value="<?php echo esc_attr($sh->early_exit_grace_mins ?? 15); ?>"></div>
        </div>
        <div class="wsa-form-btns">
          <button type="submit" class="wsa-btn wsa-btn--accent"><?php echo $edit_id ? 'Update' : 'Add Shift'; ?></button>
          <a href="<?php echo admin_url('admin.php?page=wsa-shifts'); ?>" class="wsa-btn">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
