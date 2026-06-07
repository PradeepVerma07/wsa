<?php defined('ABSPATH') || exit; ?>
<div class="wsa-auth-wrap" id="wsa-login-app">
  <div class="wsa-auth-card">

    <div class="wsa-auth-brand">
      <div class="wsa-auth-logo">🏭</div>
      <div class="wsa-auth-company"><?php echo esc_html($company); ?></div>
      <div class="wsa-auth-subtitle">Staff Login</div>
    </div>

    <!-- Alert -->
    <div class="wsa-auth-alert" id="login-alert" style="display:none"></div>

    <!-- Login Form -->
    <div id="login-form-wrap">
      <div class="wsa-auth-field">
        <label for="login-eid">Employee ID</label>
        <input type="text" id="login-eid" placeholder="e.g. EMP-001" autocapitalize="characters" autocomplete="username">
      </div>
      <div class="wsa-auth-field">
        <label for="login-pin">PIN</label>
        <div class="wsa-pin-wrap">
          <input type="password" id="login-pin" placeholder="Enter your PIN" inputmode="numeric" autocomplete="current-password">
          <button type="button" class="wsa-pin-eye" id="login-pin-eye">👁</button>
        </div>
      </div>
      <button class="wsa-auth-btn" id="login-btn">
        <span id="login-btn-label">🔐 Login</span>
        <span id="login-spin" class="wsa-spin" style="display:none">⏳</span>
      </button>
    </div>

    <div class="wsa-auth-footer">
      <p>Don't have an account? <a id="goto-register" href="#">Register here</a></p>
      <p class="wsa-auth-qr-note">Or scan the <strong>QR code</strong> at the gate to mark attendance directly.</p>
    </div>

  </div>
</div>
