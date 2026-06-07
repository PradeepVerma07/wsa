<?php
/**
 * WSA Salary Slip Actual Frontend Fix
 */
if (!defined('ABSPATH')) exit;

function wsa_salary_slip_actual_fix_assets() {
    if (is_admin()) return;

    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';

    if (strpos($uri, '/wsa-admin') === false) {
        return;
    }

    wp_enqueue_style(
        'wsa-salary-slip-actual-fix',
        plugin_dir_url(dirname(__FILE__)) . 'assets/css/wsa-salary-slip-actual-fix.css',
        array(),
        '13.0.0'
    );
}
add_action('wp_enqueue_scripts', 'wsa_salary_slip_actual_fix_assets', 1000002);
