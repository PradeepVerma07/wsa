<?php
defined('ABSPATH') || exit;

class WSA_Attendance {

    /* ════════════════════════════════════════════════
       SMART SCAN
    ════════════════════════════════════════════════ */
    public static function smart_scan($employee_id, $pin, $gate_token = '') {
        $staff = WSA_DB::get_staff_by_eid($employee_id);
        if (!$staff)             return self::err('Employee not found. Check your ID.');
        if ($staff->pin !== $pin) return self::err('Incorrect PIN. Try again.');

        $dup_mins = (int)get_option('wsa_duplicate_mins', 3);
        $last     = WSA_DB::get_last_scan($staff->id, $dup_mins);
        if ($last) {
            $wait = ceil($dup_mins - ((current_time('timestamp') - strtotime($last->scanned_at)) / 60));
            return self::err("Scan too fast! Please wait {$wait} more minute(s).", ['type' => 'duplicate', 'wait' => $wait]);
        }

        $record = WSA_DB::get_today($staff->id);
        $now    = current_time('mysql');
        $today  = current_time('Y-m-d');

        // No record → Check IN
        if (!$record) return self::do_checkin($staff, $gate_token, $now, $today);
        // Already IN, no OUT → Check OUT
        if ($record->status === 'IN') return self::do_checkout($staff, $record, $gate_token, $now);
        // Already fully done
        return self::err('You have already completed attendance for today. Contact admin for corrections.');
    }

    /* ════════════════════════════════════════════════
       CHECK IN
    ════════════════════════════════════════════════ */
    private static function do_checkin($staff, $gate_token, $now, $today) {
        global $wpdb;

        $is_late = 0;
        if ($staff->start_time) {
            $shift_start = strtotime(date('Y-m-d') . ' ' . $staff->start_time);
            $grace_end   = $shift_start + (($staff->late_grace_mins ?: 15) * 60);
            if (current_time('timestamp') > $grace_end) $is_late = 1;
        }

        $wpdb->insert("{$wpdb->prefix}wsa_attendance", [
            'staff_id'   => $staff->id,
            'att_date'   => $today,
            'login_time' => $now,
            'status'     => 'IN',
            'type'       => 'SCAN',
            'is_late'    => $is_late,
            'gate_token' => $gate_token,
            'ip_address' => self::get_ip(),
        ]);

        WSA_DB::log_scan($staff->id, 'IN');

        return [
            'success' => true,
            'action'  => 'IN',
            'message' => ($is_late ? '⚠️ Checked IN (Late)' : '✅ Checked IN') . ' — ' . date('h:i A', strtotime($now)),
            'data'    => [
                'name'       => $staff->name,
                'emp_id'     => $staff->employee_id,
                'department' => $staff->department,
                'login_time' => $now,
                'is_late'    => $is_late,
                'shift'      => $staff->shift_name ?: 'General',
                'shift_end'  => $staff->end_time   ?: '18:00:00',
            ],
        ];
    }

    /* ════════════════════════════════════════════════
       CHECK OUT
    ════════════════════════════════════════════════ */
    private static function do_checkout($staff, $record, $gate_token, $now) {
        global $wpdb;

        [$total_h, $ot_h, $is_early] = self::calculate($record->login_time, $now, $staff);

        $wpdb->update("{$wpdb->prefix}wsa_attendance", [
            'logout_time'  => $now,
            'total_hours'  => round($total_h, 2),
            'overtime_hours' => round($ot_h, 2),
            'status'       => 'OUT',
            'is_early_exit'=> $is_early,
            'gate_token'   => $gate_token,
        ], ['id' => $record->id]);

        WSA_DB::log_scan($staff->id, 'OUT');

        $h   = floor($total_h);
        $m   = round(($total_h - $h) * 60);
        $msg = "🚪 Checked OUT — " . date('h:i A', strtotime($now)) . " | Worked: {$h}h {$m}m";
        if ($ot_h > 0) {
            $oh = floor($ot_h); $om = round(($ot_h - $oh)*60);
            $msg .= " | OT: {$oh}h {$om}m";
        }

        return [
            'success'     => true,
            'action'      => 'OUT',
            'message'     => $msg,
            'data'        => [
                'name'         => $staff->name,
                'emp_id'       => $staff->employee_id,
                'login_time'   => $record->login_time,
                'logout_time'  => $now,
                'total_hours'  => round($total_h, 2),
                'overtime_hours' => round($ot_h, 2),
                'is_early'     => $is_early,
                'hours_display'=> "{$h}h {$m}m",
            ],
        ];
    }

