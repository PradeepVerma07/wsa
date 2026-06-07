<?php defined('ABSPATH') || exit; ?>
<div class="wsa-att-app" id="wsa-att-app" data-company="<?php echo esc_attr($company); ?>">
  <div class="wsa-att-header">
    <div class="wsa-att-logo">🏭</div>
    <div class="wsa-att-company"><?php echo esc_html($company); ?></div>
    <div class="wsa-att-clock" id="att-clock">--:--:--</div>
    <div class="wsa-att-date"  id="att-date"></div>
  </div>
  <div class="wsa-att-screen" id="sc-status">
    <div id="st-auth-form">
      <div class="wsa-login-card">
        <div class="wsa-lc-title">📋 My Attendance</div>
        <div class="wsa-lc-sub">Enter your Employee ID and PIN</div>
        <div class="wsa-lc-field">
          <label>Employee ID</label>
          <input type="text" id="st-eid" placeholder="EMP-001" autocapitalize="characters" autocomplete="off">
        </div>
        <div class="wsa-lc-field">
          <label>PIN</label>
          <input type="password" id="st-pin" placeholder="••••••" maxlength="6" inputmode="numeric">
        </div>
        <div class="wsa-lc-err" id="st-err" style="display:none">
          <span class="wsa-err-ico">⚠</span><span id="st-err-msg"></span>
        </div>
        <button class="wsa-att-btn" id="st-check-btn">🔍 Check My Status</button>
      </div>
    </div>
    <div id="st-data" style="display:none"></div>
  </div>
</div>
