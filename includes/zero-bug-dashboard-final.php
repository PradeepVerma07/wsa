<?php
/**
 * WSA Zero Bug Final Frontend Dashboard Fix
 * Frontend-only. Does not affect wp-admin.
 */
if (!defined('ABSPATH')) exit;

function wsa_zero_bug_dashboard_assets() {
    if (is_admin()) return;

    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';

    $has_admin_portal = false;
    global $post;
    if ($post && isset($post->post_content) && has_shortcode($post->post_content, 'wsa_admin_portal')) {
        $has_admin_portal = true;
    }

    if (!$has_admin_portal && strpos($uri, '/wsa-admin') === false && strpos($uri, 'wsa_face_view') === false) {
        return;
    }

    $css_file = plugin_dir_path(dirname(__FILE__)) . 'assets/css/wsa-zero-bug-dashboard.css';
    $js_file  = plugin_dir_path(dirname(__FILE__)) . 'assets/js/wsa-zero-bug-dashboard.js';

    wp_enqueue_style(
        'wsa-zero-bug-dashboard',
        plugin_dir_url(dirname(__FILE__)) . 'assets/css/wsa-zero-bug-dashboard.css',
        array(),
        file_exists($css_file) ? '12.0.9-' . filemtime($css_file) : '12.0.9'
    );

    wp_enqueue_script(
        'wsa-zero-bug-dashboard',
        plugin_dir_url(dirname(__FILE__)) . 'assets/js/wsa-zero-bug-dashboard.js',
        array('jquery'),
        file_exists($js_file) ? '12.0.9-' . filemtime($js_file) : '12.0.9',
        true
    );
}
add_action('wp_enqueue_scripts', 'wsa_zero_bug_dashboard_assets', 999999);

/**
 * Dequeue old separate dashboard fix plugin assets if they are still active.
 */
function wsa_zero_bug_dequeue_old_dashboard_assets() {
    if (is_admin()) return;

    $old_handles = array(
        'wsa-dashboard-final-v5',
        'wsa-dashboard-final-v4',
        'wsa-dashboard-emergency-fix-v2',
        'wsa-emergency-dashboard-fix',
        'wsa-force-fix-v3',
        'wsa-clean-frontend-layout-repair',
        'wsa-frontend-only-dashboard-repair',
        'wsa-2026-modern-dashboard-fix',
        'wsa-dashboard-layout-fix',
        'wsa-exact-dashboard-fix',
        'wsa-final-frontend-dashboard'
    );

    foreach ($old_handles as $handle) {
        wp_dequeue_style($handle);
        wp_deregister_style($handle);
        wp_dequeue_script($handle);
        wp_deregister_script($handle);
    }
}
add_action('wp_enqueue_scripts', 'wsa_zero_bug_dequeue_old_dashboard_assets', 1000000);
add_action('wp_print_styles', 'wsa_zero_bug_dequeue_old_dashboard_assets', 1000000);
add_action('wp_print_scripts', 'wsa_zero_bug_dequeue_old_dashboard_assets', 1000000);
