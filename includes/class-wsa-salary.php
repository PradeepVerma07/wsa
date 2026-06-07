<?php
defined('ABSPATH') || exit;

/**
 * WSA_Salary — Monthly salary calculation engine
 *
 * Salary formula per staff:
 *   Net = (daily_rate × present_days) + (ot_rate_per_hour × total_ot_hours)
 *         − (per_absent_deduction × absent_days)
 *
 * Leave days (approved) count as PRESENT, not absent.
 */
class WSA_Salary {

    /* ════════════════════════════════════════════════
       INSTALL — create salary_config & leaves tables
    ════════════════════════════════════════════════ */
    public static function install() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        /* Per-staff salary configuration */
        dbDelta("CREATE TABLE {$wpdb->prefix}wsa_salary_config (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            staff_id        BIGINT UNSIGNED NOT NULL,
            monthly_salary  DECIMAL(10,2)   NOT NULL DEFAULT 0.00  COMMENT 'Fixed monthly gross',
            daily_rate      DECIMAL(10,2)   NOT NULL DEFAULT 0.00  COMMENT 'Auto-calc if 0: monthly/working_days',
            ot_rate_per_hr  DECIMAL(10,2)   NOT NULL DEFAULT 0.00  COMMENT '0 = no OT pay',
            absent_deduction DECIMAL(10,2)  NOT NULL DEFAULT 0.00  COMMENT 'Per absent day penalty',
            working_days    TINYINT         NOT NULL DEFAULT 26     COMMENT 'Standard working days per month',
            currency        VARCHAR(10)     NOT NULL DEFAULT 'INR',
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY staff_id (staff_id)
        ) $c;");

