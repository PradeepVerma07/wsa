<?php
/**
 * WSA Salary Slip Form Text Final Fix
 */
if (!defined('ABSPATH')) exit;

function wsa_salary_slip_form_text_final_assets() {
    if (is_admin()) return;

    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';

    if (strpos($uri, '/wsa-admin') === false) {
        return;
    }

    wp_enqueue_style(
        'wsa-salary-slip-form-text-final',
        plugin_dir_url(dirname(__FILE__)) . 'assets/css/wsa-salary-slip-form-text-final.css',
        array(),
        '15.0.1'
    );

    wp_enqueue_script(
        'wsa-salary-slip-form-text-final',
        plugin_dir_url(dirname(__FILE__)) . 'assets/js/wsa-salary-slip-form-text-final.js',
        array('jquery'),
        '15.0.1',
        true
    );
}
add_action('wp_enqueue_scripts', 'wsa_salary_slip_form_text_final_assets', 99999999);
