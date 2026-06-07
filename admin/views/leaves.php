<?php defined('ABSPATH') || exit;

$df      = sanitize_text_field($_GET['date_from'] ?? date('Y-m-01'));
$dt      = sanitize_text_field($_GET['date_to']   ?? date('Y-m-t'));
$sid     = absint($_GET['staff_id'] ?? 0);
$status  = sanitize_text_field($_GET['status']    ?? '');
$saved   = isset($_GET['saved'])   ? '<div class="wsa-alert wsa-alert--ok">✅ Leave saved.</div>'    : '';
$deleted = isset($_GET['deleted']) ? '<div class="wsa-alert wsa-alert--ok">✅ Leave deleted.</div>'  : '';
$err     = isset($_GET['error'])   ? '<div class="wsa-alert wsa-alert--err">⚠️ '.esc_html(urldecode($_GET['error'])).'</div>' : '';

$leaves    = WSA_Salary::get_leaves(['date_from'=>$df,'date_to'=>$dt,'staff_id'=>$sid,'status'=>$status]);
$all_staff = WSA_DB::get_all_staff(['status'=>'active','limit'=>999]);

$leave_types = ['Casual','Sick','Earned','Unpaid','Holiday','Compensatory'];
?>
<div class="wsa-wrap">
  <?php echo $saved . $deleted . $err; ?>

  <div class="wsa-page-header">
    <div>
      <h1 class="wsa-title">📋 Leave Management</h1>
      <p class="wsa-sub">Assign & manage staff leaves — approved leaves count as paid present days</p>
    </div>
    <button class="wsa-btn wsa-btn--accent" id="wsa-toggle-leave-form">+ Assign Leave</button>
  </div>

  <!-- Add Leave Form -->
  <div class="wsa-card" id="wsa-leave-form-card" style="display:none;margin-bottom:20px">
    <h3 class="wsa-card-title">Assign Leave / Override Status</h3>
    <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" class="wsa-form"
          style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">
      <?php wp_nonce_field('wsa_leave_action'); ?>
      <input type="hidden" name="action" value="wsa_assign_leave">

      <div class="wsa-field">
        <label>Staff Member *</label>
        <select name="leave_staff_id" required>
          <option value="">— Select —</option>
          <?php foreach ($all_staff as $s): ?>
            <option value="<?php echo $s->id; ?>"><?php echo esc_html($s->name.' ('.$s->employee_id.')'); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="wsa-field">
        <label>Date *</label>
        <input type="date" name="leave_date" value="<?php echo date('Y-m-d'); ?>" required>
      </div>

      <div class="wsa-field">
        <label>Leave Type</label>
        <select name="leave_type">
          <?php foreach ($leave_types as $lt): ?>
            <option value="<?php echo $lt; ?>"><?php echo $lt; ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="wsa-field">
        <label>Status</label>
        <select name="leave_status">
          <option value="approved">✅ Approved (paid, counts as present)</option>
          <option value="pending">⏳ Pending (still absent until approved)</option>
          <option value="rejected">❌ Rejected (counts as absent)</option>
        </select>
        <small class="wsa-hint">Only "Approved" leaves remove absent deduction</small>
      </div>

      <div class="wsa-field" style="grid-column:span 2">
        <label>Notes (optional)</label>
        <input type="text" name="leave_notes" placeholder="Reason / remarks…">
      </div>

      <div class="wsa-field" style="align-self:flex-end">
        <label>&nbsp;</label>
        <button type="submit" class="wsa-btn wsa-btn--accent">💾 Save Leave</button>
      </div>
    </form>

    <div style="margin-top:16px;padding:12px;background:rgba(34,214,138,.06);border:1px solid rgba(34,214,138,.15);border-radius:8px;font-size:13px">
      <strong>💡 How leave override works:</strong><br>
      • If staff <strong>did not scan</strong> that day and you assign an <em>Approved</em> leave → counts as <strong>paid present</strong>, no absent deduction.<br>
      • If staff <strong>already scanned</strong> that day, the scan record takes priority — you can still assign a leave type for record-keeping.<br>
      • <em>Pending</em> or <em>Rejected</em> leaves → still counted as absent in salary.
    </div>
  </div>

  <!-- Filter bar -->
  <div class="wsa-filter-bar">
    <form method="GET" class="wsa-filter-form">
      <input type="hidden" name="page" value="wsa-leaves">
      <div class="wsa-filter-grid">
        <div class="wsa-field"><label>From</label><input type="date" name="date_from" value="<?php echo esc_attr($df); ?>"></div>
        <div class="wsa-field"><label>To</label><input type="date" name="date_to" value="<?php echo esc_attr($dt); ?>"></div>
        <div class="wsa-field">
          <label>Employee</label>
          <select name="staff_id">
            <option value="">All Staff</option>
            <?php foreach ($all_staff as $s): ?><option value="<?php echo $s->id; ?>" <?php selected($sid,$s->id); ?>><?php echo esc_html($s->name); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="wsa-field">
          <label>Status</label>
          <select name="status">
            <option value="">All</option>
            <option value="approved" <?php selected($status,'approved'); ?>>Approved</option>
            <option value="pending"  <?php selected($status,'pending'); ?>>Pending</option>
            <option value="rejected" <?php selected($status,'rejected'); ?>>Rejected</option>
          </select>
        </div>
        <div class="wsa-field" style="align-self:flex-end"><label>&nbsp;</label><button type="submit" class="wsa-btn wsa-btn--accent">Filter</button></div>
      </div>
    </form>
  </div>

  <!-- Leave table -->
  <div class="wsa-card">
    <div class="wsa-table-wrap">
      <table class="wsa-table wsa-table--full">
        <thead>
          <tr><th>Employee</th><th>Dept</th><th>Date</th><th>Leave Type</th><th>Status</th><th>Notes</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($leaves)): ?>
            <tr><td colspan="7" class="wsa-empty">No leave records for selected period.</td></tr>
          <?php else: foreach ($leaves as $l): ?>
          <tr>
            <td>
              <strong><?php echo esc_html($l->staff_name); ?></strong>
              <span class="wsa-muted"><?php echo esc_html($l->emp_code); ?></span>
            </td>
            <td><?php echo esc_html($l->department ?: '—'); ?></td>
            <td><?php echo date('D, d M Y', strtotime($l->leave_date)); ?></td>
            <td><span class="wsa-badge wsa-badge--leave"><?php echo esc_html($l->leave_type); ?></span></td>
            <td>
              <?php if ($l->status === 'approved'): ?>
                <span class="wsa-badge wsa-badge--out">✅ Approved</span>
              <?php elseif ($l->status === 'pending'): ?>
                <span class="wsa-badge wsa-badge--in">⏳ Pending</span>
              <?php else: ?>
                <span class="wsa-badge wsa-badge--absent">❌ Rejected</span>
              <?php endif; ?>
            </td>
            <td><?php echo esc_html($l->notes ?: '—'); ?></td>
            <td class="wsa-actions">
              <!-- Quick approve/reject buttons -->
              <?php if ($l->status !== 'approved'): ?>
                <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=wsa_leave_status&id='.$l->id.'&status=approved'),'wsa_leave_status_'.$l->id); ?>"
                   class="wsa-btn wsa-btn--xs" style="background:#22d68a;color:#000">✅</a>
              <?php endif; ?>
              <?php if ($l->status !== 'rejected'): ?>
                <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=wsa_leave_status&id='.$l->id.'&status=rejected'),'wsa_leave_status_'.$l->id); ?>"
                   class="wsa-btn wsa-btn--xs wsa-btn--danger">❌</a>
              <?php endif; ?>
              <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=wsa_delete_leave&id='.$l->id),'wsa_delete_leave_'.$l->id); ?>"
                 class="wsa-btn wsa-btn--xs wsa-btn--danger" onclick="return confirm('Delete this leave?')">🗑</a>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
document.getElementById('wsa-toggle-leave-form').addEventListener('click', function(){
  var c = document.getElementById('wsa-leave-form-card');
  c.style.display = c.style.display === 'none' ? '' : 'none';
});
</script>
