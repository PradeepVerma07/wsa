<?php defined('ABSPATH') || exit;
$df     = sanitize_text_field($_GET['date_from']   ?? date('Y-m-01'));
$dt     = sanitize_text_field($_GET['date_to']     ?? date('Y-m-d'));
$sid    = absint($_GET['staff_id']   ?? 0);
$dept   = sanitize_text_field($_GET['department']  ?? '');
$rows   = WSA_DB::get_attendance(['date_from'=>$df,'date_to'=>$dt,'staff_id'=>$sid,'department'=>$dept,'limit'=>500,'include_leaves'=>true]);
$staff  = WSA_DB::get_all_staff(['status'=>'active']);
$depts  = WSA_DB::get_departments();
$saved  = isset($_GET['saved'])   ? '<div class="wsa-alert wsa-alert--ok">Record updated.</div>'  : '';
$deld   = isset($_GET['deleted']) ? '<div class="wsa-alert wsa-alert--ok">Record deleted.</div>'  : '';
?>
<div class="wsa-wrap">
  <?php echo $saved.$deld; ?>
  <div class="wsa-page-header">
    <div>
      <h1 class="wsa-title">Attendance Logs</h1>
      <p class="wsa-sub"><?php echo count($rows); ?> records</p>
    </div>
    <div style="display:flex;gap:10px">
      <a href="<?php echo admin_url('admin.php?page=wsa-manual'); ?>" class="wsa-btn wsa-btn--accent">+ Manual Entry</a>
      <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=wsa_run_absent_cron&date='.date('Y-m-d',strtotime('yesterday'))),'wsa_admin'); ?>"
         class="wsa-btn" style="background:rgba(239,68,68,.15);color:#fca5a5"
         onclick="return confirm('Mark all staff without attendance yesterday as ABSENT?')">
         📋 Mark Yesterday Absent
      </a>
    </div>
  </div>

  <!-- Filters + Export -->
  <div class="wsa-filter-bar">
    <form method="GET" class="wsa-filter-form">
      <input type="hidden" name="page" value="wsa-attendance">
      <div class="wsa-filter-grid">
        <div class="wsa-field"><label>From</label><input type="date" name="date_from" value="<?php echo esc_attr($df); ?>"></div>
        <div class="wsa-field"><label>To</label><input type="date" name="date_to" value="<?php echo esc_attr($dt); ?>"></div>
        <div class="wsa-field">
          <label>Department</label>
          <select name="department">
            <option value="">All Depts</option>
            <?php foreach ($depts as $d): ?><option value="<?php echo esc_attr($d); ?>" <?php selected($dept,$d); ?>><?php echo esc_html($d); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="wsa-field">
          <label>Employee</label>
          <select name="staff_id">
            <option value="">All Staff</option>
            <?php foreach ($staff as $s): ?><option value="<?php echo $s->id; ?>" <?php selected($sid,$s->id); ?>><?php echo esc_html($s->name.' ('.$s->employee_id.')'); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="wsa-field" style="justify-content:flex-end">
          <label>&nbsp;</label><button type="submit" class="wsa-btn wsa-btn--accent">Filter</button>
        </div>
      </div>
    </form>
    <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" class="wsa-export-btns">
      <?php wp_nonce_field('wsa_export_action'); ?>
      <input type="hidden" name="action"     value="wsa_export">
      <input type="hidden" name="date_from"  value="<?php echo esc_attr($df); ?>">
      <input type="hidden" name="date_to"    value="<?php echo esc_attr($dt); ?>">
      <input type="hidden" name="staff_id"   value="<?php echo esc_attr($sid); ?>">
      <input type="hidden" name="department" value="<?php echo esc_attr($dept); ?>">
      <button type="submit" name="format" value="csv" class="wsa-btn">⬇ CSV</button>
      <button type="submit" name="format" value="pdf" class="wsa-btn">🖨 PDF</button>
    </form>
  </div>

  <!-- Table -->
  <div class="wsa-card">
    <div class="wsa-table-wrap">
      <table class="wsa-table wsa-table--full">
        <thead><tr>
          <th>Employee</th><th>Dept</th><th>Date</th><th>Check IN</th><th>Check OUT</th>
          <th>Hours</th>
              <th>Break</th><th>Overtime</th><th>Type</th><th>Late</th><th>Status</th><th>Actions</th>
        </tr></thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="11" class="wsa-empty">No records for selected filters.</td></tr>
          <?php else: foreach ($rows as $r):
            $display_hours = (float)($r->total_hours ?? 0);
            $display_ot    = (float)($r->overtime_hours ?? 0);
            $display_break = (float)($r->break_duration_mins ?? 0);
            if (!empty($r->login_time) && !empty($r->logout_time)) {
                $staff_obj = WSA_DB::get_staff((int)$r->staff_id);
                if ($staff_obj) {
                    $break_for_calc = $display_break > 0 ? $display_break : WSA_Attendance::scheduled_break_mins($r->login_time, $r->logout_time);
                    $calc = WSA_Attendance::calculate($r->login_time, $r->logout_time, $staff_obj, $break_for_calc);
                    $display_hours = (float)$calc[0];
                    $display_ot    = (float)$calc[1];
                    $display_break = WSA_Attendance::skips_scheduled_break_after_9pm($r->login_time, $r->logout_time) ? 0.0 : $break_for_calc;
                }
            }
          ?>
          <tr>
            <td>
              <strong><?php echo esc_html($r->staff_name); ?></strong>
              <span class="wsa-muted"><?php echo esc_html($r->emp_code); ?></span>
            </td>
            <td><?php echo esc_html($r->department ?: '—'); ?></td>
            <td><?php echo date('d M Y', strtotime($r->att_date)); ?></td>
            <td><span class="wsa-time-in"><?php echo $r->login_time  ? date('h:i A',strtotime($r->login_time))  : '—'; ?></span></td>
            <td><?php
              if ($r->logout_time) {
                echo '<span class="wsa-time-out">'.date('h:i A',strtotime($r->logout_time)).'</span>';
              } elseif ($r->status === 'BREAK') {
                echo '<span class="wsa-badge wsa-badge--break">☕ On Break</span>';
              } else {
                echo '<span class="wsa-badge wsa-badge--in">Inside</span>';
              }
            ?></td>
            <td><?php echo $r->status === 'LEAVE' ? '—' : WSA_Attendance::fmt($display_hours); ?></td>
            <td><?php
              $bm = $display_break;
              if ($bm > 0) {
                $bh = floor($bm/60); $bmm = round(fmod($bm,60));
                echo '<span style="color:#f59e0b">'.($bh>0?$bh.'h ':'').round($bmm).'m</span>';
              } else { echo '—'; }
            ?></td>
            <td><?php echo $display_ot > 0 ? '<span class="wsa-ot">'.WSA_Attendance::fmt($display_ot).'</span>' : '—'; ?></td>
            <td><span class="wsa-type-badge wsa-type-badge--<?php echo strtolower($r->type); ?>"><?php echo $r->type === 'LEAVE' ? esc_html($r->leave_type ?? 'LEAVE') : esc_html($r->type); ?></span></td>
            <td><?php echo ($r->type === 'SCAN' && $r->is_late) ? '<span class="wsa-flag wsa-flag--late">Late</span>' : '—'; ?></td>
            <td><?php echo $r->status === 'LEAVE' ? '<span class="wsa-badge wsa-badge--leave">On Leave</span>' : WSA_Attendance::status_badge($r->status,$r->type); ?></td>
            <td class="wsa-actions">
              <?php if ($r->status === 'LEAVE'): ?>
                <span class="wsa-muted">Managed in Leaves</span>
              <?php else: ?>
              <button class="wsa-btn wsa-btn--xs wsa-edit-rec"
                data-id="<?php echo $r->id; ?>"
                data-in="<?php echo $r->login_time  ? date('H:i', strtotime($r->login_time))  : ''; ?>"
                data-out="<?php echo $r->logout_time ? date('H:i', strtotime($r->logout_time)) : ''; ?>"
                data-notes="<?php echo esc_attr($r->notes ?? ''); ?>">✏️ Edit</button>
              <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=wsa_delete_attendance&id='.$r->id),'wsa_delete_att_'.$r->id); ?>"
                 class="wsa-btn wsa-btn--xs wsa-btn--danger"
                 onclick="return confirm('Delete this record?')">🗑</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div id="wsa-modal" class="wsa-modal" style="display:none">
  <div class="wsa-modal-backdrop"></div>
  <div class="wsa-modal-box">
    <div class="wsa-modal-head"><h3>Edit Attendance Record</h3><button class="wsa-modal-close">✕</button></div>
    <input type="hidden" id="wsa-edit-id">
    <div class="wsa-field"><label>Check IN Time</label><input type="time" id="wsa-edit-in"></div>
    <div class="wsa-field" style="margin-top:12px"><label>Check OUT Time</label><input type="time" id="wsa-edit-out"></div>
    <div class="wsa-field" style="margin-top:12px"><label>Notes</label><textarea id="wsa-edit-notes" rows="3"></textarea></div>
    <div class="wsa-modal-foot">
      <button id="wsa-save-rec" class="wsa-btn wsa-btn--accent">Save Changes</button>
      <button class="wsa-btn wsa-modal-close">Cancel</button>
    </div>
  </div>
</div>