    /* ════════════════════════════════════════════════
       MANUAL ENTRY (Admin)
    ════════════════════════════════════════════════ */
    public static function manual_entry($args) {
        global $wpdb;
        $staff_id   = absint($args['staff_id']);
        $att_date   = sanitize_text_field($args['att_date']);
        $entry_status = strtoupper(sanitize_text_field($args['entry_status'] ?? 'PRESENT'));
        $login_str  = sanitize_text_field($args['login_time'] ?? '');
        $logout_str = sanitize_text_field($args['logout_time'] ?? '');
        $notes      = sanitize_text_field($args['notes'] ?? '');

        if (!$staff_id || !$att_date) return self::err('Staff and date are required.');
        if ($entry_status !== 'ABSENT' && !$login_str) return self::err('Login time is required for present/manual attendance.');

        $staff     = WSA_DB::get_staff($staff_id);
        if (!$staff) return self::err('Staff not found.');

        $login_dt  = $login_str ? self::normalize_datetime($att_date, $login_str) : null;
        $logout_dt = $logout_str ? self::normalize_datetime($att_date, $logout_str) : null;

        // Check if record exists for this date
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wsa_attendance WHERE staff_id=%d AND att_date=%s",
            $staff_id, $att_date
        ));

        $total_h = 0; $ot_h = 0; $is_early = 0;
        $status  = ($entry_status === 'ABSENT') ? 'ABSENT' : ($logout_dt ? 'OUT' : 'IN');

        $auto_break_mins = 0;
        if ($entry_status !== 'ABSENT' && $login_dt && $logout_dt) {
            // Manual entries use the scheduled break window from Settings, not a blind 30-minute deduction.
            $auto_break_mins = self::scheduled_break_mins($login_dt, $logout_dt);
            [$total_h, $ot_h, $is_early] = self::calculate($login_dt, $logout_dt, $staff, $auto_break_mins);
        }

        $data = [
            'staff_id'     => $staff_id,
            'att_date'     => $att_date,
            'login_time'   => ($entry_status === 'ABSENT') ? null : $login_dt,
            'logout_time'  => ($entry_status === 'ABSENT') ? null : $logout_dt,
            'total_hours'  => ($entry_status === 'ABSENT') ? 0 : round($total_h, 2),
            'overtime_hours'=> ($entry_status === 'ABSENT') ? 0 : round($ot_h, 2),
            'type'         => 'MANUAL',
            'status'       => $status,
            'is_late'      => 0,
            'is_early_exit'=> ($entry_status === 'ABSENT') ? 0 : $is_early,
            'notes'        => $notes,
            'break_duration_mins' => ($entry_status === 'ABSENT') ? 0 : round($auto_break_mins, 2),
        ];

        if ($existing) {
            $wpdb->update("{$wpdb->prefix}wsa_attendance", $data, ['id' => $existing->id]);
            $record_id = $existing->id;
        } else {
            $wpdb->insert("{$wpdb->prefix}wsa_attendance", $data);
            $record_id = $wpdb->insert_id;
        }

        return ['success' => true, 'message' => 'Attendance recorded successfully.', 'id' => $record_id];
    }

    /* ════════════════════════════════════════════════
       EDIT RECORD (Admin)
    ════════════════════════════════════════════════ */
    public static function update_record($id, $args) {
        global $wpdb;
        $record = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wsa_attendance WHERE id=%d", $id));
        if (!$record) return self::err('Record not found.');

        $staff    = WSA_DB::get_staff($record->staff_id);
        $login_dt = !empty($args['login_time'])  ? $record->att_date . ' ' . $args['login_time']  . ':00' : $record->login_time;
        $logout_dt= !empty($args['logout_time']) ? $record->att_date . ' ' . $args['logout_time'] . ':00' : $record->logout_time;

        $total_h = 0; $ot_h = 0; $is_early = 0; $auto_break_mins = 0;
        if ($login_dt && $logout_dt) {
            $auto_break_mins = self::scheduled_break_mins($login_dt, $logout_dt);
            [$total_h, $ot_h, $is_early] = self::calculate($login_dt, $logout_dt, $staff, $auto_break_mins);
        }

        $update = [
            'login_time'    => $login_dt,
            'logout_time'   => $logout_dt,
            'total_hours'   => round($total_h, 2),
            'overtime_hours'=> round($ot_h, 2),
            'status'        => $logout_dt ? 'OUT' : 'IN',
            'is_early_exit' => $is_early,
            'notes'         => sanitize_text_field($args['notes'] ?? $record->notes),
            'break_duration_mins' => round($auto_break_mins, 2),
        ];
        $wpdb->update("{$wpdb->prefix}wsa_attendance", $update, ['id' => $id]);
        return ['success' => true, 'message' => 'Record updated.'];
    }

    private static function normalize_datetime($date, $time_or_dt) {
        $time_or_dt = trim((string)$time_or_dt);
        if ($time_or_dt === '') return null;
        // Accept either HH:MM, HH:MM:SS, or a full Y-m-d HH:MM(:SS) string from older JS builds.
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $time_or_dt)) {
            return str_replace('T', ' ', strlen($time_or_dt) === 16 ? $time_or_dt . ':00' : $time_or_dt);
        }
        if (preg_match('/^\d{2}:\d{2}$/', $time_or_dt)) return $date . ' ' . $time_or_dt . ':00';
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time_or_dt)) return $date . ' ' . $time_or_dt;
        return $date . ' ' . $time_or_dt;
    }

    /**
     * Scheduled break overlap from Settings.
     * Example: break window 13:00–13:30 deducts only when the IN→OUT time crosses that window.
     * 09:00–12:00 = 0 min, 09:00–19:00 = 30 min, 13:15–18:00 = 15 min.
     * Regular same-day checkout at/after 21:00 = 0 min scheduled break.
     */
    public static function skips_scheduled_break_after_9pm($login, $logout) {
        $in_ts  = strtotime($login);
        $out_ts = strtotime($logout);
        if (!$in_ts || !$out_ts || $out_ts <= $in_ts) return false;

        return date('Y-m-d', $in_ts) === date('Y-m-d', $out_ts)
            && (int) date('w', $in_ts) !== 0
            && date('H:i', $out_ts) >= '21:00';
    }

    public static function scheduled_break_mins($login, $logout) {
        $in_ts  = strtotime($login);
        $out_ts = strtotime($logout);
        if (!$in_ts || !$out_ts || $out_ts <= $in_ts) return 0;
        if (self::skips_scheduled_break_after_9pm($login, $logout)) return 0;

        $start = get_option('wsa_break_start_time', '13:00');
        $end   = get_option('wsa_break_end_time', '13:30');
        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
            $start = '13:00';
            $end   = '13:30';
        }

        $mins = 0;
        $day_start = strtotime(date('Y-m-d 00:00:00', $in_ts));
        $day_end   = strtotime(date('Y-m-d 00:00:00', $out_ts));

        for ($day = $day_start; $day <= $day_end; $day += DAY_IN_SECONDS) {
            $date = date('Y-m-d', $day);
            $break_start = strtotime($date . ' ' . $start . ':00');
            $break_end   = strtotime($date . ' ' . $end . ':00');
            if ($break_end <= $break_start) $break_end += DAY_IN_SECONDS;

            $overlap_start = max($in_ts, $break_start);
            $overlap_end   = min($out_ts, $break_end);
            if ($overlap_end > $overlap_start) {
                $mins += (int) round(($overlap_end - $overlap_start) / 60);
            }
        }
        return max(0, $mins);
    }

    /* ════════════════════════════════════════════════
       CALCULATION ENGINE
    ════════════════════════════════════════════════ */
    public static function calculate($login, $logout, $staff, $break_duration_mins = null) {
        $in_ts  = strtotime($login);
        $out_ts = strtotime($logout);
        if (!$in_ts || !$out_ts || $out_ts <= $in_ts) return [0, 0, 0];

        $raw_mins = max(0, (int) round(($out_ts - $in_ts) / 60));
        $att_date = date('Y-m-d', $in_ts);
        $is_sunday = ((int) date('w', $in_ts) === 0);
        $skip_break_after_9pm = self::skips_scheduled_break_after_9pm($login, $logout);

        $std_mins = 480; // default 8 hours
        if (!empty($staff->overtime_after_mins)) {
            $std_mins = max(1, (int) $staff->overtime_after_mins);
        } elseif (!empty($staff->standard_hours)) {
            $std_mins = max(1, (int) round(((float) $staff->standard_hours) * 60));
        }

        // Regular checkout at/after 21:00 skips the scheduled 30m break.
        // Example: 09:00 -> 21:00 = 8h working + 4h OT.
        if ($skip_break_after_9pm) {
            $is_early_after_9 = 0;
            if (!empty($staff->end_time)) {
                $shift_end = strtotime($att_date . ' ' . $staff->end_time);
                $grace_early = $shift_end - ((int)($staff->early_exit_grace_mins ?: 15) * 60);
                if ($out_ts < $grace_early) $is_early_after_9 = 1;
            }
            return [
                round(min($raw_mins, $std_mins) / 60, 4),
                round(max(0, $raw_mins - $std_mins) / 60, 4),
                $is_early_after_9,
            ];
        }

        // Normal rule for every other time:
        // 1) Work from raw IN→OUT minutes.
        // 2) Deduct ONLY the configured break-window overlap, or actual recorded break scans if passed.
        // 3) Normal paid hours are capped at 8h; remaining payable time is OT.
        // Example with Settings break 13:00–13:30: 09:00 → 19:00 = 10h raw - 30m break = 8h work + 1h30m OT.
        $deduct_break_mins = ($break_duration_mins === null)
            ? self::scheduled_break_mins($login, $logout)
            : max(0, (int) round((float) $break_duration_mins));
        $payable_mins = max(0, $raw_mins - $deduct_break_mins);

        if ($is_sunday) {
            // Sunday is OT only. It never contributes to basic/working-day salary.
            $worked_h = 0.0;
            $ot_h = $payable_mins / 60;
        } else {
            $worked_mins = min($payable_mins, $std_mins);
            $ot_mins = max(0, $payable_mins - $std_mins);
            $worked_h = $worked_mins / 60;
            $ot_h = $ot_mins / 60;
        }

        $is_early = 0;
        if (!$is_sunday && !empty($staff->end_time)) {
            $shift_end   = strtotime($att_date . ' ' . $staff->end_time);
            $grace_early = $shift_end - ((int)($staff->early_exit_grace_mins ?: 15) * 60);
            if ($out_ts < $grace_early) $is_early = 1;
        }

        return [round($worked_h, 4), round($ot_h, 4), $is_early];
    }

    /* ════════════════════════════════════════════════
       BREAK HELPERS
    ════════════════════════════════════════════════ */
    /**
     * Calculate cumulative worked minutes for a currently-IN/BREAK record.
     * Excludes all break time (both completed breaks AND ongoing break).
     */
    public static function worked_mins_so_far($record) {
        $elapsed_secs = current_time('timestamp') - strtotime($record->login_time);
        $break_secs   = (float)($record->break_duration_mins ?: 0) * 60;
        // If currently on break, also subtract the ongoing break time
        if ($record->status === 'BREAK' && $record->break_start) {
            $ongoing_break_secs = current_time('timestamp') - strtotime($record->break_start);
            $break_secs += max(0, $ongoing_break_secs);
        }
        return max(0, ($elapsed_secs - $break_secs) / 60);
    }

    /* ════════════════════════════════════════════════
       AUTO-LOGOUT CRON (called by WSA_Cron)
    ════════════════════════════════════════════════ */
    public static function run_auto_logout() {
        $hr = (int)get_option('wsa_auto_logout_hr', 0);
        if (!$hr) return;
        global $wpdb;
        $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - ($hr * 3600));
        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, s.break_minutes, s.overtime_after_mins, s.early_exit_grace_mins, s.end_time
             FROM {$wpdb->prefix}wsa_attendance a
             JOIN {$wpdb->prefix}wsa_staff s ON a.staff_id=s.id
             WHERE a.status='IN' AND a.login_time <= %s", $cutoff
        ));
        foreach ($records as $r) {
            $now = current_time('mysql');
            [$total_h, $ot_h] = self::calculate($r->login_time, $now, (object)$r);
            $wpdb->update("{$wpdb->prefix}wsa_attendance", [
                'logout_time'=>$now, 'total_hours'=>round($total_h,2),
                'overtime_hours'=>round($ot_h,2), 'status'=>'OUT',
                'notes'=>'Auto-logged out by system',
            ], ['id'=>$r->id]);
        }
    }

    /* ════════════════════════════════════════════════
       HELPERS
    ════════════════════════════════════════════════ */
    public static function fmt($hours) {
        if (!$hours || $hours <= 0) return '—';
        $h = floor($hours);
        $m = round(($hours - $h) * 60);
        return "{$h}h {$m}m";
    }

    public static function live_hours($login_time) {
        if (!$login_time) return '0h 0m';
        $secs = current_time('timestamp') - strtotime($login_time);
        $h = floor($secs / 3600);
        $m = floor(($secs % 3600) / 60);
        return "{$h}h {$m}m";
    }

    public static function status_badge($status, $type='') {
        $map = [
            'IN'     => ['label'=>'Inside',   'cls'=>'in'],
            'OUT'    => ['label'=>'Done',      'cls'=>'out'],
            'ABSENT' => ['label'=>'Absent',    'cls'=>'absent'],
            'BREAK'  => ['label'=>'On Break',  'cls'=>'break'],
        ];
        $b = $map[$status] ?? ['label'=>$status,'cls'=>''];
        $t = $type === 'MANUAL' ? ' <span class="wsa-manual-tag">Manual</span>' : '';
        return "<span class=\"wsa-badge wsa-badge--{$b['cls']}\">{$b['label']}</span>{$t}";
    }

    private static function get_ip() {
        foreach (['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) return trim(explode(',', $_SERVER[$k])[0]);
        }
        return '';
    }

    private static function err($msg, $extra=[]) {
        return array_merge(['success'=>false,'message'=>$msg], $extra);
    }
}
