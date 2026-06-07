<?php defined('ABSPATH') || exit;
$gates    = WSA_DB::get_gates();
$scan_url = get_permalink(get_option('wsa_scanner_page_id'));
$edit_id  = absint($_GET['edit'] ?? 0);
global $wpdb;
$gate_edit = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wsa_gates WHERE id=%d",$edit_id)) : null;
$saved = isset($_GET['saved'])   ? '<div class="wsa-alert wsa-alert--ok">Gate saved.</div>'   : '';
$del   = isset($_GET['deleted']) ? '<div class="wsa-alert wsa-alert--ok">Gate deleted.</div>' : '';
?>
<div class="wsa-wrap">
  <?php echo $saved.$del; ?>
  <div class="wsa-page-header">
    <div>
      <h1 class="wsa-title">QR Codes & Gates</h1>
      <p class="wsa-sub">Print QR codes and post them at factory entry/exit points.</p>
    </div>
    <button class="wsa-btn wsa-btn--accent" id="wsa-toggle-gate-form">+ Add Gate</button>
  </div>

  <!-- Gate QR Grid -->
  <div class="wsa-qr-grid">
    <?php foreach ($gates as $g):
      $url = add_query_arg('gate', $g->token, $scan_url);
    ?>
    <div class="wsa-qr-card">
      <div class="wsa-qr-top">
        <div>
          <div class="wsa-qr-name"><?php echo esc_html($g->name); ?></div>
          <span class="wsa-badge wsa-badge--<?php echo $g->type==='entry'?'in':($g->type==='exit'?'out':'active'); ?>">
            <?php echo ucfirst($g->type); ?>
          </span>
        </div>
        <div class="wsa-qr-acts">
          <a href="<?php echo admin_url('admin.php?page=wsa-qrcodes&edit='.$g->id); ?>" class="wsa-btn wsa-btn--xs">✏️</a>
          <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=wsa_delete_gate&id='.$g->id),'wsa_delete_gate_'.$g->id); ?>"
             class="wsa-btn wsa-btn--xs wsa-btn--danger" onclick="return confirm('Delete gate?')">🗑</a>
        </div>
      </div>
      <div class="wsa-qr-canvas" id="qr-<?php echo $g->id; ?>"></div>
      <div class="wsa-qr-loc"><?php echo esc_html($g->location ?: 'No location'); ?></div>
      <div class="wsa-qr-btns">
        <button class="wsa-btn wsa-btn--sm wsa-qr-print" data-id="<?php echo $g->id; ?>" data-name="<?php echo esc_attr($g->name); ?>">🖨 Print</button>
        <button class="wsa-btn wsa-btn--sm wsa-qr-copy" data-url="<?php echo esc_attr($url); ?>">📋 Copy URL</button>
        <a href="<?php echo esc_url($url); ?>" target="_blank" class="wsa-btn wsa-btn--sm">🔗 Open</a>
      </div>
      <input type="hidden" class="wsa-gate-url" data-id="<?php echo $g->id; ?>" value="<?php echo esc_attr($url); ?>">
    </div>
    <?php endforeach; ?>
    <?php if (empty($gates)): ?><div class="wsa-empty-state">No gates yet. Add your first gate to generate a QR code.</div><?php endif; ?>
  </div>

  <!-- Add/Edit Gate Form -->
  <div class="wsa-card" id="wsa-gate-form-card" <?php echo !$edit_id ? 'style="display:none"' : ''; ?>>
    <h3 class="wsa-card-title"><?php echo $edit_id ? 'Edit Gate' : 'Add Gate'; ?></h3>
    <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" class="wsa-form wsa-form--inline">
      <?php wp_nonce_field('wsa_gate_action'); ?>
      <input type="hidden" name="action"     value="wsa_save_gate">
      <input type="hidden" name="gate_db_id" value="<?php echo $edit_id; ?>">
      <div class="wsa-field"><label>Gate Name</label><input type="text" name="name" required placeholder="e.g. North Gate" value="<?php echo esc_attr($gate_edit->name ?? ''); ?>"></div>
      <div class="wsa-field">
        <label>Type</label>
        <select name="type">
          <option value="both"  <?php selected($gate_edit->type??'both','both'); ?>>Both (IN & OUT)</option>
          <option value="entry" <?php selected($gate_edit->type??'','entry'); ?>>Entry Only</option>
          <option value="exit"  <?php selected($gate_edit->type??'','exit'); ?>>Exit Only</option>
        </select>
      </div>
      <div class="wsa-field"><label>Location</label><input type="text" name="location" placeholder="e.g. Building A entrance" value="<?php echo esc_attr($gate_edit->location ?? ''); ?>"></div>
      <div class="wsa-field">
        <label>Status</label>
        <select name="status">
          <option value="active"   <?php selected($gate_edit->status??'active','active'); ?>>Active</option>
          <option value="inactive" <?php selected($gate_edit->status??'','inactive'); ?>>Inactive</option>
        </select>
      </div>
      <div class="wsa-field" style="justify-content:flex-end"><label>&nbsp;</label>
        <div style="display:flex;gap:8px">
          <button type="submit" class="wsa-btn wsa-btn--accent"><?php echo $edit_id ? 'Update' : 'Add Gate'; ?></button>
          <a href="<?php echo admin_url('admin.php?page=wsa-qrcodes'); ?>" class="wsa-btn">Cancel</a>
        </div>
      </div>
    </form>
  </div>

  <!-- How to use -->
  <div class="wsa-card wsa-howto">
    <h3 class="wsa-card-title">📱 How It Works</h3>
    <div class="wsa-steps">
      <?php $steps = [
        ['01','Print QR', 'Print the QR code and post it at the gate entrance or exit.'],
        ['02','Staff Scans', 'Staff opens phone camera → scans QR → browser opens scan page.'],
        ['03','Enter Credentials', 'Staff enters Employee ID + PIN.'],
        ['04','Auto Records', '1st scan = Check IN · 2nd scan = Check OUT. Time tracked automatically.'],
        ['05','Admin Monitors', 'View real-time attendance in the dashboard.'],
      ];
      foreach ($steps as [$n,$title,$desc]): ?>
      <div class="wsa-step">
        <div class="wsa-step-num"><?php echo $n; ?></div>
        <div><strong><?php echo $title; ?></strong><p><?php echo $desc; ?></p></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
