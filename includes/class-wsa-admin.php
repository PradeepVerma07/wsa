<?php
defined('ABSPATH') || exit;

class WSA_Admin {

    public function __construct() {
        add_action('wp_ajax_wsa_get_salary_config', [$this, 'ajax_get_salary_config']);
        add_action('wp_ajax_nopriv_wsa_get_salary_config', [$this, 'ajax_get_salary_config']);
        add_action('wp_ajax_wsa_front_save_salary_config', [$this, 'ajax_front_save_salary_config']);
        add_action('wp_ajax_nopriv_wsa_front_save_salary_config', [$this, 'ajax_front_save_salary_config']);
        add_action('wp_ajax_wsa_front_salary_detail', [$this, 'ajax_front_salary_detail']);
        add_action('wp_ajax_nopriv_wsa_front_salary_detail', [$this, 'ajax_front_salary_detail']);
        add_action('admin_menu',            [$this, 'menus']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        // Form POST handlers
        foreach ([
            'wsa_save_staff','wsa_delete_staff','wsa_save_shift','wsa_delete_shift',
            'wsa_save_gate','wsa_delete_gate','wsa_manual_entry','wsa_save_settings',
            'wsa_export','wsa_delete_attendance','wsa_add_holiday','wsa_delete_holiday',
            'wsa_save_salary_config','wsa_assign_leave','wsa_delete_leave',
            'wsa_leave_status','wsa_export_salary','wsa_export_salary_detail','wsa_run_absent_cron',
            'wsa_approve_staff','wsa_reject_staff','wsa_save_portal_admin','wsa_delete_portal_admin',
        ] as $action) {
            add_action("admin_post_{$action}", [$this, "handle_{$action}"]);
        }
    }

    /* ─── Menus ─────────────────────────────────────── */
    public function menus() {
        add_menu_page('Staff Attendance', 'Attendance', 'manage_options', 'wsa-dashboard',
            [$this,'page_dashboard'], 'dashicons-id-alt', 29);
        $pages = [
            ['Dashboard',    'wsa-dashboard',   'page_dashboard'],
            ['Attendance',   'wsa-attendance',  'page_attendance'],
            ['Quick Mark',   'wsa-quick-mark',  'page_quick_mark'],
            ['Who\'s Inside','wsa-inside',      'page_inside'],
            ['Staff',        'wsa-staff',       'page_staff'],
            ['Pending Staff','wsa-pending',     'page_pending'],
            ['Manual Entry', 'wsa-manual',      'page_manual'],
            ['QR Codes',     'wsa-qrcodes',     'page_qrcodes'],
            ['Shifts',       'wsa-shifts',      'page_shifts'],
            ['Settings',     'wsa-settings',    'page_settings'],
            ['Salary',       'wsa-salary',      'page_salary'],
            ['Salary Slip',  'wsa-salary-slip', 'page_salary_slip'],
            ['Face Attendance','wsa-face-attendance','page_face_attendance'],
            ['Leaves',       'wsa-leaves',      'page_leaves'],
            ['Portal Admins','wsa-portal-admins','page_portal_admins'],
        ];
        foreach ($pages as [$title, $slug, $cb]) {
            if (class_exists('WSA_Module_Access') && !WSA_Module_Access::can_backend_slug($slug)) {
                continue;
            }
            add_submenu_page('wsa-dashboard', $title, $title, 'manage_options', $slug, [$this, $cb]);
        }
    }

    public function enqueue($hook) {
        if (strpos($hook, 'wsa-') === false && $hook !== 'toplevel_page_wsa-dashboard') return;
        wp_enqueue_style('wsa-admin',  WSA_URL . 'admin/css/admin.css', [], WSA_VER);
        wp_enqueue_script('wsa-admin', WSA_URL . 'admin/js/admin.js', ['jquery'], WSA_VER, false);
        if (strpos($hook, 'wsa-face-attendance') !== false) {
            wp_enqueue_style('wsa-face', WSA_URL . 'public/css/face.css', [], WSA_VER);
            wp_enqueue_script('face-api', 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.15/dist/face-api.js', [], WSA_VER, true);
            wp_enqueue_script('wsa-face-admin', WSA_URL . 'admin/js/face-admin.js', ['face-api','jquery'], WSA_VER, true);
            // Get all shifts for the quick-add modal
            global $wpdb;
            $shifts_data = $wpdb->get_results("SELECT id, name, start_time, end_time FROM {$wpdb->prefix}wsa_shifts ORDER BY name ASC");
            wp_localize_script('wsa-face-admin', 'wsaFaceAdmin', [
                'apiStaff'     => rest_url('wsa/v2/face/staff'),
                'apiAddStaff'  => rest_url('wsa/v2/face/staff/add'),
                'apiRegister'  => rest_url('wsa/v2/face/register'),
                'apiDelete'    => rest_url('wsa/v2/face/delete'),
                'apiLogs'      => rest_url('wsa/v2/face/logs'),
                'apiStats'     => rest_url('wsa/v2/face/stats'),
                'apiModels'    => WSA_URL . 'public/models',
                'nonce'        => wp_create_nonce('wp_rest'),
                'shifts'       => $shifts_data,
                'staffPageUrl' => admin_url('admin.php?page=wsa-staff'),
            ]);
        } // load in header so inline admin page scripts can access localized wsaAdmin
        wp_localize_script('wsa-admin', 'wsaAdmin', [
            'ajaxurl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('wsa_admin'),
            'siteurl'      => get_site_url(),
            'rest_url'     => rest_url('wsa/v2/'),
            'rest_nonce'   => wp_create_nonce('wp_rest'),
            'scanner_page' => get_permalink(get_option('wsa_scanner_page_id')),
        ]);
    }

    /* ─── Page renderers ─────────────────────────────── */
    public function page_dashboard()  { include WSA_DIR.'admin/views/dashboard.php'; }
    public function page_attendance() { include WSA_DIR.'admin/views/attendance.php'; }
    public function page_inside()     { include WSA_DIR.'admin/views/who-inside.php'; }
    public function page_staff()      { include WSA_DIR.'admin/views/staff.php'; }
    public function page_manual()     { include WSA_DIR.'admin/views/manual-entry.php'; }
    public function page_qrcodes()    { include WSA_DIR.'admin/views/qrcodes.php'; }
    public function page_shifts()     { include WSA_DIR.'admin/views/shifts.php'; }
    public function page_settings()   { include WSA_DIR.'admin/views/settings.php'; }
    public function page_salary()     { include WSA_DIR.'admin/views/salary.php'; }
    public function page_salary_slip(){ include WSA_DIR.'admin/views/salary-slip.php'; }
    public function page_face_attendance(){ include WSA_DIR.'admin/views/face-attendance.php'; }
    public function page_leaves()     { include WSA_DIR.'admin/views/leaves.php'; }
    public function page_quick_mark() { include WSA_DIR.'admin/views/quick-mark.php'; }
    public function page_pending()    { include WSA_DIR.'admin/views/pending-staff.php'; }
    public function page_portal_admins() { include WSA_DIR.'admin/views/portal-admins.php'; }

    public function handle_wsa_save_portal_admin() {
        $this->check_nonce('wsa_portal_admin_action');
        global $wpdb;
        $id = absint($_POST['admin_id'] ?? 0);
        $username = sanitize_user($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $data = [
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'role' => in_array(($_POST['role'] ?? 'admin'), ['super_admin','admin'], true) ? $_POST['role'] : 'admin',
            'status' => in_array(($_POST['status'] ?? 'active'), ['active','inactive'], true) ? $_POST['status'] : 'active',
        ];
        if (!$id) {
            if (!$username || strlen($password) < 6) { wp_redirect(admin_url('admin.php?page=wsa-portal-admins&error=Username and min 6 char password required')); exit; }
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}wsa_portal_admins WHERE username=%s", $username));
            if ($exists) { wp_redirect(admin_url('admin.php?page=wsa-portal-admins&error=Username already exists')); exit; }
            $data['username'] = $username;
            $data['password'] = password_hash($password, PASSWORD_DEFAULT);
            $data['created_at'] = current_time('mysql');
            $wpdb->insert("{$wpdb->prefix}wsa_portal_admins", $data);
        } else {
            if ($password !== '') $data['password'] = password_hash($password, PASSWORD_DEFAULT);
            $wpdb->update("{$wpdb->prefix}wsa_portal_admins", $data, ['id'=>$id]);
        }
        wp_redirect(admin_url('admin.php?page=wsa-portal-admins&saved=1')); exit;
    }

    public function handle_wsa_delete_portal_admin() {
        $id = absint($_GET['id'] ?? 0);
        $this->check_nonce('wsa_delete_portal_admin_' . $id);
        global $wpdb;
        $role = $wpdb->get_var($wpdb->prepare("SELECT role FROM {$wpdb->prefix}wsa_portal_admins WHERE id=%d", $id));
        if ($role === 'super_admin') {
            $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_portal_admins WHERE role='super_admin'");
            if ($count <= 1) { wp_redirect(admin_url('admin.php?page=wsa-portal-admins&error=Cannot delete last Super Admin')); exit; }
        }
        $wpdb->delete("{$wpdb->prefix}wsa_portal_admins", ['id'=>$id]);
        wp_redirect(admin_url('admin.php?page=wsa-portal-admins&deleted=1')); exit;
    }

    /* ─── Staff handlers ─────────────────────────────── */
    public function handle_wsa_save_staff() {
        $this->check_nonce('wsa_staff_action');
        $id     = absint($_POST['staff_db_id'] ?? 0);
        $result = $id ? WSA_Staff::update($id, $_POST) : WSA_Staff::add($_POST);
        if (is_wp_error($result)) {
            wp_redirect(admin_url('admin.php?page=wsa-staff&error=' . urlencode($result->get_error_message())));
        } else {
            wp_redirect(admin_url('admin.php?page=wsa-staff&saved=1'));
        }
        exit;
    }

    public function handle_wsa_delete_staff() {
        $this->check_nonce('wsa_delete_staff_' . absint($_GET['id']));
        WSA_Staff::delete(absint($_GET['id']));
        wp_redirect(admin_url('admin.php?page=wsa-staff&deleted=1'));
        exit;
    }

    /* ─── Shift handlers ────────────────────────────── */
    public function handle_wsa_save_shift() {
        $this->check_nonce('wsa_shift_action');
        global $wpdb;
        $id   = absint($_POST['shift_db_id'] ?? 0);
        $data = [
            'name'                 => sanitize_text_field($_POST['name']),
            'start_time'           => sanitize_text_field($_POST['start_time']),
            'end_time'             => sanitize_text_field($_POST['end_time']),
            'break_minutes'        => absint($_POST['break_minutes']),
            'standard_hours'       => absint($_POST['standard_hours']),
            'overtime_after_mins'  => absint($_POST['overtime_after_mins']),
            'late_grace_mins'      => absint($_POST['late_grace_mins']),
            'early_exit_grace_mins'=> absint($_POST['early_exit_grace_mins']),
        ];
        $id ? $wpdb->update("{$wpdb->prefix}wsa_shifts", $data, ['id'=>$id])
            : $wpdb->insert("{$wpdb->prefix}wsa_shifts", $data);
        wp_redirect(admin_url('admin.php?page=wsa-shifts&saved=1'));
        exit;
    }

    public function handle_wsa_delete_shift() {
        $this->check_nonce('wsa_delete_shift_' . absint($_GET['id']));
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}wsa_shifts", ['id' => absint($_GET['id'])]);
        wp_redirect(admin_url('admin.php?page=wsa-shifts&deleted=1'));
        exit;
    }

    /* ─── Gate handlers ─────────────────────────────── */
    public function handle_wsa_save_gate() {
        $this->check_nonce('wsa_gate_action');
        global $wpdb;
        $id   = absint($_POST['gate_db_id'] ?? 0);
        $data = [
            'name'     => sanitize_text_field($_POST['name']),
            'type'     => sanitize_text_field($_POST['type']),
            'location' => sanitize_text_field($_POST['location']),
            'status'   => sanitize_text_field($_POST['status']),
        ];
        if (!$id) $data['token'] = wp_generate_password(32, false);
        $id ? $wpdb->update("{$wpdb->prefix}wsa_gates", $data, ['id'=>$id])
            : $wpdb->insert("{$wpdb->prefix}wsa_gates", $data);
        wp_redirect(admin_url('admin.php?page=wsa-qrcodes&saved=1'));
        exit;
    }

    public function handle_wsa_delete_gate() {
        $this->check_nonce('wsa_delete_gate_' . absint($_GET['id']));
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}wsa_gates", ['id' => absint($_GET['id'])]);
        wp_redirect(admin_url('admin.php?page=wsa-qrcodes&deleted=1'));
        exit;
    }

