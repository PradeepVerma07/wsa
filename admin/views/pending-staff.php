<?php defined('ABSPATH') || exit;
global $wpdb;
$pending = $wpdb->get_results("SELECT s.*, sh.name AS shift_name FROM {$wpdb->prefix}wsa_staff s LEFT JOIN {$wpdb->prefix}wsa_shifts sh ON s.shift_id=sh.id WHERE s.status='pending' ORDER BY s.created_at DESC");
$approved = isset($_GET['approved']) ? '<div class="wsa-alert wsa-alert--ok">✅ Staff member approved and can now log in.</div>' : '';
$rejected = isset($_GET['rejected']) ? '<div class="wsa-alert wsa-alert--ok">❌ Registration rejected.</div>' : '';
?>
<div class="wsa-wrap">
  <?php echo $approved . $rejected; ?>
  <div class="wsa-page-header">
    <div>
      <h1 class="wsa-title">Pending Staff Registrations</h1>
      <p class="wsa-sub">Staff who registered via the self-service form and are awaiting approval</p>
    </div>
    <span class="wsa-badge wsa-badge--in" style="font-size:16px;padding:8px 16px"><?php echo count($pending); ?> pending</span>
  </div>

  <?php if (empty($pending)): ?>
    <div class="wsa-card">
      <div class="wsa-empty" style="padding:40px 0;text-align:center">
        <div style="font-size:40px;margin-bottom:12px">✅</div>
        <p>No pending registrations. All clear!</p>
        <p class="wsa-muted">When staff register via the self-service page, they appear here for approval.</p>
      </div>
    </div>
  <?php else: ?>
  <div class="wsa-card">
    <div class="wsa-table-wrap">
      <table class="wsa-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Employee ID</th>
            <th>Department</th>
            <th>Phone</th>
            <th>Email</th>
            <th>Registered</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pending as $s): ?>
          <tr>
            <td><strong><?php echo esc_html($s->name); ?></strong></td>
            <td><code class="wsa-code"><?php echo esc_html($s->employee_id); ?></code></td>
            <td><?php echo esc_html($s->department ?: '—'); ?></td>
            <td><?php echo esc_html($s->phone ?: '—'); ?></td>
            <td><?php echo esc_html($s->email ?: '—'); ?></td>
            <td><?php echo date('d M Y, h:i A', strtotime($s->created_at)); ?></td>
            <td class="wsa-actions" style="display:flex;gap:6px;flex-wrap:wrap">
              <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=wsa_approve_staff&id='.$s->id), 'wsa_approve_staff_'.$s->id); ?>"
                 class="wsa-btn wsa-btn--xs" style="background:#22D68A;color:#000"
                 onclick="return confirm('Approve <?php echo esc_js($s->name); ?> and allow them to log in?')">
                ✅ Approve
              </a>
              <a href="<?php echo admin_url('admin.php?page=wsa-staff&edit='.$s->id); ?>"
                 class="wsa-btn wsa-btn--xs">✏️ Edit</a>
              <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=wsa_reject_staff&id='.$s->id), 'wsa_reject_staff_'.$s->id); ?>"
                 class="wsa-btn wsa-btn--xs wsa-btn--danger"
                 onclick="return confirm('Reject and deactivate this registration?')">
                ❌ Reject
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="wsa-card" style="margin-top:20px">
    <h3 class="wsa-card-title">ℹ️ How Self-Registration Works</h3>
    <ol style="line-height:2">
      <li>Staff visit the <strong>Staff Register</strong> page and fill in their details.</li>
      <li>Their account is created with <strong>Pending</strong> status — they cannot log in yet.</li>
      <li>Admin reviews and clicks <strong>Approve</strong> to activate the account.</li>
      <li>Once approved, staff can log in via the <strong>Staff Login</strong> page and view their portal.</li>
      <li>Staff can still mark attendance via <strong>QR scan</strong> at the gate regardless of portal login status.</li>
    </ol>
    <p><strong>Portal pages:</strong>
      <?php
        $login_url    = get_permalink(get_option('wsa_login_page_id'))    ?: home_url('/staff-login/');
        $register_url = get_permalink(get_option('wsa_register_page_id')) ?: home_url('/staff-register/');
        $portal_url   = get_permalink(get_option('wsa_portal_page_id'))   ?: home_url('/staff-portal/');
      ?>
      <a href="<?php echo esc_url($login_url); ?>" target="_blank">🔐 Login</a> ·
      <a href="<?php echo esc_url($register_url); ?>" target="_blank">📝 Register</a> ·
      <a href="<?php echo esc_url($portal_url); ?>" target="_blank">📊 Staff Portal</a>
    </p>
  </div>
</div>
