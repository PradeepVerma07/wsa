<?php
/**
 * Plugin Name:  Webtrionix Staff Attendance
 * Plugin URI:   https://webtrionix.com
 * Description:  Secure one-time QR attendance system — each QR is single-use, rotates every 30 seconds, staff redirected to a private login page to mark IN/OUT/Break.
 * Version: 5.7.15
 * Author:       Webtrionix
 * License:      GPL-2.0+
 * Text Domain:  wsa
 * Requires PHP: 7.4
 * Requires at least: 5.8
 */
defined('ABSPATH') || exit;

define('WSA_VER', '5.7.15-face-responsive-dropdown-fix');
define('WSA_DIR',    plugin_dir_path(__FILE__));
define('WSA_URL',    plugin_dir_url(__FILE__));
define('WSA_FILE',   __FILE__);
define('WSA_DB_VER', '4.6');

/* ── Autoloader ────────────────────────────────── */
spl_autoload_register(function ($class) {
    $map = [
        'WSA_DB'            => 'class-wsa-db',
        'WSA_Staff'         => 'class-wsa-staff',
        'WSA_Attendance'    => 'class-wsa-attendance',
        'WSA_Admin'         => 'class-wsa-admin',
        'WSA_Ajax'          => 'class-wsa-ajax',
        'WSA_Shortcodes'    => 'class-wsa-shortcodes',
        'WSA_Export'        => 'class-wsa-export',
        'WSA_Cron'          => 'class-wsa-cron',
        'WSA_QrCode'        => 'class-wsa-qrcode',
        'WSA_Salary'        => 'class-wsa-salary',
        'WSA_Auth'          => 'class-wsa-auth',
        'WSA_Admin_Portal'  => 'class-wsa-admin-portal',
        'WSA_Admin_Rest'    => 'class-wsa-admin-rest',
        'WSA_Face'          => 'class-wsa-face',
        'WSA_Module_Access' => 'class-wsa-module-access',
    ];
    if (isset($map[$class])) {
        require_once WSA_DIR . 'includes/' . $map[$class] . '.php';
    }
});


/* ── Timezone Sync ──────────────────────────────── */
function wsa_sync_timezone_setting() {
    $tz = get_option('wsa_timezone', '');
    if ($tz && in_array($tz, timezone_identifiers_list(), true) && wp_timezone_string() !== $tz) {
        update_option('timezone_string', $tz);
        update_option('gmt_offset', 0);
    }
}

/* ── Lifecycle ──────────────────────────────────── */
register_activation_hook(WSA_FILE, function () {
    WSA_DB::install();
    WSA_Salary::install();
    WSA_Face::install();
    WSA_Cron::schedule();
});
register_deactivation_hook(WSA_FILE, function () {
    WSA_Cron::unschedule();
});

/* ── Boot ───────────────────────────────────────── */
add_action('plugins_loaded', function () {
    wsa_sync_timezone_setting();
    // Run database upgrades only when needed. The previous build compared against v4.5
    // while upgrade() stores v4.6, causing upgrade checks to run on every page load.
    if (get_option('wsa_db_version') !== WSA_DB_VER) {
        WSA_DB::upgrade();
    }

    // Ensure default pages/gates once for old FTP/manual installs, not on every frontend request.
    if (!get_option('wsa_setup_ensured')) {
        WSA_DB::ensure_setup();
        update_option('wsa_setup_ensured', '1', false);
    }

    // Face DB install/upgrade is heavy; run only on version mismatch.
    if (get_option('wsa_face_db_version') !== WSA_Face::DB_VER) {
        WSA_Face::install();
    }

    // FORCE-CORRECT: keep QR rotation at 30 seconds without running heavy setup.
    if ((int) get_option('wsa_qr_ttl', 30) !== 30) {
        update_option('wsa_qr_ttl', 30);
    }

    new WSA_Admin();
    new WSA_Ajax();
    new WSA_Shortcodes();
    new WSA_Cron();
    new WSA_Admin_Portal();
    new WSA_Face();
    new WSA_Admin_Rest();
}, 10);








require_once plugin_dir_path(__FILE__) . 'includes/zero-bug-dashboard-final.php';


require_once plugin_dir_path(__FILE__) . 'includes/salary-text-final-fix.php';


require_once plugin_dir_path(__FILE__) . 'includes/salary-slip-actual-fix.php';


require_once plugin_dir_path(__FILE__) . 'includes/salary-slip-dark-text-force.php';


require_once plugin_dir_path(__FILE__) . 'includes/salary-slip-form-text-final.php';