    /* ─── Manual entry ──────────────────────────────── */
    public function handle_wsa_manual_entry() {
        $this->check_nonce('wsa_manual_action');
        $result = WSA_Attendance::manual_entry($_POST);
        if ($result['success']) {
            wp_redirect(admin_url('admin.php?page=wsa-manual&saved=1'));
        } else {
            wp_redirect(admin_url('admin.php?page=wsa-manual&error=' . urlencode($result['message'])));
        }
        exit;
    }

    /* ─── Delete attendance ─────────────────────────── */
    public function handle_wsa_delete_attendance() {
        $this->check_nonce('wsa_delete_att_' . absint($_GET['id']));
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}wsa_attendance", ['id' => absint($_GET['id'])]);
        wp_redirect(admin_url('admin.php?page=wsa-attendance&deleted=1'));
        exit;
    }

    /* ─── Settings ───────────────────────────────────── */
    public function handle_wsa_save_settings() {
        $this->check_nonce('wsa_settings_action');
        $fields = ['wsa_company','wsa_standard_hours','wsa_auto_logout_hr','wsa_duplicate_mins','wsa_timezone','wsa_break_start_time','wsa_break_end_time'];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) update_option($f, sanitize_text_field($_POST[$f]));
        }
        if (function_exists('wsa_sync_timezone_setting')) wsa_sync_timezone_setting();
        // Minimum checkout hours → stored as minutes
        if (isset($_POST['wsa_min_checkout_hrs'])) {
            $hrs = (float) $_POST['wsa_min_checkout_hrs'];
            update_option('wsa_min_checkout_mins', max(0, (int) round($hrs * 60)));
        }
        wp_redirect(admin_url('admin.php?page=wsa-settings&saved=1'));
        exit;
    }

    /* ─── Export ─────────────────────────────────────── */
    public function handle_wsa_export() {
        $this->check_nonce('wsa_export_action');
        $format = sanitize_text_field($_POST['format'] ?? 'csv');
        $args   = [
            'date_from'  => sanitize_text_field($_POST['date_from']   ?? date('Y-m-01')),
            'date_to'    => sanitize_text_field($_POST['date_to']     ?? date('Y-m-d')),
            'staff_id'   => absint($_POST['staff_id']   ?? 0),
            'department' => sanitize_text_field($_POST['department']  ?? ''),
            'limit'      => 9999,
        ];
        WSA_Export::export($args, $format);
        exit;
    }

    /* ─── Salary Config AJAX ────────────────────────── */
    public function handle_wsa_save_salary_config() {
        $this->check_nonce('wsa_salary_config_action');
        $staff_id = absint($_POST['cfg_staff_id'] ?? 0);
        if (!$staff_id) {
            wp_redirect(admin_url('admin.php?page=wsa-salary&error=' . urlencode('Please select a staff member.')));
            exit;
        }
        WSA_Salary::save_config($staff_id, $_POST);
        $yr = absint($_POST['yr'] ?? date('Y'));
        $mn = absint($_POST['mn'] ?? date('n'));
        wp_redirect(admin_url("admin.php?page=wsa-salary&yr={$yr}&mn={$mn}&saved=1"));
        exit;
    }

    /* ─── Leave handlers ─────────────────────────────── */
    public function handle_wsa_assign_leave() {
        $this->check_nonce('wsa_leave_action');
        $staff_id = absint($_POST['leave_staff_id'] ?? 0);
        $date     = sanitize_text_field($_POST['leave_date']   ?? '');
        $type     = sanitize_text_field($_POST['leave_type']   ?? 'Casual');
        $status   = sanitize_text_field($_POST['leave_status'] ?? 'approved');
        $notes    = sanitize_text_field($_POST['leave_notes']  ?? '');
        if (!$staff_id || !$date) {
            wp_redirect(admin_url('admin.php?page=wsa-leaves&error=' . urlencode('Staff and date required.')));
            exit;
        }
        WSA_Salary::assign_leave($staff_id, $date, $type, $status, $notes);
        wp_redirect(admin_url('admin.php?page=wsa-leaves&saved=1'));
        exit;
    }

    public function handle_wsa_delete_leave() {
        $id = absint($_GET['id'] ?? 0);
        $this->check_nonce('wsa_delete_leave_' . $id);
        WSA_Salary::delete_leave($id);
        wp_redirect(admin_url('admin.php?page=wsa-leaves&deleted=1'));
        exit;
    }

    public function handle_wsa_leave_status() {
        $id     = absint($_GET['id']     ?? 0);
        $status = sanitize_text_field($_GET['status'] ?? 'approved');
        $this->check_nonce('wsa_leave_status_' . $id);
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}wsa_leaves", ['status' => $status], ['id' => $id]);
        wp_redirect(admin_url('admin.php?page=wsa-leaves&saved=1'));
        exit;
    }

    /* ─── Salary CSV export ──────────────────────────── */
    public function handle_wsa_export_salary() {
        $this->check_nonce('wsa_export_action');
        $yr   = (int) ($_POST['yr'] ?? date('Y'));
        $mn   = (int) ($_POST['mn'] ?? date('n'));
        $rows = WSA_Salary::all_staff_summary($yr, $mn);
        $label = date('F-Y', mktime(0,0,0,$mn,1,$yr));
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (function_exists('nocache_headers')) { nocache_headers(); }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="salary-'.$label.'.csv"');
        $out = fopen('php://output','w');
        fputcsv($out, ['Employee','EmpID','Department','Present','Absent','Leave','Late','WorkHours','OT_Hours','Gross','Deductions','Net','Currency']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['staff']->name, $r['staff']->employee_id, $r['staff']->department,
                $r['present'], $r['absent'], $r['on_leave'], $r['late_count'],
                $r['total_hours'], $r['total_ot'],
                $r['gross'], $r['deductions'], $r['net'], $r['currency'],
            ]);
        }
        fclose($out);
        exit;
    }

    public function handle_wsa_export_salary_detail() {
        $staff_id = absint($_GET['staff_id'] ?? 0);
        if (!$staff_id) {
            wp_die('Staff required.');
        }
        $this->check_nonce('wsa_export_salary_detail_' . $staff_id);

        $yr = (int) ($_GET['yr'] ?? date('Y'));
        $mn = (int) ($_GET['mn'] ?? date('n'));
        $yr = max(2000, min(2100, $yr));
        $mn = max(1, min(12, $mn));

        $report = WSA_Salary::monthly_report($staff_id, $yr, $mn);
        if (!$report) {
            wp_die('Salary detail not found.');
        }

        $staff = $report['staff'];
        $cfg   = $report['config'];
        $label = date('F-Y', mktime(0, 0, 0, $mn, 1, $yr));
        $safe_name = sanitize_title($staff->name ?: 'staff');
        $filename = 'salary-detail-' . $safe_name . '-' . $label . '.csv';

        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (function_exists('nocache_headers')) { nocache_headers(); }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF");

        fputcsv($out, ['Salary Detail Report']);
        fputcsv($out, ['Employee', $staff->name]);
        fputcsv($out, ['Employee ID', $staff->employee_id]);
        fputcsv($out, ['Department', $staff->department ?: '']);
        fputcsv($out, ['Month', $report['month_label']]);
        fputcsv($out, ['Currency', $report['currency']]);
        fputcsv($out, []);

        fputcsv($out, ['Summary']);
        fputcsv($out, ['Present', 'Absent', 'Leave', 'Late', 'Work Hours', 'OT Hours', 'Gross', 'Deductions', 'Net']);
        fputcsv($out, [
            $report['present'],
            $report['absent'],
            $report['on_leave'],
            $report['late_count'],
            WSA_Salary::fmt_hours($report['total_hours']),
            WSA_Salary::fmt_hours($report['total_ot']),
            $report['gross'],
            $report['deductions'],
            $report['net'],
        ]);
        fputcsv($out, []);

        fputcsv($out, ['Salary Breakup']);
        fputcsv($out, ['Item', 'Value']);
        fputcsv($out, ['Monthly Gross Config', (float) ($cfg->monthly_salary ?? 0)]);
        fputcsv($out, ['Working Days Config', (int) ($cfg->working_days ?? 26)]);
        fputcsv($out, ['Daily Rate', $report['daily_rate']]);
        fputcsv($out, ['Basic Earned', $report['earned_basic']]);
        fputcsv($out, ['Leave Pay', $report['leave_pay']]);
        fputcsv($out, ['OT Rate Per Hour', (float) ($cfg->ot_rate_per_hr ?? 0)]);
        fputcsv($out, ['Overtime Pay', $report['ot_pay']]);
        fputcsv($out, ['Absent Deduction Per Day', (float) ($cfg->absent_deduction ?? 0)]);
        fputcsv($out, ['Total Deductions', $report['deductions']]);
        fputcsv($out, ['Net Salary', $report['net']]);
        fputcsv($out, []);

        fputcsv($out, ['Daily Attendance Log']);
        fputcsv($out, ['Date', 'Day', 'Status', 'Check In', 'Check Out', 'Work Hours', 'Break', 'OT Hours', 'Late', 'Leave Type', 'Notes']);
        foreach ($report['days'] as $day) {
            if (($day['status'] ?? '') === 'future') {
                continue;
            }
            $date = $day['date'] ?? '';
            $break_mins = isset($day['salary_break_mins']) ? (float) $day['salary_break_mins'] : (float) ($day['break_duration_mins'] ?? 0);
            fputcsv($out, [
                $date,
                $date ? date('D', strtotime($date)) : '',
                $day['status'] ?? '',
                !empty($day['login']) ? date('h:i A', strtotime($day['login'])) : '',
                !empty($day['logout']) ? date('h:i A', strtotime($day['logout'])) : '',
                !empty($day['hours']) ? WSA_Salary::fmt_hours($day['hours']) : '',
                $break_mins > 0 ? WSA_Salary::fmt_hours($break_mins / 60) : '',
                !empty($day['ot']) ? WSA_Salary::fmt_hours($day['ot']) : '',
                !empty($day['is_late']) ? 'Yes' : 'No',
                $day['leave_type'] ?? '',
                $day['notes'] ?? '',
            ]);
        }

        fclose($out);
        exit;
    }

    /* ─── Manual absent-cron trigger ────────────────── */
    public function handle_wsa_run_absent_cron() {
        $this->check_nonce('wsa_admin');
        $date = sanitize_text_field($_GET['date'] ?? date('Y-m-d', strtotime('yesterday')));
        WSA_DB::mark_absents_for_date($date);
        wp_redirect(admin_url('admin.php?page=wsa-attendance&saved=1'));
        exit;
    }

    private function front_ajax_allowed() {
        // Frontend admin portal sends wp_rest nonce + portal token. Older builds checked only
        // the custom `wsa_admin` nonce, so Salary Detail fallback could fail on frontend while
        // WP-admin detail continued working. Accept all valid frontend/admin paths here.
        $nonce = '';
        foreach (['nonce', '_ajax_nonce', '_wpnonce'] as $nonce_key) {
            if (isset($_REQUEST[$nonce_key])) {
                $nonce = sanitize_text_field(wp_unslash($_REQUEST[$nonce_key]));
                break;
            }
        }

        $nonce_ok = false;
        if ($nonce) {
            $nonce_ok = (bool) (
                wp_verify_nonce($nonce, 'wsa_admin') ||
                wp_verify_nonce($nonce, 'wp_rest') ||
                wp_verify_nonce($nonce, 'wsa_ap_attendance_direct')
            );
        }

        $token = '';
        if (isset($_POST['wsa_admin_token'])) {
            $token = sanitize_text_field(wp_unslash($_POST['wsa_admin_token']));
        } elseif (isset($_REQUEST['wsa_admin_token'])) {
            $token = sanitize_text_field(wp_unslash($_REQUEST['wsa_admin_token']));
        }
        $portal_ok = $token && class_exists('WSA_Admin_Portal') && WSA_Admin_Portal::validate($token);

        // Logged-in WP admins are allowed as a final fallback, matching REST permission logic.
        $wp_admin_ok = is_user_logged_in() && current_user_can('manage_options');

        if (!$nonce_ok && !$portal_ok && !$wp_admin_ok) {
            wp_send_json_error(['message' => 'Security check failed. Please refresh and login again.'], 403);
        }
    }

    public function ajax_get_salary_config() {
        $this->front_ajax_allowed();
        $sid = absint($_POST['staff_id'] ?? 0);
        $cfg = $sid ? WSA_Salary::get_config($sid) : null;
        wp_send_json_success($cfg);
    }



    public function ajax_front_save_salary_config() {
        $this->front_ajax_allowed();
        $staff_id = absint($_POST['cfg_staff_id'] ?? $_POST['staff_id'] ?? 0);
        if (!$staff_id) {
            wp_send_json_error(['message' => 'Please select a staff member.'], 400);
        }
        WSA_Salary::save_config($staff_id, $_POST);
        wp_send_json_success(['message' => 'Salary config saved.']);
    }

    public function ajax_front_salary_detail() {
        $this->front_ajax_allowed();
        $staff_id = absint($_POST['staff_id'] ?? 0);
        $yr = absint($_POST['yr'] ?? date('Y'));
        $mn = absint($_POST['mn'] ?? date('n'));
        if (!$staff_id) {
            wp_send_json_error(['message' => 'Staff required.'], 400);
        }
        $report = WSA_Salary::monthly_report($staff_id, $yr, $mn);
        if (!$report) {
            wp_send_json_error(['message' => 'Staff not found.'], 404);
        }
        wp_send_json_success(['report' => $report]);
    }

    /* ── Approve / Reject pending staff ─────────────── */
    public function handle_wsa_approve_staff() {
        $id = absint($_GET['id'] ?? 0);
        $this->check_nonce('wsa_approve_staff_' . $id);
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}wsa_staff", ['status' => 'active'], ['id' => $id]);
        wp_redirect(admin_url('admin.php?page=wsa-pending&approved=1'));
        exit;
    }

    public function handle_wsa_reject_staff() {
        $id = absint($_GET['id'] ?? 0);
        $this->check_nonce('wsa_reject_staff_' . $id);
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}wsa_staff", ['status' => 'inactive'], ['id' => $id]);
        wp_redirect(admin_url('admin.php?page=wsa-pending&rejected=1'));
        exit;
    }

    private function check_nonce($action) {
        if (!current_user_can('manage_options') || !check_admin_referer($action)) wp_die('Security check failed.');
    }

    /* ── Add / delete holidays ── */
    public function handle_wsa_add_holiday() {
        $this->check_nonce('wsa_holiday_action');
        $date = sanitize_text_field($_POST['holiday_date'] ?? '');
        $name = sanitize_text_field($_POST['holiday_name'] ?? '');
        if (!$date || !$name) { wp_redirect(admin_url('admin.php?page=wsa-settings&tab=holidays&error='.urlencode('Date and name required.'))); exit; }
        WSA_DB::add_holiday($date, $name);
        wp_redirect(admin_url('admin.php?page=wsa-settings&tab=holidays&saved=1'));
        exit;
    }

    public function handle_wsa_delete_holiday() {
        $this->check_nonce('wsa_delete_holiday_'.absint($_GET['id']));
        WSA_DB::delete_holiday(absint($_GET['id']));
        wp_redirect(admin_url('admin.php?page=wsa-settings&tab=holidays&saved=1'));
        exit;
    }

}
