<?php
defined('ABSPATH') || exit;

/**
 * WSA_Auth — Staff session management
 * Token stored in localStorage (client) and sent as X-WSA-Token header.
 * Transient: wsa_sess_{token} → staff_id, TTL 8 hours.
 */
class WSA_Auth {

    const TOKEN_TTL  = 28800; // 8 hours in seconds
    const TOKEN_LEN  = 40;    // hex chars (20 random bytes)

    /* ── LOGIN ── */
    public static function login( string $employee_id, string $pin ): array {
        global $wpdb;
        $staff = WSA_DB::get_staff_by_eid( strtoupper( $employee_id ) );

        if ( ! $staff )
            return [ 'ok' => false, 'message' => 'Employee ID not found.' ];

        if ( $staff->status === 'pending' )
            return [ 'ok' => false, 'message' => 'Your account is pending admin approval. Please wait.' ];

        if ( $staff->status !== 'active' )
            return [ 'ok' => false, 'message' => 'Account is inactive. Contact your administrator.' ];

        if ( $staff->pin !== $pin )
            return [ 'ok' => false, 'message' => 'Incorrect PIN. Please try again.' ];

        $token = bin2hex( random_bytes( 20 ) );
        set_transient( 'wsa_sess_' . $token, (int) $staff->id, self::TOKEN_TTL );

        return [ 'ok' => true, 'token' => $token, 'staff_id' => (int) $staff->id ];
    }

    /* ── VALIDATE token → returns staff object or null ── */
    public static function validate( string $token ): ?object {
        if ( ! $token || strlen( $token ) !== self::TOKEN_LEN ) return null;
        $staff_id = get_transient( 'wsa_sess_' . $token );
        if ( ! $staff_id ) return null;
        // Slide expiry on activity
        set_transient( 'wsa_sess_' . $token, (int) $staff_id, self::TOKEN_TTL );
        return WSA_DB::get_staff( (int) $staff_id );
    }

    /* ── LOGOUT ── */
    public static function logout( string $token ): void {
        delete_transient( 'wsa_sess_' . $token );
    }

    /* ── Get token from the current REST request header ── */
    public static function get_token( WP_REST_Request $r = null ): string {
        if ( $r ) {
            $h = $r->get_header( 'x_wsa_token' );
            if ( $h ) return sanitize_text_field( $h );
        }
        return sanitize_text_field( $_SERVER['HTTP_X_WSA_TOKEN'] ?? '' );
    }

    /* ── Resolve staff from current request (for REST callbacks) ── */
    public static function staff_from_request( WP_REST_Request $r ): ?object {
        return self::validate( self::get_token( $r ) );
    }

    /* ── REGISTER (self-service, status=pending) ── */
    public static function register( array $data ): array {
        global $wpdb;

        $employee_id = strtoupper( sanitize_text_field( $data['employee_id'] ?? '' ) );
        $name        = sanitize_text_field( $data['name']        ?? '' );
        $department  = sanitize_text_field( $data['department']  ?? '' );
        $phone       = sanitize_text_field( $data['phone']       ?? '' );
        $email       = sanitize_email(      $data['email']       ?? '' );
        $pin         = preg_replace( '/[^0-9]/', '', $data['pin'] ?? '' );
        $pin_confirm = preg_replace( '/[^0-9]/', '', $data['pin_confirm'] ?? '' );

        if ( ! $employee_id ) return [ 'ok' => false, 'message' => 'Employee ID is required.' ];
        if ( ! $name )        return [ 'ok' => false, 'message' => 'Full name is required.' ];
        if ( strlen( $pin ) < 4 ) return [ 'ok' => false, 'message' => 'PIN must be at least 4 digits.' ];
        if ( $pin !== $pin_confirm ) return [ 'ok' => false, 'message' => 'PINs do not match.' ];

        // Check duplicate employee_id
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wsa_staff WHERE employee_id=%s", $employee_id
        ) );
        if ( $exists ) return [ 'ok' => false, 'message' => 'Employee ID is already registered.' ];

        $shifts = WSA_DB::get_shifts();
        $default_shift = $shifts ? $shifts[0]->id : 1;

        $wpdb->insert( "{$wpdb->prefix}wsa_staff", [
            'employee_id' => $employee_id,
            'name'        => $name,
            'department'  => $department,
            'phone'       => $phone,
            'email'       => $email,
            'shift_id'    => $default_shift,
            'pin'         => $pin,
            'status'      => 'pending',
        ] );

        return [ 'ok' => true, 'message' => 'Registration submitted. Admin will activate your account.' ];
    }
}
