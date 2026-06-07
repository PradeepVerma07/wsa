<?php defined('ABSPATH') || exit; ?>
<div class="wsa-portal-wrap" id="wsa-portal-app">

  <!-- Loading state -->
  <div class="wsa-portal-loading" id="portal-loading">
    <div class="wsa-portal-spinner"></div>
    <p>Loading your dashboard…</p>
  </div>

  <!-- Not authenticated -->
  <div class="wsa-portal-auth-prompt" id="portal-auth-prompt" style="display:none">
    <div class="wsa-auth-logo">🔐</div>
    <h3 id="portal-auth-title">Please Login</h3>
    <p id="portal-auth-msg">Log in to view your attendance dashboard.</p>
    <a id="portal-goto-login" href="#" class="wsa-auth-btn">🔐 Go to Login</a>
  </div>

  <!-- Main dashboard (shown after auth) -->
  <div id="portal-content" style="display:none">

    <!-- Header -->
    <div class="wsa-portal-header">
      <div class="wsa-portal-greeting">
        <div class="wsa-portal-avatar" id="p-avatar">👤</div>
        <div>
          <div class="wsa-portal-name" id="p-name">—</div>
          <div class="wsa-portal-meta" id="p-meta">—</div>
        </div>
      </div>
      <div class="wsa-portal-header-right">
        <div class="wsa-portal-clock" id="p-clock">--:--:--</div>
        <div class="wsa-portal-date"  id="p-date"></div>
        <button class="wsa-portal-logout-btn" id="portal-logout-btn">Sign Out</button>
      </div>
    </div>

    <!-- TODAY STATUS CARD -->
    <div class="wsa-portal-today-card" id="p-today-card">
      <div class="wsa-ptc-left">
        <div class="wsa-ptc-status-label">Today's Status</div>
        <div class="wsa-ptc-status-badge" id="p-status-badge">ABSENT</div>
        <div class="wsa-ptc-times" id="p-times"></div>
      </div>
      <div class="wsa-ptc-right">
        <div class="wsa-ptc-live-label">Worked Today</div>
        <div class="wsa-ptc-live-timer" id="p-live-timer">--:--:--</div>
        <div class="wsa-ptc-checkout-info" id="p-checkout-info"></div>
        <div class="wsa-ptc-break-info" id="p-break-info" style="display:none"></div>
        <div class="wsa-ptc-shift" id="p-shift-info"></div>
      </div>
    </div>

    <!-- MONTH STATS ROW -->
    <div class="wsa-portal-stats-row" id="p-month-stats">
      <div class="wsa-pstat-card wsa-pstat-present">
        <div class="wsa-pstat-val" id="ps-present">—</div>
        <div class="wsa-pstat-label">Present</div>
      </div>
      <div class="wsa-pstat-card wsa-pstat-absent">
        <div class="wsa-pstat-val" id="ps-absent">—</div>
        <div class="wsa-pstat-label">Absent</div>
      </div>
      <div class="wsa-pstat-card wsa-pstat-leave">
        <div class="wsa-pstat-val" id="ps-leave">—</div>
        <div class="wsa-pstat-label">Leave</div>
      </div>
      <div class="wsa-pstat-card wsa-pstat-late">
        <div class="wsa-pstat-val" id="ps-late">—</div>
        <div class="wsa-pstat-label">Late Days</div>
      </div>
      <div class="wsa-pstat-card wsa-pstat-hours">
        <div class="wsa-pstat-val" id="ps-hours">—</div>
        <div class="wsa-pstat-label">Hours</div>
      </div>
      <div class="wsa-pstat-card wsa-pstat-ot">
        <div class="wsa-pstat-val" id="ps-ot">—</div>
        <div class="wsa-pstat-label">Overtime</div>
      </div>
    </div>

    <!-- SALARY CARD (only if configured) -->
    <div class="wsa-portal-salary-card" id="p-salary-card" style="display:none">
      <div class="wsa-psal-title">💰 <span id="p-salary-month">This Month</span> Salary</div>
      <div class="wsa-psal-row">
        <div class="wsa-psal-item">
          <div class="wsa-psal-val" id="ps-gross">—</div>
          <div class="wsa-psal-lbl">Gross</div>
        </div>
        <div class="wsa-psal-item">
          <div class="wsa-psal-val wsa-psal-deduct" id="ps-deduct">—</div>
          <div class="wsa-psal-lbl">Deductions</div>
        </div>
        <div class="wsa-psal-item wsa-psal-net-wrap">
          <div class="wsa-psal-val wsa-psal-net" id="ps-net">—</div>
          <div class="wsa-psal-lbl">Net Pay</div>
        </div>
      </div>
      <div class="wsa-psal-note">* Estimate based on attendance. Final amount set by admin.</div>
    </div>

    <!-- ATTENDANCE HISTORY -->
    <div class="wsa-portal-section">
      <div class="wsa-portal-section-title">📅 Last 30 Days Attendance</div>
      <div class="wsa-portal-history-wrap" id="p-history">
        <div class="wsa-portal-empty">Loading…</div>
      </div>
    </div>

  </div><!-- /portal-content -->
</div>
