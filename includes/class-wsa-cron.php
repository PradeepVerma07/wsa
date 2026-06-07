<?php
defined('ABSPATH') || exit;

class WSA_Cron {

    const ABSENT_HOOK    = 'wsa_mark_absent_cron';
    const AUTO_OUT_HOOK  = 'wsa_auto_logout_cron';
    const QR_PURGE_HOOK  = 'wsa_qr_purge_cron';

    public function __construct() {
        add_action(self::AUTO_OUT_HOOK,  ['WSA_Attendance', 'run_auto_logout']);
        add_action(self::QR_PURGE_HOOK,  ['WSA_QrCode',    'purge']);
        add_action(self::ABSENT_HOOK,    [__CLASS__,        'run_mark_absents']);
        add_filter('cron_schedules',     [__CLASS__,        'add_intervals']);
    }

    public static function add_intervals($schedules) {
        $schedules['wsa_every_15min'] = [
            'interval' => 900,
            'display'  => 'Every 15 Minutes (WSA)',
        ];
        return $schedules;
    }

    public static function schedule() {
        // Auto-logout every 15 min
        if (!wp_next_scheduled(self::AUTO_OUT_HOOK)) {
            wp_schedule_event(time(), 'wsa_every_15min', self::AUTO_OUT_HOOK);
        }
        // QR purge twice daily
        if (!wp_next_scheduled(self::QR_PURGE_HOOK)) {
            wp_schedule_event(time(), 'twicedaily', self::QR_PURGE_HOOK);
        }
        // Mark absent once daily at 11:55 PM
        if (!wp_next_scheduled(self::ABSENT_HOOK)) {
            $next = strtotime('tomorrow midnight') - 300;
            wp_schedule_event($next, 'daily', self::ABSENT_HOOK);
        }
    }

    public static function unschedule() {
        foreach ([self::AUTO_OUT_HOOK, self::QR_PURGE_HOOK, self::ABSENT_HOOK] as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    /**
     * Run absent marking for yesterday.
     *
     * FIXED: Skips weekends/holidays UNLESS the day is a configured working day.
     * Does NOT mark absent on Sundays by default.
     * Does NOT mark absent on public holidays (wsa_holidays table).
     * Does NOT mark absent for staff who have an approved leave.
     * Does NOT mark absent for staff already marked present/IN/OUT.
     */
    public static function run_mark_absents() {
        $yesterday = date('Y-m-d', current_time('timestamp') - DAY_IN_SECONDS);
        WSA_DB::mark_absents_for_date($yesterday);
    }
}
