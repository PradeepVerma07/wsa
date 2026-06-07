<?php
/**
 * WSA Salary Slip Dark Text Force Fix
 */
if (!defined('ABSPATH')) exit;

function wsa_salary_slip_dark_text_force_assets() {
    if (is_admin()) return;

    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';

    if (strpos($uri, '/wsa-admin') === false) {
        return;
    }

    wp_enqueue_style(
        'wsa-salary-slip-dark-text-force',
        plugin_dir_url(dirname(__FILE__)) . 'assets/css/wsa-salary-slip-dark-text-force.css',
        array(),
        '14.0.0'
    );

    wp_enqueue_script(
        'wsa-salary-slip-dark-text-force',
        plugin_dir_url(dirname(__FILE__)) . 'assets/js/wsa-salary-slip-dark-text-force.js',
        array('jquery'),
        '14.0.0',
        true
    );
}
add_action('wp_enqueue_scripts', 'wsa_salary_slip_dark_text_force_assets', 9999999);
