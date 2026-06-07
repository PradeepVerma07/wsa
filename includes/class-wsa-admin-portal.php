<?php
defined('ABSPATH') || exit;

/**
 * WSA_Admin_Portal
 * Renders the standalone frontend admin panel via [wsa_admin_portal] shortcode.
 *
 * Auth hierarchy:
 *   1. wsa_portal_admins table  (role = super_admin | admin)
 *   2. WordPress administrator  (treated as 'admin' role)
 */
class WSA_Admin_Portal {

    const TOKEN_TTL    = 43200; // 12 hours
    const TOKEN_LEN    = 48;
    const TOKEN_PREFIX = 'wsa_adminp_';

    public function __construct() {
        add_shortcode('wsa_admin_portal', [$this, 'render_portal']);
        add_action('wp_enqueue_scripts',  [$this, 'enqueue_assets']);
    }

    public function enqueue_assets() {
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'wsa_admin_portal')) return;
        wp_enqueue_style('wsa-admin-css',    WSA_URL . 'admin/css/admin.css',          [], WSA_VER);
        wp_enqueue_style('wsa-admin-portal', WSA_URL . 'public/css/admin-portal.css',  [], WSA_VER . '-' . filemtime(WSA_DIR . 'public/css/admin-portal.css'), 'all');
        wp_enqueue_script('wsa-admin-portal', WSA_URL . 'public/js/admin-portal.js', [], WSA_VER . '-' . filemtime(WSA_DIR . 'public/js/admin-portal.js'), true);
        $custom_logo_id = get_theme_mod('custom_logo');
        $logo_url = $custom_logo_id ? wp_get_attachment_image_url($custom_logo_id, 'medium') : '';
        if (!$logo_url && function_exists('get_site_icon_url')) { $logo_url = get_site_icon_url(192); }
        wp_localize_script('wsa-admin-portal', 'wsaAdminPortal', [
            'apiBase'       => rest_url('wsa/v2/wsa-admin/'),
            'adminAjaxUrl'  => admin_url('admin-ajax.php'),
            'adminPostUrl'  => admin_url('admin-post.php'),
            'restNonce'     => wp_create_nonce('wp_rest'),
            'attActionNonce'=> wp_create_nonce('wsa_ap_attendance_direct'),
            'company'       => esc_js(get_option('wsa_company', get_bloginfo('name'))),
            'logoUrl'       => esc_url_raw($logo_url),
            'siteUrl'       => get_site_url(),
            'portalUrl'     => get_permalink($post->ID),
            'scannerPageId' => (int) get_option('wsa_scanner_page_id', 0),
            'scannerUrl'    => get_permalink(get_option('wsa_scanner_page_id')) ?: home_url('/attendance-scanner/'),
        ]);
    }

    public function render_portal() {
        ob_start();
        include WSA_DIR . 'public/views/admin-portal.php';
        return ob_get_clean();
    }

    /* ══ Auth helpers ══════════════════════════════════════════ */

    /**
     * Login: check wsa_portal_admins first, then WP admins.
     * Returns ['ok'=>bool, 'token'=>string, 'name'=>string, 'role'=>string]
     */
    public static function login(string $username, string $password): array {
        global $wpdb;
        $table = $wpdb->prefix . 'wsa_portal_admins';

        // 1. Check portal admins table
        $admin = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE username=%s AND status='active' LIMIT 1",
            sanitize_user($username)
        ));

        if ($admin && password_verify($password, $admin->password)) {
            $wpdb->update($table, ['last_login' => current_time('mysql')], ['id' => $admin->id]);
            $token = self::issue_token($admin->id, $admin->role, 'portal');
            return [
                'ok'    => true,
                'token' => $token,
                'name'  => $admin->name ?: $admin->username,
                'email' => $admin->email,
                'role'  => $admin->role,
            ];
        }

        // 2. Fall back to WP administrator
        $wp_user = wp_authenticate(sanitize_user($username), $password);
        if (is_wp_error($wp_user)) {
            return ['ok' => false, 'message' => 'Invalid username or password.'];
        }
        if (!user_can($wp_user, 'manage_options')) {
            return ['ok' => false, 'message' => 'This account does not have admin access.'];
        }
        $token = self::issue_token($wp_user->ID, 'super_admin', 'wp');
        return [
            'ok'    => true,
            'token' => $token,
            'name'  => $wp_user->display_name,
            'email' => $wp_user->user_email,
            'role'  => 'super_admin',
        ];
    }

    private static function issue_token(int $id, string $role, string $type): string {
        $token = bin2hex(random_bytes(24));
        set_transient(self::TOKEN_PREFIX . $token, json_encode([
            'id'   => $id,
            'role' => $role,
            'type' => $type,
        ]), self::TOKEN_TTL);
        return $token;
    }

    /** Returns null or ['id'=>int,'role'=>string,'type'=>string] */
    public static function validate(string $token): ?array {
        if (!$token || strlen($token) !== self::TOKEN_LEN) return null;
        $raw = get_transient(self::TOKEN_PREFIX . $token);
        if (!$raw) return null;
        $data = json_decode($raw, true);
        if (!$data || empty($data['role'])) return null;

        // Verify WP user still admin
        if (($data['type'] ?? '') === 'wp') {
            $u = get_userdata($data['id']);
            if (!$u || !user_can($u, 'manage_options')) return null;
        }

        set_transient(self::TOKEN_PREFIX . $token, $raw, self::TOKEN_TTL); // slide TTL
        return $data;
    }

    public static function logout(string $token): void {
        delete_transient(self::TOKEN_PREFIX . $token);
    }

    public static function token_from_request(WP_REST_Request $r): string {
        $h = $r->get_header('x_wsa_admin_token');
        if ($h) return sanitize_text_field($h);

        // Fallback for hosts/cache/firewalls that strip custom REST headers.
        $param = $r->get_param('wsa_admin_token');
        if ($param) return sanitize_text_field($param);

        if (!empty($_SERVER['HTTP_X_WSA_ADMIN_TOKEN'])) {
            return sanitize_text_field($_SERVER['HTTP_X_WSA_ADMIN_TOKEN']);
        }
        if (!empty($_REQUEST['wsa_admin_token'])) {
            return sanitize_text_field(wp_unslash($_REQUEST['wsa_admin_token']));
        }
        return '';
    }

    public static function is_super(array $session): bool {
        return ($session['role'] ?? '') === 'super_admin';
    }
}
