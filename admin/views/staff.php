<?php
defined('ABSPATH') || exit;

$edit_id = absint($_GET['edit'] ?? 0);
$emp     = $edit_id ? WSA_DB::get_staff($edit_id) : null;
$all     = WSA_DB::get_all_staff();
$shifts  = WSA_DB::get_shifts();
$search  = sanitize_text_field($_GET['s']    ?? '');
$dept_f  = sanitize_text_field($_GET['dept'] ?? '');
$depts   = WSA_DB::get_departments();

if ($search || $dept_f) {
    $all = WSA_DB::get_all_staff(['search' => $search, 'department' => $dept_f]);
}

$saved   = isset($_GET['saved'])   ? '<div class="wsa-alert wsa-alert--ok">✅ Staff saved successfully.</div>'   : '';
$deleted = isset($_GET['deleted']) ? '<div class="wsa-alert wsa-alert--ok">✅ Staff member deleted.</div>'       : '';
$err     = isset($_GET['error'])   ? '<div class="wsa-alert wsa-alert--err">❌ '.esc_html(urldecode($_GET['error'])).'</div>' : '';

$total_active   = count(array_filter($all, fn($s) => $s->status === 'active'));
$total_inactive = count(array_filter($all, fn($s) => $s->status === 'inactive'));
$total_pending  = count(array_filter($all, fn($s) => $s->status === 'pending'));
?>
<div class="wsa-wrap">

  <?php echo $saved . $deleted . $err; ?>

  <div class="wsa-page-header">
    <div>
      <h1 class="wsa-title">Staff Management</h1>
      <p class="wsa-sub">
        <?php echo count($all); ?> total —
        <span style="color:#16a34a;font-weight:700;"><?php echo $total_active; ?> active</span> ·
        <span style="color:#dc2626;font-weight:700;"><?php echo $total_inactive; ?> inactive</span>
        <?php if ($total_pending): ?> · <span style="color:#d97706;font-weight:700;"><?php echo $total_pending; ?> pending</span><?php endif; ?>
      </p>
    </div>
    <button class="wsa-btn wsa-btn--accent" id="wsa-toggle-form">
      <?php echo $edit_id ? '✏️ Edit Staff' : '+ Add Staff'; ?>
    </button>
  </div>

  <!-- Search & Filter -->
  <div class="wsa-filter-bar" style="margin-bottom:18px;">
    <form method="GET" class="wsa-filter-form">
      <input type="hidden" name="page" value="wsa-staff">
      <div class="wsa-filter-grid">
        <div class="wsa-field">
          <label>Search</label>
          <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Name, ID, or phone…">
        </div>
        <div class="wsa-field">
          <label>Department</label>
          <select name="dept">
            <option value="">All Departments</option>
            <?php foreach ($depts as $d): ?>
              <option value="<?php echo esc_attr($d); ?>" <?php selected($dept_f, $d); ?>><?php echo esc_html($d); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="wsa-field" style="justify-content:flex-end;">
          <label>&nbsp;</label>
          <button type="submit" class="wsa-btn">🔍 Search</button>
          <?php if ($search || $dept_f): ?>
            <a href="<?php echo admin_url('admin.php?page=wsa-staff'); ?>" class="wsa-btn" style="margin-left:6px;">✕ Clear</a>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>

  <div class="wsa-twocol">

    <!-- Staff Table -->
    <div class="wsa-card">
      <div class="wsa-table-wrap">
        <table class="wsa-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Emp ID</th>
              <th>Name</th>
              <th>Department</th>
              <th>Shift</th>
              <th>Phone</th>
              <th>Email</th>
              <th>Status</th>
              <th>Face</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($all)): ?>
              <tr>
                <td colspan="10" class="wsa-empty">
                  No staff found.
                  <?php if ($search || $dept_f): ?>
                    <a href="<?php echo admin_url('admin.php?page=wsa-staff'); ?>">Clear search</a> or
                  <?php endif; ?>
                  <button class="wsa-btn wsa-btn--xs wsa-btn--accent" id="wsa-toggle-form-empty">+ Add first staff member</button>
                </td>
              </tr>
            <?php else: ?>
              <?php
              global $wpdb;
              $face_registered = [];
              $face_rows = $wpdb->get_results("SELECT staff_id FROM {$wpdb->prefix}wsa_face_profiles WHERE status='registered'");
              foreach ($face_rows as $fr) $face_registered[$fr->staff_id] = true;
              ?>
              <?php foreach ($all as $i => $s): ?>
              <tr>
                <td style="color:#94a3b8;font-size:12px;"><?php echo $i+1; ?></td>
                <td><code class="wsa-code"><?php echo esc_html($s->employee_id); ?></code></td>
                <td>
                  <div style="display:flex;align-items:center;gap:8px;">
                    <?php if (!empty($s->photo_url)): ?>
                      <img src="<?php echo esc_url($s->photo_url); ?>" style="width:32px;height:32px;border-radius:8px;object-fit:cover;border:1px solid #e5e7eb;" alt="">
                    <?php else: ?>
                      <span style="width:32px;height:32px;border-radius:8px;background:#f1f5f9;display:grid;place-items:center;font-size:16px;border:1px solid #e5e7eb;">👤</span>
                    <?php endif; ?>
                    <div>
                      <strong><?php echo esc_html($s->name); ?></strong>
                      <?php if (!empty($s->email)): ?>
                        <br><span class="wsa-muted" style="font-size:12px;"><?php echo esc_html($s->email); ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td><?php echo esc_html($s->department ?: '—'); ?></td>
                <td><?php echo esc_html($s->shift_name ?: '—'); ?></td>
                <td><?php echo esc_html($s->phone ?: '—'); ?></td>
                <td><?php echo esc_html($s->email ?: '—'); ?></td>
                <td>
                  <span class="wsa-badge wsa-badge--<?php echo esc_attr($s->status); ?>">
                    <?php echo ucfirst($s->status); ?>
                  </span>
                </td>
                <td>
                  <?php if (isset($face_registered[$s->id])): ?>
                    <span style="color:#16a34a;font-weight:700;font-size:12px;">✅ Registered</span>
                  <?php else: ?>
                    <a href="<?php echo admin_url('admin.php?page=wsa-face-attendance'); ?>" style="color:#dc2626;font-size:12px;font-weight:700;text-decoration:none;">❌ Register →</a>
                  <?php endif; ?>
                </td>
                <td class="wsa-actions">
                  <a href="<?php echo admin_url('admin.php?page=wsa-staff&edit=' . $s->id); ?>"
                     class="wsa-btn wsa-btn--xs" title="Edit">✏️ Edit</a>
                  <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=wsa_delete_staff&id=' . $s->id), 'wsa_delete_staff_' . $s->id); ?>"
                     class="wsa-btn wsa-btn--xs wsa-btn--danger"
                     onclick="return confirm('Delete <?php echo esc_js($s->name); ?> and all their attendance records? This cannot be undone.')">
                     🗑 Delete
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Add / Edit Form -->
    <div class="wsa-card wsa-staff-form" id="wsa-staff-form" <?php echo !$edit_id ? 'style="display:none"' : ''; ?>>
      <h3 class="wsa-card-title"><?php echo $edit_id ? '✏️ Edit Staff: ' . esc_html($emp->name ?? '') : '➕ Add New Staff'; ?></h3>

      <?php if ($edit_id && !$emp): ?>
        <p style="color:#dc2626;">Staff not found.</p>
      <?php else: ?>

      <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" class="wsa-form" id="wsa-add-staff-form">
        <?php wp_nonce_field('wsa_staff_action'); ?>
        <input type="hidden" name="action"      value="wsa_save_staff">
        <input type="hidden" name="staff_db_id" value="<?php echo $edit_id; ?>">

        <!-- Employee ID -->
        <div class="wsa-field">
          <label for="f_employee_id">Employee ID <span class="req" style="color:#dc2626;">*</span></label>
          <input type="text" id="f_employee_id" name="employee_id" required maxlength="50"
                 placeholder="e.g. EMP-001"
                 value="<?php echo esc_attr($emp->employee_id ?? ''); ?>">
          <small class="wsa-hint">Must be unique. Used for QR scan login.</small>
        </div>

        <!-- Full Name -->
        <div class="wsa-field">
          <label for="f_name">Full Name <span class="req" style="color:#dc2626;">*</span></label>
          <input type="text" id="f_name" name="name" required maxlength="120"
                 placeholder="e.g. John Smith"
                 value="<?php echo esc_attr($emp->name ?? ''); ?>">
        </div>

        <!-- Department -->
        <div class="wsa-field">
          <label for="f_dept">Department</label>
          <input type="text" id="f_dept" name="department" maxlength="100" list="dept-list"
                 placeholder="e.g. Production, HR, Sales"
                 value="<?php echo esc_attr($emp->department ?? ''); ?>">
          <datalist id="dept-list">
            <?php foreach ($depts as $d): ?>
              <option value="<?php echo esc_attr($d); ?>">
            <?php endforeach; ?>
          </datalist>
        </div>

        <!-- Phone -->
        <div class="wsa-field">
          <label for="f_phone">Phone</label>
          <input type="tel" id="f_phone" name="phone" maxlength="30"
                 placeholder="+1 555 000 0000"
                 value="<?php echo esc_attr($emp->phone ?? ''); ?>">
        </div>

        <!-- Email -->
        <div class="wsa-field">
          <label for="f_email">Email</label>
          <input type="email" id="f_email" name="email" maxlength="150"
                 placeholder="john@company.com"
                 value="<?php echo esc_attr($emp->email ?? ''); ?>">
        </div>

        <!-- Photo URL -->
        <div class="wsa-field">
          <label for="f_photo_url">Photo URL <span class="wsa-hint">(optional)</span></label>
          <input type="url" id="f_photo_url" name="photo_url" maxlength="500"
                 placeholder="https://… (link to staff photo)"
                 value="<?php echo esc_attr($emp->photo_url ?? ''); ?>">
          <?php if (!empty($emp->photo_url)): ?>
            <div style="margin-top:8px;">
              <img src="<?php echo esc_url($emp->photo_url); ?>"
                   style="width:64px;height:64px;border-radius:10px;object-fit:cover;border:1px solid #e5e7eb;"
                   alt="Current photo">
            </div>
          <?php endif; ?>
          <small class="wsa-hint">Paste a direct image URL. Shown in face scanner results and staff list.</small>
        </div>

        <!-- Shift -->
        <div class="wsa-field">
          <label for="f_shift">Shift</label>
          <select id="f_shift" name="shift_id">
            <option value="">— No Shift Assigned —</option>
            <?php foreach ($shifts as $sh): ?>
              <option value="<?php echo $sh->id; ?>" <?php selected($emp->shift_id ?? '', $sh->id); ?>>
                <?php echo esc_html($sh->name); ?>
                (<?php echo esc_html(substr($sh->start_time,0,5) . ' – ' . substr($sh->end_time,0,5)); ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($shifts)): ?>
            <small class="wsa-hint" style="color:#d97706;">⚠️ No shifts found. <a href="<?php echo admin_url('admin.php?page=wsa-shifts'); ?>">Create a shift first</a>.</small>
          <?php endif; ?>
        </div>

        <!-- PIN -->
        <div class="wsa-field">
          <label for="f_pin">PIN (4–6 digits)</label>
          <input type="text" id="f_pin" name="pin" maxlength="6" pattern="[0-9]{4,6}"
                 inputmode="numeric"
                 placeholder="<?php echo $edit_id ? 'Leave blank to keep current PIN' : '1234'; ?>">
          <?php if ($edit_id): ?>
            <small class="wsa-hint">Leave blank to keep the current PIN.</small>
          <?php else: ?>
            <small class="wsa-hint">Default: 1234 — advise staff to remember this for QR attendance.</small>
          <?php endif; ?>
        </div>

        <!-- Status (edit only) -->
        <div class="wsa-field">
          <label for="f_status">Status</label>
          <select id="f_status" name="status">
            <option value="active"   <?php selected($emp->status ?? 'active', 'active'); ?>>✅ Active</option>
            <option value="inactive" <?php selected($emp->status ?? '', 'inactive'); ?>>❌ Inactive</option>
            <option value="pending"  <?php selected($emp->status ?? '', 'pending'); ?>>⏳ Pending Approval</option>
          </select>
        </div>

        <!-- Buttons -->
        <div class="wsa-form-btns" style="display:flex;gap:10px;margin-top:20px;flex-wrap:wrap;">
          <button type="submit" class="wsa-btn wsa-btn--accent">
            <?php echo $edit_id ? '💾 Update Staff' : '➕ Add Staff'; ?>
          </button>
          <a href="<?php echo admin_url('admin.php?page=wsa-staff'); ?>" class="wsa-btn">Cancel</a>
          <?php if ($edit_id): ?>
            <a href="<?php echo admin_url('admin.php?page=wsa-face-attendance'); ?>"
               class="wsa-btn" style="margin-left:auto;">🔬 Register Face →</a>
          <?php endif; ?>
        </div>
      </form>

      <?php endif; ?>
    </div>
  </div>
</div>

<script>
// Show form immediately if we're on add mode and button is clicked
document.addEventListener('DOMContentLoaded', function () {
    var emptyBtn = document.getElementById('wsa-toggle-form-empty');
    if (emptyBtn) {
        emptyBtn.addEventListener('click', function (e) {
            e.preventDefault();
            document.getElementById('wsa-staff-form').style.display = '';
            document.getElementById('wsa-toggle-form').textContent = 'Cancel';
        });
    }
    // Auto-open form on edit mode
    <?php if ($edit_id): ?>
    document.getElementById('wsa-staff-form').style.display = '';
    <?php endif; ?>
});
</script>
