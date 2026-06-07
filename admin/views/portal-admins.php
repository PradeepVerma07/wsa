<?php defined('ABSPATH') || exit; global $wpdb;
$editing = null;
if (!empty($_GET['edit'])) {
  $editing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wsa_portal_admins WHERE id=%d", absint($_GET['edit'])));
}
$admins = $wpdb->get_results("SELECT id,username,name,email,role,status,last_login,created_at FROM {$wpdb->prefix}wsa_portal_admins ORDER BY role DESC,id ASC");
?>
<div class="wrap wsa-admin-wrap wsa-dark-admin">
  <h1>Portal Admins</h1>
  <p>Add / edit / delete frontend Admin Portal users from WordPress admin.</p>
  <?php if (!empty($_GET['saved'])): ?><div class="notice notice-success"><p>Saved successfully.</p></div><?php endif; ?>
  <?php if (!empty($_GET['deleted'])): ?><div class="notice notice-success"><p>Deleted successfully.</p></div><?php endif; ?>
  <?php if (!empty($_GET['error'])): ?><div class="notice notice-error"><p><?php echo esc_html($_GET['error']); ?></p></div><?php endif; ?>

  <div class="wsa-card">
    <h2><?php echo $editing ? 'Edit Admin' : 'Add New Admin'; ?></h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wsa-form-grid">
      <?php wp_nonce_field('wsa_portal_admin_action'); ?>
      <input type="hidden" name="action" value="wsa_save_portal_admin">
      <input type="hidden" name="admin_id" value="<?php echo esc_attr($editing->id ?? 0); ?>">
      <label>Username<br><input name="username" value="<?php echo esc_attr($editing->username ?? ''); ?>" <?php echo $editing ? 'readonly' : 'required'; ?>></label>
      <label>Name<br><input name="name" value="<?php echo esc_attr($editing->name ?? ''); ?>"></label>
      <label>Email<br><input type="email" name="email" value="<?php echo esc_attr($editing->email ?? ''); ?>"></label>
      <label>Password<br><input type="password" name="password" placeholder="<?php echo $editing ? 'Leave blank to keep same' : 'Minimum 6 characters'; ?>" <?php echo $editing ? '' : 'required'; ?>></label>
      <label>Role<br><select name="role"><option value="admin" <?php selected($editing->role ?? '', 'admin'); ?>>Admin</option><option value="super_admin" <?php selected($editing->role ?? '', 'super_admin'); ?>>Super Admin</option></select></label>
      <label>Status<br><select name="status"><option value="active" <?php selected($editing->status ?? 'active', 'active'); ?>>Active</option><option value="inactive" <?php selected($editing->status ?? '', 'inactive'); ?>>Inactive</option></select></label>
      <p><button class="button button-primary"><?php echo $editing ? 'Update Admin' : 'Add Admin'; ?></button> <?php if ($editing): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wsa-portal-admins')); ?>">Cancel</a><?php endif; ?></p>
    </form>
  </div>

  <div class="wsa-card">
    <h2>Admin Users</h2>
    <table class="widefat striped wsa-table">
      <thead><tr><th>Username</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($admins as $a): ?>
        <tr>
          <td><strong><?php echo esc_html($a->username); ?></strong></td>
          <td><?php echo esc_html($a->name); ?></td>
          <td><?php echo esc_html($a->email); ?></td>
          <td><?php echo esc_html($a->role); ?></td>
          <td><?php echo esc_html($a->status); ?></td>
          <td><?php echo esc_html($a->last_login ?: '—'); ?></td>
          <td>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wsa-portal-admins&edit=' . $a->id)); ?>">Edit</a>
            <a class="button button-link-delete" onclick="return confirm('Delete this admin?')" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wsa_delete_portal_admin&id=' . $a->id), 'wsa_delete_portal_admin_' . $a->id)); ?>">Delete</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$admins): ?><tr><td colspan="7">No portal admin found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
