<?php
defined('ABSPATH') || exit;

class WSA_Shortcodes {

    const SC_LOGIN    = ['wsa_staff_login',    'attendance_login',    'wsa_login'];
    const SC_REGISTER = ['wsa_staff_register', 'attendance_register'];
    const SC_PORTAL   = ['wsa_staff_portal',   'attendance_portal'];
    const SC_SCANNER  = ['attendance_scanner'];
    const SC_ATTEND   = ['employee_attendance'];
    const SC_DASH     = ['staff_dashboard'];
    const SC_FACE     = ['wsa_face_scanner', 'face_attendance_scanner'];
    const SC_FACE_DASH = ['wsa_face_dashboard', 'face_dashboard'];

    public function __construct() {
        foreach (self::SC_SCANNER  as $sc) add_shortcode($sc, [$this, 'sc_scanner']);
        foreach (self::SC_ATTEND   as $sc) add_shortcode($sc, [$this, 'sc_attendance']);
        foreach (self::SC_DASH     as $sc) add_shortcode($sc, [$this, 'sc_dashboard']);
        foreach (self::SC_FACE     as $sc) add_shortcode($sc, [$this, 'sc_face_scanner']);
        foreach (self::SC_FACE_DASH as $sc) add_shortcode($sc, [$this, 'sc_face_dashboard']);
        foreach (self::SC_LOGIN    as $sc) add_shortcode($sc, [$this, 'sc_staff_login']);
        foreach (self::SC_REGISTER as $sc) add_shortcode($sc, [$this, 'sc_staff_register']);
        foreach (self::SC_PORTAL   as $sc) add_shortcode($sc, [$this, 'sc_staff_portal']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    private function has_any(string $content, array $shortcodes): bool {
        foreach ($shortcodes as $sc) {
            if (has_shortcode($content, $sc)) return true;
        }
        return false;
    }

    public function enqueue() {
        global $post;
        if (!$post) return;
        $c = $post->post_content;

        $is_scanner  = $this->has_any($c, self::SC_SCANNER);
        $is_attend   = $this->has_any($c, self::SC_ATTEND);
        $is_dash     = $this->has_any($c, self::SC_DASH);
        $is_login    = $this->has_any($c, self::SC_LOGIN);
        $is_register = $this->has_any($c, self::SC_REGISTER);
        $is_portal   = $this->has_any($c, self::SC_PORTAL);
        $is_face     = $this->has_any($c, self::SC_FACE);
        $is_face_dash = $this->has_any($c, self::SC_FACE_DASH);
        if ($is_face_dash) $is_face = true; // dashboard includes scanner

        if (!$is_scanner && !$is_attend && !$is_dash && !$is_login && !$is_register && !$is_portal && !$is_face) return;

        wp_enqueue_style('wsa-public', WSA_URL . 'public/css/public.css', [], WSA_VER);

        if ($is_scanner) {
            wp_enqueue_script('wsa-scanner', WSA_URL . 'public/js/scanner.js', [], WSA_VER, true);
            $gates = WSA_DB::get_gates();
            $gate  = $gates ? $gates[0] : null;
            wp_localize_script('wsa-scanner', 'wsaScanner', [
                'apiDisplay' => rest_url('wsa/v2/qr/display/' . ($gate ? $gate->id : 1)),
                'nonce'      => wp_create_nonce('wp_rest'),
                'gateId'     => $gate ? (int) $gate->id : 1,
                'company'    => esc_js(get_option('wsa_company', get_bloginfo('name'))),
                'qrTtl'      => WSA_QrCode::get_actual_ttl(),
            ]);
        }

        if ($is_attend) {
            wp_enqueue_script('wsa-attendance', WSA_URL . 'public/js/attendance.js', [], WSA_VER, true);
            $qr_token = sanitize_text_field($_GET['qr'] ?? '');
            wp_localize_script('wsa-attendance', 'wsaAttend', [
                'apiClaim'   => rest_url('wsa/v2/qr/claim'),
                'apiAttend'  => rest_url('wsa/v2/attend'),
                'apiStatus'  => rest_url('wsa/v2/status'),
                'nonce'      => wp_create_nonce('wp_rest'),
                'qrToken'    => $qr_token,
                'company'    => esc_js(get_option('wsa_company', get_bloginfo('name'))),
                'claimTtl'   => WSA_QrCode::CLAIM_TTL,
                'scannerUrl' => get_permalink(get_option('wsa_scanner_page_id')) ?: home_url('/attendance-scanner/'),
            ]);
        }

        if ($is_dash) {
            wp_enqueue_script('wsa-dashboard', WSA_URL . 'public/js/attendance.js', [], WSA_VER, true);
            wp_localize_script('wsa-dashboard', 'wsaAttend', [
                'apiStatus'   => rest_url('wsa/v2/status'),
                'nonce'       => wp_create_nonce('wp_rest'),
                'company'     => esc_js(get_option('wsa_company', get_bloginfo('name'))),
                'isDashboard' => true,
            ]);
        }

        if ($is_face) {
            wp_enqueue_style('wsa-face', WSA_URL . 'public/css/face.css', [], WSA_VER);
            wp_enqueue_script('face-api', 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.15/dist/face-api.js', [], WSA_VER, true);
            wp_enqueue_script('wsa-face-scanner', WSA_URL . 'public/js/face-scanner.js', ['face-api'], WSA_VER, true);
            wp_localize_script('wsa-face-scanner', 'wsaFace', [
                'apiMatch'  => rest_url('wsa/v2/face/match'),
                'apiStatus' => rest_url('wsa/v2/face/status'),
                'apiModels' => WSA_URL . 'public/models',
                'nonce'     => wp_create_nonce('wp_rest'),
                'company'   => esc_js(get_option('wsa_company', get_bloginfo('name'))),
                'location'    => esc_js(get_bloginfo('name') . ' — Face Scanner'),
                'apiDashboard' => rest_url('wsa/v2/face/dashboard'),
            ]);
        }

        if ($is_login || $is_register || $is_portal) {
            wp_enqueue_script('wsa-portal', WSA_URL . 'public/js/portal.js', [], WSA_VER, true);
            wp_localize_script('wsa-portal', 'wsaPortal', [
                'apiLogin'        => rest_url('wsa/v2/auth/login'),
                'apiLogout'       => rest_url('wsa/v2/auth/logout'),
                'apiMe'           => rest_url('wsa/v2/auth/me'),
                'apiRegister'     => rest_url('wsa/v2/auth/register'),
                'apiDashboard'    => rest_url('wsa/v2/portal/dashboard'),
                'loginUrl'        => get_permalink(get_option('wsa_login_page_id'))    ?: home_url('/staff-login/'),
                'portalUrl'       => get_permalink(get_option('wsa_portal_page_id'))   ?: home_url('/staff-portal/'),
                'registerUrl'     => get_permalink(get_option('wsa_register_page_id')) ?: home_url('/staff-register/'),
                'company'         => esc_js(get_option('wsa_company', get_bloginfo('name'))),
                'isLogin'         => $is_login    ? 'yes' : 'no',
                'isRegister'      => $is_register ? 'yes' : 'no',
                'isPortal'        => $is_portal   ? 'yes' : 'no',
                'minCheckoutMins' => (int) get_option('wsa_min_work_mins', 420),
                'nonce'           => wp_create_nonce('wp_rest'),
            ]);
        }
    }

    public function sc_scanner($atts) {
        $atts  = shortcode_atts(['gate_id' => 0], $atts);
        $gates = WSA_DB::get_gates();
        $gate  = $atts['gate_id'] ? WSA_DB::get_gate_by_id((int) $atts['gate_id']) : ($gates ? $gates[0] : null);
        $company = get_option('wsa_company', get_bloginfo('name'));
        ob_start(); include WSA_DIR . 'public/views/scanner.php'; return ob_get_clean();
    }

    public function sc_attendance() {
        $qr_token = sanitize_text_field($_GET['qr'] ?? '');
        $company  = get_option('wsa_company', get_bloginfo('name'));
        ob_start(); include WSA_DIR . 'public/views/employee-attendance.php'; return ob_get_clean();
    }

    public function sc_dashboard() {
        $company = get_option('wsa_company', get_bloginfo('name'));
        ob_start(); include WSA_DIR . 'public/views/staff-dashboard.php'; return ob_get_clean();
    }

    public function sc_staff_login() {
        $company = get_option('wsa_company', get_bloginfo('name'));
        ob_start(); include WSA_DIR . 'public/views/staff-login.php'; return ob_get_clean();
    }

    public function sc_staff_register() {
        $company = get_option('wsa_company', get_bloginfo('name'));
        ob_start(); include WSA_DIR . 'public/views/staff-register.php'; return ob_get_clean();
    }

    public function sc_staff_portal() {
        $company = get_option('wsa_company', get_bloginfo('name'));
        ob_start(); include WSA_DIR . 'public/views/staff-portal.php'; return ob_get_clean();
    }

    public function sc_face_scanner() {
        $company = get_option('wsa_company', get_bloginfo('name'));
        ob_start(); include WSA_DIR . 'public/views/face-scanner.php'; return ob_get_clean();
    }

    public function sc_face_dashboard() {
        $company = get_option('wsa_company', get_bloginfo('name'));
        ob_start(); include WSA_DIR . 'public/views/face-dashboard.php'; return ob_get_clean();
    }
}
