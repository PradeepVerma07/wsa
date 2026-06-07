<?php
defined('ABSPATH') || exit;

/**
 * WSA_Admin_Rest
 * REST API under /wsa/v2/wsa-admin/ for the frontend admin portal.
 * Auth: X-WSA-Admin-Token header (token issued by WSA_Admin_Portal::login).
 */
class WSA_Admin_Rest {

    private const NS = 'wsa/v2';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);

        // Hard fallback for frontend Attendance Edit/Delete.
        // Some hosts/security plugins/cache layers allow GET REST calls but silently block
        // PUT/DELETE/custom REST POST requests from frontend pages. Admin-Ajax is more
        // reliable on shared hosting, so the frontend now uses this as the primary action path.
        add_action('wp_ajax_wsa_ap_attendance_update',        [$this, 'ajax_attendance_update']);
        add_action('wp_ajax_nopriv_wsa_ap_attendance_update', [$this, 'ajax_attendance_update']);
        add_action('wp_ajax_wsa_ap_attendance_delete',        [$this, 'ajax_attendance_delete']);
        add_action('wp_ajax_nopriv_wsa_ap_attendance_delete', [$this, 'ajax_attendance_delete']);
        add_action('wp_ajax_wsa_ap_attendance_action',        [$this, 'ajax_attendance_action']);
        add_action('wp_ajax_nopriv_wsa_ap_attendance_action', [$this, 'ajax_attendance_action']);

        // Browser form-submit fallback. This is the most reliable path when a host,
        // cache plugin, or security rule blocks frontend REST/admin-ajax fetch calls.
        add_action('admin_post_wsa_ap_attendance_update_direct',        [$this, 'direct_attendance_update']);
        add_action('admin_post_nopriv_wsa_ap_attendance_update_direct', [$this, 'direct_attendance_update']);
        add_action('admin_post_wsa_ap_attendance_delete_direct',        [$this, 'direct_attendance_delete']);
        add_action('admin_post_nopriv_wsa_ap_attendance_delete_direct', [$this, 'direct_attendance_delete']);
    }

    public function register_routes() {
        $ns  = self::NS;
        $perm = [$this, 'perm'];

        /* ── Auth ── */
        $this->r($ns, '/wsa-admin/login',         'POST', 'login',         '__return_true');
        $this->r($ns, '/wsa-admin/first-boot',    'GET',  'first_boot',    '__return_true');
        $this->r($ns, '/wsa-admin/first-boot',    'POST', 'first_boot_do', $perm);
        $this->r($ns, '/wsa-admin/logout', 'POST', 'logout', '__return_true');
        $this->r($ns, '/wsa-admin/me',     'GET',  'me',     $perm);

        /* ── Dashboard ── */
        $this->r($ns, '/wsa-admin/dashboard', 'GET', 'dashboard', $perm);

        /* ── Staff ── */
        $this->r($ns, '/wsa-admin/staff',              'GET',    'staff_list',   $perm);
        $this->r($ns, '/wsa-admin/staff',              'POST',   'staff_create', $perm);
        $this->r($ns, '/wsa-admin/staff/(?P<id>\d+)', 'PUT',    'staff_update', $perm);
        $this->r($ns, '/wsa-admin/staff/(?P<id>\d+)', 'DELETE', 'staff_delete', $perm);
        $this->r($ns, '/wsa-admin/staff/(?P<id>\d+)/approve', 'POST', 'staff_approve', $perm);
        $this->r($ns, '/wsa-admin/staff/(?P<id>\d+)/reject',  'POST', 'staff_reject',  $perm);
        $this->r($ns, '/wsa-admin/staff/pending', 'GET', 'staff_pending', $perm);
        $this->r($ns, '/wsa-admin/departments',   'GET', 'departments',   $perm);

        /* ── Attendance ── */
        $this->r($ns, '/wsa-admin/attendance',              'GET',    'attendance_list',   $perm);
        $this->r($ns, '/wsa-admin/attendance/(?P<id>\d+)', 'PUT',    'attendance_update', $perm);
        $this->r($ns, '/wsa-admin/attendance/(?P<id>\d+)', 'DELETE', 'attendance_delete', $perm);
        $this->r($ns, '/wsa-admin/attendance/(?P<id>\d+)', 'POST',   'attendance_post_action', $perm);
        // POST fallback endpoints keep frontend Edit/Delete working on hosts/proxies that block PUT/DELETE.
        $this->r($ns, '/wsa-admin/attendance/(?P<id>\d+)/update', 'POST', 'attendance_update', $perm);
        $this->r($ns, '/wsa-admin/attendance/(?P<id>\d+)/delete', 'POST', 'attendance_delete', $perm);
        $this->r($ns, '/wsa-admin/attendance/manual',       'POST',   'manual_entry',      $perm);
        $this->r($ns, '/wsa-admin/attendance/mark-absent',  'POST',   'mark_absent',       $perm);

        /* ── Shifts ── */
        $this->r($ns, '/wsa-admin/shifts',              'GET',    'shifts_list',   $perm);
        $this->r($ns, '/wsa-admin/shifts',              'POST',   'shifts_create', $perm);
        $this->r($ns, '/wsa-admin/shifts/(?P<id>\d+)', 'PUT',    'shifts_update', $perm);
        $this->r($ns, '/wsa-admin/shifts/(?P<id>\d+)', 'DELETE', 'shifts_delete', $perm);

        /* ── Gates / QR ── */
        $this->r($ns, '/wsa-admin/gates',              'GET',    'gates_list',   $perm);
        $this->r($ns, '/wsa-admin/gates',              'POST',   'gates_create', $perm);
        $this->r($ns, '/wsa-admin/gates/(?P<id>\d+)', 'PUT',    'gates_update', $perm);
        $this->r($ns, '/wsa-admin/gates/(?P<id>\d+)', 'DELETE', 'gates_delete', $perm);

        /* ── Leaves ── */
        $this->r($ns, '/wsa-admin/leaves',                         'GET',    'leaves_list',   $perm);
        $this->r($ns, '/wsa-admin/leaves',                         'POST',   'leaves_create', $perm);
        $this->r($ns, '/wsa-admin/leaves/(?P<id>\d+)/status',      'PUT',    'leaves_status', $perm);
        $this->r($ns, '/wsa-admin/leaves/(?P<id>\d+)',             'DELETE', 'leaves_delete', $perm);

        /* ── Salary ── */
        $this->r($ns, '/wsa-admin/salary',                          'GET',  'salary_summary', $perm);
        $this->r($ns, '/wsa-admin/salary/detail/(?P<id>\d+)',       'GET',  'salary_detail',  $perm);
        $this->r($ns, '/wsa-admin/salary/config/(?P<id>\d+)',       'GET',  'salary_config',  $perm);
        $this->r($ns, '/wsa-admin/salary/config',                   'POST', 'salary_save',    $perm);

        /* ── Settings ── */
        $this->r($ns, '/wsa-admin/settings', 'GET',  'settings_get',  $perm);
        $this->r($ns, '/wsa-admin/settings', 'POST', 'settings_save', $perm);

        /* ── Holidays ── */
        $this->r($ns, '/wsa-admin/holidays',              'GET',    'holidays_list',   $perm);
        $this->r($ns, '/wsa-admin/holidays',              'POST',   'holidays_create', $perm);
        $this->r($ns, '/wsa-admin/holidays/(?P<id>\d+)', 'DELETE', 'holidays_delete', $perm);

        /* ── Super Admin ── */
        $this->r($ns, '/wsa-admin/super/admins',                   'GET',    'sa_list',    $perm);
        $this->r($ns, '/wsa-admin/super/admins',                   'POST',   'sa_create',  [$this, 'perm_super']);
        $this->r($ns, '/wsa-admin/super/admins/(?P<id>\\d+)',    'PUT',    'sa_update',  [$this, 'perm_super']);
        $this->r($ns, '/wsa-admin/super/admins/(?P<id>\\d+)',    'DELETE', 'sa_delete',  [$this, 'perm_super']);
        $this->r($ns, '/wsa-admin/super/admins/(?P<id>\\d+)/reset','POST', 'sa_reset',   [$this, 'perm_super']);
        $this->r($ns, '/wsa-admin/super/access',                   'GET',    'access_get', [$this, 'perm_super']);
        $this->r($ns, '/wsa-admin/super/access',                   'POST',   'access_save',[$this, 'perm_super']);

        /* ── Inside / Quick-mark ── */
        $this->r($ns, '/wsa-admin/inside',             'GET',  'inside',              $perm);
        $this->r($ns, '/wsa-admin/quick-mark-status',  'GET',  'quick_mark_status',   $perm);
        $this->r($ns, '/wsa-admin/quick-mark',         'POST', 'quick_mark_action',   $perm);
    }

    /* ── Route helper ── */
    private function r($ns, $path, $method, $cb, $perm) {
        register_rest_route($ns, $path, [
            'methods'             => $method,
            'callback'            => [$this, $cb],
            'permission_callback' => $perm,
        ]);
    }

    /* ── Permission: valid WSA admin token ── */
    public function perm(WP_REST_Request $r): bool {
        $token = WSA_Admin_Portal::token_from_request($r);
        if ($token && WSA_Admin_Portal::validate($token)) return true;

        // Also allow logged-in WordPress administrators. This keeps frontend admin actions
        // working even when a cache/security layer strips the custom WSA token header.
        if (is_user_logged_in() && current_user_can('manage_options')) return true;

        return false;
    }

    private function ajax_perm(): bool {
        $token = '';
        if (isset($_POST['wsa_admin_token'])) {
            $token = sanitize_text_field(wp_unslash($_POST['wsa_admin_token']));
        } elseif (isset($_REQUEST['wsa_admin_token'])) {
            $token = sanitize_text_field(wp_unslash($_REQUEST['wsa_admin_token']));
        }
        if ($token && WSA_Admin_Portal::validate($token)) return true;

        // When the user is already logged in as WP admin (as in the normal wp-admin-bar view),
        // allow the action even if the portal token is blocked by hosting/security rules.
        // Accept wp_rest nonce, attendance-direct nonce, or the logged-in admin cookie as a final fallback.
        $nonce = '';
        foreach (['nonce', '_ajax_nonce', '_wpnonce', '_wsa_att_nonce'] as $nonce_key) {
            if (isset($_REQUEST[$nonce_key])) {
                $nonce = sanitize_text_field(wp_unslash($_REQUEST[$nonce_key]));
                break;
            }
        }
        if (is_user_logged_in() && current_user_can('manage_options')) {
            if (!$nonce || wp_verify_nonce($nonce, 'wp_rest') || wp_verify_nonce($nonce, 'wsa_ap_attendance_direct')) {
                return true;
            }
        }

        return false;
    }

    private function ajax_send_rest($response): void {
        if ($response instanceof WP_REST_Response) {
            wp_send_json($response->get_data(), $response->get_status());
        }
        wp_send_json(['success' => false, 'message' => 'Invalid server response.'], 500);
    }

    /**
     * Collect frontend action params from normal form POST, query-string, or JSON body.
     * This makes the Attendance Edit/Delete buttons work even when a host/cache plugin
     * changes the request content type or strips REST custom headers.
     */
    private function attendance_action_params(): array {
        $data = [];
        foreach ($_REQUEST as $key => $value) {
            $data[$key] = is_string($value) ? wp_unslash($value) : $value;
        }
        $raw = file_get_contents('php://input');
        if ($raw) {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                foreach ($json as $key => $value) $data[$key] = $value;
            }
        }
        return $data;
    }

    private function attendance_action_id(array $data): int {
        foreach (['id', 'record_id', 'attendance_id', 'att_id'] as $key) {
            if (isset($data[$key])) return absint($data[$key]);
        }
        return 0;
    }

    private function rest_request_from_action(array $data, string $method = 'POST'): WP_REST_Request {
        $id = $this->attendance_action_id($data);
        $req = new WP_REST_Request($method, '/wsa/v2/wsa-admin/attendance/' . $id);
        foreach (['id', 'record_id', 'attendance_id', 'att_id', 'att_date', 'date', 'login_time', 'check_in', 'in_time', 'logout_time', 'check_out', 'out_time', 'notes'] as $key) {
            if (!array_key_exists($key, $data)) continue;
            $value = is_string($data[$key]) ? $data[$key] : (string) $data[$key];
            $req->set_param($key, $key === 'notes' ? sanitize_textarea_field($value) : sanitize_text_field($value));
        }
        if (!$req->get_param('id')) $req->set_param('id', $id);
        if (!$req->get_param('att_date') && $req->get_param('date')) $req->set_param('att_date', $req->get_param('date'));
        if (!$req->get_param('login_time') && $req->get_param('check_in')) $req->set_param('login_time', $req->get_param('check_in'));
        if (!$req->get_param('login_time') && $req->get_param('in_time')) $req->set_param('login_time', $req->get_param('in_time'));
        if (!$req->get_param('logout_time') && $req->get_param('check_out')) $req->set_param('logout_time', $req->get_param('check_out'));
        if (!$req->get_param('logout_time') && $req->get_param('out_time')) $req->set_param('logout_time', $req->get_param('out_time'));
        return $req;
    }

    public function ajax_attendance_action(): void {
        if (!$this->ajax_perm()) {
            wp_send_json(['success' => false, 'message' => 'Unauthorized. Please login again.'], 401);
        }
        $data = $this->attendance_action_params();
        $mode = sanitize_key($data['mode'] ?? $data['do'] ?? $data['_wsa_action'] ?? $data['att_action'] ?? 'update');
        $req  = $this->rest_request_from_action($data, 'POST');
        if (in_array($mode, ['delete', 'remove', 'trash'], true)) {
            $this->ajax_send_rest($this->attendance_delete($req));
        }
        $this->ajax_send_rest($this->attendance_update($req));
    }

    public function ajax_attendance_update(): void {
        if (!$this->ajax_perm()) {
            wp_send_json(['success' => false, 'message' => 'Unauthorized. Please login again.'], 401);
        }
        $this->ajax_send_rest($this->attendance_update($this->rest_request_from_action($this->attendance_action_params(), 'POST')));
    }

    public function ajax_attendance_delete(): void {
        if (!$this->ajax_perm()) {
            wp_send_json(['success' => false, 'message' => 'Unauthorized. Please login again.'], 401);
        }
        $this->ajax_send_rest($this->attendance_delete($this->rest_request_from_action($this->attendance_action_params(), 'POST')));
    }

    private function direct_perm(): bool {
        $token = '';
        if (isset($_POST['wsa_admin_token'])) {
            $token = sanitize_text_field(wp_unslash($_POST['wsa_admin_token']));
        } elseif (isset($_REQUEST['wsa_admin_token'])) {
            $token = sanitize_text_field(wp_unslash($_REQUEST['wsa_admin_token']));
        }
        if ($token && WSA_Admin_Portal::validate($token)) return true;

        // WP admin fallback uses a dedicated nonce so direct form posts stay protected.
        $nonce = '';
        if (isset($_POST['_wsa_att_nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['_wsa_att_nonce']));
        } elseif (isset($_REQUEST['_wsa_att_nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_REQUEST['_wsa_att_nonce']));
        }
        if (is_user_logged_in() && current_user_can('manage_options') && wp_verify_nonce($nonce, 'wsa_ap_attendance_direct')) {
            return true;
        }

        return false;
    }

    private function direct_redirect(string $message = '', bool $success = true): void {
        $redirect = '';
        if (isset($_POST['_redirect'])) {
            $redirect = esc_url_raw(wp_unslash($_POST['_redirect']));
        } elseif (isset($_REQUEST['_redirect'])) {
            $redirect = esc_url_raw(wp_unslash($_REQUEST['_redirect']));
        }
        if (!$redirect) $redirect = wp_get_referer();
        if (!$redirect) $redirect = home_url('/wsa-admin/');

        $redirect = remove_query_arg(['wsa_att_msg', 'wsa_att_status', 'wsa_att_ts'], $redirect);
        $redirect = add_query_arg([
            'wsa_att_status' => $success ? 'success' : 'error',
            'wsa_att_msg'    => $message,
            'wsa_att_ts'     => time(),
        ], $redirect);
        wp_safe_redirect($redirect);
        exit;
    }

    private function direct_response_ok($response, string $fallback_message): array {
        if ($response instanceof WP_REST_Response) {
            $data = $response->get_data();
            return [
                'ok' => (bool)($data['success'] ?? false),
                'message' => (string)($data['message'] ?? $fallback_message),
            ];
        }
        return ['ok' => false, 'message' => 'Invalid server response.'];
    }

    public function direct_attendance_update(): void {
        if (!$this->direct_perm()) {
            $this->direct_redirect('Unauthorized. Please login again.', false);
        }

        $id = absint($_POST['id'] ?? $_REQUEST['id'] ?? 0);
        $req = new WP_REST_Request('POST', '/wsa/v2/wsa-admin/attendance/' . $id);
        foreach (['id', 'att_date', 'login_time', 'logout_time', 'notes'] as $key) {
            if (isset($_POST[$key])) {
                $value = wp_unslash($_POST[$key]);
                $req->set_param($key, $key === 'notes' ? sanitize_textarea_field($value) : sanitize_text_field($value));
            }
        }
        if (!$req->get_param('id')) $req->set_param('id', $id);
        $status = $this->direct_response_ok($this->attendance_update($req), 'Record updated.');
        $this->direct_redirect($status['message'], $status['ok']);
    }

    public function direct_attendance_delete(): void {
        if (!$this->direct_perm()) {
            $this->direct_redirect('Unauthorized. Please login again.', false);
        }

        $id = absint($_POST['id'] ?? $_REQUEST['id'] ?? 0);
        $req = new WP_REST_Request('POST', '/wsa/v2/wsa-admin/attendance/' . $id);
        $req->set_param('id', $id);
        $status = $this->direct_response_ok($this->attendance_delete($req), 'Record deleted.');
        $this->direct_redirect($status['message'], $status['ok']);
    }

    /* ══ AUTH ══════════════════════════════════════════ */

    public function login(WP_REST_Request $r) {
        $username = sanitize_text_field($r->get_param('username') ?? '');
        $password = $r->get_param('password') ?? '';
        if (!$username || !$password) return $this->fail('Username and password required.', 400);
        $result = WSA_Admin_Portal::login($username, $password);
        if (!$result['ok']) return $this->fail($result['message'], 401);
        return $this->ok(['token' => $result['token'], 'name' => $result['name'], 'email' => $result['email'], 'role' => $result['role'] ?? 'admin', 'access' => WSA_Module_Access::session_access($result['role'] ?? 'admin')]);
    }

    public function logout(WP_REST_Request $r) {
        $token = WSA_Admin_Portal::token_from_request($r);
        if ($token) WSA_Admin_Portal::logout($token);
        return $this->ok(['message' => 'Logged out.']);
    }

    public function me(WP_REST_Request $r) {
        $sess = WSA_Admin_Portal::validate(WSA_Admin_Portal::token_from_request($r));
        if (!$sess) return $this->fail('Unauthorized.', 401);
        $name  = ''; $email = '';
        if (($sess['type'] ?? '') === 'wp') {
            $u = get_userdata($sess['id']);
            $name = $u ? $u->display_name : ''; $email = $u ? $u->user_email : '';
        } else {
            global $wpdb; $a = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wsa_portal_admins WHERE id=%d", $sess['id']));
            $name = $a ? ($a->name ?: $a->username) : ''; $email = $a ? $a->email : '';
        }
        return $this->ok(['name' => $name, 'email' => $email, 'role' => $sess['role'], 'access' => WSA_Module_Access::session_access($sess['role'])]);
    }

    /* ══ DASHBOARD ════════════════════════════════════ */

    public function dashboard(WP_REST_Request $r) {
        $stats  = WSA_DB::get_dashboard_stats();
        $today  = WSA_DB::get_attendance(['date_from' => current_time('Y-m-d'), 'date_to' => current_time('Y-m-d'), 'limit' => 100]);
        $inside = WSA_DB::get_who_inside();

        $today_rows = [];
        foreach ($today as $rec) {
            $today_rows[] = [
                'id'         => (int) $rec->id,
                'staff_name' => $rec->staff_name,
                'emp_code'   => $rec->emp_code,
                'department' => $rec->department,
                'login_time' => $rec->login_time,
                'logout_time'=> $rec->logout_time,
                'total_hours'=> round((float)$rec->total_hours, 2),
                'status'     => $rec->status,
                'is_late'    => (bool) $rec->is_late,
                'type'       => $rec->type,
                'break_start'=> $rec->break_start ?? null,
            ];
        }
        $inside_rows = [];
        foreach ($inside as $ins) {
            $inside_rows[] = [
                'staff_name' => $ins->staff_name,
                'emp_code'   => $ins->emp_code,
                'department' => $ins->department,
                'login_time' => $ins->login_time,
            ];
        }
        return $this->ok([
            'stats'      => $stats,
            'today'      => $today_rows,
            'inside'     => $inside_rows,
            'server_time'=> current_time('H:i:s'),
            'date_label' => date_i18n('l, d F Y', current_time('timestamp')),
        ]);
    }

    /* ══ STAFF ════════════════════════════════════════ */

    public function staff_list(WP_REST_Request $r) {
        $args = [
            'status'     => sanitize_text_field($r->get_param('status')     ?? ''),
            'department' => sanitize_text_field($r->get_param('department') ?? ''),
            'search'     => sanitize_text_field($r->get_param('search')     ?? ''),
            'limit'      => 500,
        ];
        $staff  = WSA_DB::get_all_staff($args);
        $shifts = WSA_DB::get_shifts();
        return $this->ok(['staff' => $staff, 'shifts' => $shifts]);
    }

    public function staff_create(WP_REST_Request $r) {
        $result = WSA_Staff::add($r->get_params());
        if (is_wp_error($result)) return $this->fail($result->get_error_message());
        return $this->ok(['id' => $result, 'message' => 'Staff added successfully.']);
    }

    public function staff_update(WP_REST_Request $r) {
        $id = (int) $r->get_param('id');
        $result = WSA_Staff::update($id, $r->get_params());
        if (is_wp_error($result)) return $this->fail($result->get_error_message());
        return $this->ok(['message' => 'Staff updated.']);
    }

    public function staff_delete(WP_REST_Request $r) {
        $id = (int) $r->get_param('id');
        WSA_Staff::delete($id);
        return $this->ok(['message' => 'Staff deleted.']);
    }

    public function staff_approve(WP_REST_Request $r) {
        global $wpdb;
        $id = (int) $r->get_param('id');
        $wpdb->update("{$wpdb->prefix}wsa_staff", ['status' => 'active'], ['id' => $id]);
        return $this->ok(['message' => 'Staff approved.']);
    }

    public function staff_reject(WP_REST_Request $r) {
        global $wpdb;
        $id = (int) $r->get_param('id');
        $wpdb->update("{$wpdb->prefix}wsa_staff", ['status' => 'inactive'], ['id' => $id]);
        return $this->ok(['message' => 'Staff rejected.']);
    }

    public function staff_pending(WP_REST_Request $r) {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT s.*, sh.name AS shift_name FROM {$wpdb->prefix}wsa_staff s LEFT JOIN {$wpdb->prefix}wsa_shifts sh ON s.shift_id=sh.id WHERE s.status='pending' ORDER BY s.created_at DESC");
        return $this->ok(['staff' => $rows]);
    }

    public function departments(WP_REST_Request $r) {
        return $this->ok(['departments' => WSA_DB::get_departments()]);
    }

    /* ══ ATTENDANCE ═══════════════════════════════════ */

    public function attendance_list(WP_REST_Request $r) {
        $args = [
            'date_from'  => sanitize_text_field($r->get_param('date_from')  ?? date('Y-m-01')),
            'date_to'    => sanitize_text_field($r->get_param('date_to')    ?? date('Y-m-d')),
            'staff_id'   => absint($r->get_param('staff_id')  ?? 0),
            'department' => sanitize_text_field($r->get_param('department') ?? ''),
            'limit'      => 1000,
            'include_leaves' => true,
        ];
        $rows = WSA_DB::get_attendance($args);
        $result = [];
        $staff_cache = [];
        foreach ($rows as $rec) {
            $bm = (float)($rec->break_duration_mins ?? 0);
            $display_total = (float)$rec->total_hours;
            $display_ot    = (float)($rec->overtime_hours ?? 0);

            // Recalculate display hours from current rules so old saved rows also show correctly.
            // Regular checkout at/after 21:00 shows zero scheduled break.
            if (!empty($rec->login_time) && !empty($rec->logout_time)) {
                $sid = (int) $rec->staff_id;
                if (!array_key_exists($sid, $staff_cache)) {
                    $staff_cache[$sid] = WSA_DB::get_staff($sid);
                }
                $staff_obj = $staff_cache[$sid];
                if ($staff_obj) {
                    $break_for_calc = $bm > 0 ? $bm : WSA_Attendance::scheduled_break_mins($rec->login_time, $rec->logout_time);
                    [$display_total, $display_ot] = WSA_Attendance::calculate($rec->login_time, $rec->logout_time, $staff_obj, $break_for_calc);
                    $bm = WSA_Attendance::skips_scheduled_break_after_9pm($rec->login_time, $rec->logout_time) ? 0.0 : $break_for_calc;
                }
            }

            $result[] = [
                'id'           => (int) $rec->id,
                'staff_name'   => $rec->staff_name,
                'emp_code'     => $rec->emp_code,
                'department'   => $rec->department,
                'att_date'     => $rec->att_date,
                'login_time'   => $rec->login_time,
                'logout_time'  => $rec->logout_time,
                'total_hours'  => round((float)$display_total, 2),
                'overtime_hours'=> round((float)$display_ot, 2),
                'break_mins'   => round($bm, 1),
                'status'       => $rec->status,
                'type'         => $rec->type,
                'is_late'      => (strtoupper((string)$rec->type) === 'SCAN') ? (bool) $rec->is_late : false,
                'notes'        => $rec->notes ?? '',
            ];
        }
        $staff = WSA_DB::get_all_staff(['status' => 'active']);
        $depts = WSA_DB::get_departments();
        return $this->ok(['records' => $result, 'staff' => $staff, 'departments' => $depts]);
    }

    public function attendance_post_action(WP_REST_Request $r) {
        $action = sanitize_key($r->get_param('_wsa_action') ?: $r->get_param('action') ?: 'update');
        if ($action === 'delete' || $action === 'remove') {
            return $this->attendance_delete($r);
        }
        return $this->attendance_update($r);
    }

    public function attendance_update(WP_REST_Request $r) {
        global $wpdb;
        $id = absint($r->get_param('id') ?: $r->get_param('record_id') ?: $r->get_param('attendance_id') ?: $r->get_param('att_id'));
        if (!$id) return $this->fail('Invalid attendance record.', 400);

        $table = $wpdb->prefix . 'wsa_attendance';
        $rec = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id));
        if (!$rec) return $this->fail('Record not found.', 404);

        $date = sanitize_text_field($r->get_param('att_date') ?: $r->get_param('date') ?: '');
        $att_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : $rec->att_date;

        $normalise_time = function($value) {
            $value = trim((string) $value);
            if ($value === '') return '';
            $value = str_replace('T', ' ', $value);
            if (preg_match('/^\d{2}:\d{2}$/', $value)) return $value . ':00';
            if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) return $value;
            if (preg_match('/\d{2}:\d{2}:\d{2}/', $value, $m)) return $m[0];
            if (preg_match('/\d{1}:\d{2}/', $value, $m)) return '0' . $m[0] . ':00';
            if (preg_match('/\d{2}:\d{2}/', $value, $m)) return $m[0] . ':00';
            return '';
        };

        $in_raw  = $r->get_param('login_time');
        if ($in_raw === null) $in_raw = $r->get_param('check_in');
        if ($in_raw === null) $in_raw = $r->get_param('in_time');
        $out_raw = $r->get_param('logout_time');
        if ($out_raw === null) $out_raw = $r->get_param('check_out');
        if ($out_raw === null) $out_raw = $r->get_param('out_time');

        $in_time  = $normalise_time(sanitize_text_field((string)($in_raw ?? '')));
        $out_time = $normalise_time(sanitize_text_field((string)($out_raw ?? '')));

        // If a field is not sent, keep the old value. If it is sent blank, clear it.
        $login_dt = ($in_raw === null)
            ? ($rec->login_time ?: null)
            : ($in_time ? ($att_date . ' ' . $in_time) : null);
        $logout_dt = ($out_raw === null)
            ? ($rec->logout_time ?: null)
            : ($out_time ? ($att_date . ' ' . $out_time) : null);

        $notes_param = $r->get_param('notes');
        $notes = ($notes_param === null) ? (string)($rec->notes ?? '') : sanitize_textarea_field((string)$notes_param);

        $update = [
            'att_date'    => $att_date,
            'login_time'  => $login_dt,
            'logout_time' => $logout_dt,
            'notes'       => $notes,
            'updated_at'  => current_time('mysql'),
        ];

        if ($login_dt && $logout_dt) {
            $staff = WSA_DB::get_staff((int) $rec->staff_id);
            if ($staff) {
                $break_mins_for_calc = (float)($rec->break_duration_mins ?? 0);
                if (WSA_Attendance::skips_scheduled_break_after_9pm($login_dt, $logout_dt)) {
                    $break_mins_for_calc = 0;
                } elseif ($break_mins_for_calc <= 0) {
                    $break_mins_for_calc = WSA_Attendance::scheduled_break_mins($login_dt, $logout_dt);
                }
                [$total_h, $ot_h, $is_early] = WSA_Attendance::calculate($login_dt, $logout_dt, $staff, $break_mins_for_calc);
                $update['break_duration_mins'] = round($break_mins_for_calc, 2);
                $update['total_hours']    = round($total_h, 4);
                $update['overtime_hours'] = round($ot_h, 4);
                $update['status']         = 'OUT';
                $update['is_early_exit']  = (int) $is_early;
            }
        } elseif ($login_dt && !$logout_dt) {
            $update['total_hours']    = 0;
            $update['overtime_hours'] = 0;
            $update['is_early_exit']  = 0;
            $update['status']         = in_array((string) $rec->status, ['ABSENT','BREAK'], true) ? $rec->status : 'IN';
        } elseif (!$login_dt && !$logout_dt) {
            $update['total_hours']    = 0;
            $update['overtime_hours'] = 0;
            $update['is_early_exit']  = 0;
            $update['status']         = in_array((string) $rec->status, ['ABSENT','LEAVE'], true) ? $rec->status : 'ABSENT';
        }

        $saved = $wpdb->update($table, $update, ['id' => $id]);
        if ($saved === false) return $this->fail('Could not update attendance record. ' . $wpdb->last_error, 500);

        return $this->ok(['message' => 'Record updated.', 'id' => $id]);
    }

    public function attendance_delete(WP_REST_Request $r) {
        global $wpdb;
        $id = absint($r->get_param('id') ?: $r->get_param('record_id') ?: $r->get_param('attendance_id') ?: $r->get_param('att_id'));
        if (!$id) return $this->fail('Invalid attendance record.', 400);

        $table = $wpdb->prefix . 'wsa_attendance';
        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE id=%d", $id));
        if (!$exists) return $this->fail('Record not found or already deleted.', 404);

        $wpdb->delete($wpdb->prefix . 'wsa_breaks', ['attendance_id' => $id], ['%d']);
        $deleted = $wpdb->delete($table, ['id' => $id], ['%d']);
        if ($deleted === false) return $this->fail('Could not delete attendance record. ' . $wpdb->last_error, 500);

        return $this->ok(['message' => 'Record deleted.', 'id' => $id]);
    }

    public function manual_entry(WP_REST_Request $r) {
        $result = WSA_Attendance::manual_entry($r->get_params());
        if (!$result['success']) return $this->fail($result['message']);
        return $this->ok(['message' => $result['message']]);
    }

    public function mark_absent(WP_REST_Request $r) {
        $date = sanitize_text_field($r->get_param('date') ?? date('Y-m-d', strtotime('yesterday')));
        WSA_DB::mark_absents_for_date($date);
        return $this->ok(['message' => 'Absent records marked for ' . $date]);
    }

    /* ══ SHIFTS ═══════════════════════════════════════ */

    public function shifts_list(WP_REST_Request $r) {
        return $this->ok(['shifts' => WSA_DB::get_shifts()]);
    }

    public function shifts_create(WP_REST_Request $r) {
        global $wpdb;
        $data = $this->shift_data($r);
        $wpdb->insert("{$wpdb->prefix}wsa_shifts", $data);
        return $this->ok(['id' => $wpdb->insert_id, 'message' => 'Shift created.']);
    }

    public function shifts_update(WP_REST_Request $r) {
        global $wpdb;
        $id   = (int) $r->get_param('id');
        $data = $this->shift_data($r);
        $wpdb->update("{$wpdb->prefix}wsa_shifts", $data, ['id' => $id]);
        return $this->ok(['message' => 'Shift updated.']);
    }

    public function shifts_delete(WP_REST_Request $r) {
        global $wpdb;
        $id = (int) $r->get_param('id');
        $wpdb->delete("{$wpdb->prefix}wsa_shifts", ['id' => $id]);
        return $this->ok(['message' => 'Shift deleted.']);
    }

    private function shift_data(WP_REST_Request $r): array {
        return [
            'name'                  => sanitize_text_field($r->get_param('name')                  ?? ''),
            'start_time'            => sanitize_text_field($r->get_param('start_time')            ?? '09:00:00'),
            'end_time'              => sanitize_text_field($r->get_param('end_time')              ?? '18:00:00'),
            'break_minutes'         => absint($r->get_param('break_minutes')         ?? 60),
            'standard_hours'        => absint($r->get_param('standard_hours')        ?? 8),
            'overtime_after_mins'   => absint($r->get_param('overtime_after_mins')   ?? 480),
            'late_grace_mins'       => absint($r->get_param('late_grace_mins')       ?? 15),
            'early_exit_grace_mins' => absint($r->get_param('early_exit_grace_mins') ?? 15),
        ];
    }

    /* ══ GATES / QR ═══════════════════════════════════ */

    public function gates_list(WP_REST_Request $r) {
        return $this->ok(['gates' => WSA_DB::get_gates()]);
    }

    public function gates_create(WP_REST_Request $r) {
        global $wpdb;
        $data = $this->gate_data($r);
        $data['token'] = wp_generate_password(32, false);
        $wpdb->insert("{$wpdb->prefix}wsa_gates", $data);
        return $this->ok(['id' => $wpdb->insert_id, 'message' => 'Gate created.']);
    }

    public function gates_update(WP_REST_Request $r) {
        global $wpdb;
        $id   = (int) $r->get_param('id');
        $data = $this->gate_data($r);
        $wpdb->update("{$wpdb->prefix}wsa_gates", $data, ['id' => $id]);
        return $this->ok(['message' => 'Gate updated.']);
    }

    public function gates_delete(WP_REST_Request $r) {
        global $wpdb;
        $id = (int) $r->get_param('id');
        $wpdb->delete("{$wpdb->prefix}wsa_gates", ['id' => $id]);
        return $this->ok(['message' => 'Gate deleted.']);
    }

    private function gate_data(WP_REST_Request $r): array {
        return [
            'name'     => sanitize_text_field($r->get_param('name')     ?? ''),
            'type'     => sanitize_text_field($r->get_param('type')     ?? 'both'),
            'location' => sanitize_text_field($r->get_param('location') ?? ''),
            'status'   => sanitize_text_field($r->get_param('status')   ?? 'active'),
        ];
    }

    /* ══ LEAVES ════════════════════════════════════════ */

    public function leaves_list(WP_REST_Request $r) {
        $args = [
            'staff_id'  => absint($r->get_param('staff_id') ?? 0),
            'date_from' => sanitize_text_field($r->get_param('date_from') ?? date('Y-m-01')),
            'date_to'   => sanitize_text_field($r->get_param('date_to')   ?? date('Y-m-d')),
            'limit'     => 500,
        ];
        $leaves = WSA_Salary::get_leaves($args);
        $staff  = WSA_DB::get_all_staff(['status' => 'active']);
        return $this->ok(['leaves' => $leaves, 'staff' => $staff]);
    }

    public function leaves_create(WP_REST_Request $r) {
        $staff_id = absint($r->get_param('staff_id') ?? 0);
        $date     = sanitize_text_field($r->get_param('date')   ?? '');
        $type     = sanitize_text_field($r->get_param('type')   ?? 'Casual');
        $status   = sanitize_text_field($r->get_param('status') ?? 'approved');
        $notes    = sanitize_text_field($r->get_param('notes')  ?? '');
        if (!$staff_id || !$date) return $this->fail('Staff and date required.', 400);
        WSA_Salary::assign_leave($staff_id, $date, $type, $status, $notes);
        return $this->ok(['message' => 'Leave recorded.']);
    }

    public function leaves_status(WP_REST_Request $r) {
        global $wpdb;
        $id     = (int) $r->get_param('id');
        $status = sanitize_text_field($r->get_param('status') ?? 'approved');
        if (!in_array($status, ['approved', 'rejected', 'pending'])) return $this->fail('Invalid status.', 400);
        $wpdb->update("{$wpdb->prefix}wsa_leaves", ['status' => $status], ['id' => $id]);
        return $this->ok(['message' => 'Leave status updated.']);
    }

    public function leaves_delete(WP_REST_Request $r) {
        $id = (int) $r->get_param('id');
        WSA_Salary::delete_leave($id);
        return $this->ok(['message' => 'Leave deleted.']);
    }

    /* ══ SALARY ════════════════════════════════════════ */

    public function salary_summary(WP_REST_Request $r) {
        $yr = (int)($r->get_param('yr') ?? date('Y'));
        $mn = (int)($r->get_param('mn') ?? date('n'));
        $rows = WSA_Salary::all_staff_summary($yr, $mn);
        $staff = WSA_DB::get_all_staff(['status' => 'active']);
        return $this->ok(['summary' => $rows, 'staff' => $staff, 'yr' => $yr, 'mn' => $mn]);
    }

    public function salary_detail(WP_REST_Request $r) {
        $id = (int) $r->get_param('id');
        $yr = (int)($r->get_param('yr') ?? date('Y'));
        $mn = (int)($r->get_param('mn') ?? date('n'));
        $report = WSA_Salary::monthly_report($id, $yr, $mn);
        if (!$report) return $this->fail('Staff not found.', 404);
        return $this->ok(['report' => $report]);
    }

    public function salary_config(WP_REST_Request $r) {
        $id  = (int) $r->get_param('id');
        $cfg = WSA_Salary::get_config($id);
        // Add frontend admin-portal aliases so existing config values show in the modal.
        $cfg->base_salary = $cfg->monthly_salary;
        $cfg->overtime_rate_per_hour = $cfg->ot_rate_per_hr;
        $cfg->absent_deduction_per_day = $cfg->absent_deduction;
        $cfg->working_days_per_month = $cfg->working_days;
        return $this->ok(['config' => $cfg]);
    }

    public function salary_save(WP_REST_Request $r) {
        $staff_id = absint($r->get_param('cfg_staff_id') ?? 0);
        if (!$staff_id) return $this->fail('Staff required.', 400);
        WSA_Salary::save_config($staff_id, $r->get_params());
        return $this->ok(['message' => 'Salary config saved.']);
    }

    /* ══ SETTINGS ══════════════════════════════════════ */

    public function settings_get(WP_REST_Request $r) {
        return $this->ok([
            'wsa_company'          => get_option('wsa_company', get_bloginfo('name')),
            'wsa_standard_hours'   => get_option('wsa_standard_hours', 8),
            'wsa_auto_logout_hr'   => get_option('wsa_auto_logout_hr', 0),
            'wsa_duplicate_mins'   => get_option('wsa_duplicate_mins', 5),
            'wsa_timezone'         => get_option('wsa_timezone', ''),
            'wsa_break_start_time' => get_option('wsa_break_start_time', '13:00'),
            'wsa_break_end_time'   => get_option('wsa_break_end_time', '13:30'),
            'wsa_min_checkout_hrs' => round((int)get_option('wsa_min_checkout_mins', 420) / 60, 1),
            'wsa_work_days'        => get_option('wsa_work_days', '1,2,3,4,5,6'),
        ]);
    }

    public function settings_save(WP_REST_Request $r) {
        $fields = ['wsa_company','wsa_standard_hours','wsa_auto_logout_hr','wsa_duplicate_mins','wsa_timezone','wsa_work_days','wsa_break_start_time','wsa_break_end_time'];
        foreach ($fields as $f) {
            if ($r->get_param($f) !== null) update_option($f, sanitize_text_field($r->get_param($f)));
        }
        if (function_exists('wsa_sync_timezone_setting')) wsa_sync_timezone_setting();
        if ($r->get_param('wsa_min_checkout_hrs') !== null) {
            $hrs = (float) $r->get_param('wsa_min_checkout_hrs');
            update_option('wsa_min_checkout_mins', max(0, (int) round($hrs * 60)));
        }
        return $this->ok(['message' => 'Settings saved.']);
    }

    /* ══ HOLIDAYS ══════════════════════════════════════ */

    public function holidays_list(WP_REST_Request $r) {
        $ym = sanitize_text_field($r->get_param('year_month') ?? date('Y-m'));
        return $this->ok(['holidays' => WSA_DB::get_holidays($ym)]);
    }

    public function holidays_create(WP_REST_Request $r) {
        $date = sanitize_text_field($r->get_param('date') ?? '');
        $name = sanitize_text_field($r->get_param('name') ?? '');
        if (!$date || !$name) return $this->fail('Date and name required.', 400);
        WSA_DB::add_holiday($date, $name);
        return $this->ok(['message' => 'Holiday added.']);
    }

    public function holidays_delete(WP_REST_Request $r) {
        $id = (int) $r->get_param('id');
        WSA_DB::delete_holiday($id);
        return $this->ok(['message' => 'Holiday deleted.']);
    }

    /* ══ INSIDE ════════════════════════════════════════ */

    public function inside(WP_REST_Request $r) {
        $rows = WSA_DB::get_who_inside();
        $result = [];
        foreach ($rows as $ins) {
            $result[] = [
                'staff_name' => $ins->staff_name,
                'emp_code'   => $ins->emp_code,
                'department' => $ins->department,
                'login_time' => $ins->login_time,
            ];
        }
        return $this->ok(['inside' => $result, 'count' => count($result)]);
    }

    /* ══ QUICK MARK ════════════════════════════════════ */

    public function quick_mark_status(WP_REST_Request $r) {
        $all_staff  = WSA_DB::get_all_staff(['status' => 'active', 'limit' => 500]);
        $today_date = current_time('Y-m-d');
        global $wpdb;
        $today_records = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wsa_attendance WHERE att_date=%s ORDER BY id ASC", $today_date
        ));
        $by_staff = [];
        foreach ($today_records as $rec) $by_staff[$rec->staff_id] = $rec;

        $result = [];
        foreach ($all_staff as $s) {
            $rec    = $by_staff[$s->id] ?? null;
            $status = $rec ? $rec->status : 'ABSENT';
            $result[] = [
                'id'          => (int) $s->id,
                'name'        => $s->name,
                'employee_id' => $s->employee_id,
                'department'  => $s->department,
                'status'      => $status,
                'login_time'  => $rec ? $rec->login_time : null,
                'on_break'    => $status === 'BREAK',
                'break_start' => ($rec && $status === 'BREAK') ? $rec->break_start : null,
                'worked_mins' => $rec ? round(WSA_Attendance::worked_mins_so_far($rec), 1) : 0,
            ];
        }
        return $this->ok(['staff' => $result, 'server_ts_ms' => (int)(microtime(true) * 1000)]);
    }

    public function quick_mark_action(WP_REST_Request $r) {
        nocache_headers();
        $staff_id = absint($r->get_param('staff_id') ?? 0);
        $action   = sanitize_text_field($r->get_param('action') ?? '');
        $action   = strtolower(str_replace('-', '_', trim($action)));
        $aliases  = [
            'check_in' => 'checkin', 'in' => 'checkin',
            'check_out' => 'checkout', 'out' => 'checkout',
            'break' => 'break_start', 'start_break' => 'break_start',
            'resume' => 'break_end', 'end_break' => 'break_end',
        ];
        if (isset($aliases[$action])) $action = $aliases[$action];
        if (!$staff_id) return $this->fail('Staff ID required.', 400);
        if (!in_array($action, ['checkin','checkout','break_start','break_end'], true)) return $this->fail('Invalid action.', 400);

        $staff = WSA_DB::get_staff($staff_id);
        if (!$staff) return $this->fail('Staff not found.', 404);

        global $wpdb;
        $now        = current_time('mysql');
        $today_date = current_time('Y-m-d');
        $now_ts     = current_time('timestamp');
        $table      = "{$wpdb->prefix}wsa_attendance";
        $breaks     = "{$wpdb->prefix}wsa_breaks";
        $today      = WSA_DB::get_today($staff_id);
        $status     = $today ? strtoupper((string)$today->status) : 'ABSENT';

        if ($action === 'checkin') {
            if ($today && in_array($status, ['IN','BREAK'], true)) return $this->ok(['message'=>'Already checked in.','status'=>$status]);
            if ($today && $status === 'OUT') return $this->ok(['message'=>'Already completed today.','status'=>'OUT']);
            $is_late = 0;
            if (!empty($staff->start_time)) {
                $grace = strtotime($today_date.' '.$staff->start_time) + ((int)($staff->late_grace_mins ?: 15)*60);
                if ($now_ts > $grace) $is_late = 1;
            }
            if ($today) $wpdb->delete($table, ['id'=>$today->id]);
            $wpdb->insert($table, [
                'staff_id'=>$staff_id, 'att_date'=>$today_date, 'login_time'=>$now, 'status'=>'IN',
                'type'=>'MANUAL', 'is_late'=>0, 'break_duration_mins'=>0, 'break_start'=>null,
            ]);
            WSA_DB::log_scan($staff_id, 'IN');
            return $this->ok(['message'=>'✅ '.$staff->name.' checked IN.','status'=>'IN']);
        }

        if (!$today) return $this->ok(['message'=>'No active record today.','status'=>'ABSENT']);

        if ($action === 'break_start') {
            if ($status === 'BREAK') return $this->ok(['message'=>'Already on break.','status'=>'BREAK']);
            if ($status === 'OUT') return $this->ok(['message'=>'Already completed today.','status'=>'OUT']);
            if ($status !== 'IN') return $this->ok(['message'=>'Staff is not checked in.','status'=>$status ?: 'ABSENT']);
            $wpdb->update($table, ['status'=>'BREAK','break_start'=>$now], ['id'=>$today->id]);
            $wpdb->insert($breaks, ['attendance_id'=>$today->id,'staff_id'=>$staff_id,'break_start'=>$now]);
            return $this->ok(['message'=>'☕ Break started for '.$staff->name.'.','status'=>'BREAK']);
        }

        if ($action === 'break_end') {
            if ($status === 'IN') return $this->ok(['message'=>'Already resumed.','status'=>'IN']);
            if ($status === 'OUT') return $this->ok(['message'=>'Already completed today.','status'=>'OUT']);
            if ($status !== 'BREAK') return $this->ok(['message'=>'Staff is not on break.','status'=>$status ?: 'ABSENT']);
            $break_start_ts = $today->break_start ? strtotime($today->break_start) : $now_ts;
            $break_mins = max(0, ($now_ts - $break_start_ts) / 60);
            $new_total  = (float)($today->break_duration_mins ?: 0) + $break_mins;
            $ob = $wpdb->get_row($wpdb->prepare("SELECT * FROM $breaks WHERE attendance_id=%d AND break_end IS NULL ORDER BY id DESC LIMIT 1", $today->id));
            if ($ob) $wpdb->update($breaks, ['break_end'=>$now,'duration_mins'=>round($break_mins,2)], ['id'=>$ob->id]);
            $wpdb->update($table, ['status'=>'IN','break_duration_mins'=>round($new_total,2),'break_start'=>null], ['id'=>$today->id]);
            return $this->ok(['message'=>'▶️ '.$staff->name.' resumed.','status'=>'IN']);
        }

        if ($action === 'checkout') {
            if ($status === 'OUT') return $this->ok(['message'=>'Already completed today.','status'=>'OUT']);
            if (!in_array($status, ['IN','BREAK'], true)) return $this->ok(['message'=>'No active attendance to check out.','status'=>$status ?: 'ABSENT']);
            $break_total = (float)($today->break_duration_mins ?? 0);
            if ($status === 'BREAK') {
                $break_start_ts = $today->break_start ? strtotime($today->break_start) : $now_ts;
                $break_mins = max(0, ($now_ts - $break_start_ts) / 60);
                $break_total += $break_mins;
                $ob = $wpdb->get_row($wpdb->prepare("SELECT * FROM $breaks WHERE attendance_id=%d AND break_end IS NULL ORDER BY id DESC LIMIT 1", $today->id));
                if ($ob) $wpdb->update($breaks, ['break_end'=>$now,'duration_mins'=>round($break_mins,2)], ['id'=>$ob->id]);
            }
            $calc = WSA_Attendance::calculate($today->login_time, $now, $staff, $break_total);
            $total_h  = (float)($calc[0] ?? 0);
            $ot_h     = (float)($calc[1] ?? 0);
            $is_early = (int)($calc[2] ?? 0);
            $wpdb->update($table, [
                'logout_time'=>$now, 'total_hours'=>round($total_h,4), 'overtime_hours'=>round($ot_h,4),
                'status'=>'OUT', 'is_early_exit'=>$is_early, 'break_duration_mins'=>round($break_total,2), 'break_start'=>null,
            ], ['id'=>$today->id]);
            WSA_DB::log_scan($staff_id, 'OUT');
            $h = floor($total_h); $m = round(($total_h - $h) * 60);
            return $this->ok(['message'=>'🚪 '.$staff->name.' OUT. Worked: '.$h.'h '.$m.'m','status'=>'OUT']);
        }

        return $this->fail('Unknown action.', 400);
    }

    /* ── Super-admin permission ── */
    public function perm_super(WP_REST_Request $r): bool {
        $token = WSA_Admin_Portal::token_from_request($r);
        $sess  = WSA_Admin_Portal::validate($token);
        if ($sess && WSA_Admin_Portal::is_super($sess)) return true;

        // WordPress administrators are treated as Super Admins in the frontend portal.
        // This keeps Role Access working when the portal token is missing/expired or a
        // cache/security layer strips custom REST headers.
        if (is_user_logged_in() && current_user_can('manage_options')) return true;

        return false;
    }

    /* ══ SUPER ADMIN MANAGEMENT ════════════════════════════ */

    public function sa_list(WP_REST_Request $r) {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id,username,name,email,role,status,last_login,created_at FROM {$wpdb->prefix}wsa_portal_admins ORDER BY role DESC,id ASC");
        return $this->ok(['admins' => $rows]);
    }

    public function sa_create(WP_REST_Request $r) {
        global $wpdb;
        $username = sanitize_user($r->get_param('username') ?? '');
        $password = $r->get_param('password') ?? '';
        $name     = sanitize_text_field($r->get_param('name')  ?? '');
        $email    = sanitize_email($r->get_param('email')      ?? '');
        $role     = in_array($r->get_param('role'), ['super_admin','admin']) ? $r->get_param('role') : 'admin';
        if (!$username || strlen($password) < 6) return $this->fail('Username and password (min 6 chars) required.', 400);
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}wsa_portal_admins WHERE username=%s", $username));
        if ($exists) return $this->fail('Username already exists.', 409);
        $wpdb->insert("{$wpdb->prefix}wsa_portal_admins", [
            'username'   => $username,
            'name'       => $name ?: $username,
            'email'      => $email,
            'password'   => password_hash($password, PASSWORD_DEFAULT),
            'role'       => $role,
            'status'     => 'active',
            'created_at' => current_time('mysql'),
        ]);
        return $this->ok(['id' => $wpdb->insert_id, 'message' => 'Admin created successfully.']);
    }

    public function sa_update(WP_REST_Request $r) {
        global $wpdb;
        $id   = (int) $r->get_param('id');
        $data = [];
        if ($r->get_param('name'))   $data['name']   = sanitize_text_field($r->get_param('name'));
        if ($r->get_param('email'))  $data['email']  = sanitize_email($r->get_param('email'));
        if ($r->get_param('status')) $data['status'] = in_array($r->get_param('status'), ['active','inactive']) ? $r->get_param('status') : 'active';
        if ($r->get_param('role'))   $data['role']   = in_array($r->get_param('role'), ['super_admin','admin']) ? $r->get_param('role') : 'admin';
        if ($r->get_param('password') && strlen($r->get_param('password')) >= 6) {
            $data['password'] = password_hash($r->get_param('password'), PASSWORD_DEFAULT);
        }
        if ($data) $wpdb->update("{$wpdb->prefix}wsa_portal_admins", $data, ['id' => $id]);
        return $this->ok(['message' => 'Admin updated.']);
    }

    public function sa_delete(WP_REST_Request $r) {
        global $wpdb;
        $id = (int) $r->get_param('id');
        // Prevent deleting the last super admin
        $is_super = $wpdb->get_var($wpdb->prepare("SELECT role FROM {$wpdb->prefix}wsa_portal_admins WHERE id=%d", $id));
        if ($is_super === 'super_admin') {
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_portal_admins WHERE role='super_admin'");
            if ($count <= 1) return $this->fail('Cannot delete the last Super Admin.', 409);
        }
        $wpdb->delete("{$wpdb->prefix}wsa_portal_admins", ['id' => $id]);
        return $this->ok(['message' => 'Admin deleted.']);
    }

    public function sa_reset(WP_REST_Request $r) {
        global $wpdb;
        $id  = (int) $r->get_param('id');
        $pwd = $r->get_param('password') ?? '';
        if (strlen($pwd) < 6) return $this->fail('New password must be at least 6 characters.', 400);
        $wpdb->update("{$wpdb->prefix}wsa_portal_admins",
            ['password' => password_hash($pwd, PASSWORD_DEFAULT)], ['id' => $id]);
        return $this->ok(['message' => 'Password reset successfully.']);
    }


    public function access_get(WP_REST_Request $r) {
        $sess = WSA_Admin_Portal::validate(WSA_Admin_Portal::token_from_request($r));
        $role = $sess ? ($sess['role'] ?? 'admin') : 'admin';
        if (!$sess && is_user_logged_in() && current_user_can('manage_options')) {
            $role = 'super_admin';
        }
        return $this->ok(WSA_Module_Access::api_payload($role));
    }

    public function access_save(WP_REST_Request $r) {
        $frontend = $r->get_param('admin_modules');
        $backend  = $r->get_param('backend_admin_modules');

        if (!is_array($frontend)) $frontend = [];
        if (!is_array($backend))  $backend = [];

        WSA_Module_Access::save($frontend, $backend);

        return $this->ok([
            'message' => 'Dashboard module access saved successfully.',
            'access'  => WSA_Module_Access::api_payload('super_admin')
        ]);
    }

    /* ═════ FIRST BOOT ════════════════════════════════════════ */

    public function first_boot(WP_REST_Request $r) {
        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_portal_admins");
        return $this->ok(['needs_setup' => $count === 0, 'count' => $count]);
    }

    public function first_boot_do(WP_REST_Request $r) {
        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_portal_admins");
        if ($count > 0) return $this->fail('Super admin already set up.', 409);
        $username = sanitize_user($r->get_param('username') ?? '');
        $password = $r->get_param('password') ?? '';
        $name     = sanitize_text_field($r->get_param('name')  ?? $username);
        $email    = sanitize_email($r->get_param('email')      ?? '');
        if (!$username || strlen($password) < 6) return $this->fail('Username and password (min 6) required.', 400);
        $wpdb->insert("{$wpdb->prefix}wsa_portal_admins", [
            'username'   => $username,
            'name'       => $name,
            'email'      => $email,
            'password'   => password_hash($password, PASSWORD_DEFAULT),
            'role'       => 'super_admin',
            'status'     => 'active',
            'created_at' => current_time('mysql'),
        ]);
        // Immediately issue a token for this new super admin
        $token = bin2hex(random_bytes(24));
        set_transient('wsa_adminp_' . $token, json_encode(['id' => $wpdb->insert_id, 'role' => 'super_admin', 'type' => 'portal']), 43200);
        return $this->ok(['message' => 'Super Admin created!', 'token' => $token, 'name' => $name, 'role' => 'super_admin', 'access' => WSA_Module_Access::session_access('super_admin')]);
    }

    /* ── Helpers ── */
    private function ok($data): WP_REST_Response {
        return new WP_REST_Response(['success' => true] + $data, 200);
    }
    private function fail(string $msg, int $code = 400): WP_REST_Response {
        return new WP_REST_Response(['success' => false, 'message' => $msg], $code);
    }
}
