<?php
/**
 * WSA_QrCode — Database-backed one-time QR code system
 *
 * Lifecycle:  OPEN (0) → CLAIMED (2) → USED (1)
 *
 * FIXED v4.3.1:
 *  - Timezone consistency: all DB datetimes use current_time('mysql') / wp_date()
 *  - generate() and current() both use WordPress time so no mismatch on IST/UTC+5:30
 */
defined('ABSPATH') || exit;

class WSA_QrCode {

    const STATUS_OPEN    = 0;
    const STATUS_CLAIMED = 2;
    const STATUS_USED    = 1;
    const CLAIM_TTL      = 180; // seconds staff have to fill the form after claiming

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'wsa_qr_codes';
    }

    private static function get_ttl() {
        // Always enforce 30 seconds — update DB if it has a stale value
        $stored = (int) get_option('wsa_qr_ttl', 30);
        if ($stored !== 30) {
            update_option('wsa_qr_ttl', 30);
        }
        return 30;
    }

    /** Public accessor so shortcodes/JS can read the actual enforced TTL */
    public static function get_actual_ttl(): int {
        return self::get_ttl();
    }

    /* ════════════════════════════════════════
       GENERATE — create a fresh OPEN QR
       FIXED: uses current_time() throughout so
       timezone matches the WHERE queries
    ════════════════════════════════════════ */
    public static function generate(int $gate_id): string {
        global $wpdb;
        $t   = self::table();
        $ttl = self::get_ttl();

        // Mark any existing OPEN QRs for this gate as USED
        $wpdb->query($wpdb->prepare(
            "UPDATE $t SET status = %d WHERE gate_id = %d AND status = %d",
            self::STATUS_USED, $gate_id, self::STATUS_OPEN
        ));

        $token      = bin2hex(random_bytes(32));      // 64-char unique hex
        $now_mysql  = current_time('mysql');           // WP local time
        $expires_ts = time() + $ttl;                  // real Unix epoch
        $expires    = wp_date('Y-m-d H:i:s', $expires_ts); // formatted in WP timezone

        $wpdb->insert($t, [
            'gate_id'    => $gate_id,
            'token'      => $token,
            'status'     => self::STATUS_OPEN,
            'created_at' => $now_mysql,
            'expires_at' => $expires,
        ]);

        return $token;
    }

    /* ════════════════════════════════════════
       CURRENT — get active QR for display
       FIXED: $now uses current_time('mysql') to
       match the format stored by generate()
    ════════════════════════════════════════ */
    public static function current(int $gate_id): ?object {
        global $wpdb;
        $t   = self::table();
        $now = current_time('mysql'); // same tz as generate()

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t
             WHERE gate_id = %d
               AND status  = %d
               AND expires_at > %s
             ORDER BY id DESC
             LIMIT 1",
            $gate_id, self::STATUS_OPEN, $now
        ));

        if (!$row) {
            // No valid open QR → generate one immediately
            $token = self::generate($gate_id);
            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $t WHERE token = %s LIMIT 1", $token
            ));
        }

        return $row;
    }

    /* ════════════════════════════════════════
       CLAIM — atomic reserve (staff loaded page)
    ════════════════════════════════════════ */
    public static function claim(string $token): array {
        global $wpdb;
        $t   = self::table();
        $now = current_time('mysql');

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t WHERE token = %s AND status = %d LIMIT 1",
            $token, self::STATUS_OPEN
        ));

        if (!$row) {
            return ['ok' => false, 'message' => 'QR code not found or already used.'];
        }

        if (self::mysql_to_epoch($row->expires_at) < time()) {
            $wpdb->update($t, ['status' => self::STATUS_USED], ['id' => $row->id]);
            return ['ok' => false, 'message' => 'This QR code has expired. Please scan the updated code.'];
        }

        // Atomic claim: WHERE status=0 prevents double-claim race
        $ip      = self::get_ip();
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE $t SET status = %d, claimed_at = %s, ip_claimed = %s
             WHERE id = %d AND status = %d",
            self::STATUS_CLAIMED, $now, $ip, $row->id, self::STATUS_OPEN
        ));

        if (!$updated) {
            return ['ok' => false, 'message' => 'QR already claimed. Please scan the new QR code.'];
        }

        // Issue claim key via WP transient (server-side only, never in URL)
        $claim_key = bin2hex(random_bytes(20));
        set_transient('wsa_claim_' . $claim_key, [
            'qr_id'   => $row->id,
            'gate_id' => (int) $row->gate_id,
            'created' => current_time('timestamp'),
        ], self::CLAIM_TTL);

        // Generate new QR immediately so the display screen updates
        self::generate((int) $row->gate_id);

        return [
            'ok'        => true,
            'claim_key' => $claim_key,
            'gate_id'   => (int) $row->gate_id,
            'message'   => 'QR valid. Please log in to mark attendance.',
        ];
    }

    /* ════════════════════════════════════════
       CONSUME — spend the claim key
    ════════════════════════════════════════ */
    public static function consume(string $claim_key, int $staff_id): array {
        $data = get_transient('wsa_claim_' . $claim_key);

        if (!$data) {
            return ['ok' => false, 'message' => 'Session expired or invalid. Please scan the QR again.'];
        }

        delete_transient('wsa_claim_' . $claim_key); // one-time use

        if (current_time('timestamp') - $data['created'] > self::CLAIM_TTL) {
            return ['ok' => false, 'message' => 'Session timed out. Please scan again.'];
        }

        global $wpdb;
        $wpdb->update(self::table(), [
            'status'  => self::STATUS_USED,
            'used_at' => current_time('mysql'),
            'used_by' => $staff_id,
        ], ['id' => $data['qr_id']]);

        return [
            'ok'      => true,
            'gate_id' => $data['gate_id'],
            'message' => 'Session consumed.',
        ];
    }

    /* ════════════════════════════════════════
       DISPLAY STATUS — for wall screen polling
    ════════════════════════════════════════ */
    public static function display_status(int $gate_id): array {
        global $wpdb;
        $t   = self::table();
        $qr  = self::current($gate_id);
        $now = time();
        $ttl = self::get_ttl(); // always 30

        // HARD FIX: if the QR returned by current() is expired for any reason
        // (DB timezone/cache/plugin page kept open), force-create a new token now.
        // This guarantees the wall QR changes every 30 seconds.
        if (!$qr || self::mysql_to_epoch($qr->expires_at) <= $now) {
            $token = self::generate($gate_id);
            $qr = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $t WHERE token = %s LIMIT 1", $token
            ));
        }

        // Detect if a QR was *just* claimed for this gate (within last 3 s).
        // current() always returns a fresh OPEN qr after a claim, so without
        // this check the wall display would never show the "QR Scanned" state.
        $now_mysql      = current_time('mysql');
        $recently_claimed = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t
             WHERE gate_id    = %d
               AND status     = %d
               AND claimed_at >= DATE_SUB(%s, INTERVAL 3 SECOND)
             LIMIT 1",
            $gate_id, self::STATUS_CLAIMED, $now_mysql
        ));

        // Use claimed status only for the brief 3-second window so the badge shows;
        // after that the response reverts to the new open QR as normal.
        $display_status = $recently_claimed ? self::STATUS_CLAIMED : (int) $qr->status;

        // Calculate seconds_left using WP-local timestamp throughout.
        // HARD FINAL FIX: if seconds_left is 0 here, immediately generate a
        // fresh token and recalculate. This prevents the /wsa-admin/ QR panel
        // from ever getting stuck at 0 seconds because of stale DB rows,
        // timezone mismatch, proxy cache, or a long-open browser tab.
        $expires_ts   = self::mysql_to_epoch($qr->expires_at);
        $seconds_left = $expires_ts - $now;
        if ($seconds_left <= 0) {
            $token = self::generate($gate_id);
            $qr = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $t WHERE token = %s LIMIT 1", $token
            ));
            $display_status = (int) $qr->status;
            $expires_ts     = self::mysql_to_epoch($qr->expires_at);
            $seconds_left   = max(1, $expires_ts - $now);
        } else {
            $seconds_left = max(1, $seconds_left);
        }

        return [
            'token'           => $qr->token,
            'status'          => $display_status,
            'qr_status'       => $display_status,
            'expires_at'      => $qr->expires_at,
            'seconds_left'    => $seconds_left,
            'qr_ttl'          => $ttl,
            'attendance_url'  => self::attendance_url($qr->token),
            'qr_image_url'    => self::qr_image_url(self::attendance_url($qr->token)),
            'server_ts_ms'    => (int) round(microtime(true) * 1000),
        ];
    }


    /** Convert a WP-local MySQL datetime to a real Unix epoch safely. */
    private static function mysql_to_epoch(string $mysql): int {
        try {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysql, wp_timezone());
            if ($dt instanceof DateTimeImmutable) {
                return $dt->getTimestamp();
            }
        } catch (Exception $e) {}
        return strtotime($mysql) ?: 0;
    }

    /* ── URL helpers ── */
    public static function attendance_url(string $token): string {
        $page_id = get_option('wsa_attend_page_id');
        $base    = $page_id ? get_permalink($page_id) : home_url('/employee-attendance/');
        return add_query_arg('qr', $token, $base);
    }

    public static function qr_image_url(string $url): string {
        // Use qrserver.com — no cache-busting needed; the token in $url makes it unique
        return 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&qzone=2&ecc=M&data=' . rawurlencode($url);
    }

    /* ── Purge (cron) ── */
    public static function purge(): void {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->query("DELETE FROM " . self::table() . " WHERE expires_at < '$now' AND status != " . self::STATUS_CLAIMED);
    }

    private static function get_ip(): string {
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) return trim(explode(',', $_SERVER[$k])[0]);
        }
        return '';
    }
}
