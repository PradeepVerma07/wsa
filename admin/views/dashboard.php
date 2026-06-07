<?php defined('ABSPATH') || exit;
$stats  = WSA_DB::get_dashboard_stats();
$today  = WSA_DB::get_attendance(['date_from'=>date('Y-m-d'),'date_to'=>date('Y-m-d'),'limit'=>30]);
$inside = WSA_DB::get_who_inside();
?>
<div class="wsa-wrap">
  <div class="wsa-page-header">
    <div>
      <h1 class="wsa-title">Dashboard</h1>
      <p class="wsa-sub"><?php echo date('l, d F Y'); ?> &nbsp;·&nbsp; <span id="wsa-server-time"><?php echo current_time('h:i:s A'); ?></span></p>
    </div>
    <div style="display:flex;gap:10px">
      <a href="<?php echo admin_url('admin.php?page=wsa-manual'); ?>" class="wsa-btn wsa-btn--accent">+ Manual Entry</a>
      <a href="<?php echo admin_url('admin.php?page=wsa-attendance'); ?>" class="wsa-btn">View All Logs</a>
    </div>
  </div>

  <!-- Stats -->
  <div class="wsa-stats">
    <?php $cards = [
      ['Total Staff',    $stats['total_staff'],    '👥','blue'],
      ['Present Today',  $stats['present_today'],  '✅','green'],
      ['Inside Now',     $stats['inside_now'],      '🏭','orange'],
      ['On Break',       $stats['on_break_now'] ?? 0, '☕','teal'],
      ['Checked Out',    $stats['checked_out'],     '🚪','purple'],
      ['Late Today',     $stats['late_today'],      '⏰','yellow'],
      ['Overtime',       $stats['overtime_today'],  '⚡','red'],
      ['Absent',         max(0,$stats['total_staff'] - $stats['present_today']), '❌','grey'],
    ];
    foreach ($cards as [$label,$val,$icon,$color]): ?>
    <div class="wsa-stat wsa-stat--<?php echo $color; ?>">
      <div class="wsa-stat__icon"><?php echo $icon; ?></div>
      <div class="wsa-stat__val" id="wsa-s-<?php echo sanitize_title($label); ?>"><?php echo max(0,$val); ?></div>
      <div class="wsa-stat__label"><?php echo $label; ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="wsa-grid-2">
    <!-- Who's Inside Live -->
    <div class="wsa-card">
      <div class="wsa-card-head">
        <h2>🏭 Currently Inside <span class="wsa-pill"><?php echo count($inside); ?></span></h2>
        <button class="wsa-btn wsa-btn--sm" id="wsa-refresh-inside">↻</button>
      </div>
      <div id="wsa-inside-list">
        <?php if (empty($inside)): ?>
          <div class="wsa-empty">No one inside right now.</div>
        <?php else: foreach ($inside as $r): ?>
        <div class="wsa-inside-row">
          <div class="wsa-inside-av"><?php echo mb_substr($r->staff_name, 0, 1); ?></div>
          <div class="wsa-inside-info">
            <strong><?php echo esc_html($r->staff_name); ?></strong>
            <small><?php echo esc_html($r->emp_code); ?> &nbsp;·&nbsp; <?php echo esc_html($r->department ?: '—'); ?></small>
          </div>
          <div class="wsa-inside-time">
            <div class="wsa-live-dot"></div>
            <span class="wsa-live-timer" data-login="<?php echo esc_attr($r->login_time); ?>"><?php echo WSA_Attendance::live_hours($r->login_time); ?></span>
            <small><?php echo date('h:i A', strtotime($r->login_time)); ?></small>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Today's Activity -->
    <div class="wsa-card">
      <div class="wsa-card-head"><h2>📋 Today's Activity</h2></div>
      <div class="wsa-table-wrap">
        <table class="wsa-table">
          <thead><tr><th>Name</th><th>IN</th><th>OUT</th><th>Hours</th><th>Status</th></tr></thead>
          <tbody>
            <?php if (empty($today)): ?>
              <tr><td colspan="5" class="wsa-empty">No activity today yet.</td></tr>
            <?php else: foreach ($today as $r): ?>
            <tr>
              <td>
                <strong><?php echo esc_html($r->staff_name); ?></strong>
                <small class="wsa-muted"><?php echo esc_html($r->emp_code); ?></small>
              </td>
              <td><span class="wsa-time-in"><?php echo $r->login_time  ? date('h:i A',strtotime($r->login_time))  : '—'; ?></span></td>
              <td><span class="wsa-time-out"><?php echo $r->logout_time ? date('h:i A',strtotime($r->logout_time)) : '—'; ?></span></td>
              <td><?php echo WSA_Attendance::fmt($r->total_hours); ?></td>
              <td>
                <?php echo WSA_Attendance::status_badge($r->status,$r->type); ?>
                <?php if ($r->status === 'BREAK' && !empty($r->break_start)): ?>
                  <small class="wsa-muted"> since <?php echo date('h:i A', strtotime($r->break_start)); ?></small>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
