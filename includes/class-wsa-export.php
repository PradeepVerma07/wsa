<?php
defined('ABSPATH') || exit;

class WSA_Export {

    public static function export($args, $format = 'csv') {
        $rows    = WSA_DB::get_attendance($args);
        $company = get_option('wsa_company', get_bloginfo('name'));
        $from    = $args['date_from']; $to = $args['date_to'];

        match ($format) {
            'pdf'   => self::pdf($rows, $company, $from, $to),
            default => self::csv($rows, $company, $from, $to),
        };
    }

    private static function csv($rows, $company, $from, $to) {
        // Prevent WordPress/admin notices or theme banners from corrupting the CSV download.
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (function_exists('nocache_headers')) { nocache_headers(); }
        $fn = 'attendance_' . $from . '_to_' . $to . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fn . '"');
        header('Pragma: no-cache');
        $f = fopen('php://output', 'w');
        fputs($f, "\xEF\xBB\xBF"); // UTF-8 BOM
        fputcsv($f, [$company . ' — Attendance Report: ' . $from . ' to ' . $to]);
        fputcsv($f, []);
        fputcsv($f, ['#','Emp ID','Name','Department','Date','Check IN','Check OUT','Work Hours','Break','Overtime','Type','Late','Early Exit','Notes']);
        foreach ($rows as $i => $r) {
            $display_hours = (float)($r->total_hours ?? 0);
            $display_ot    = (float)($r->overtime_hours ?? 0);
            $display_break = (float)($r->break_duration_mins ?? 0);
            if (!empty($r->login_time) && !empty($r->logout_time) && !empty($r->staff_id)) {
                $staff_obj = WSA_DB::get_staff((int)$r->staff_id);
                if ($staff_obj) {
                    $break_for_calc = $display_break > 0 ? $display_break : WSA_Attendance::scheduled_break_mins($r->login_time, $r->logout_time);
                    $calc = WSA_Attendance::calculate($r->login_time, $r->logout_time, $staff_obj, $break_for_calc);
                    $display_hours = (float)$calc[0];
                    $display_ot    = (float)$calc[1];
                    $login_ts = strtotime($r->login_time);
                    $logout_ts = strtotime($r->logout_time);
                    $is_sunday = ((int)date('w', $login_ts) === 0);
                    $is_exact_9_to_9 = (date('Y-m-d',$login_ts) === date('Y-m-d',$logout_ts) && date('H:i',$login_ts) === '09:00' && date('H:i',$logout_ts) === '21:00' && !$is_sunday);
                    $display_break = $is_exact_9_to_9 ? 0.0 : $break_for_calc;
                }
            }
            fputcsv($f, [
                $i + 1,
                $r->emp_code,
                $r->staff_name,
                $r->department ?: '—',
                $r->att_date,
                $r->login_time  ? date('h:i A', strtotime($r->login_time))  : '—',
                $r->logout_time ? date('h:i A', strtotime($r->logout_time)) : '—',
                $display_hours > 0 ? WSA_Attendance::fmt($display_hours) : '—',
                $display_break > 0 ? WSA_Attendance::fmt($display_break / 60) : '—',
                $display_ot > 0 ? WSA_Attendance::fmt($display_ot) : '0',
                $r->type,
                (strtoupper((string)$r->type) === 'SCAN' && $r->is_late) ? 'Yes' : 'No',
                $r->is_early_exit ? 'Yes' : 'No',
                $r->notes ?: '',
            ]);
        }
        fclose($f);
        exit;
    }

    private static function pdf($rows, $company, $from, $to) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (function_exists('nocache_headers')) { nocache_headers(); }
        header('Content-Type: text/html; charset=UTF-8');
        $total = count($rows);
        $ot_count = count(array_filter($rows, fn($r) => $r->overtime_hours > 0));
        $late_count = count(array_filter($rows, fn($r) => $r->is_late));
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Attendance Report</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:"DM Sans",sans-serif;font-size:11px;color:#1a1a1a;padding:24px;background:#fff}
        .header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;padding-bottom:16px;border-bottom:2px solid #FF4D00}
        .company{font-size:20px;font-weight:800;color:#0F1117}
        .company span{color:#FF4D00}
        .meta{font-size:11px;color:#666;margin-top:4px}
        .summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
        .sum-box{background:#f8f8f8;border-radius:6px;padding:10px 14px;text-align:center}
        .sum-val{font-size:22px;font-weight:800;color:#0F1117}
        .sum-lbl{font-size:10px;color:#666;text-transform:uppercase;letter-spacing:.05em;margin-top:2px}
        table{width:100%;border-collapse:collapse;font-size:10.5px}
        th{background:#0F1117;color:#fff;padding:8px 10px;text-align:left;font-size:9.5px;text-transform:uppercase;letter-spacing:.06em;white-space:nowrap}
        td{padding:7px 10px;border-bottom:1px solid #eee;vertical-align:middle}
        tr:nth-child(even) td{background:#fafafa}
        .badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:9.5px;font-weight:600}
        .b-in{background:#dcfce7;color:#166534}
        .b-out{background:#dbeafe;color:#1e40af}
        .b-scan{background:#f3f4f6;color:#374151}
        .b-manual{background:#fef3c7;color:#92400e}
        .b-late{background:#fee2e2;color:#991b1b}
        .ot{color:#FF4D00;font-weight:700}
        .print-btn{position:fixed;bottom:20px;right:20px;background:#FF4D00;color:#fff;border:none;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(255,77,0,.3)}
        @media print{.print-btn{display:none!important}body{padding:12px}}
        </style></head><body>
        <div class="header">
          <div><div class="company">'.esc_html($company).'</div><div class="meta">Attendance Report &nbsp;|&nbsp; '.esc_html($from).' to '.esc_html($to).'</div></div>
          <div class="meta">Generated: '.date('d M Y, H:i').'</div>
        </div>
        <div class="summary">
          <div class="sum-box"><div class="sum-val">'.$total.'</div><div class="sum-lbl">Total Records</div></div>
          <div class="sum-box"><div class="sum-val">'.$ot_count.'</div><div class="sum-lbl">Overtime</div></div>
          <div class="sum-box"><div class="sum-val">'.$late_count.'</div><div class="sum-lbl">Late Arrivals</div></div>
          <div class="sum-box"><div class="sum-val">'.count(array_filter($rows,fn($r)=>$r->type==='MANUAL')).'</div><div class="sum-lbl">Manual Entries</div></div>
        </div>
        <table><thead><tr>
          <th>#</th><th>Emp ID</th><th>Name</th><th>Dept</th><th>Date</th>
          <th>Check IN</th><th>Check OUT</th><th>Hours</th><th>Overtime</th><th>Type</th><th>Status</th>
        </tr></thead><tbody>';
        foreach ($rows as $i => $r) {
            $st_cls = $r->status === 'IN' ? 'b-in' : 'b-out';
            $ty_cls = $r->type === 'MANUAL' ? 'b-manual' : 'b-scan';
            echo '<tr>
              <td>'.($i+1).'</td>
              <td>'.esc_html($r->emp_code).'</td>
              <td>'.esc_html($r->staff_name).($r->is_late ? ' <span class="badge b-late">Late</span>' : '').'</td>
              <td>'.esc_html($r->department ?: '—').'</td>
              <td>'.esc_html($r->att_date).'</td>
              <td>'.($r->login_time  ? date('h:i A',strtotime($r->login_time))  : '—').'</td>
              <td>'.($r->logout_time ? date('h:i A',strtotime($r->logout_time)) : '<em>Still inside</em>').'</td>
              <td>'.esc_html(WSA_Attendance::fmt($r->total_hours)).'</td>
              <td>'.($r->overtime_hours>0 ? '<span class="ot">'.esc_html(WSA_Attendance::fmt($r->overtime_hours)).'</span>' : '—').'</td>
              <td><span class="badge '.$ty_cls.'">'.esc_html($r->type).'</span></td>
              <td><span class="badge '.$st_cls.'">'.esc_html($r->status).'</span></td>
            </tr>';
        }
        echo '</tbody></table>
        <button class="print-btn" onclick="window.print()">🖨 Print / Save PDF</button>
        <script>window.onload=function(){window.print();}</script>
        </body></html>';
    }
}
