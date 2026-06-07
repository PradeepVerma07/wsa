<?php defined('ABSPATH') || exit;
$inside    = WSA_DB::get_who_inside();
$stats     = WSA_DB::get_dashboard_stats();
$server_ts = (int)(microtime(true)*1000);
?>
<div class="wsa-wrap">
  <div class="wsa-page-header">
    <div>
      <h1 class="wsa-title">🏭 Who's Inside</h1>
      <p class="wsa-sub">Real-time live view — updates every 15 seconds</p>
    </div>
    <div style="display:flex;align-items:center;gap:12px">
      <div class="wsa-live-indicator"><div class="wsa-live-dot"></div> LIVE</div>
      <span id="wsa-inside-count-badge" class="wsa-pill wsa-pill--lg"><?php echo count($inside); ?> Inside</span>
      <button class="wsa-btn wsa-btn--sm" id="wsa-refresh-inside">↻ Refresh</button>
    </div>
  </div>

  <!-- Stats strip -->
  <div class="wsa-inside-stats">
    <div class="wsa-istat">
      <span class="wsa-istat-val" id="wsa-inside-count"><?php echo count($inside); ?></span>
      <span>Currently Inside</span>
    </div>
    <div class="wsa-istat">
      <span class="wsa-istat-val"><?php echo $stats['checked_out']; ?></span>
      <span>Checked Out Today</span>
    </div>
    <div class="wsa-istat">
      <span class="wsa-istat-val"><?php echo $stats['present_today']; ?></span>
      <span>Total Present</span>
    </div>
    <div class="wsa-istat">
      <!-- FIXED: live server clock via JS — not PHP static time -->
      <span class="wsa-istat-val" id="wsa-server-time" style="font-variant-numeric:tabular-nums">--:--:--</span>
      <span>Current Time</span>
    </div>
  </div>

  <!-- Grid — data-login-ms carries exact server Unix ms for JS timer -->
  <div class="wsa-inside-grid" id="wsa-inside-grid">
    <?php if (empty($inside)): ?>
      <div class="wsa-empty-full">No staff currently inside the factory.</div>
    <?php else: foreach ($inside as $r):
      $login_ts_ms  = $r->login_time ? (int)(strtotime($r->login_time) * 1000) : 0;
      $break_mins   = (float)($r->break_duration_mins ?? 0);
      // If on break, don't count ongoing break in worked time
      $break_secs   = (int)($break_mins * 60);
      if ($r->status === 'BREAK' && !empty($r->break_start)) {
        $break_secs += max(0, intdiv($server_ts, 1000) - strtotime($r->break_start));
      }
      $elapsed_secs = $login_ts_ms ? max(0, intdiv($server_ts - $login_ts_ms, 1000)) : 0;
      $worked_secs  = max(0, $elapsed_secs - $break_secs);
      $eh = floor($worked_secs/3600); $em = floor(($worked_secs%3600)/60); $es = $worked_secs%60;
      $is_break = $r->status === 'BREAK';
    ?>
    <div class="wsa-inside-card<?php echo $is_break ? ' wsa-card-on-break' : ''; ?>">
      <div class="wsa-ic-avatar"><?php echo mb_strtoupper(mb_substr($r->staff_name,0,2)); ?></div>
      <div class="wsa-ic-body">
        <div class="wsa-ic-name">
          <?php echo esc_html($r->staff_name); ?>
          <?php if ($is_break): ?>
            <span class="wsa-badge wsa-badge--break">☕ Break</span>
          <?php endif; ?>
        </div>
        <div class="wsa-ic-meta">
          <?php echo esc_html($r->emp_code); ?> &nbsp;·&nbsp; <?php echo esc_html($r->department ?: 'No Dept'); ?>
        </div>
        <div class="wsa-ic-time">
          <span class="wsa-ic-label">IN:</span>
          <strong><?php echo $r->login_time ? date('h:i A', strtotime($r->login_time)) : '—'; ?></strong>
          <?php if ($is_break && !empty($r->break_start)): ?>
            &nbsp;·&nbsp; <span style="color:#f59e0b">Break since <?php echo date('h:i A', strtotime($r->break_start)); ?></span>
          <?php endif; ?>
        </div>
        <div class="wsa-ic-timer">
          <div class="wsa-live-dot<?php echo $is_break ? ' wsa-dot-amber' : ''; ?>"></div>
          <span class="wsa-live-timer"
            data-login-ms="<?php echo $login_ts_ms; ?>"
            data-break-ms="<?php echo (int)($break_mins * 60 * 1000); ?>"
            data-break-start-ts="<?php echo ($is_break && !empty($r->break_start)) ? (int)(strtotime($r->break_start)*1000) : 0; ?>"
            data-on-break="<?php echo $is_break ? '1' : '0'; ?>">
            <?php printf('%02d:%02d:%02d', $eh, $em, $es); ?>
          </span>
          <?php if ($is_break && $break_mins > 0): ?>
            <small style="margin-left:6px;color:#f59e0b">(<?php echo round($break_mins); ?>m break)</small>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <p class="wsa-sub" style="text-align:center;margin-top:20px" id="wsa-last-refresh">
    Live · <?php echo current_time('h:i:s A'); ?>
  </p>
</div>

<script>
/* Inject server offset so JS timers are accurate from first paint.
   server_ts_ms = server's exact Unix ms at page render time.
   JS will compute offset = server_ts_ms - Date.now() and use it. */
(function(){
  var serverTsMs = <?php echo $server_ts; ?>;
  if (window.syncOffset) window.syncOffset(serverTsMs);
  // If admin.js hasn't loaded yet, store it and let admin.js pick it up
  window._wsaInitServerTs = serverTsMs;
})();
</script>
