<?php
defined('ABSPATH') || exit;

/**
 * WSA_Face — Face Recognition Engine v2.0
 * Full attendance cycle: Check-In → Break Start → Break End → Check-Out
 */
class WSA_Face {

    const DB_VER          = '2.0';
    const MATCH_THRESHOLD = 0.50;
    const MIN_QUALITY     = 50;

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$wpdb->prefix}wsa_face_profiles (
            id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            staff_id        BIGINT UNSIGNED  NOT NULL,
            descriptor      LONGTEXT         NOT NULL,
            angles_json     LONGTEXT         NULL,
            quality_score   DECIMAL(5,2)     DEFAULT 0.00,
            capture_count   TINYINT UNSIGNED DEFAULT 1,
            status          ENUM('registered','disabled') NOT NULL DEFAULT 'registered',
            registered_by   BIGINT UNSIGNED  DEFAULT NULL,
            registered_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY staff_id (staff_id),
            INDEX status_idx(status)
        ) $c;");

        dbDelta("CREATE TABLE {$wpdb->prefix}wsa_face_logs (
            id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            staff_id        BIGINT UNSIGNED  DEFAULT NULL,
            attendance_id   BIGINT UNSIGNED  DEFAULT NULL,
            action          VARCHAR(40)      NOT NULL DEFAULT '',
            confidence      DECIMAL(6,4)     DEFAULT 0.0000,
            quality_score   DECIMAL(5,2)     DEFAULT 0.00,
            liveness_passed TINYINT(1)       DEFAULT 0,
            status          ENUM('success','failed') NOT NULL DEFAULT 'failed',
            reason          VARCHAR(255)     DEFAULT '',
            device_hash     VARCHAR(96)      DEFAULT '',
            ip_address      VARCHAR(64)      DEFAULT '',
            location        VARCHAR(190)     DEFAULT '',
            created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            INDEX staff_idx(staff_id),
            INDEX status_idx(status),
            INDEX created_idx(created_at)
        ) $c;");

        $att_tbl = $wpdb->prefix . 'wsa_attendance';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $att_tbl)) === $att_tbl) {
            $col = $wpdb->get_row("SHOW COLUMNS FROM {$att_tbl} WHERE Field='type'");
            if ($col && strpos((string)$col->Type, 'FACE') === false)
                $wpdb->query("ALTER TABLE {$att_tbl} MODIFY COLUMN type ENUM('SCAN','MANUAL','FACE') NOT NULL DEFAULT 'SCAN'");
            $sc = $wpdb->get_row("SHOW COLUMNS FROM {$att_tbl} WHERE Field='status'");
            if ($sc && strpos((string)$sc->Type, 'BREAK') === false)
                $wpdb->query("ALTER TABLE {$att_tbl} MODIFY COLUMN status ENUM('IN','OUT','BREAK','ABSENT') NOT NULL DEFAULT 'IN'");
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$att_tbl}");
            if (!in_array('break_start', $cols))
                $wpdb->query("ALTER TABLE {$att_tbl} ADD COLUMN break_start DATETIME NULL AFTER logout_time");
            if (!in_array('break_duration_mins', $cols))
                $wpdb->query("ALTER TABLE {$att_tbl} ADD COLUMN break_duration_mins DECIMAL(8,2) DEFAULT 0.00 AFTER break_start");
        }

        $breaks_tbl = $wpdb->prefix . 'wsa_breaks';
        dbDelta("CREATE TABLE {$breaks_tbl} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attendance_id   BIGINT UNSIGNED NOT NULL,
            staff_id        BIGINT UNSIGNED NOT NULL,
            break_start     DATETIME        NOT NULL,
            break_end       DATETIME        NULL,
            duration_mins   DECIMAL(8,2)    DEFAULT 0.00,
            PRIMARY KEY(id),
            INDEX att_idx(attendance_id),
            INDEX staff_idx(staff_id)
        ) $c;");

        update_option('wsa_face_db_version', self::DB_VER);
    }

    public function __construct() {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes() {
        $ns = 'wsa/v2';
        register_rest_route($ns, '/face/staff',        ['methods'=>'GET',  'callback'=>[$this,'get_staff'],       'permission_callback'=>[$this,'admin_perm']]);
        register_rest_route($ns, '/face/staff/add',    ['methods'=>'POST', 'callback'=>[$this,'add_staff_quick'], 'permission_callback'=>[$this,'admin_perm']]);
        register_rest_route($ns, '/face/register',     ['methods'=>'POST', 'callback'=>[$this,'register_face'],   'permission_callback'=>[$this,'admin_perm']]);
        register_rest_route($ns, '/face/delete',       ['methods'=>'POST', 'callback'=>[$this,'delete_face'],     'permission_callback'=>[$this,'admin_perm']]);
        register_rest_route($ns, '/face/logs',         ['methods'=>'GET',  'callback'=>[$this,'get_logs'],        'permission_callback'=>[$this,'admin_perm']]);
        register_rest_route($ns, '/face/stats',        ['methods'=>'GET',  'callback'=>[$this,'get_stats'],       'permission_callback'=>[$this,'admin_perm']]);
        register_rest_route($ns, '/face/match',        ['methods'=>'POST', 'callback'=>[$this,'match_face'],      'permission_callback'=>'__return_true']);
        register_rest_route($ns, '/face/status',       ['methods'=>'POST', 'callback'=>[$this,'get_face_status'], 'permission_callback'=>'__return_true']);
        register_rest_route($ns, '/face/today',        ['methods'=>'GET',  'callback'=>[$this,'get_today_feed'],  'permission_callback'=>'__return_true']);
        register_rest_route($ns, '/face/dashboard',    ['methods'=>'GET',  'callback'=>[$this,'get_dashboard'],   'permission_callback'=>'__return_true']);
    }

    public function admin_perm(WP_REST_Request $r = null) {
        if (current_user_can('manage_options')) return true;

        if ($r && class_exists('WSA_Admin_Portal')) {
            $token = WSA_Admin_Portal::token_from_request($r);
            if ($token && WSA_Admin_Portal::validate($token)) return true;
        }

        return false;
    }

    /* ── Quick Add Staff (from face page) ──────────────────────── */
    public function add_staff_quick(WP_REST_Request $r) {
        $data = [
            'employee_id' => sanitize_text_field($r->get_param('employee_id') ?? ''),
            'name'        => sanitize_text_field($r->get_param('name')        ?? ''),
            'department'  => sanitize_text_field($r->get_param('department')  ?? ''),
            'phone'       => sanitize_text_field($r->get_param('phone')       ?? ''),
            'email'       => sanitize_email($r->get_param('email')            ?? ''),
            'photo_url'   => esc_url_raw($r->get_param('photo_url')           ?? ''),
            'shift_id'    => absint($r->get_param('shift_id')                 ?? 1),
            'pin'         => preg_replace('/[^0-9]/', '', $r->get_param('pin') ?? '1234') ?: '1234',
            'status'      => 'active',
        ];
        if (empty($data['employee_id'])) return $this->fail('Employee ID is required.', 400);
        if (empty($data['name']))        return $this->fail('Full Name is required.', 400);

        $result = WSA_Staff::add($data);
        if (is_wp_error($result)) return $this->fail($result->get_error_message(), 409);

        return new WP_REST_Response([
            'success'    => true,
            'staff_id'   => (int)$result,
            'message'    => 'Staff "' . $data['name'] . '" added (ID: ' . $data['employee_id'] . '). Now capture their face.',
        ], 200);
    }

    /* ── Staff List ─────────────────────────────────────────────── */
    public function get_staff(WP_REST_Request $r) {
        global $wpdb;
        $rows = $wpdb->get_results("
            SELECT s.id, s.name, s.employee_id, s.department, s.status, s.photo_url,
                   fp.status AS face_status, fp.quality_score, fp.capture_count, fp.updated_at,
                   (SELECT att.status FROM {$wpdb->prefix}wsa_attendance att
                    WHERE att.staff_id=s.id AND att.att_date=CURDATE() LIMIT 1) AS today_status
            FROM {$wpdb->prefix}wsa_staff s
            LEFT JOIN {$wpdb->prefix}wsa_face_profiles fp ON fp.staff_id=s.id
            WHERE s.status IN ('active','pending') ORDER BY s.name ASC LIMIT 2000");
        return new WP_REST_Response(['success'=>true,'staff'=>$rows], 200);
    }

    /* ── Register Face ──────────────────────────────────────────── */
    public function register_face(WP_REST_Request $r) {
        global $wpdb;
        $staff_id   = absint($r->get_param('staff_id'));
        $descriptor = $this->clean_descriptor($r->get_param('descriptor'));
        $angles     = $r->get_param('angles') ?: [];
        $quality    = max(0, min(100, (float)$r->get_param('quality_score')));
        $count      = max(1, min(10, (int)($r->get_param('capture_count') ?: 1)));

        if (!$staff_id)   return $this->fail('Staff ID is required.', 400);
        if (!$descriptor) return $this->fail('Valid face descriptor (128 floats) required.', 400);
        if ($quality < 45) return $this->fail("Quality too low ({$quality}%). Improve lighting and face the camera.", 422);

        $dup = $this->find_best_match($descriptor, $staff_id);
        if ($dup && $dup['distance'] <= 0.40)
            return $this->fail("Duplicate face! Already registered to: {$dup['name']} ({$dup['employee_id']}). Each employee needs a unique face.", 409);

        $data = [
            'staff_id'      => $staff_id,
            'descriptor'    => wp_json_encode($descriptor),
            'angles_json'   => wp_json_encode($angles),
            'quality_score' => round($quality, 2),
            'capture_count' => $count,
            'status'        => 'registered',
            'registered_by' => get_current_user_id(),
            'updated_at'    => current_time('mysql'),
        ];
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}wsa_face_profiles WHERE staff_id=%d", $staff_id));
        if ($exists) $wpdb->update("{$wpdb->prefix}wsa_face_profiles", $data, ['staff_id'=>$staff_id]);
        else { $data['registered_at'] = current_time('mysql'); $wpdb->insert("{$wpdb->prefix}wsa_face_profiles", $data); }

        $name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}wsa_staff WHERE id=%d", $staff_id));
        return new WP_REST_Response(['success'=>true,'message'=>"Face saved for {$name}. Quality: ".round($quality)."% ({$count} angles)."], 200);
    }

    /* ── Delete Face Profile ────────────────────────────────────── */
    public function delete_face(WP_REST_Request $r) {
        global $wpdb;
        $staff_id = absint($r->get_param('staff_id'));
        if (!$staff_id) return $this->fail('Staff ID required.', 400);
        $deleted = $wpdb->delete("{$wpdb->prefix}wsa_face_profiles", ['staff_id'=>$staff_id]);
        if (!$deleted) return $this->fail('No face profile found.', 404);
        return new WP_REST_Response(['success'=>true,'message'=>'Face profile deleted.'], 200);
    }

    /* ── Match Face & Mark Attendance ───────────────────────────── */
    public function match_face(WP_REST_Request $r) {
        $descriptor = $this->clean_descriptor($r->get_param('descriptor'));
        $quality    = max(0, min(100, (float)$r->get_param('quality_score')));
        $live       = filter_var($r->get_param('liveness_passed'), FILTER_VALIDATE_BOOLEAN);
        $location   = sanitize_text_field($r->get_param('location') ?: 'Face Scanner');
        $device     = sanitize_text_field($r->get_param('device_hash') ?: 'browser');
        $action     = sanitize_key($r->get_param('action') ?: 'auto');

        if (!$descriptor)           return $this->log_fail(null,'Invalid face data. Try again.',0,$quality,$live,$device,$location,400);
        if (!$live)                 return $this->log_fail(null,'Liveness failed. Please blink or slowly nod.',0,$quality,$live,$device,$location,403);
        if ($quality < self::MIN_QUALITY) return $this->log_fail(null,"Face quality too low ({$quality}%). Come closer and use better light.",0,$quality,$live,$device,$location,422);

        $best = $this->find_best_match($descriptor);
        if (!$best || $best['distance'] > self::MATCH_THRESHOLD) {
            $conf = $best ? round((1-$best['distance'])*100,1) : 0;
            return $this->log_fail(null,"Face not recognized (match: {$conf}%). Please register face first.",$best['confidence']??0,$quality,$live,$device,$location,404);
        }

        $res = $this->mark_attendance((int)$best['staff_id'], $location, $action);
        if (empty($res['success'])) return $this->log_fail((int)$best['staff_id'],$res['message']??'Attendance failed.',$best['confidence'],$quality,$live,$device,$location,422);

        $this->insert_log($best['staff_id'],$res['attendance_id']??null,$res['action']??$action,$best['confidence'],$quality,$live,'success','',$device,$location);

        return new WP_REST_Response([
            'success'    => true,
            'action'     => $res['action'] ?? $action,
            'message'    => $res['message'] ?? 'Attendance recorded.',
            'status'     => $res['status'] ?? 'IN',
            'staff'      => $res['staff']  ?? [],
            'confidence' => round($best['confidence']*100,1),
            'quality'    => round($quality,1),
            'timestamp'  => current_time('mysql'),
            'today_log'  => $res['today_log'] ?? [],
            'is_late'    => $res['is_late'] ?? false,
            'total_hours'=> $res['total_hours'] ?? null,
        ], 200);
    }

    /* ── Get Face Status ─────────────────────────────────────────── */
    public function get_face_status(WP_REST_Request $r) {
        $descriptor = $this->clean_descriptor($r->get_param('descriptor'));
        if (!$descriptor) return $this->fail('No descriptor.', 400);
        $best = $this->find_best_match($descriptor);
        if (!$best || $best['distance'] > self::MATCH_THRESHOLD)
            return new WP_REST_Response(['success'=>false,'identified'=>false], 200);
        $today = WSA_DB::get_today((int)$best['staff_id']);
        $staff = WSA_DB::get_staff((int)$best['staff_id']);
        return new WP_REST_Response([
            'success'    => true,
            'identified' => true,
            'staff'      => $this->staff_payload($staff),
            'status'     => $today ? $today->status : 'NOT_IN',
            'login_time' => $today->login_time ?? null,
            'confidence' => round($best['confidence']*100,1),
        ], 200);
    }

    /* ── Logs ───────────────────────────────────────────────────── */
    public function get_logs(WP_REST_Request $r) {
        global $wpdb;
        $from   = sanitize_text_field($r->get_param('from') ?: date('Y-m-01'));
        $to     = sanitize_text_field($r->get_param('to')   ?: date('Y-m-d'));
        $status = sanitize_text_field($r->get_param('status') ?: '');
        $limit  = max(1, min(2000, (int)($r->get_param('limit') ?: 500)));
        $where  = $status ? $wpdb->prepare(' AND l.status=%s', $status) : '';

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT l.*, s.name, s.employee_id, s.department
            FROM {$wpdb->prefix}wsa_face_logs l
            LEFT JOIN {$wpdb->prefix}wsa_staff s ON s.id=l.staff_id
            WHERE DATE(l.created_at) BETWEEN %s AND %s {$where}
            ORDER BY l.created_at DESC LIMIT %d
        ", $from, $to, $limit));

        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT COUNT(*) AS total,
                   SUM(status='success') AS successes,
                   SUM(status='failed')  AS failures,
                   ROUND(AVG(CASE WHEN status='success' THEN confidence END)*100,1) AS avg_confidence
            FROM {$wpdb->prefix}wsa_face_logs WHERE DATE(created_at) BETWEEN %s AND %s
        ", $from, $to));

        return new WP_REST_Response(['success'=>true,'logs'=>$rows,'stats'=>$stats], 200);
    }

    /* ── Stats ──────────────────────────────────────────────────── */
    public function get_stats(WP_REST_Request $r) {
        global $wpdb;
        $reg   = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_face_profiles WHERE status='registered'");
        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_staff WHERE status='active'");
        $scans = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_face_logs WHERE DATE(created_at)=CURDATE() AND status='success'");
        $fails = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_face_logs WHERE DATE(created_at)=CURDATE() AND status='failed'");
        $conf  = (float)$wpdb->get_var("SELECT AVG(confidence) FROM {$wpdb->prefix}wsa_face_logs WHERE DATE(created_at)=CURDATE() AND status='success'");
        return new WP_REST_Response(['success'=>true,'registered'=>$reg,'unregistered'=>max(0,$total-$reg),'total_staff'=>$total,'today_scans'=>$scans,'today_failures'=>$fails,'avg_confidence'=>round($conf*100,1)], 200);
    }

    /* ══════════════════════════════════════════════════════════════
       ATTENDANCE LOGIC
    ══════════════════════════════════════════════════════════════ */
    private function mark_attendance(int $staff_id, string $location='', string $action='auto'): array {
        global $wpdb;
        $staff = WSA_DB::get_staff($staff_id);
        if (!$staff)                     return $this->err('Staff not found.');
        if ($staff->status !== 'active') return $this->err('Staff account inactive. Contact admin.');

        $att = $wpdb->prefix.'wsa_attendance';
        $brk = $wpdb->prefix.'wsa_breaks';
        $now = current_time('mysql');
        $dt  = current_time('Y-m-d');
        $ts  = current_time('timestamp');
        $today = WSA_DB::get_today($staff_id);
        $p   = $this->staff_payload($staff);

        /* No record → Check-In */
        if (!$today) {
            if (in_array($action, ['checkout','break'])) return $this->err('Not checked in yet. Please check in first.');
            $late = 0;
            if (!empty($staff->start_time)) {
                $ss = strtotime($dt.' '.$staff->start_time);
                if ($ts > $ss + ((int)($staff->late_grace_mins ?: 15)*60)) $late=1;
            }
            $wpdb->insert($att,['staff_id'=>$staff_id,'att_date'=>$dt,'login_time'=>$now,'status'=>'IN','type'=>'FACE','is_late'=>$late,'notes'=>"Face check-in — {$location}",'ip_address'=>$this->ip()]);
            $aid = $wpdb->insert_id;
            return ['success'=>true,'attendance_id'=>$aid,'action'=>'CHECKIN','status'=>'IN','is_late'=>$late,
                'message'=>($late?'⚠️ Checked In (Late)':'✅ Checked In').' — '.date('h:i A',strtotime($now)),
                'staff'=>$p,'today_log'=>$this->today_log($aid,$now,null,null,null,'IN')];
        }

        /* Already OUT */
        if ($today->status === 'OUT') {
            return ['success'=>true,'attendance_id'=>$today->id,'action'=>'ALREADY_OUT','status'=>'OUT',
                'message'=>'✅ Attendance complete for today.',
                'staff'=>$p,'today_log'=>$this->today_log($today->id,$today->login_time,$today->logout_time,$today->total_hours,$today->break_duration_mins,'OUT')];
        }

        /* Currently IN */
        if ($today->status === 'IN') {
            if ($action === 'checkin') return ['success'=>true,'attendance_id'=>$today->id,'action'=>'ALREADY_IN','status'=>'IN','message'=>'✅ Already checked in.','staff'=>$p,'today_log'=>$this->today_log($today->id,$today->login_time,null,null,$today->break_duration_mins,'IN')];
            if ($action === 'checkout') return $this->do_checkout($staff_id,$today,$staff,$now,$ts,$location,$p,$att,$brk);
            if ($action === 'break')    return $this->do_break_start($staff_id,$today,$now,$location,$p,$att,$brk);
            // auto: if had completed breaks → checkout; else → break start
            $bc = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$brk} WHERE attendance_id=%d AND break_end IS NOT NULL",$today->id));
            return $bc === 0
                ? $this->do_break_start($staff_id,$today,$now,$location,$p,$att,$brk)
                : $this->do_checkout($staff_id,$today,$staff,$now,$ts,$location,$p,$att,$brk);
        }

        /* Currently on BREAK */
        if ($today->status === 'BREAK') {
            if ($action === 'checkin') return $this->err('Currently on break. Scan again to end break.');
            if ($action === 'checkout') {
                // End break then checkout
                $br = $this->do_break_end($staff_id,$today,$now,$ts,$location,$p,$att,$brk);
                $today2 = WSA_DB::get_today($staff_id);
                return $this->do_checkout($staff_id,$today2??$today,$staff,$now,$ts,$location,$p,$att,$brk);
            }
            return $this->do_break_end($staff_id,$today,$now,$ts,$location,$p,$att,$brk);
        }

        return $this->err('Unknown attendance state. Contact admin.');
    }

    private function do_break_start($staff_id,$today,$now,$location,$p,$att,$brk): array {
        global $wpdb;
        $wpdb->update($att,['status'=>'BREAK','break_start'=>$now,'notes'=>trim(($today->notes??'')." | Break start — {$location}")],['id'=>$today->id]);
        $wpdb->insert($brk,['attendance_id'=>$today->id,'staff_id'=>$staff_id,'break_start'=>$now]);
        return ['success'=>true,'attendance_id'=>$today->id,'action'=>'BREAK_START','status'=>'BREAK',
            'message'=>'☕ Break started — '.date('h:i A',strtotime($now)),
            'staff'=>$p,'today_log'=>$this->today_log($today->id,$today->login_time,null,null,$today->break_duration_mins,'BREAK')];
    }

    private function do_break_end($staff_id,$today,$now,$ts,$location,$p,$att,$brk): array {
        global $wpdb;
        $bs_ts = $today->break_start ? strtotime($today->break_start) : $ts;
        $mins  = max(0,($ts-$bs_ts)/60);
        $total = (float)($today->break_duration_mins??0)+$mins;
        $ob = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$brk} WHERE attendance_id=%d AND break_end IS NULL ORDER BY id DESC LIMIT 1",$today->id));
        if ($ob) $wpdb->update($brk,['break_end'=>$now,'duration_mins'=>round($mins,2)],['id'=>$ob->id]);
        $wpdb->update($att,['status'=>'IN','break_start'=>null,'break_duration_mins'=>round($total,2),'notes'=>trim(($today->notes??'')." | Break end — {$location} (".round($mins).' min)')],['id'=>$today->id]);
        return ['success'=>true,'attendance_id'=>$today->id,'action'=>'BREAK_END','status'=>'IN',
            'message'=>'✅ Break ended ('.round($mins).' min) — '.date('h:i A',strtotime($now)),
            'staff'=>$p,'today_log'=>$this->today_log($today->id,$today->login_time,null,null,round($total,2),'IN')];
    }

    private function do_checkout($staff_id,$today,$staff,$now,$ts,$location,$p,$att,$brk): array {
        global $wpdb;
        // Close open break
        $ob = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$brk} WHERE attendance_id=%d AND break_end IS NULL ORDER BY id DESC LIMIT 1",$today->id));
        $extra = 0.0;
        if ($ob) { $extra=max(0,($ts-strtotime($ob->break_start))/60); $wpdb->update($brk,['break_end'=>$now,'duration_mins'=>round($extra,2)],['id'=>$ob->id]); }
        $brk_mins = (float)($today->break_duration_mins??0)+$extra;

        [$net_hours, $ot_hours, $early] = WSA_Attendance::calculate($today->login_time, $now, $staff, $brk_mins);

        $wpdb->update($att,['logout_time'=>$now,'total_hours'=>round($net_hours,2),'overtime_hours'=>round($ot_hours,2),'break_duration_mins'=>round($brk_mins,2),'status'=>'OUT','is_early_exit'=>$early,'notes'=>trim(($today->notes??'')." | Face check-out — {$location}")],['id'=>$today->id]);

        $h=$floor=floor($net_hours); $m=round(($net_hours-$h)*60);
        $msg="🚪 Checked Out — ".date('h:i A',strtotime($now))." | Worked: {$h}h {$m}m";
        if($ot_hours>0){$oh=floor($ot_hours);$om=round(($ot_hours-$oh)*60);$msg.=" | OT: {$oh}h {$om}m";}
        if($early) $msg.=' ⚠️ (Early exit)';

        return ['success'=>true,'attendance_id'=>$today->id,'action'=>'CHECKOUT','status'=>'OUT',
            'message'=>$msg,'total_hours'=>round($net_hours,2),'ot_hours'=>round($ot_hours,2),
            'staff'=>$p,'today_log'=>$this->today_log($today->id,$today->login_time,$now,round($net_hours,2),round($brk_mins,2),'OUT')];
    }

    /* ── Helpers ─────────────────────────────────────────────────── */
    private function today_log($aid,$login,$logout,$hours,$brk_mins,$status): array {
        global $wpdb;
        $breaks = $wpdb->get_results($wpdb->prepare("SELECT break_start,break_end,duration_mins FROM {$wpdb->prefix}wsa_breaks WHERE attendance_id=%d ORDER BY id ASC",$aid));
        return ['login_time'=>$login,'logout_time'=>$logout,'total_hours'=>$hours,'break_duration_mins'=>$brk_mins,'status'=>$status,'breaks'=>$breaks];
    }

    private function staff_payload($staff): array {
        if(!$staff) return [];
        return ['id'=>(int)$staff->id,'name'=>$staff->name,'employee_id'=>$staff->employee_id,'department'=>$staff->department??'','photo'=>$staff->photo_url??''];
    }

    private function clean_descriptor($d): ?array {
        if(is_string($d)) $d=json_decode($d,true);
        if(!is_array($d)||count($d)<64) return null;
        return array_map('floatval',array_slice(array_values($d),0,128));
    }

    private function find_best_match(array $desc, int $excl=0): ?array {
        global $wpdb;
        $rows=$wpdb->get_results("SELECT fp.staff_id,fp.descriptor,s.name,s.employee_id FROM {$wpdb->prefix}wsa_face_profiles fp JOIN {$wpdb->prefix}wsa_staff s ON s.id=fp.staff_id WHERE fp.status='registered' AND s.status='active'");
        $best=null;
        foreach($rows as $r){
            if($excl&&(int)$r->staff_id===$excl) continue;
            $stored=json_decode($r->descriptor,true);
            if(!is_array($stored)||count($stored)<64) continue;
            $dist=$this->euc($desc,$stored);
            if($best===null||$dist<$best['distance'])
                $best=['staff_id'=>(int)$r->staff_id,'name'=>$r->name,'employee_id'=>$r->employee_id,'distance'=>$dist,'confidence'=>max(0.0,1.0-($dist/self::MATCH_THRESHOLD))];
        }
        return $best;
    }

    private function euc(array $a,array $b): float {
        $s=0.0;$n=min(count($a),count($b));
        for($i=0;$i<$n;$i++){$d=(float)$a[$i]-(float)$b[$i];$s+=$d*$d;}
        return sqrt($s);
    }

    private function log_fail($sid,$reason,$conf,$qual,$live,$dev,$loc,$code): WP_REST_Response {
        $this->insert_log($sid,null,'FAILED',(float)$conf,(float)$qual,$live,'failed',$reason,$dev,$loc);
        return new WP_REST_Response(['success'=>false,'message'=>$reason],$code);
    }

    private function insert_log($sid,$aid,$action,$conf,$qual,$live,$status,$reason,$dev,$loc): void {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}wsa_face_logs",['staff_id'=>$sid??null,'attendance_id'=>$aid??null,'action'=>$action,'confidence'=>round((float)$conf,4),'quality_score'=>round((float)$qual,2),'liveness_passed'=>$live?1:0,'status'=>$status,'reason'=>$reason,'device_hash'=>substr(hash('sha256',$dev),0,96),'ip_address'=>$this->ip(),'location'=>$loc]);
    }

    private function fail(string $msg,int $code=400): WP_REST_Response {
        return new WP_REST_Response(['success'=>false,'message'=>$msg],$code);
    }

    private function err(string $msg): array { return ['success'=>false,'message'=>$msg]; }

    private function ip(): string {
        foreach(['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k)
            if(!empty($_SERVER[$k])) return trim(explode(',',$_SERVER[$k])[0]);
        return '';
    }

    /* ── REST: Today's Face Feed (public) ──────────────────────── */
    public function get_today_feed(WP_REST_Request $r) {
        global $wpdb;
        $rows = $wpdb->get_results("
            SELECT l.action, l.confidence, l.quality_score, l.status, l.created_at,
                   s.name, s.employee_id, s.department, s.photo_url
            FROM {$wpdb->prefix}wsa_face_logs l
            LEFT JOIN {$wpdb->prefix}wsa_staff s ON s.id = l.staff_id
            WHERE DATE(l.created_at) = CURDATE() AND l.status = 'success'
            ORDER BY l.created_at DESC LIMIT 50
        ");
        return new WP_REST_Response(['success' => true, 'scans' => $rows], 200);
    }

    /* ── REST: Dashboard Stats (public) ─────────────────────────── */
    public function get_dashboard(WP_REST_Request $r) {
        global $wpdb;
        $currently_in    = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_attendance WHERE att_date=CURDATE() AND status='IN' AND type='FACE'");
        $on_break        = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_attendance WHERE att_date=CURDATE() AND status='BREAK'");
        $checked_out     = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_attendance WHERE att_date=CURDATE() AND status='OUT' AND type='FACE'");
        $total_face_scans= (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_face_logs WHERE DATE(created_at)=CURDATE() AND status='success'");
        $total_staff     = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_staff WHERE status='active'");
        $registered_faces= (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_face_profiles WHERE status='registered'");
        $not_arrived     = max(0, $total_staff - $currently_in - $on_break - $checked_out);
        $recent_scan     = $wpdb->get_row("
            SELECT l.action, l.created_at, s.name, s.photo_url, s.department
            FROM {$wpdb->prefix}wsa_face_logs l
            LEFT JOIN {$wpdb->prefix}wsa_staff s ON s.id=l.staff_id
            WHERE l.status='success' AND DATE(l.created_at)=CURDATE()
            ORDER BY l.created_at DESC LIMIT 1");
        return new WP_REST_Response([
            'success'          => true,
            'currently_in'     => $currently_in,
            'on_break'         => $on_break,
            'checked_out'      => $checked_out,
            'not_arrived'      => $not_arrived,
            'total_face_scans' => $total_face_scans,
            'total_staff'      => $total_staff,
            'registered_faces' => $registered_faces,
            'recent_scan'      => $recent_scan,
        ], 200);
    }
}
