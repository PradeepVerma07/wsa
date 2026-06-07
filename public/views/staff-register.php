<?php defined('ABSPATH') || exit; ?>
<div class="wsa-auth-wrap" id="wsa-register-app">
  <div class="wsa-auth-card wsa-auth-card--wide">

    <div class="wsa-auth-brand">
      <div class="wsa-auth-logo">🏭</div>
      <div class="wsa-auth-company"><?php echo esc_html($company); ?></div>
      <div class="wsa-auth-subtitle">Staff Registration</div>
    </div>

    <!-- Alert -->
    <div class="wsa-auth-alert" id="reg-alert" style="display:none"></div>

    <!-- Success state -->
    <div id="reg-success" style="display:none">
      <div class="wsa-auth-success-icon">✅</div>
      <h3 class="wsa-auth-success-title">Registration Submitted!</h3>
      <p class="wsa-auth-success-msg">Your account is pending admin approval.<br>You'll be able to log in once activated.</p>
      <a id="goto-login-after-reg" href="#" class="wsa-auth-btn">← Back to Login</a>
    </div>

    <!-- Form -->
    <div id="reg-form-wrap">
      <div class="wsa-auth-field-row">
        <div class="wsa-auth-field">
          <label for="reg-name">Full Name <span class="req">*</span></label>
          <input type="text" id="reg-name" placeholder="Your full name">
        </div>
        <div class="wsa-auth-field">
          <label for="reg-eid">Employee ID <span class="req">*</span></label>
          <input type="text" id="reg-eid" placeholder="e.g. EMP-001" autocapitalize="characters">
        </div>
      </div>
      <div class="wsa-auth-field-row">
        <div class="wsa-auth-field">
          <label for="reg-dept">Department</label>
          <input type="text" id="reg-dept" placeholder="e.g. Production">
        </div>
        <div class="wsa-auth-field">
          <label for="reg-phone">Phone</label>
          <input type="tel" id="reg-phone" placeholder="Mobile number">
        </div>
      </div>
      <div class="wsa-auth-field">
        <label for="reg-email">Email <span class="wsa-optional">(optional)</span></label>
        <input type="email" id="reg-email" placeholder="your@email.com">
      </div>
      <div class="wsa-auth-field-row">
        <div class="wsa-auth-field">
          <label for="reg-pin">PIN <span class="req">*</span></label>
          <div class="wsa-pin-wrap">
            <input type="password" id="reg-pin" placeholder="4+ digit PIN" inputmode="numeric">
            <button type="button" class="wsa-pin-eye" id="reg-pin-eye">👁</button>
          </div>
        </div>
        <div class="wsa-auth-field">
          <label for="reg-pin2">Confirm PIN <span class="req">*</span></label>
          <input type="password" id="reg-pin2" placeholder="Repeat PIN" inputmode="numeric">
        </div>
      </div>
      <div class="wsa-auth-hint-box">
        🔒 Your PIN is used to log in and mark attendance. Keep it secret.
      </div>
      <button class="wsa-auth-btn" id="reg-btn">
        <span id="reg-btn-label">📝 Submit Registration</span>
        <span id="reg-spin" class="wsa-spin" style="display:none">⏳</span>
      </button>
    </div>

    <div class="wsa-auth-footer">
      <p>Already registered? <a id="goto-login" href="#">Login here</a></p>
    </div>

  </div>
</div>
