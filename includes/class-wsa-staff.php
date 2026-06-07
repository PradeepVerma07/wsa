<?php
defined('ABSPATH') || exit;

class WSA_Staff {

    public static function add($data) {
        global $wpdb;
        $pin = preg_replace('/[^0-9]/', '', $data['pin'] ?? '1234');
        $insert = [
            'employee_id' => sanitize_text_field($data['employee_id'] ?? ''),
            'name'        => sanitize_text_field($data['name'] ?? ''),
            'department'  => sanitize_text_field($data['department'] ?? ''),
            'phone'       => sanitize_text_field($data['phone'] ?? ''),
            'email'       => sanitize_email($data['email'] ?? ''),
            'photo_url'   => esc_url_raw($data['photo_url'] ?? ''),
            'shift_id'    => absint($data['shift_id'] ?? 1),
            'pin'         => $pin ?: '1234',
            'status'      => in_array($data['status'] ?? 'active', ['active','inactive','pending']) ? $data['status'] : 'active',
        ];
        if (empty($insert['employee_id'])) return new WP_Error('missing_field', 'Employee ID is required.');
        if (empty($insert['name']))        return new WP_Error('missing_field', 'Full Name is required.');
        // Check duplicate employee_id
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}wsa_staff WHERE employee_id=%s", $insert['employee_id']));
        if ($exists) return new WP_Error('duplicate', 'Employee ID already exists.');
        $r = $wpdb->insert("{$wpdb->prefix}wsa_staff", $insert);
        return $r ? $wpdb->insert_id : new WP_Error('db_error', $wpdb->last_error);
    }

    public static function update($id, $data) {
        global $wpdb;
        $update = [
            'employee_id' => sanitize_text_field($data['employee_id'] ?? ''),
            'name'        => sanitize_text_field($data['name'] ?? ''),
            'department'  => sanitize_text_field($data['department'] ?? ''),
            'phone'       => sanitize_text_field($data['phone'] ?? ''),
            'email'       => sanitize_email($data['email'] ?? ''),
            'photo_url'   => esc_url_raw($data['photo_url'] ?? ''),
            'shift_id'    => absint($data['shift_id'] ?? 1),
            'status'      => in_array($data['status'] ?? 'active', ['active','inactive','pending']) ? $data['status'] : 'active',
        ];
        if (!empty($data['pin'])) $update['pin'] = preg_replace('/[^0-9]/', '', $data['pin']);
        // Duplicate check (excluding self)
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wsa_staff WHERE employee_id=%s AND id!=%d",
            $update['employee_id'], $id
        ));
        if ($exists) return new WP_Error('duplicate', 'Employee ID already used by another staff.');
        $r = $wpdb->update("{$wpdb->prefix}wsa_staff", $update, ['id' => $id]);
        return $r !== false ? true : new WP_Error('db_error', $wpdb->last_error);
    }

    public static function delete($id) {
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}wsa_attendance", ['staff_id' => $id]);
        $wpdb->delete("{$wpdb->prefix}wsa_scan_log",   ['staff_id' => $id]);
        return $wpdb->delete("{$wpdb->prefix}wsa_staff", ['id' => $id]) !== false;
    }
}
