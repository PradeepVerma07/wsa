<?php
/**
 * WSA Final Salary Slip + Text Color Fix
 * Frontend-only. Does not affect wp-admin.
 */
if (!defined('ABSPATH')) exit;

function wsa_salary_text_final_assets() {
    if (is_admin()) return;

    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';

    if (strpos($uri, '/wsa-admin') === false && strpos($uri, 'wsa_face_view') === false) {
        return;
    }

    wp_enqueue_style(
        'wsa-salary-text-final',
        plugin_dir_url(dirname(__FILE__)) . 'assets/css/wsa-salary-text-final.css',
        array(),
        '12.0.0'
    );

    wp_enqueue_script(
        'wsa-salary-text-final',
        plugin_dir_url(dirname(__FILE__)) . 'assets/js/wsa-salary-text-final.js',
        array('jquery'),
        '12.0.0',
        true
    );
}
add_action('wp_enqueue_scripts', 'wsa_salary_text_final_assets', 1000001);