        /* Leaves assigned by admin */
        dbDelta("CREATE TABLE {$wpdb->prefix}wsa_leaves (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            staff_id    BIGINT UNSIGNED NOT NULL,
            leave_date  DATE            NOT NULL,
            leave_type  VARCHAR(60)     NOT NULL DEFAULT 'Casual'
                            COMMENT 'Casual, Sick, Earned, Unpaid, Holiday',
            status      ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved',
            notes       TEXT            DEFAULT NULL,
            assigned_by BIGINT UNSIGNED DEFAULT NULL COMMENT 'WP user id of admin',
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY staff_date (staff_id, leave_date),
            INDEX date_idx   (leave_date),
            INDEX staff_idx  (staff_id),
            INDEX status_idx (status)
        ) $c;");
    }

    /* ════════════════════════════════════════════════
       SALARY CONFIG — get / upsert
    ════════════════════════════════════════════════ */
    public static function get_config($staff_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wsa_salary_config WHERE staff_id=%d", $staff_id
        ));
        if (!$row) {
            // Return defaults
            return (object)[
                'staff_id'         => $staff_id,
                'monthly_salary'   => 0,
                'daily_rate'       => 0,
                'ot_rate_per_hr'   => 0,
                'absent_deduction' => 0,
                'working_days'     => 26,
                'currency'         => 'INR',
            ];
        }
        return $row;
    }

    public static function save_config($staff_id, $args) {
        global $wpdb;
        // Accept both classic admin field names and frontend admin-portal aliases.
        // This keeps OT salary working when config is saved from either dashboard.
        $monthly_salary   = $args['monthly_salary'] ?? ($args['base_salary'] ?? 0);
        $ot_rate_per_hr   = $args['ot_rate_per_hr'] ?? ($args['overtime_rate_per_hour'] ?? 0);
        $absent_deduction = $args['absent_deduction'] ?? ($args['absent_deduction_per_day'] ?? 0);
        $working_days     = $args['working_days'] ?? ($args['working_days_per_month'] ?? 26);

        $data = [
            'staff_id'         => absint($staff_id),
            'monthly_salary'   => (float) $monthly_salary,
            'daily_rate'       => (float) ($args['daily_rate'] ?? 0),
            'ot_rate_per_hr'   => (float) $ot_rate_per_hr,
            'absent_deduction' => (float) $absent_deduction,
            'working_days'     => max(1, (int) $working_days),
            'currency'         => sanitize_text_field($args['currency'] ?? 'INR'),
        ];
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wsa_salary_config WHERE staff_id=%d", $staff_id
        ));
        if ($exists) {
            $wpdb->update("{$wpdb->prefix}wsa_salary_config", $data, ['staff_id' => $staff_id]);
        } else {
            $wpdb->insert("{$wpdb->prefix}wsa_salary_config", $data);
        }
        return ['success' => true];
    }

    /**
     * Payroll hours follow the final salary rule used by both WP Admin and frontend salary dashboards.
     * Exact 09:00 AM to 09:00 PM = 8 paid working hours + 4 OT hours with NO break deduction.
     * Every other timing uses the normal break rule: tracked break if available, otherwise shift break minutes.
     */
    private static function payroll_hours_for_attendance($att, $staff) {
        $login  = !empty($att->login_time)  ? strtotime($att->login_time)  : 0;
        $logout = !empty($att->logout_time) ? strtotime($att->logout_time) : 0;

        // If staff is still IN/BREAK for today, use current time only for live dashboard estimate.
        if (!$logout && !empty($att->att_date) && $att->att_date === current_time('Y-m-d') && in_array(strtoupper((string)$att->status), ['IN','BREAK'], true)) {
            $logout = current_time('timestamp');
        }

        if (!$login || !$logout || $logout <= $login) {
            return [0.0, 0.0, 0.0, 0.0]; // raw, normal, ot, salary_break_mins
        }

        $raw_mins  = max(0, (int) round(($logout - $login) / 60));
        $raw_hours = $raw_mins / 60;
        $same_day  = (date('Y-m-d', $login) === date('Y-m-d', $logout));
        $is_sunday = ((int) date('w', $login) === 0);
        $is_exact_9_to_9 = ($same_day && date('H:i', $login) === '09:00' && date('H:i', $logout) === '21:00');

        $std_mins = 480;
        if (!empty($staff->overtime_after_mins)) {
            $std_mins = max(1, (int) $staff->overtime_after_mins);
        } elseif (!empty($staff->standard_hours)) {
            $std_mins = max(1, (int) round(((float)$staff->standard_hours) * 60));
        }

        // Only exact 09:00 AM → 09:00 PM on a regular day skips break deduction.
        if (!$is_sunday && $is_exact_9_to_9) {
            $normal_hours = $std_mins / 60;
            $ot_hours = max(0, $raw_mins - $std_mins) / 60;
            return [$raw_hours, round($normal_hours, 4), round($ot_hours, 4), 0.0];
        }

        // Proper break deduction for all other cases.
        // Deduct actual tracked break if present; otherwise deduct only the scheduled break-window overlap.
        // This deduction reduces OT first because normal hours are capped at 8h.
        $recorded_break = isset($att->break_duration_mins) ? max(0, (float)$att->break_duration_mins) : 0.0;
        $salary_break_mins = $recorded_break > 0 ? $recorded_break : WSA_Attendance::scheduled_break_mins($att->login_time, $att->logout_time);
        $payable_mins = max(0, $raw_mins - (int) round($salary_break_mins));

        if ($is_sunday) {
            // Sunday = OT only, no basic salary/workday count.
            return [$raw_hours, 0.0, round($payable_mins / 60, 4), $salary_break_mins];
        }

        $normal_mins = min($payable_mins, $std_mins);
        $ot_mins = max(0, $payable_mins - $std_mins);
        return [$raw_hours, round($normal_mins / 60, 4), round($ot_mins / 60, 4), $salary_break_mins];
    }

    /* ════════════════════════════════════════════════
       MONTHLY REPORT — compute everything for one month
    ════════════════════════════════════════════════ */
    public static function monthly_report($staff_id, $year, $month) {
        global $wpdb;

        $staff  = WSA_DB::get_staff($staff_id);
        if (!$staff) return null;

        $cfg    = self::get_config($staff_id);
        $month_str  = sprintf('%04d-%02d', $year, $month);
        $date_from  = $month_str . '-01';
        $days_in_month = (int) date('t', strtotime($date_from));
        $date_to    = $month_str . '-' . $days_in_month;
        $today      = function_exists('current_time') ? current_time('Y-m-d') : date('Y-m-d');

        // All attendance rows for this month
        $att_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wsa_attendance
             WHERE staff_id=%d AND att_date BETWEEN %s AND %s
             ORDER BY att_date ASC",
            $staff_id, $date_from, $date_to
        ));
        $att_by_date = [];
        foreach ($att_rows as $r) $att_by_date[$r->att_date] = $r;

        // All approved leaves
        $leave_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wsa_leaves
             WHERE staff_id=%d AND leave_date BETWEEN %s AND %s AND status='approved'
             ORDER BY leave_date ASC",
            $staff_id, $date_from, $date_to
        ));
        $leaves_by_date = [];
        foreach ($leave_rows as $l) $leaves_by_date[$l->leave_date] = $l;

        // Day-by-day breakup
        $days     = [];
        $present  = 0;
        $absent   = 0;
        $on_leave = 0;
        $late_cnt = 0;
        $total_hours  = 0.0;
        $total_ot     = 0.0;
        $sunday_ot_days = 0;

        for ($d = 1; $d <= $days_in_month; $d++) {
            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);

            // Don't count future dates
            if ($date_str > $today) {
                $days[] = ['date'=>$date_str,'status'=>'future'];
                continue;
            }

            $att   = $att_by_date[$date_str] ?? null;
            $leave = $leaves_by_date[$date_str] ?? null;

            // Salary rule:
            // Sunday must NEVER be counted as a paid working day, even if Sunday is
            // accidentally enabled in Work Days settings. If staff has attendance on
            // Sunday, pay it only as overtime. No daily-rate/basic salary is added.
            $day_of_week = (int) date('w', strtotime($date_str)); // 0=Sun
            $is_sunday   = ($day_of_week === 0);
            $work_days   = array_map('intval', explode(',', get_option('wsa_work_days', '1,2,3,4,5,6')));
            $is_working_day = (!$is_sunday) && in_array($day_of_week, $work_days, true) && !WSA_DB::is_holiday($date_str);

            if (!$is_working_day) {
                // Sunday/holiday rule: do NOT count as normal present, absent,
                // worked hours, or basic salary. Attendance on Sunday/holiday
                // is paid ONLY through OT amount.
                $att_status = $att ? strtoupper((string) $att->status) : '';
                $has_work_attendance = $att && in_array($att_status, ['IN','OUT','BREAK','PRESENT'], true);
                if ($has_work_attendance) {
                    // Sunday/holiday payroll: full punch duration is OT only.
                    [$raw_hours, $normal_hours, $ot_hours, $salary_break_mins] = self::payroll_hours_for_attendance($att, $staff);
                    // Important: do NOT add Sunday/holiday time to $total_hours.
                    // $ot_hours already deducts the standard 30 minute break for non-working days.
                    // It must appear/pay as overtime only.
                    $total_ot += $ot_hours;
                    if ($is_sunday) $sunday_ot_days++;

                    $days[] = [
                        'date'              => $date_str,
                        'status'            => $is_sunday ? 'sunday_ot' : 'holiday_ot',
                        'login'             => $att->login_time,
                        'logout'            => $att->logout_time,
                        'hours'             => 0,
                        'ot'                => $ot_hours,
                        'is_late'           => false,
                        'type'              => $att->type,
                        'break_duration_mins' => (float)($att->break_duration_mins ?? 0),
                        'salary_break_mins'  => isset($salary_break_mins) ? (float)$salary_break_mins : 0,
                    ];
                } else {
                    $days[] = ['date'=>$date_str,'status'=>'holiday','day_name'=>date('D', strtotime($date_str))];
                }
                continue;
            }

            if ($leave) {
                $on_leave++;
                $days[] = [
                    'date'       => $date_str,
                    'status'     => 'leave',
                    'leave_type' => $leave->leave_type,
                    'notes'      => $leave->notes,
                ];
            } elseif ($att) {
                $att_status = strtoupper((string) $att->status);

                if (in_array($att_status, ['ABSENT','A'], true)) {
                    // Stored absent records must count as absent, not present.
                    $absent++;
                    $days[] = ['date'=>$date_str,'status'=>'absent'];
                } elseif (in_array($att_status, ['IN','OUT','BREAK','PRESENT'], true)) {
                    $present++;
                    $late_cnt += (strtoupper((string)$att->type) === 'SCAN') ? (int) $att->is_late : 0;

                    // Salary dashboard hours: do NOT deduct break. Cap normal paid hours
                    // at shift standard/overtime threshold. Example: 9 AM–9 PM = 8h work + 4h OT.
                    [$raw_hours, $normal_hours, $ot_hours, $salary_break_mins] = self::payroll_hours_for_attendance($att, $staff);
                    $total_hours += $normal_hours;
                    $total_ot    += $ot_hours;
                    $days[] = [
                        'date'              => $date_str,
                        'status'            => $att->status,
                        'login'             => $att->login_time,
                        'logout'            => $att->logout_time,
                        'hours'             => $normal_hours,
                        'ot'                => $ot_hours,
                        'is_late'           => (strtoupper((string)$att->type) === 'SCAN') ? (bool) $att->is_late : false,
                        'type'              => $att->type,
                        'break_duration_mins' => (float)($att->break_duration_mins ?? 0),
                        'salary_break_mins'  => isset($salary_break_mins) ? (float)$salary_break_mins : 0,
                    ];
                } else {
                    $absent++;
                    $days[] = ['date'=>$date_str,'status'=>'absent'];
                }
            } else {
                $absent++;
                $days[] = ['date'=>$date_str,'status'=>'absent'];
            }
        }

        // Salary calculation
        $working_days  = $cfg->working_days ?: 26;
        $monthly       = (float) $cfg->monthly_salary;
        $daily_rate    = (float) $cfg->daily_rate;
        $ot_rate       = (float) $cfg->ot_rate_per_hr;
        $abs_deduction = (float) $cfg->absent_deduction;
        $currency      = $cfg->currency ?: 'INR';

        // If daily_rate is 0 and monthly_salary is set → auto-calc
        if ($daily_rate <= 0 && $monthly > 0) {
            $daily_rate = $monthly / $working_days;
        }

        $earned_basic  = $daily_rate * $present;
        $leave_pay     = $daily_rate * $on_leave; // leaves count as paid
        $ot_pay        = $ot_rate > 0 ? ($ot_rate * $total_ot) : 0;
        $deductions    = $abs_deduction * $absent;
        $gross         = $earned_basic + $leave_pay + $ot_pay;
        $net           = max(0, $gross - $deductions);

        return [
            'staff'        => $staff,
            'config'       => $cfg,
            'month_label'  => date('F Y', strtotime($date_from)),
            'year'         => $year,
            'month'        => $month,
            'date_from'    => $date_from,
            'date_to'      => $date_to,
            'days'         => $days,
            'days_in_month'=> $days_in_month,
            'present'      => $present,
            'absent'       => $absent,
            'on_leave'     => $on_leave,
            'late_count'   => $late_cnt,
            'sunday_ot_days'=> $sunday_ot_days,
            'total_hours'      => round($total_hours, 2),
            'total_ot'         => round($total_ot, 2),
            'daily_rate'   => round($daily_rate, 2),
            'earned_basic' => round($earned_basic, 2),
            'leave_pay'    => round($leave_pay, 2),
            'ot_pay'       => round($ot_pay, 2),
            'deductions'   => round($deductions, 2),
            'gross'        => round($gross, 2),
            'net'          => round($net, 2),
            'currency'     => $currency,
        ];
    }

    /* ════════════════════════════════════════════════
       ALL-STAFF MONTHLY SUMMARY (for report list)
    ════════════════════════════════════════════════ */
    public static function all_staff_summary($year, $month) {
        $all = WSA_DB::get_all_staff(['status' => 'active', 'limit' => 999]);
        $rows = [];
        foreach ($all as $s) {
            $rows[] = self::monthly_report($s->id, $year, $month);
        }
        return array_filter($rows);
    }

    /* ════════════════════════════════════════════════
       LEAVES
    ════════════════════════════════════════════════ */
    public static function assign_leave($staff_id, $date, $type, $status, $notes = '') {
        global $wpdb;
        $data = [
            'staff_id'   => absint($staff_id),
            'leave_date' => sanitize_text_field($date),
            'leave_type' => sanitize_text_field($type),
            'status'     => in_array($status, ['approved','pending','rejected']) ? $status : 'approved',
            'notes'      => sanitize_text_field($notes),
            'assigned_by'=> get_current_user_id(),
        ];
        // Upsert
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wsa_leaves WHERE staff_id=%d AND leave_date=%s",
            $staff_id, $date
        ));
        if ($exists) {
            $wpdb->update("{$wpdb->prefix}wsa_leaves", $data, ['id' => $exists]);
            return ['success' => true, 'action' => 'updated'];
        }
        $wpdb->insert("{$wpdb->prefix}wsa_leaves", $data);
        return ['success' => true, 'action' => 'created'];
    }

    public static function delete_leave($id) {
        global $wpdb;
        return $wpdb->delete("{$wpdb->prefix}wsa_leaves", ['id' => absint($id)]);
    }

    public static function get_leaves($args = []) {
        global $wpdb;
        $args = wp_parse_args($args, [
            'staff_id'  => 0,
            'date_from' => date('Y-m-01'),
            'date_to'   => date('Y-m-t'),
            'status'    => '',
            'limit'     => 500,
        ]);
        $sql = "SELECT l.*, s.name AS staff_name, s.employee_id AS emp_code, s.department
                FROM {$wpdb->prefix}wsa_leaves l
                JOIN {$wpdb->prefix}wsa_staff s ON l.staff_id=s.id
                WHERE l.leave_date BETWEEN %s AND %s";
        $p = [$args['date_from'], $args['date_to']];
        if ($args['staff_id']) { $sql .= " AND l.staff_id=%d"; $p[] = $args['staff_id']; }
        if ($args['status'])   { $sql .= " AND l.status=%s";   $p[] = $args['status']; }
        $sql .= " ORDER BY l.leave_date DESC LIMIT %d";
        $p[]  = $args['limit'];
        return $wpdb->get_results($wpdb->prepare($sql, $p));
    }

    /* ════════════════════════════════════════════════
       FORMAT
    ════════════════════════════════════════════════ */

    public static function fmt_hours($hours) {
        $hours = (float) $hours;
        if ($hours <= 0) return '—';
        $mins = (int) round($hours * 60);
        $h = intdiv($mins, 60);
        $m = $mins % 60;
        if ($m === 0) return $h . 'h';
        if ($h === 0) return $m . 'm';
        return $h . 'h ' . $m . 'm';
    }

    public static function fmt_money($amount, $currency = 'INR') {
        $symbols = ['INR'=>'₹','USD'=>'$','EUR'=>'€','GBP'=>'£','AED'=>'د.إ'];
        $sym = $symbols[$currency] ?? $currency . ' ';
        return $sym . number_format((float)$amount, 2);
    }
}
