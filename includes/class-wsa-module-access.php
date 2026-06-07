<?php
defined('ABSPATH') || exit;

/**
 * WSA_Module_Access
 * Super Admin can control which modules normal Admin can see
 * in frontend portal and mapped backend plugin menu.
 */
class WSA_Module_Access {

    const OPTION = 'wsa_module_access';

    public static function frontend_pages(): array {
        return [
            'dashboard'       => ['label'=>'Dashboard',         'icon'=>'📊', 'section'=>'Overview'],
            'quickmark'       => ['label'=>'Quick Mark',        'icon'=>'⚡', 'section'=>'Overview'],
            'inside'          => ['label'=>"Who's Inside",      'icon'=>'🏭', 'section'=>'Overview'],
            'qrscanner'       => ['label'=>'QR Scanner',        'icon'=>'📲', 'section'=>'Overview'],
            'faceattendance'  => ['label'=>'Face Attendance',   'icon'=>'🧑‍💼','section'=>'Overview'],
            'faceregister'    => ['label'=>'Face Registration', 'icon'=>'📸', 'section'=>'Overview'],
            'attendance'      => ['label'=>'Attendance',        'icon'=>'📋', 'section'=>'Management'],
            'manual'          => ['label'=>'Manual Entry',      'icon'=>'✏️', 'section'=>'Management'],
            'staff'           => ['label'=>'Staff',             'icon'=>'👥', 'section'=>'Management'],
            'pending'         => ['label'=>'Pending Staff',     'icon'=>'🕐', 'section'=>'Management'],
            'leaves'          => ['label'=>'Leaves',            'icon'=>'🌿', 'section'=>'Management'],
            'salary'          => ['label'=>'Salary',            'icon'=>'💰', 'section'=>'Reports'],
            'salaryslip'      => ['label'=>'Salary Slip',       'icon'=>'🧾', 'section'=>'Reports'],
            'shifts'          => ['label'=>'Shifts',            'icon'=>'🕐', 'section'=>'Configuration'],
            'gates'           => ['label'=>'QR Gates',          'icon'=>'📡', 'section'=>'Configuration'],
            'holidays'        => ['label'=>'Holidays',          'icon'=>'🎉', 'section'=>'Configuration'],
            'settings'        => ['label'=>'Settings',          'icon'=>'⚙️', 'section'=>'Configuration'],
        ];
    }

    public static function backend_pages(): array {
        return [
            'wsa-dashboard'        => ['label'=>'Dashboard',        'frontend'=>'dashboard'],
            'wsa-attendance'       => ['label'=>'Attendance',       'frontend'=>'attendance'],
            'wsa-quick-mark'       => ['label'=>'Quick Mark',       'frontend'=>'quickmark'],
            'wsa-inside'           => ['label'=>"Who's Inside",     'frontend'=>'inside'],
            'wsa-staff'            => ['label'=>'Staff',            'frontend'=>'staff'],
            'wsa-pending'          => ['label'=>'Pending Staff',    'frontend'=>'pending'],
            'wsa-manual'           => ['label'=>'Manual Entry',     'frontend'=>'manual'],
            'wsa-qrcodes'          => ['label'=>'QR Codes',         'frontend'=>'qrscanner'],
            'wsa-shifts'           => ['label'=>'Shifts',           'frontend'=>'shifts'],
            'wsa-settings'         => ['label'=>'Settings',         'frontend'=>'settings'],
            'wsa-salary'           => ['label'=>'Salary',           'frontend'=>'salary'],
            'wsa-salary-slip'      => ['label'=>'Salary Slip',      'frontend'=>'salaryslip'],
            'wsa-face-attendance'  => ['label'=>'Face Attendance',  'frontend'=>'faceattendance'],
            'wsa-leaves'           => ['label'=>'Leaves',           'frontend'=>'leaves'],
            'wsa-portal-admins'    => ['label'=>'Portal Admins',    'frontend'=>'superadmin', 'super_only'=>true],
        ];
    }

    public static function defaults(): array {
        return [
            'admin_modules' => array_values(array_keys(self::frontend_pages())),
            'backend_admin_modules' => array_values(array_keys(self::backend_pages())),
        ];
    }

    public static function get(): array {
        $saved = get_option(self::OPTION, []);
        if (!is_array($saved)) $saved = [];
        $defaults = self::defaults();

        $saved['admin_modules'] = isset($saved['admin_modules']) && is_array($saved['admin_modules'])
            ? array_values(array_intersect($saved['admin_modules'], array_keys(self::frontend_pages())))
            : $defaults['admin_modules'];

        $saved['backend_admin_modules'] = isset($saved['backend_admin_modules']) && is_array($saved['backend_admin_modules'])
            ? array_values(array_intersect($saved['backend_admin_modules'], array_keys(self::backend_pages())))
            : $defaults['backend_admin_modules'];

        if (!in_array('dashboard', $saved['admin_modules'], true)) $saved['admin_modules'][] = 'dashboard';
        if (!in_array('wsa-dashboard', $saved['backend_admin_modules'], true)) $saved['backend_admin_modules'][] = 'wsa-dashboard';

        return $saved;
    }

    public static function save(array $frontend, array $backend): void {
        $frontend = array_values(array_intersect(array_map('sanitize_key', $frontend), array_keys(self::frontend_pages())));
        $backend  = array_values(array_intersect(array_map('sanitize_key', $backend), array_keys(self::backend_pages())));

        if (!in_array('dashboard', $frontend, true)) $frontend[] = 'dashboard';
        if (!in_array('wsa-dashboard', $backend, true)) $backend[] = 'wsa-dashboard';

        update_option(self::OPTION, [
            'admin_modules' => $frontend,
            'backend_admin_modules' => $backend,
            'updated_at' => current_time('mysql'),
        ], false);
    }

    public static function session_access(string $role): array {
        $settings = self::get();

        if ($role === 'super_admin') {
            return [
                'modules' => array_values(array_keys(self::frontend_pages())),
                'backend_modules' => array_values(array_keys(self::backend_pages())),
                'is_super' => true,
            ];
        }

        return [
            'modules' => $settings['admin_modules'],
            'backend_modules' => $settings['backend_admin_modules'],
            'is_super' => false,
        ];
    }

    public static function can_frontend_page(string $role, string $page): bool {
        if ($role === 'super_admin') return true;
        return in_array($page, self::get()['admin_modules'], true);
    }

    public static function current_wp_backend_role(): string {
        if (!is_user_logged_in()) return 'admin';

        $user = wp_get_current_user();
        if (!$user || empty($user->user_login)) return 'admin';

        global $wpdb;
        $table = $wpdb->prefix . 'wsa_portal_admins';
        $role = $wpdb->get_var($wpdb->prepare(
            "SELECT role FROM $table WHERE username=%s AND status='active' LIMIT 1",
            $user->user_login
        ));

        // If the WP user is not mapped to a portal admin record, treat WP administrator as super admin.
        if (!$role && user_can($user, 'manage_options')) return 'super_admin';

        return $role === 'super_admin' ? 'super_admin' : 'admin';
    }

    public static function can_backend_slug(string $slug): bool {
        if (self::current_wp_backend_role() === 'super_admin') return true;
        return in_array($slug, self::get()['backend_admin_modules'], true);
    }

    public static function api_payload(string $role = 'super_admin'): array {
        $settings = self::get();
        return [
            'frontend_pages' => self::frontend_pages(),
            'backend_pages' => self::backend_pages(),
            'admin_modules' => $settings['admin_modules'],
            'backend_admin_modules' => $settings['backend_admin_modules'],
            'session_access' => self::session_access($role),
        ];
    }
}
