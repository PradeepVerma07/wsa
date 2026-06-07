<?php defined('ABSPATH') || exit; ?>
<div class="wsa-att-app" id="wsa-att-app"
     data-qr="<?php echo esc_attr($qr_token); ?>"
     data-company="<?php echo esc_attr($company); ?>">

  <!-- ── Header ── -->
  <div class="wsa-att-header">
    <div class="wsa-att-logo">🏭</div>
    <div class="wsa-att-company"><?php echo esc_html($company); ?></div>
    <div class="wsa-att-clock" id="att-clock">--:--:--</div>
    <div class="wsa-att-date"  id="att-date"></div>
  </div>

  <!-- ════════════════════════════════════════════
       SCREEN: Validating QR (shown on load)
  ════════════════════════════════════════════ -->
  <div class="wsa-att-screen" id="sc-validating">
    <div class="wsa-val-spinner"></div>
    <div class="wsa-val-text">Validating QR code…</div>
  </div>

  <!-- ════════════════════════════════════════════
       SCREEN: Invalid / Expired QR
  ════════════════════════════════════════════ -->
  <div class="wsa-att-screen" id="sc-invalid" style="display:none">
    <div class="wsa-inv-icon">🚫</div>
    <div class="wsa-inv-title" id="inv-title">Invalid QR Code</div>
    <div class="wsa-inv-msg"   id="inv-msg">This QR code is not valid.</div>
    <div class="wsa-inv-note">Please scan the current QR code displayed at the factory entrance.</div>
    <a class="wsa-att-btn wsa-att-btn--outline" id="inv-scan-link" href="<?php echo esc_url(get_permalink(get_option('wsa_scanner_page_id')) ?: home_url('/attendance-scanner/')); ?>">
      ← Back to Scanner
    </a>
  </div>

  <!-- ════════════════════════════════════════════
       SCREEN: No QR in URL (direct access blocked)
  ════════════════════════════════════════════ -->
  <div class="wsa-att-screen" id="sc-noqr" style="display:none">
    <div class="wsa-inv-icon">📋</div>
    <div class="wsa-inv-title">Direct Access Blocked</div>
    <div class="wsa-inv-msg">You must scan the QR code at the factory gate to access this page.</div>
    <div class="wsa-inv-note">This page cannot be accessed directly from a browser link.</div>
    <a class="wsa-att-btn" href="<?php echo esc_url(get_permalink(get_option('wsa_scanner_page_id')) ?: home_url('/attendance-scanner/')); ?>">
      Go to Scanner Display
    </a>
    <button class="wsa-att-btn wsa-att-btn--ghost" id="noqr-status-btn">📋 Check My Status</button>
  </div>

  <!-- ════════════════════════════════════════════
       SCREEN: Login Form (after QR validated)
  ════════════════════════════════════════════ -->
  <div class="wsa-att-screen" id="sc-login" style="display:none">

    <!-- QR verified badge -->
    <div class="wsa-qr-verified">
      <span class="wsa-qv-icon">✅</span>
      <div>
        <div class="wsa-qv-title">QR Verified</div>
        <div class="wsa-qv-sub" id="login-gate-name">Gate access granted</div>
      </div>
      <div class="wsa-qv-timer" id="qv-timer-wrap">
        <div class="wsa-qv-timer-ring">
          <svg viewBox="0 0 40 40">
            <circle class="wsa-ring-bg"   cx="20" cy="20" r="16" fill="none" stroke-width="3"/>
            <circle class="wsa-ring-prog" cx="20" cy="20" r="16" fill="none" stroke-width="3"
              stroke-dasharray="100.5" stroke-dashoffset="0" id="qv-ring" transform="rotate(-90 20 20)"/>
          </svg>
          <span class="wsa-qv-secs" id="qv-secs">--</span>
        </div>
      </div>
    </div>

    <div class="wsa-login-card">
      <div class="wsa-lc-title">Enter Your Credentials</div>

      <div class="wsa-lc-field">
        <label for="att-eid">Employee ID</label>
        <input type="text" id="att-eid"
               placeholder="e.g. EMP-001"
               autocapitalize="characters"
               autocomplete="off"
               inputmode="text"
               spellcheck="false">
      </div>

      <div class="wsa-lc-field">
        <label for="att-pin">PIN</label>
        <div class="wsa-pin-row">
          <input type="password" id="att-pin"
                 placeholder="••••••"
                 maxlength="6"
                 inputmode="numeric"
                 autocomplete="off">
          <button type="button" class="wsa-pin-eye" id="att-pin-eye" aria-label="Toggle PIN">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
      </div>

      <div class="wsa-lc-err" id="login-err" style="display:none">
        <span class="wsa-err-ico">⚠</span>
        <span id="login-err-msg"></span>
      </div>

      <button class="wsa-att-btn" id="att-submit-btn">
        <span id="att-btn-label">✅ Mark Attendance</span>
        <span class="wsa-spin" id="att-spin" style="display:none"></span>
      </button>
    </div>

    <!-- Session expired overlay -->
    <div class="wsa-expired-overlay" id="session-expired" style="display:none">
      <div class="wsa-exp-icon">⏱</div>
      <div class="wsa-exp-title">Session Expired</div>
      <div class="wsa-exp-msg">Your 3-minute session has ended. Please scan the QR code again.</div>
      <a class="wsa-att-btn" href="<?php echo esc_url(get_permalink(get_option('wsa_scanner_page_id')) ?: home_url('/attendance-scanner/')); ?>">
        Scan Again
      </a>
    </div>
  </div>

  <!-- ════════════════════════════════════════════
       SCREEN: Success Result
  ════════════════════════════════════════════ -->
  <div class="wsa-att-screen" id="sc-result" style="display:none">

    <div class="wsa-res-burst" id="res-burst"></div>

    <div class="wsa-res-card" id="res-card">
      <div class="wsa-res-emoji" id="res-emoji">✅</div>
      <div class="wsa-res-action" id="res-action">Checked IN</div>
      <div class="wsa-res-name"   id="res-name">--</div>
      <div class="wsa-res-dept"   id="res-dept">--</div>

      <div class="wsa-res-time-block">
        <div class="wsa-res-time-big" id="res-time">--:--:-- --</div>
        <div class="wsa-res-date"     id="res-date">--</div>
      </div>

      <!-- Stats grid (shown for checkout) -->
      <div class="wsa-res-stats" id="res-stats" style="display:none">
        <div class="wsa-res-stat">
          <div class="wsa-rs-val" id="rs-in-time">--</div>
          <div class="wsa-rs-label">Check-In</div>
        </div>
        <div class="wsa-res-stat">
          <div class="wsa-rs-val" id="rs-out-time">--</div>
          <div class="wsa-rs-label">Check-Out</div>
        </div>
        <div class="wsa-res-stat wsa-rs-full">
          <div class="wsa-rs-val wsa-rs-hours" id="rs-hours">--</div>
          <div class="wsa-rs-label">Total Hours</div>
        </div>
        <div class="wsa-res-stat wsa-rs-full" id="rs-ot-wrap" style="display:none">
          <div class="wsa-rs-val wsa-rs-ot" id="rs-ot">--</div>
          <div class="wsa-rs-label">Overtime</div>
        </div>
      </div>

      <!-- Live elapsed timer (for check-in) -->
      <div class="wsa-res-elapsed" id="res-elapsed" style="display:none">
        <div class="wsa-elapsed-label">⏱ Time at Work</div>
        <div class="wsa-elapsed-val wsa-live-val" id="res-elapsed-val">00:00:00</div>
      </div>

      <!-- Checkout unlock countdown (shown after check-in) -->
      <div class="wsa-checkout-info" id="res-checkout-info" style="display:none"></div>

      <!-- Overtime notice (shown after 8h) -->
      <div class="wsa-ot-notice" id="res-ot-notice" style="display:none"></div>

      <div class="wsa-res-note" id="res-note"></div>
    </div>

    <button class="wsa-att-btn wsa-att-btn--ghost" id="res-status-btn">📋 View My Records</button>
  </div>

  <!-- ════════════════════════════════════════════
       SCREEN: Status / History
  ════════════════════════════════════════════ -->
  <div class="wsa-att-screen" id="sc-status" style="display:none">

    <!-- Auth form -->
    <div id="st-auth-form">
      <div class="wsa-login-card">
        <div class="wsa-lc-title">📋 My Attendance</div>
        <div class="wsa-lc-sub">Enter credentials to view today's record</div>
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
        <button class="wsa-att-btn wsa-att-btn--ghost" id="st-back-btn">← Back</button>
      </div>
    </div>

    <!-- Loaded data -->
    <div id="st-data" style="display:none"></div>
  </div>

</div><!-- .wsa-att-app -->
