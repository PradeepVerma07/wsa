<?php
defined('ABSPATH') || exit;

class WSA_DB {

    public static function install() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$wpdb->prefix}wsa_staff (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id   VARCHAR(50)  NOT NULL,
            name          VARCHAR(120) NOT NULL,
            department    VARCHAR(100) NOT NULL DEFAULT '',
            phone         VARCHAR(30)  DEFAULT '',
            email         VARCHAR(150) NOT NULL DEFAULT '',
            photo_url     VARCHAR(500) NOT NULL DEFAULT '',
            shift_id      BIGINT UNSIGNED DEFAULT 1,
            pin           VARCHAR(8)   NOT NULL DEFAULT '1234',
            status        ENUM('active','inactive','pending') NOT NULL DEFAULT 'active',
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY employee_id (employee_id),
            INDEX dept_idx (department),
            INDEX status_idx (status)
        ) $c;");

        dbDelta("CREATE TABLE {$wpdb->prefix}wsa_shifts (
            id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name                  VARCHAR(100) NOT NULL,
            start_time            TIME NOT NULL DEFAULT '09:00:00',
            end_time              TIME NOT NULL DEFAULT '18:00:00',
            break_minutes         INT  NOT NULL DEFAULT 60,
            standard_hours        INT  NOT NULL DEFAULT 8,
            overtime_after_mins   INT  NOT NULL DEFAULT 480,
            late_grace_mins       INT  NOT NULL DEFAULT 15,
            early_exit_grace_mins INT  NOT NULL DEFAULT 15,
            PRIMARY KEY (id)
        ) $c;");

        dbDelta("CREATE TABLE {$wpdb->prefix}wsa_attendance (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            staff_id            BIGINT UNSIGNED NOT NULL,
            att_date            DATE NOT NULL,
            login_time          DATETIME DEFAULT NULL,
            logout_time         DATETIME DEFAULT NULL,
            total_hours         DECIMAL(6,4) DEFAULT 0.0000,
            overtime_hours      DECIMAL(6,4) DEFAULT 0.0000,
            type                ENUM('SCAN','MANUAL') NOT NULL DEFAULT 'SCAN',
            status              ENUM('IN','OUT','ABSENT','BREAK') NOT NULL DEFAULT 'IN',
            is_late             TINYINT(1) DEFAULT 0,
            is_early_exit       TINYINT(1) DEFAULT 0,
            gate_token          VARCHAR(80) DEFAULT '',
            ip_address          VARCHAR(50) DEFAULT '',
            notes               TEXT DEFAULT NULL,
            break_duration_mins DECIMAL(8,2) DEFAULT 0.00,
            break_start         DATETIME DEFAULT NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY staff_date (staff_id, att_date),
            INDEX date_idx (att_date),
            INDEX staff_idx (staff_id),
            INDEX status_idx (status)
        ) $c;");

        /* Break sessions log */
        dbDelta("CREATE TABLE {$wpdb->prefix}wsa_breaks (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attendance_id   BIGINT UNSIGNED NOT NULL,
            staff_id        BIGINT UNSIGNED NOT NULL,
            break_start     DATETIME NOT NULL,
            break_end       DATETIME DEFAULT NULL,
            duration_mins   DECIMAL(8,2) DEFAULT 0.00,
            PRIMARY KEY (id),
            INDEX att_idx (attendance_id),
            INDEX staff_idx (staff_id)
        ) $c;");

        dbDelta("CREATE TABLE {$wpdb->prefix}wsa_gates (
            id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name     VARCHAR(100) NOT NULL,
            token    VARCHAR(64)  NOT NULL,
            type     ENUM('entry','exit','both') NOT NULL DEFAULT 'both',
            location VARCHAR(200) DEFAULT '',
            status   ENUM('active','inactive') NOT NULL DEFAULT 'active',
            PRIMARY KEY (id),
            UNIQUE KEY token (token)
        ) $c;");

        dbDelta("CREATE TABLE {$wpdb->prefix}wsa_scan_log (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            staff_id   BIGINT UNSIGNED NOT NULL,
            scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            action     ENUM('IN','OUT') NOT NULL,
            PRIMARY KEY (id),
            INDEX staff_time (staff_id, scanned_at)
        ) $c;");

        dbDelta("CREATE TABLE {$wpdb->prefix}wsa_qr_codes (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            gate_id    BIGINT UNSIGNED NOT NULL,
            token      VARCHAR(96)     NOT NULL,
            status     TINYINT(1)      NOT NULL DEFAULT 0,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME        NOT NULL,
            claimed_at DATETIME        DEFAULT NULL,
            used_at    DATETIME        DEFAULT NULL,
            used_by    BIGINT UNSIGNED DEFAULT NULL,
            ip_claimed VARCHAR(50)     DEFAULT '',
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            INDEX gate_idx (gate_id),
            INDEX status_idx (status),
            INDEX expiry_idx (expires_at)
        ) $c;");

        /* Salary config */
        dbDelta("CREATE TABLE {$wpdb->prefix}wsa_salary_config (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            staff_id         BIGINT UNSIGNED NOT NULL,
            monthly_salary   DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            daily_rate       DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            ot_rate_per_hr   DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            absent_deduction DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            working_days     TINYINT         NOT NULL DEFAULT 26,
            currency         VARCHAR(10)     NOT NULL DEFAULT 'INR',
            updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY staff_id (staff_id)
        ) $c;");

        /* Leaves */
        dbDelta("CREATE TABLE {$wpdb->prefix}wsa_leaves (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            staff_id    BIGINT UNSIGNED NOT NULL,
            leave_date  DATE            NOT NULL,
            leave_type  VARCHAR(60)     NOT NULL DEFAULT 'Casual',
            status      ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved',
            notes       TEXT            DEFAULT NULL,
            assigned_by BIGINT UNSIGNED DEFAULT NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY staff_date (staff_id, leave_date),
            INDEX date_idx (leave_date),
            INDEX staff_idx (staff_id),
            INDEX status_idx (status)
        ) $c;");

        /* Public holidays — NEW */
        dbDelta("CREATE TABLE {$wpdb->prefix}wsa_holidays (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            holiday_date DATE            NOT NULL,
            name         VARCHAR(120)    NOT NULL,
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY holiday_date (holiday_date)
        ) $c;");

        // Portal Admins (super admin / sub-admin for the frontend portal)
        dbDelta("CREATE TABLE {$wpdb->prefix}wsa_portal_admins (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            username   VARCHAR(80)  NOT NULL,
            email      VARCHAR(150) NOT NULL DEFAULT '',
            name       VARCHAR(150) NOT NULL DEFAULT '',
            password   VARCHAR(255) NOT NULL,
            role       ENUM('super_admin','admin') NOT NULL DEFAULT 'admin',
            status     ENUM('active','inactive') NOT NULL DEFAULT 'active',
            last_login DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY username (username)
        ) $c;");

        self::seed_defaults();
        update_option('wsa_db_version', WSA_DB_VER);
        self::create_public_pages();
    }


    public static function upgrade() {
        global $wpdb;
        // v4.4 — add break columns and break status
        $cols = $wpdb->get_col("DESCRIBE {$wpdb->prefix}wsa_attendance");
        if (!in_array('break_duration_mins', $cols)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}wsa_attendance ADD COLUMN break_duration_mins DECIMAL(8,2) DEFAULT 0.00 AFTER notes");
        }
        if (!in_array('break_start', $cols)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}wsa_attendance ADD COLUMN break_start DATETIME DEFAULT NULL AFTER break_duration_mins");
        }
        // Add BREAK to ENUM if not present
        $row = $wpdb->get_row("SHOW COLUMNS FROM {$wpdb->prefix}wsa_attendance WHERE Field='status'");
        if ($row && strpos($row->Type, 'BREAK') === false) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}wsa_attendance MODIFY COLUMN status ENUM('IN','OUT','ABSENT','BREAK') NOT NULL DEFAULT 'IN'");
        }
        // Create wsa_breaks table if missing
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wsa_breaks'")) {
            $c = $wpdb->get_charset_collate();
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE {$wpdb->prefix}wsa_breaks (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                attendance_id   BIGINT UNSIGNED NOT NULL,
                staff_id        BIGINT UNSIGNED NOT NULL,
                break_start     DATETIME NOT NULL,
                break_end       DATETIME DEFAULT NULL,
                duration_mins   DECIMAL(8,2) DEFAULT 0.00,
                PRIMARY KEY (id),
                INDEX att_idx (attendance_id),
                INDEX staff_idx (staff_id)
            ) $c;");
        }
        // v4.5 — add email + photo_url + pending status to wsa_staff
        $staff_cols = $wpdb->get_col("DESCRIBE {$wpdb->prefix}wsa_staff");
        if (!in_array('email', $staff_cols)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}wsa_staff ADD COLUMN email VARCHAR(150) NOT NULL DEFAULT '' AFTER phone");
        }
        if (!in_array('photo_url', $staff_cols)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}wsa_staff ADD COLUMN photo_url VARCHAR(500) NOT NULL DEFAULT '' AFTER email");
        }
        // Add 'pending' to staff status ENUM if missing
        $s_row = $wpdb->get_row("SHOW COLUMNS FROM {$wpdb->prefix}wsa_staff WHERE Field='status'");
        if ($s_row && strpos($s_row->Type, 'pending') === false) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}wsa_staff MODIFY COLUMN status ENUM('active','inactive','pending') NOT NULL DEFAULT 'active'");
        }
        update_option('wsa_db_version', '4.5');

        // v4.6 — wsa_portal_admins table
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wsa_portal_admins'")) {
            $c = $wpdb->get_charset_collate();
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE {$wpdb->prefix}wsa_portal_admins (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                username   VARCHAR(80)  NOT NULL,
                email      VARCHAR(150) NOT NULL DEFAULT '',
                name       VARCHAR(150) NOT NULL DEFAULT '',
                password   VARCHAR(255) NOT NULL,
                role       ENUM('super_admin','admin') NOT NULL DEFAULT 'admin',
                status     ENUM('active','inactive') NOT NULL DEFAULT 'active',
                last_login DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY username (username)
            ) $c;");
        }
        update_option('wsa_db_version', '4.6');
    }

    /**
     * Runs on every plugins_loaded — safe to call repeatedly.
     * Guarantees that the default gate and required WP pages exist even if
     * the activation hook was never fired (FTP upload, manual activation, etc.)
     */
    public static function ensure_setup() {
        // Ensure tables exist (harmless if already created)
        if (!get_option('wsa_db_version')) {
            self::install();
            return; // install() already seeds + creates pages
        }

        // Seed default gate if table is empty
        global $wpdb;
        if (!$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_gates")) {
            $wpdb->insert("{$wpdb->prefix}wsa_gates", [
                'name'     => 'Main Gate',
                'token'    => wp_generate_password(32, false),
                'type'     => 'both',
                'location' => 'Main Entrance',
            ]);
        }

        // Seed default options if missing
        self::seed_defaults();

        // Create WP pages if missing
        self::create_public_pages();
    }

    public static function seed_defaults() {
        global $wpdb;
        if (!$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_shifts")) {
            $wpdb->insert("{$wpdb->prefix}wsa_shifts", [
                'name'=>'General Shift','start_time'=>'09:00:00','end_time'=>'18:00:00',
                'break_minutes'=>60,'standard_hours'=>8,'overtime_after_mins'=>480,
                'late_grace_mins'=>15,'early_exit_grace_mins'=>15,
            ]);
        }
        if (!$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_gates")) {
            $wpdb->insert("{$wpdb->prefix}wsa_gates", [
                'name'=>'Main Gate','token'=>wp_generate_password(32,false),'type'=>'both','location'=>'Main Entrance',
            ]);
        }
        foreach ([
            'wsa_company'        => get_bloginfo('name'),
            'wsa_standard_hours' => 8,
            'wsa_auto_logout_hr' => 0,
            'wsa_duplicate_mins' => 5,
            'wsa_qr_ttl'         => 30,
            'wsa_claim_ttl'      => 180,
            'wsa_min_work_mins'  => 420,
            'wsa_work_days'      => '1,2,3,4,5,6', // Mon-Sat (0=Sun,6=Sat)
        ] as $k => $v) {
            if (get_option($k) === false) update_option($k, $v);
        }
    }

    public static function create_public_pages() {
        $pages = [
            ['title'=>'Attendance Display',  'slug'=>'attendance-scanner',  'content'=>'[attendance_scanner]',  'opt'=>'wsa_scanner_page_id'],
            ['title'=>'Employee Attendance', 'slug'=>'employee-attendance',  'content'=>'[employee_attendance]', 'opt'=>'wsa_attend_page_id'],
            ['title'=>'My Attendance',       'slug'=>'my-attendance',        'content'=>'[staff_dashboard]',     'opt'=>'wsa_dashboard_page_id'],
            ['title'=>'Staff Login',         'slug'=>'staff-login',          'content'=>'[wsa_staff_login]',     'opt'=>'wsa_login_page_id'],
            ['title'=>'Staff Register',      'slug'=>'staff-register',       'content'=>'[wsa_staff_register]',  'opt'=>'wsa_register_page_id'],
            ['title'=>'Staff Portal',        'slug'=>'staff-portal',         'content'=>'[wsa_staff_portal]',    'opt'=>'wsa_portal_page_id'],
            ['title'=>'Admin Portal',        'slug'=>'wsa-admin',            'content'=>'[wsa_admin_portal]',    'opt'=>'wsa_admin_portal_page_id'],
            ['title'=>'Face Attendance Scanner','slug'=>'face-attendance-scanner','content'=>'[wsa_face_scanner]', 'opt'=>'wsa_face_scanner_page_id'],
        ];
        foreach ($pages as $pg) {
            $existing_id = (int) get_option($pg['opt']);
            if ($existing_id) {
                $page = get_post($existing_id);
                // Repair deleted pages or pages where shortcode content was accidentally removed.
                if ($page && $page->post_type === 'page') {
                    if (strpos((string) $page->post_content, $pg['content']) === false) {
                        wp_update_post(['ID' => $existing_id, 'post_content' => $pg['content'], 'post_status' => 'publish']);
                    }
                    update_post_meta($existing_id, '_wsa_page', $pg['slug']);
                    continue;
                }
                delete_option($pg['opt']);
            }

            $ex = get_posts(['post_type'=>'page','name'=>$pg['slug'],'numberposts'=>1,'post_status'=>'any']);
            if (!$ex) {
                $ex = get_posts(['post_type'=>'page','meta_key'=>'_wsa_page','meta_value'=>$pg['slug'],'numberposts'=>1,'post_status'=>'any']);
            }
            if ($ex) {
                $id = (int) $ex[0]->ID;
                if (strpos((string) $ex[0]->post_content, $pg['content']) === false) {
                    wp_update_post(['ID' => $id, 'post_content' => $pg['content'], 'post_status' => 'publish']);
                }
                update_post_meta($id, '_wsa_page', $pg['slug']);
                update_option($pg['opt'], $id);
                continue;
            }
            $id = wp_insert_post(['post_title'=>$pg['title'],'post_name'=>$pg['slug'],'post_content'=>$pg['content'],'post_status'=>'publish','post_type'=>'page']);
            if ($id && !is_wp_error($id)) { update_post_meta($id, '_wsa_page', $pg['slug']); update_option($pg['opt'], $id); }
        }
    }

    /* ── Staff ── */
    public static function get_staff($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, sh.name AS shift_name, sh.start_time, sh.end_time,
                    sh.overtime_after_mins, sh.late_grace_mins, sh.early_exit_grace_mins,
                    sh.break_minutes, sh.standard_hours
             FROM {$wpdb->prefix}wsa_staff s
             LEFT JOIN {$wpdb->prefix}wsa_shifts sh ON s.shift_id=sh.id
             WHERE s.id=%d", $id
        ));
    }
    public static function get_staff_by_eid($eid) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, sh.name AS shift_name, sh.start_time, sh.end_time,
                    sh.overtime_after_mins, sh.late_grace_mins, sh.early_exit_grace_mins,
                    sh.break_minutes, sh.standard_hours
             FROM {$wpdb->prefix}wsa_staff s
             LEFT JOIN {$wpdb->prefix}wsa_shifts sh ON s.shift_id=sh.id
             WHERE s.employee_id=%s AND s.status='active'", $eid
        ));
    }
    public static function get_all_staff($args=[]) {
        global $wpdb;
        $args = wp_parse_args($args,['status'=>'','department'=>'','search'=>'','limit'=>500,'offset'=>0]);
        $sql  = "SELECT s.*, sh.name AS shift_name FROM {$wpdb->prefix}wsa_staff s LEFT JOIN {$wpdb->prefix}wsa_shifts sh ON s.shift_id=sh.id WHERE 1=1";
        $p = [];
        if ($args['status'])     { $sql.=" AND s.status=%s"; $p[]=$args['status']; }
        if ($args['department']) { $sql.=" AND s.department=%s"; $p[]=$args['department']; }
        if ($args['search'])     { $sql.=" AND (s.name LIKE %s OR s.employee_id LIKE %s)"; $q='%'.$args['search'].'%'; $p[]=$q;$p[]=$q; }
        $sql.=" ORDER BY s.name ASC LIMIT %d OFFSET %d";
        $p[]=$args['limit'];$p[]=$args['offset'];
        return $wpdb->get_results($p?$wpdb->prepare($sql,$p):$sql);
    }
    public static function get_departments() {
        global $wpdb;
        return $wpdb->get_col("SELECT DISTINCT department FROM {$wpdb->prefix}wsa_staff WHERE department!='' ORDER BY department");
    }

    /* ── Attendance ── */
    public static function get_today($staff_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wsa_attendance WHERE staff_id=%d AND att_date=%s ORDER BY id DESC LIMIT 1", $staff_id, current_time('Y-m-d')
        ));
    }
    public static function get_attendance($args=[]) {
        global $wpdb;
        $args=wp_parse_args($args,['date_from'=>date('Y-m-d'),'date_to'=>date('Y-m-d'),'staff_id'=>0,'department'=>'','status'=>'','limit'=>1000,'offset'=>0,'include_leaves'=>false]);
        $sql="SELECT a.*, s.name AS staff_name, s.employee_id AS emp_code, s.department
              FROM {$wpdb->prefix}wsa_attendance a JOIN {$wpdb->prefix}wsa_staff s ON a.staff_id=s.id
              WHERE a.att_date BETWEEN %s AND %s";
        $p=[$args['date_from'],$args['date_to']];
        if($args['staff_id']) {$sql.=" AND a.staff_id=%d"; $p[]=$args['staff_id'];}
        if($args['department']){$sql.=" AND s.department=%s";$p[]=$args['department'];}
        if($args['status'])   {$sql.=" AND a.status=%s";   $p[]=$args['status'];}
        $sql.=" ORDER BY a.att_date DESC, a.login_time DESC LIMIT %d OFFSET %d";
        $p[]=$args['limit'];$p[]=$args['offset'];
        $rows = $wpdb->get_results($wpdb->prepare($sql,$p));
        if (!empty($args['include_leaves'])) {
            $leave_sql = "SELECT 0 AS id, l.staff_id, l.leave_date AS att_date, NULL AS login_time, NULL AS logout_time, 0 AS total_hours, 0 AS overtime_hours, 0 AS break_duration_mins, 'LEAVE' AS type, 'LEAVE' AS status, 0 AS is_late, 0 AS is_early_exit, l.notes, s.name AS staff_name, s.employee_id AS emp_code, s.department, l.leave_type
                          FROM {$wpdb->prefix}wsa_leaves l JOIN {$wpdb->prefix}wsa_staff s ON l.staff_id=s.id
                          WHERE l.leave_date BETWEEN %s AND %s AND l.status='approved'";
            $lp = [$args['date_from'],$args['date_to']];
            if($args['staff_id']) { $leave_sql.=" AND l.staff_id=%d"; $lp[]=$args['staff_id']; }
            if($args['department']) { $leave_sql.=" AND s.department=%s"; $lp[]=$args['department']; }
            $leave_sql .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}wsa_attendance a WHERE a.staff_id=l.staff_id AND a.att_date=l.leave_date)";
            $leave_rows = $wpdb->get_results($wpdb->prepare($leave_sql,$lp));
            $rows = array_merge($rows ?: [], $leave_rows ?: []);
            usort($rows, function($a,$b){
                $ad = $a->att_date ?? ''; $bd = $b->att_date ?? '';
                if ($ad === $bd) return strcmp((string)($b->login_time ?? ''), (string)($a->login_time ?? ''));
                return strcmp($bd, $ad);
            });
            $rows = array_slice($rows, 0, (int)$args['limit']);
        }
        return $rows;
    }
    public static function get_who_inside() {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, s.name AS staff_name, s.employee_id AS emp_code, s.department
             FROM {$wpdb->prefix}wsa_attendance a JOIN {$wpdb->prefix}wsa_staff s ON a.staff_id=s.id
             WHERE a.att_date=%s AND a.status IN ('IN','BREAK') AND a.login_time IS NOT NULL
             ORDER BY a.login_time ASC", current_time('Y-m-d')
        ));
    }

    /* ── Gates ── */
    public static function get_gates() { global $wpdb; return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}wsa_gates ORDER BY name"); }
    public static function get_gate_by_token($token) { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wsa_gates WHERE token=%s AND status='active'",$token)); }
    public static function get_gate_by_id($id) { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wsa_gates WHERE id=%d AND status='active'",$id)); }
    public static function get_shifts() { global $wpdb; return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}wsa_shifts ORDER BY name"); }

    /* ── Scan log ── */
    public static function log_scan($staff_id, $action) {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}wsa_scan_log",['staff_id'=>$staff_id,'scanned_at'=>current_time('mysql'),'action'=>$action]);
    }
    public static function get_recent_scan($staff_id, $cooldown_secs=300) {
        global $wpdb;
        $since=date('Y-m-d H:i:s',time()-$cooldown_secs);
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wsa_scan_log WHERE staff_id=%d AND scanned_at>%s ORDER BY scanned_at DESC LIMIT 1",
            $staff_id,$since
        ));
    }

    /* ── Dashboard stats ── */
    public static function get_dashboard_stats() {
        global $wpdb; $today=current_time('Y-m-d');
        return [
            'total_staff'    => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_staff WHERE status='active'"),
            'present_today'  => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_attendance WHERE att_date=%s AND status IN ('IN','OUT','BREAK')",$today)),
            'inside_now'     => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_attendance WHERE att_date=%s AND status IN ('IN','BREAK')",$today)),
            'on_break_now'   => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_attendance WHERE att_date=%s AND status='BREAK'",$today)),
            'checked_out'    => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_attendance WHERE att_date=%s AND status='OUT'",$today)),
            'late_today'     => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_attendance WHERE att_date=%s AND is_late=1",$today)),
            'overtime_today' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_attendance WHERE att_date=%s AND overtime_hours>0",$today)),
            'manual_today'   => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_attendance WHERE att_date=%s AND type='MANUAL'",$today)),
        ];
    }

    /* ── Holidays ── */
    public static function get_holidays($year_month = null) {
        global $wpdb;
        $sql = "SELECT * FROM {$wpdb->prefix}wsa_holidays";
        if ($year_month) $sql .= $wpdb->prepare(" WHERE DATE_FORMAT(holiday_date,'%Y-%m')=%s", $year_month);
        return $wpdb->get_results($sql . " ORDER BY holiday_date ASC");
    }
    public static function is_holiday($date) {
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}wsa_holidays WHERE holiday_date=%s",$date));
    }
    public static function add_holiday($date, $name) {
        global $wpdb;
        return $wpdb->replace("{$wpdb->prefix}wsa_holidays",['holiday_date'=>sanitize_text_field($date),'name'=>sanitize_text_field($name)]);
    }
    public static function delete_holiday($id) {
        global $wpdb;
        return $wpdb->delete("{$wpdb->prefix}wsa_holidays",['id'=>(int)$id]);
    }

    /**
     * FIXED: mark_absents_for_date — respects working days config, holidays, leaves
     * ONLY marks absent on configured working days (default Mon-Sat)
     * NEVER marks absent on Sundays or public holidays
     */
    public static function mark_absents_for_date($date) {
        global $wpdb;

        // Check if this date is a configured working day
        $day_of_week = (int)date('w', strtotime($date)); // 0=Sun, 1=Mon … 6=Sat
        $work_days   = array_map('intval', explode(',', get_option('wsa_work_days', '1,2,3,4,5,6')));
        if (!in_array($day_of_week, $work_days)) return; // not a working day — skip

        // Skip public holidays
        if (self::is_holiday($date)) return;

        $all_staff = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}wsa_staff WHERE status='active'");
        foreach ($all_staff as $sid) {
            // Skip if any attendance record exists (IN, OUT, or already ABSENT)
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}wsa_attendance WHERE staff_id=%d AND att_date=%s", $sid, $date
            ));
            if ($exists) continue;

            // Skip if approved leave exists
            $on_leave = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}wsa_leaves WHERE staff_id=%d AND leave_date=%s AND status='approved'", $sid, $date
            ));
            if ($on_leave) continue;

            // Insert ABSENT
            $wpdb->insert("{$wpdb->prefix}wsa_attendance", [
                'staff_id' => $sid,
                'att_date' => $date,
                'status'   => 'ABSENT',
                'type'     => 'SCAN',
                'notes'    => 'Auto-marked absent by system',
            ]);
        }
    }
}
