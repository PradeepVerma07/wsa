<?php
defined('ABSPATH') || exit;

class WSA_Ajax {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_rest']);
        add_action('wp_ajax_wsa_live_stats',        [$this, 'ajax_live_stats']);
        add_action('wp_ajax_nopriv_wsa_live_stats', [$this, 'ajax_live_stats']);
        add_action('wp_ajax_wsa_get_staff_status',  [$this, 'ajax_get_staff_status']);
        add_action('wp_ajax_wsa_qm_status',         [$this, 'ajax_qm_status']);
        add_action('wp_ajax_wsa_qm_action',         [$this, 'ajax_qm_action']);
    }

    public function register_rest() {
        $ns = 'wsa/v2';

        register_rest_route($ns, '/qr/display/(?P<gate_id>\d+)', [
            'methods' => 'GET', 'callback' => [$this,'rest_qr_display'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route($ns, '/qr/claim', [
            'methods' => 'POST', 'callback' => [$this,'rest_qr_claim'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route($ns, '/attend', [
            'methods' => 'POST', 'callback' => [$this,'rest_attend'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route($ns, '/status', [
            'methods' => 'POST', 'callback' => [$this,'rest_status'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route($ns, '/admin/record/(?P<id>\d+)', [
            ['methods'=>'PUT',    'callback'=>[$this,'rest_update_record'], 'permission_callback'=>[$this,'is_admin']],
            ['methods'=>'DELETE', 'callback'=>[$this,'rest_delete_record'], 'permission_callback'=>[$this,'is_admin']],
        ]);
        register_rest_route($ns, '/admin/inside', [
            'methods' => 'GET', 'callback' => [$this,'rest_who_inside'], 'permission_callback' => [$this,'is_admin'],
        ]);
        register_rest_route($ns, '/admin/stats', [
            'methods' => 'GET', 'callback' => [$this,'rest_admin_stats'], 'permission_callback' => [$this,'is_admin'],
        ]);
        // ── Staff Auth ──
        register_rest_route($ns, '/auth/login',    ['methods'=>'POST','callback'=>[$this,'rest_staff_login'],    'permission_callback'=>'__return_true']);
        register_rest_route($ns, '/auth/logout',   ['methods'=>'POST','callback'=>[$this,'rest_staff_logout'],   'permission_callback'=>'__return_true']);
        register_rest_route($ns, '/auth/me',       ['methods'=>'GET', 'callback'=>[$this,'rest_staff_me'],       'permission_callback'=>'__return_true']);
        register_rest_route($ns, '/auth/register', ['methods'=>'POST','callback'=>[$this,'rest_staff_register'], 'permission_callback'=>'__return_true']);
        // ── Staff Portal ──
        register_rest_route($ns, '/portal/dashboard', ['methods'=>'GET','callback'=>[$this,'rest_portal_dashboard'],'permission_callback'=>'__return_true']);
        // ── Admin Quick-Mark ──
        register_rest_route($ns, '/admin/quick-mark',   ['methods'=>'POST','callback'=>[$this,'rest_quick_mark'],       'permission_callback'=>[$this,'is_admin']]);
        register_rest_route($ns, '/admin/staff-status', ['methods'=>'GET', 'callback'=>[$this,'rest_all_staff_status'], 'permission_callback'=>[$this,'is_admin']]);
    }



    /* ── WP Admin Quick Mark AJAX fallback (avoids REST 409/security-plugin conflicts) ── */
    public function ajax_qm_status() {
        if (!current_user_can('manage_options')) wp_send_json(['success'=>false,'message'=>'Permission denied.'], 403);
        check_ajax_referer('wp_rest', 'nonce');
        $req = new WP_REST_Request('GET', '/wsa/v2/admin/staff-status');
        $resp = $this->rest_all_staff_status($req);
        wp_send_json($resp->get_data(), $resp->get_status());
    }

    public function ajax_qm_action() {
        if (!current_user_can('manage_options')) wp_send_json(['success'=>false,'message'=>'Permission denied.'], 403);
        check_ajax_referer('wp_rest', 'nonce');
        $req = new WP_REST_Request('POST', '/wsa/v2/admin/quick-mark');
        $req->set_param('staff_id', absint($_POST['staff_id'] ?? 0));
        $req->set_param('action', sanitize_text_field($_POST['action_type'] ?? $_POST['qm_action'] ?? ''));
        $resp = $this->rest_quick_mark($req);
        wp_send_json($resp->get_data(), $resp->get_status());
    }

    /* ── QR Display ── */
    public function rest_qr_display(WP_REST_Request $r) {
        $gate_id = (int)$r->get_param('gate_id');
        $gate    = WSA_DB::get_gate_by_id($gate_id);
        if (!$gate) return $this->fail('Gate not found.', 404);
        // When the frontend countdown reaches 0 it may pass force=1.
        // Generate a fresh token before returning display status so the admin
        // QR panel changes instantly instead of waiting for any cached/stale row.
        if ((int) $r->get_param('force') === 1) {
            WSA_QrCode::generate($gate_id);
        }
        $status = WSA_QrCode::display_status($gate_id);
        $stats  = WSA_DB::get_dashboard_stats();

        $response = new WP_REST_Response([
            'success'       => true,
            'gate_name'     => $gate->name,
            'gate_location' => $gate->location,
            'token'         => $status['token'],
            'qr_status'     => (int) $status['status'],
            'seconds_left'  => (int) $status['seconds_left'],
            'qr_ttl'        => (int) $status['qr_ttl'],
            'expires_at'    => $status['expires_at'],
            'qr_image_url'  => $status['qr_image_url'],
            'attend_url'    => $status['attendance_url'],
            'inside_count'  => (int) $stats['inside_now'],
            'server_time'   => current_time('h:i:s A'),
            'server_ts_ms'  => (int)(microtime(true) * 1000),
        ], 200);

        // Prevent browser/proxy caching — always return fresh QR data
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('Expires', '0');
        $response->header('Pragma', 'no-cache');
        $response->header('Expires', '0');

        return $response;
    }

    /* ── QR Claim ── */
    public function rest_qr_claim(WP_REST_Request $r) {
        $token = sanitize_text_field($r->get_param('token') ?? '');
        if (!$token) return $this->fail('QR token required.', 400);
        $result = WSA_QrCode::claim($token);
        if (!$result['ok']) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $result['message'],
                'expired' => strpos(strtolower($result['message']), 'expir') !== false,
            ], 422);
        }
        return new WP_REST_Response([
            'success'     => true,
            'claim_key'   => $result['claim_key'],
            'gate_id'     => $result['gate_id'],
            'ttl'         => WSA_QrCode::CLAIM_TTL,
            'message'     => $result['message'],
            'server_ts_ms'=> (int)(microtime(true) * 1000),
        ], 200);
    }

    /* ── Attend ── */
    public function rest_attend(WP_REST_Request $r) {
        $claim_key   = sanitize_text_field($r->get_param('claim_key')   ?? '');
        $employee_id = strtoupper(sanitize_text_field($r->get_param('employee_id') ?? ''));
        $pin         = sanitize_text_field($r->get_param('pin')         ?? '');

        if (!$claim_key || !$employee_id || !$pin) return $this->fail('All fields required.', 400);

        $staff = WSA_DB::get_staff_by_eid($employee_id);
        if (!$staff)              return $this->fail('Employee ID not found.', 401);
        if ($staff->pin !== $pin) return $this->fail('Incorrect PIN. Try again.', 401);

        // Cooldown check (skip for BREAK/RESUME transitions — they are expected quick scans)
        $today_rec = WSA_DB::get_today($staff->id);
        $is_break_action = ($today_rec && in_array($today_rec->status, ['IN','BREAK']));
        if (!$is_break_action) {
            $cooldown = (int)get_option('wsa_duplicate_mins', 5) * 60;
            $recent   = WSA_DB::get_recent_scan($staff->id, $cooldown);
            if ($recent) {
                $wait = max(0, $cooldown - (current_time('timestamp') - strtotime($recent->scanned_at)));
                return new WP_REST_Response([
                    'success'   => false,
                    'message'   => 'Already scanned. Wait ' . ceil($wait/60) . ' minute(s).',
                    'cooldown'  => true,
                    'wait_secs' => $wait,
                ], 429);
            }
        }

        // Consume claim
        $claim = WSA_QrCode::consume($claim_key, $staff->id);
        if (!$claim['ok']) return new WP_REST_Response(['success'=>false,'message'=>$claim['message']], 422);

        $ip         = self::get_ip();
        $now        = current_time('mysql');
        $today_date = current_time('Y-m-d');
        $now_ts     = current_time('timestamp');
        $now_ts_ms  = (int)(microtime(true) * 1000);

        /* ══════════════════════════════════════════════
           CASE 1: No record today → CHECK IN
        ══════════════════════════════════════════════ */
        if (!$today_rec || $today_rec->status === 'ABSENT') {
            $is_late = 0;
            if (!empty($staff->start_time)) {
                $grace_end = strtotime($today_date . ' ' . $staff->start_time)
                           + ((int)($staff->late_grace_mins ?: 15) * 60);
                if ($now_ts > $grace_end) $is_late = 1;
            }

            global $wpdb;
            // Remove any stale ABSENT record
            $wpdb->delete("{$wpdb->prefix}wsa_attendance", [
                'staff_id' => $staff->id, 'att_date' => $today_date, 'status' => 'ABSENT',
            ]);

            $wpdb->insert("{$wpdb->prefix}wsa_attendance", [
                'staff_id'   => $staff->id,
                'att_date'   => $today_date,
                'login_time' => $now,
                'status'     => 'IN',
                'type'       => 'SCAN',
                'is_late'    => $is_late,
                'gate_token' => (string)$claim['gate_id'],
                'ip_address' => $ip,
                'break_duration_mins' => 0,
                'break_start'         => null,
            ]);

            WSA_DB::log_scan($staff->id, 'IN');
            $login_ts_ms = (int)(strtotime($now) * 1000);
            $std_mins    = (int)($staff->overtime_after_mins ?: 480);
            $min_work    = (int)get_option('wsa_min_work_mins', 420);

            return new WP_REST_Response([
                'success'  => true,
                'action'   => 'IN',
                'is_late'  => (bool)$is_late,
                'message'  => '✅ Checked IN Successfully',
                'data'     => [
                    'name'              => $staff->name,
                    'emp_id'            => $staff->employee_id,
                    'department'        => $staff->department,
                    'login_time'        => $now,
                    'login_ts_ms'       => $login_ts_ms,
                    'server_ts_ms'      => $now_ts_ms,
                    'server_timestamp'  => $now_ts_ms,
                    'shift'             => $staff->shift_name ?: 'General',
                    'shift_end'         => !empty($staff->end_time) ? date('h:i A', strtotime($today_date.' '.$staff->end_time)) : '',
                    'std_mins'          => $std_mins,
                    'min_checkout_mins' => $min_work,
                    'is_late'           => (bool)$is_late,
                ],
            ], 200);
        }

        /* ══════════════════════════════════════════════
           CASE 2: Currently ON BREAK → END BREAK / RESUME
        ══════════════════════════════════════════════ */
        if ($today_rec->status === 'BREAK') {
            global $wpdb;
            $break_secs  = max(0, $now_ts - strtotime($today_rec->break_start));
            $break_mins  = $break_secs / 60;
            $new_total_break = (float)($today_rec->break_duration_mins ?: 0) + $break_mins;

            // Close open break record in wsa_breaks
            $open_break = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wsa_breaks WHERE attendance_id=%d AND break_end IS NULL ORDER BY id DESC LIMIT 1",
                $today_rec->id
            ));
            if ($open_break) {
                $wpdb->update("{$wpdb->prefix}wsa_breaks", [
                    'break_end'    => $now,
                    'duration_mins'=> round($break_mins, 2),
                ], ['id' => $open_break->id]);
            }

            $wpdb->update("{$wpdb->prefix}wsa_attendance", [
                'status'              => 'IN',
                'break_duration_mins' => round($new_total_break, 2),
                'break_start'         => null,
            ], ['id' => $today_rec->id]);

            WSA_DB::log_scan($staff->id, 'IN');

            $worked_mins_now = WSA_Attendance::worked_mins_so_far((object)array_merge(
                (array)$today_rec,
                ['status'=>'IN','break_duration_mins'=>$new_total_break,'break_start'=>null]
            ));
            $min_work    = (int)get_option('wsa_min_work_mins', 420);
            $remaining   = max(0, $min_work - $worked_mins_now);
            $bh = floor($break_mins/60); $bm = round(fmod($break_mins, 60));
            $wh = floor($worked_mins_now/60); $wm = round(fmod($worked_mins_now, 60));

            return new WP_REST_Response([
                'success' => true,
                'action'  => 'BREAK_END',
                'message' => '▶️ Work Resumed — Break: ' . ($bh>0?$bh.'h ':'') . round($bm).'m',
                'data'    => [
                    'name'                => $staff->name,
                    'emp_id'              => $staff->employee_id,
                    'department'          => $staff->department,
                    'login_time'          => $today_rec->login_time,
                    'login_ts_ms'         => (int)(strtotime($today_rec->login_time)*1000),
                    'server_ts_ms'        => $now_ts_ms,
                    'server_timestamp'    => $now_ts_ms,
                    'break_duration_mins' => round($break_mins, 2),
                    'total_break_mins'    => round($new_total_break, 2),
                    'worked_mins_so_far'  => round($worked_mins_now, 2),
                    'hours_worked_display'=> $wh.'h '.$wm.'m',
                    'remaining_mins'      => round($remaining),
                    'min_checkout_mins'   => $min_work,
                ],
            ], 200);
        }

        /* ══════════════════════════════════════════════
           CASE 3: Currently IN → BREAK or CHECKOUT
        ══════════════════════════════════════════════ */
        if ($today_rec->status === 'IN') {
            // Calculate net worked minutes (excluding all break time)
            $worked_mins = WSA_Attendance::worked_mins_so_far($today_rec);
            $min_work    = (int)get_option('wsa_min_work_mins', 420); // 7h = 420 min

            // ── CHECKOUT: worked >= minimum ──
            if ($worked_mins >= $min_work) {
                global $wpdb;
                [$total_h, $ot_h, $is_early] = WSA_Attendance::calculate(
                    $today_rec->login_time, $now, $staff,
                    (float)($today_rec->break_duration_mins ?: 0)
                );

                $wpdb->update("{$wpdb->prefix}wsa_attendance", [
                    'logout_time'    => $now,
                    'total_hours'    => round($total_h, 4),
                    'overtime_hours' => round($ot_h, 4),
                    'status'         => 'OUT',
                    'is_early_exit'  => $is_early,
                    'gate_token'     => (string)$claim['gate_id'],
                ], ['id' => $today_rec->id]);

                WSA_DB::log_scan($staff->id, 'OUT');

                $h = floor($total_h); $m = round(($total_h-$h)*60);
                $oh= floor($ot_h);    $om= round(($ot_h-$oh)*60);

                return new WP_REST_Response([
                    'success' => true,
                    'action'  => 'OUT',
                    'message' => '🏁 Checked OUT Successfully',
                    'data'    => [
                        'name'                => $staff->name,
                        'emp_id'              => $staff->employee_id,
                        'department'          => $staff->department,
                        'login_time'          => $today_rec->login_time,
                        'login_ts_ms'         => (int)(strtotime($today_rec->login_time)*1000),
                        'logout_time'         => $now,
                        'logout_ts_ms'        => (int)(strtotime($now)*1000),
                        'server_ts_ms'        => $now_ts_ms,
                        'server_timestamp'    => $now_ts_ms,
                        'total_hours'         => round($total_h, 4),
                        'overtime_hours'      => round($ot_h, 4),
                        'hours_display'       => "{$h}h {$m}m",
                        'ot_display'          => $ot_h > 0 ? "+{$oh}h {$om}m" : null,
                        'is_early'            => (bool)$is_early,
                        'break_duration_mins' => (float)($today_rec->break_duration_mins ?: 0),
                    ],
                ], 200);
            }

            // ── START BREAK: worked < minimum ──
            global $wpdb;
            $wpdb->update("{$wpdb->prefix}wsa_attendance", [
                'status'      => 'BREAK',
                'break_start' => $now,
            ], ['id' => $today_rec->id]);

            // Insert break session record
            $wpdb->insert("{$wpdb->prefix}wsa_breaks", [
                'attendance_id' => $today_rec->id,
                'staff_id'      => $staff->id,
                'break_start'   => $now,
            ]);

            WSA_DB::log_scan($staff->id, 'OUT');

            $wh = floor($worked_mins/60); $wm = round(fmod($worked_mins, 60));
            $remaining = max(0, $min_work - $worked_mins);
            $rh = floor($remaining/60); $rm = round(fmod($remaining, 60));

            return new WP_REST_Response([
                'success' => true,
                'action'  => 'BREAK_START',
                'message' => '☕ Break Started',
                'data'    => [
                    'name'               => $staff->name,
                    'emp_id'             => $staff->employee_id,
                    'department'         => $staff->department,
                    'login_time'         => $today_rec->login_time,
                    'break_start'        => $now,
                    'break_start_ts_ms'  => $now_ts_ms,
                    'server_ts_ms'       => $now_ts_ms,
                    'server_timestamp'   => $now_ts_ms,
                    'worked_mins_so_far' => round($worked_mins, 2),
                    'hours_worked_display'=> $wh.'h '.$wm.'m',
                    'remaining_mins'     => round($remaining),
                    'remaining_display'  => ($rh>0?$rh.'h ':'').$rm.'m',
                    'min_checkout_mins'  => $min_work,
                ],
            ], 200);
        }

        /* ══════════════════════════════════════════════
           CASE 4: Already OUT
        ══════════════════════════════════════════════ */
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Attendance already complete for today. Contact admin for corrections.',
        ], 409);
    }

        /* ── Status + 30-day history ── */
    public function rest_status(WP_REST_Request $r) {
        $eid   = strtoupper(sanitize_text_field($r->get_param('employee_id') ?? ''));
        $pin   = sanitize_text_field($r->get_param('pin') ?? '');
        $staff = WSA_DB::get_staff_by_eid($eid);
        if (!$staff || $staff->pin !== $pin) return $this->fail('Invalid credentials.', 401);

        $today      = WSA_DB::get_today($staff->id);
        $now_ts_ms  = (int)(microtime(true)*1000);
        $today_date = current_time('Y-m-d');

        $history = WSA_DB::get_attendance([
            'staff_id'  => $staff->id,
            'date_from' => date('Y-m-d', strtotime('-30 days')),
            'date_to'   => $today_date,
            'limit'     => 31,
        ]);

        $today_data = null;
        if ($today && $today->status !== 'ABSENT') {
            $today_data = [
                'status'              => $today->status,
                'login_time'          => $today->login_time,
                'login_ts_ms'         => $today->login_time  ? (int)(strtotime($today->login_time)*1000)  : null,
                'logout_time'         => $today->logout_time,
                'logout_ts_ms'        => $today->logout_time ? (int)(strtotime($today->logout_time)*1000) : null,
                'server_ts_ms'        => $now_ts_ms,
                'total_hours'         => (float)$today->total_hours,
                'overtime_hours'      => (float)$today->overtime_hours,
                'is_late'             => (bool)$today->is_late,
                'is_early_exit'       => (bool)$today->is_early_exit,
                'break_duration_mins' => (float)($today->break_duration_mins ?? 0),
                'on_break'            => $today->status === 'BREAK',
                'break_start'         => $today->break_start ?? null,
                'break_start_ts_ms'   => ($today->break_start) ? (int)(strtotime($today->break_start)*1000) : null,
                'worked_mins_so_far'  => round(WSA_Attendance::worked_mins_so_far($today), 2),
                'min_checkout_mins'   => (int)get_option('wsa_min_work_mins', 420),
            ];
        }

        $hist = [];
        foreach ($history as $row) {
            if ($row->status === 'ABSENT') {
                $hist[] = ['att_date'=>$row->att_date,'day_label'=>date('D, d M Y',strtotime($row->att_date)),'status'=>'ABSENT','login_fmt'=>'—','logout_fmt'=>'—','total_fmt'=>'—','overtime_fmt'=>'—','overtime_hours'=>0,'is_late'=>false,'is_early_exit'=>false,'is_today'=>$row->att_date===$today_date];
                continue;
            }
            $hist[] = [
                'att_date'       => $row->att_date,
                'day_label'      => date('D, d M Y', strtotime($row->att_date)),
                'login_ts_ms'    => $row->login_time  ? (int)(strtotime($row->login_time)*1000)  : null,
                'logout_ts_ms'   => $row->logout_time ? (int)(strtotime($row->logout_time)*1000) : null,
                'login_fmt'      => $row->login_time  ? date('h:i:s A', strtotime($row->login_time))  : '—',
                'logout_fmt'     => $row->logout_time ? date('h:i:s A', strtotime($row->logout_time)) : '—',
                'total_fmt'      => WSA_Attendance::fmt($row->total_hours),
                'overtime_fmt'   => $row->overtime_hours > 0 ? '+'.WSA_Attendance::fmt($row->overtime_hours) : '—',
                'overtime_hours' => (float)$row->overtime_hours,
                'status'         => $row->status,
                'is_late'        => (bool)$row->is_late,
                'is_early_exit'  => (bool)$row->is_early_exit,
                'type'           => $row->type,
                'is_today'       => $row->att_date === $today_date,
            ];
        }

        return new WP_REST_Response([
            'success'     => true,
            'server_ts_ms'=> $now_ts_ms,
            'server_timestamp' => $now_ts_ms,
            'server_time' => current_time('h:i:s A'),
            'staff'       => ['name'=>$staff->name,'emp_id'=>$staff->employee_id,'department'=>$staff->department,'shift'=>$staff->shift_name],
            'today'       => $today_data,
            'history'     => $hist,
        ], 200);
    }

    /* ── Who's Inside — FIXED: returns login_ts_ms for accurate JS timer ── */
    public function rest_who_inside() {
        $rows      = WSA_DB::get_who_inside();
        $server_ts = (int)(microtime(true) * 1000);
        $now       = current_time('timestamp');

        $data = array_map(function($row) use ($now, $server_ts) {
            $login_ts_ms = $row->login_time ? (int)(strtotime($row->login_time) * 1000) : 0;
            $break_secs  = (float)($row->break_duration_mins ?? 0) * 60;
            // If currently on break, add ongoing break to break_secs for display
            if ($row->status === 'BREAK' && !empty($row->break_start)) {
                $ongoing = max(0, $server_ts/1000 - strtotime($row->break_start));
                $break_secs += $ongoing;
            }
            $elapsed_s   = $login_ts_ms ? max(0, intdiv($server_ts - $login_ts_ms, 1000)) : 0;
            $worked_s    = max(0, $elapsed_s - (int)$break_secs);
            $h = floor($worked_s/3600); $m = floor(($worked_s%3600)/60); $s = $worked_s%60;
            return [
                'id'           => $row->id,
                'name'         => $row->staff_name,
                'emp_id'       => $row->emp_code,
                'department'   => $row->department,
                'status'       => $row->status,
                'login_time'   => $row->login_time,
                'login_fmt'    => $row->login_time ? date('h:i A', strtotime($row->login_time)) : '—',
                'login_ts_ms'  => $login_ts_ms,
                'elapsed_secs' => $elapsed_s,
                'worked_secs'  => $worked_s,
                'live_hours'   => sprintf('%02d:%02d:%02d', $h, $m, $s),
                'on_break'     => $row->status === 'BREAK',
                'break_start'  => $row->break_start ?? null,
            ];
        }, $rows);

        return new WP_REST_Response([
            'success'      => true,
            'data'         => $data,
            'count'        => count($data),
            'server_ts_ms' => $server_ts,           // ← sync point for JS offset
            'server_time'  => current_time('h:i:s A'),
        ], 200);
    }

    /* ── Admin Stats ── */
    public function rest_admin_stats() {
        $stats = WSA_DB::get_dashboard_stats();
        $stats['server_ts_ms'] = (int)(microtime(true)*1000);
        return new WP_REST_Response($stats, 200);
    }

    public function rest_update_record(WP_REST_Request $r) {
        $result = WSA_Attendance::update_record($r->get_param('id'), $r->get_json_params());
        return new WP_REST_Response($result, $result['success'] ? 200 : 422);
    }

    public function rest_delete_record(WP_REST_Request $r) {
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}wsa_attendance", ['id' => (int)$r->get_param('id')]);
        return new WP_REST_Response(['success'=>true], 200);
    }

    public function ajax_live_stats() {
        check_ajax_referer('wsa_admin', 'nonce');
        wp_send_json_success(WSA_DB::get_dashboard_stats());
    }

    public function ajax_get_staff_status() {
        check_ajax_referer('wsa_admin', 'nonce');
        global $wpdb;
        $sid  = absint($_POST['staff_id'] ?? 0);
        $date = sanitize_text_field($_POST['date'] ?? current_time('Y-m-d'));
        $rec  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wsa_attendance WHERE staff_id=%d AND att_date=%s", $sid, $date));
        wp_send_json_success(['record'=>$rec]);
    }

    /* ════════════════════════════════════════════════════════
       AUTH — STAFF LOGIN
    ════════════════════════════════════════════════════════ */
    public function rest_staff_login(WP_REST_Request $r) {
        $eid = strtoupper(sanitize_text_field($r->get_param('employee_id') ?? ''));
        $pin = sanitize_text_field($r->get_param('pin') ?? '');
        if (!$eid || !$pin) return $this->fail('Employee ID and PIN are required.', 400);
        $result = WSA_Auth::login($eid, $pin);
        if (!$result['ok']) return new WP_REST_Response(['success'=>false,'message'=>$result['message']], 401);
        $staff = WSA_DB::get_staff($result['staff_id']);
        return new WP_REST_Response([
            'success'  => true,
            'token'    => $result['token'],
            'staff'    => [
                'id'          => (int)$staff->id,
                'name'        => $staff->name,
                'employee_id' => $staff->employee_id,
                'department'  => $staff->department,
                'shift'       => $staff->shift_name ?: 'General',
            ],
        ], 200);
    }

    /* ── AUTH — LOGOUT ── */
    public function rest_staff_logout(WP_REST_Request $r) {
        $token = WSA_Auth::get_token($r);
        if ($token) WSA_Auth::logout($token);
        return new WP_REST_Response(['success'=>true], 200);
    }

    /* ── AUTH — ME (validate session) ── */
    public function rest_staff_me(WP_REST_Request $r) {
        $staff = WSA_Auth::staff_from_request($r);
        if (!$staff) return new WP_REST_Response(['success'=>false,'message'=>'Not authenticated.'], 401);
        $today = WSA_DB::get_today($staff->id);
        $now_ts_ms = (int)(microtime(true)*1000);
        return new WP_REST_Response([
            'success' => true,
            'staff'   => [
                'id'          => (int)$staff->id,
                'name'        => $staff->name,
                'employee_id' => $staff->employee_id,
                'department'  => $staff->department,
                'phone'       => $staff->phone,
                'shift'       => $staff->shift_name ?: 'General',
                'shift_start' => $staff->start_time ?? '',
                'shift_end'   => $staff->end_time   ?? '',
            ],
            'today'   => $today ? [
                'status'     => $today->status,
                'login_time' => $today->login_time,
                'login_ts_ms'=> $today->login_time ? (int)(strtotime($today->login_time)*1000) : null,
                'logout_time'=> $today->logout_time,
                'total_hours'=> (float)$today->total_hours,
                'on_break'   => $today->status === 'BREAK',
                'break_start'=> $today->break_start ?? null,
                'break_start_ts_ms' => ($today->break_start) ? (int)(strtotime($today->break_start)*1000) : null,
                'break_duration_mins' => (float)($today->break_duration_mins ?? 0),
                'worked_mins_so_far' => round(WSA_Attendance::worked_mins_so_far($today), 2),
            ] : null,
            'server_ts_ms' => $now_ts_ms,
        ], 200);
    }

    /* ── AUTH — REGISTER ── */
    public function rest_staff_register(WP_REST_Request $r) {
        $data = [
            'employee_id' => $r->get_param('employee_id') ?? '',
            'name'        => $r->get_param('name')        ?? '',
            'department'  => $r->get_param('department')  ?? '',
            'phone'       => $r->get_param('phone')       ?? '',
            'email'       => $r->get_param('email')       ?? '',
            'pin'         => $r->get_param('pin')         ?? '',
            'pin_confirm' => $r->get_param('pin_confirm') ?? '',
        ];
        $result = WSA_Auth::register($data);
        if (!$result['ok']) return new WP_REST_Response(['success'=>false,'message'=>$result['message']], 422);
        return new WP_REST_Response(['success'=>true,'message'=>$result['message']], 200);
    }

    /* ════════════════════════════════════════════════════════
       PORTAL DASHBOARD — full staff self-service data
    ════════════════════════════════════════════════════════ */
    public function rest_portal_dashboard(WP_REST_Request $r) {
        $staff = WSA_Auth::staff_from_request($r);
        if (!$staff) return new WP_REST_Response(['success'=>false,'message'=>'Not authenticated.'], 401);

        $now_ts_ms  = (int)(microtime(true)*1000);
        $today_date = current_time('Y-m-d');
        $today      = WSA_DB::get_today($staff->id);

        // ── This month summary ──
        $yr = (int)date('Y'); $mn = (int)date('n');
        $salary_report = WSA_Salary::monthly_report($staff->id, $yr, $mn);

        // ── Last 30 days history ──
        global $wpdb;
        $history_rows = WSA_DB::get_attendance([
            'staff_id'  => $staff->id,
            'date_from' => date('Y-m-d', strtotime('-30 days')),
            'date_to'   => $today_date,
            'limit'     => 31,
        ]);
        $history = [];
        foreach ($history_rows as $row) {
            $history[] = [
                'att_date'   => $row->att_date,
                'day_label'  => date('D, d M', strtotime($row->att_date)),
                'status'     => $row->status,
                'login_fmt'  => $row->login_time  ? date('h:i A', strtotime($row->login_time))  : '—',
                'logout_fmt' => $row->logout_time ? date('h:i A', strtotime($row->logout_time)) : '—',
                'total_fmt'  => WSA_Attendance::fmt($row->total_hours),
                'total_hours'=> (float)$row->total_hours,
                'ot_hours'   => (float)$row->overtime_hours,
                'is_late'    => (bool)$row->is_late,
                'is_early'   => (bool)$row->is_early_exit,
                'type'       => $row->type,
                'is_today'   => $row->att_date === $today_date,
            ];
        }

        // ── Pending leaves ──
        $leaves = WSA_Salary::get_leaves(['staff_id'=>$staff->id,'status'=>'pending','limit'=>5]);
        $leave_list = [];
        foreach ($leaves as $l) {
            $leave_list[] = ['date'=>$l->leave_date,'type'=>$l->leave_type,'status'=>$l->status];
        }

        // ── Salary config for display ──
        $cfg = WSA_Salary::get_config($staff->id);

        $today_data = null;
        if ($today && $today->status !== 'ABSENT') {
            $today_data = [
                'status'              => $today->status,
                'login_time'          => $today->login_time,
                'login_ts_ms'         => $today->login_time ? (int)(strtotime($today->login_time)*1000) : null,
                'logout_time'         => $today->logout_time,
                'total_hours'         => (float)$today->total_hours,
                'on_break'            => $today->status === 'BREAK',
                'break_start'         => $today->break_start ?? null,
                'break_start_ts_ms'   => ($today->break_start) ? (int)(strtotime($today->break_start)*1000) : null,
                'break_duration_mins' => (float)($today->break_duration_mins ?? 0),
                'worked_mins_so_far'  => round(WSA_Attendance::worked_mins_so_far($today), 2),
            ];
        }

        return new WP_REST_Response([
            'success'      => true,
            'server_ts_ms' => $now_ts_ms,
            'staff'        => [
                'name'        => $staff->name,
                'employee_id' => $staff->employee_id,
                'department'  => $staff->department,
                'shift'       => $staff->shift_name ?: 'General',
                'shift_start' => $staff->start_time ?? '',
                'shift_end'   => $staff->end_time   ?? '',
            ],
            'today'        => $today_data,
            'month'        => $salary_report ? [
                'label'       => (function_exists('current_time') ? current_time('F Y') : date('F Y')),
                'present'     => (int)($salary_report['present']    ?? 0),
                'absent'      => (int)($salary_report['absent']     ?? 0),
                'on_leave'    => (int)($salary_report['on_leave']   ?? 0),
                'late_count'  => (int)($salary_report['late_count'] ?? 0),
                'total_hours' => round((float)($salary_report['total_hours'] ?? 0), 2),
                'ot_hours'    => round((float)($salary_report['total_ot']    ?? 0), 2),
                'gross'       => (float)($salary_report['gross']       ?? 0),
                'deductions'  => (float)($salary_report['deductions']  ?? 0),
                'net'         => (float)($salary_report['net']         ?? 0),
                'currency'    => $salary_report['currency'] ?? 'INR',
            ] : null,
            'salary_configured' => ($cfg && $cfg->monthly_salary > 0),
            'history'      => $history,
            'leaves'       => $leave_list,
            'min_checkout_mins' => (int)get_option('wsa_min_work_mins', 420),
        ], 200);
    }

    /* ════════════════════════════════════════════════════════
       ADMIN QUICK-MARK — check-in/out/break any staff member
    ════════════════════════════════════════════════════════ */
    public function rest_quick_mark(WP_REST_Request $r) {
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
        if (!in_array($action, ['checkin','checkout','break_start','break_end'], true)) {
            return $this->fail('Invalid action.', 400);
        }

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

        // Same forgiving state machine used by the frontend Quick Mark.
        // It is idempotent, so double taps/auto-refresh cannot create 409 Conflict errors.
        if ($action === 'checkin') {
            if ($today && in_array($status, ['IN','BREAK'], true)) {
                return new WP_REST_Response(['success'=>true,'message'=>'Already checked in.','status'=>$status], 200);
            }
            if ($today && $status === 'OUT') {
                return new WP_REST_Response(['success'=>true,'message'=>'Already completed today.','status'=>'OUT'], 200);
            }
            $is_late = 0;
            if (!empty($staff->start_time)) {
                $grace_end = strtotime($today_date.' '.$staff->start_time) + ((int)($staff->late_grace_mins ?: 15)*60);
                if ($now_ts > $grace_end) $is_late = 1;
            }
            if ($today) $wpdb->delete($table, ['id'=>$today->id]);
            $wpdb->insert($table, [
                'staff_id'=>$staff_id, 'att_date'=>$today_date, 'login_time'=>$now,
                'status'=>'IN', 'type'=>'MANUAL', 'is_late'=>0,
                'break_duration_mins'=>0, 'break_start'=>null,
            ]);
            WSA_DB::log_scan($staff_id, 'IN');
            return new WP_REST_Response(['success'=>true,'message'=>'✅ '.$staff->name.' checked IN.','status'=>'IN'], 200);
        }

        if (!$today) {
            return new WP_REST_Response(['success'=>true,'message'=>'No active record today.','status'=>'ABSENT'], 200);
        }

        if ($action === 'break_start') {
            if ($status === 'BREAK') return new WP_REST_Response(['success'=>true,'message'=>'Already on break.','status'=>'BREAK'], 200);
            if ($status === 'OUT')   return new WP_REST_Response(['success'=>true,'message'=>'Already completed today.','status'=>'OUT'], 200);
            if ($status !== 'IN')    return new WP_REST_Response(['success'=>true,'message'=>'Staff is not checked in.','status'=>$status ?: 'ABSENT'], 200);
            $wpdb->update($table, ['status'=>'BREAK','break_start'=>$now], ['id'=>$today->id]);
            $wpdb->insert($breaks, ['attendance_id'=>$today->id,'staff_id'=>$staff_id,'break_start'=>$now]);
            return new WP_REST_Response(['success'=>true,'message'=>'☕ Break started for '.$staff->name.'.','status'=>'BREAK'], 200);
        }

        if ($action === 'break_end') {
            if ($status === 'IN')  return new WP_REST_Response(['success'=>true,'message'=>'Already resumed.','status'=>'IN'], 200);
            if ($status === 'OUT') return new WP_REST_Response(['success'=>true,'message'=>'Already completed today.','status'=>'OUT'], 200);
            if ($status !== 'BREAK') return new WP_REST_Response(['success'=>true,'message'=>'Staff is not on break.','status'=>$status ?: 'ABSENT'], 200);
            $break_start_ts = $today->break_start ? strtotime($today->break_start) : $now_ts;
            $break_secs = max(0, $now_ts - $break_start_ts);
            $break_mins = $break_secs / 60;
            $new_total  = (float)($today->break_duration_mins ?: 0) + $break_mins;
            $open_break = $wpdb->get_row($wpdb->prepare("SELECT * FROM $breaks WHERE attendance_id=%d AND break_end IS NULL ORDER BY id DESC LIMIT 1", $today->id));
            if ($open_break) $wpdb->update($breaks, ['break_end'=>$now,'duration_mins'=>round($break_mins,2)], ['id'=>$open_break->id]);
            $wpdb->update($table, ['status'=>'IN','break_duration_mins'=>round($new_total,2),'break_start'=>null], ['id'=>$today->id]);
            return new WP_REST_Response(['success'=>true,'message'=>'▶️ '.$staff->name.' resumed.','status'=>'IN'], 200);
        }

        if ($action === 'checkout') {
            if ($status === 'OUT') return new WP_REST_Response(['success'=>true,'message'=>'Already completed today.','status'=>'OUT'], 200);
            if (!in_array($status, ['IN','BREAK'], true)) return new WP_REST_Response(['success'=>true,'message'=>'No active attendance to check out.','status'=>$status ?: 'ABSENT'], 200);

            $break_total = (float)($today->break_duration_mins ?? 0);
            if ($status === 'BREAK') {
                $break_start_ts = $today->break_start ? strtotime($today->break_start) : $now_ts;
                $break_mins = max(0, ($now_ts - $break_start_ts) / 60);
                $break_total += $break_mins;
                $open_break = $wpdb->get_row($wpdb->prepare("SELECT * FROM $breaks WHERE attendance_id=%d AND break_end IS NULL ORDER BY id DESC LIMIT 1", $today->id));
                if ($open_break) $wpdb->update($breaks, ['break_end'=>$now,'duration_mins'=>round($break_mins,2)], ['id'=>$open_break->id]);
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
            $h=floor($total_h); $m=round(($total_h-$h)*60);
            return new WP_REST_Response(['success'=>true,'message'=>'🚪 '.$staff->name.' checked OUT. Worked: '.$h.'h '.$m.'m','status'=>'OUT'], 200);
        }

        return $this->fail('Unknown action.', 400);
    }

    /* ── All staff with today's status (admin quick-mark page) ── */
    public function rest_all_staff_status(WP_REST_Request $r) {
        $all_staff = WSA_DB::get_all_staff(['status'=>'active','limit'=>500]);
        $today_date = current_time('Y-m-d');
        global $wpdb;
        $today_records = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wsa_attendance WHERE att_date=%s ORDER BY id ASC", $today_date
        ));
        $by_staff = [];
        foreach ($today_records as $rec) $by_staff[$rec->staff_id] = $rec;

        $result = [];
        foreach ($all_staff as $s) {
            $rec = $by_staff[$s->id] ?? null;
            $status = $rec ? $rec->status : 'ABSENT';
            $login_time = $rec ? $rec->login_time : null;
            $worked = $rec ? round(WSA_Attendance::worked_mins_so_far($rec), 2) : 0;
            $result[] = [
                'id'          => (int)$s->id,
                'name'        => $s->name,
                'employee_id' => $s->employee_id,
                'department'  => $s->department,
                'status'      => $status,
                'login_time'  => $login_time,
                'login_ts_ms' => $login_time ? (int)(strtotime($login_time)*1000) : null,
                'on_break'    => $status === 'BREAK',
                'break_start' => ($rec && $status==='BREAK') ? $rec->break_start : null,
                'worked_mins' => $worked,
            ];
        }
        return new WP_REST_Response(['success'=>true,'staff'=>$result,'server_ts_ms'=>(int)(microtime(true)*1000)], 200);
    }

    public function is_admin() { return current_user_can('manage_options'); }
    private function fail($m,$c=400) { return new WP_REST_Response(['success'=>false,'message'=>$m],$c); }
    private static function get_ip() {
        foreach (['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k)
            if (!empty($_SERVER[$k])) return trim(explode(',', $_SERVER[$k])[0]);
        return '';
    }
}
