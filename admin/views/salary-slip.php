<?php defined('ABSPATH') || exit;
$year  = isset($_GET['yr']) ? absint($_GET['yr']) : (int) current_time('Y');
$month = isset($_GET['mn']) ? absint($_GET['mn']) : (int) current_time('n');
$staff_id = isset($_GET['staff_id']) ? absint($_GET['staff_id']) : 0;
$staff_rows = WSA_DB::get_all_staff(['status'=>'active','limit'=>999]);
$selected = [];
if ($staff_id) {
    $r = WSA_Salary::monthly_report($staff_id, $year, $month);
    if ($r) $selected[] = $r;
} elseif (!empty($_GET['all'])) {
    $selected = WSA_Salary::all_staff_summary($year, $month);
}
function wsa_slip_money($amt, $cur='INR'){ return WSA_Salary::fmt_money((float)$amt, $cur); }
$custom_logo_id = get_theme_mod('custom_logo');
$wsa_slip_logo = $custom_logo_id ? wp_get_attachment_image_url($custom_logo_id, 'medium') : '';
if (!$wsa_slip_logo && function_exists('get_site_icon_url')) { $wsa_slip_logo = get_site_icon_url(192); }
?>
<div class="wrap wsa-wrap">
  <h1>🧾 Salary Slip</h1>
  <p class="wsa-sub">Generate salary slips one by one or multiple slips together with full salary breakup.</p>

  <form method="get" class="wsa-card" style="padding:18px;margin:18px 0;display:flex;gap:12px;align-items:end;flex-wrap:wrap">
    <input type="hidden" name="page" value="wsa-salary-slip">
    <label><strong>Month</strong><br><input type="number" name="mn" min="1" max="12" value="<?php echo esc_attr($month); ?>"></label>
    <label><strong>Year</strong><br><input type="number" name="yr" min="2000" value="<?php echo esc_attr($year); ?>"></label>
    <label><strong>Staff</strong><br>
      <select name="staff_id">
        <option value="0">Select one staff</option>
        <?php foreach($staff_rows as $s): ?>
          <option value="<?php echo esc_attr($s->id); ?>" <?php selected($staff_id, $s->id); ?>><?php echo esc_html($s->name.' — '.$s->employee_id); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="button button-primary">Generate One</button>
    <a class="button" href="<?php echo esc_url(admin_url("admin.php?page=wsa-salary-slip&yr={$year}&mn={$month}&all=1")); ?>">Generate All</a>
    <?php if($selected): ?><button type="button" class="button" onclick="window.print()">Print / Save PDF</button><?php endif; ?>
  </form>

  <?php if(!$selected): ?>
    <div class="wsa-card" style="padding:24px">Select staff or click <strong>Generate All</strong> to create slips.</div>
  <?php endif; ?>

  <div class="wsa-slip-list">
  <?php foreach($selected as $r): $cur=$r['currency'] ?: 'INR'; $cfg=$r['config']; ?>
    <section class="wsa-slip">
      <div class="wsa-slip-head">
        <div class="wsa-slip-brand">
          <?php if ($wsa_slip_logo): ?><img src="<?php echo esc_url($wsa_slip_logo); ?>" alt="Company Logo"><?php endif; ?>
          <div>
            <h2><?php echo esc_html(get_option('wsa_company', get_bloginfo('name'))); ?></h2>
            <p>Salary Slip — <?php echo esc_html($r['month_label']); ?></p>
          </div>
        </div>
        <div class="wsa-slip-net"><span>Net Salary</span><strong><?php echo esc_html(wsa_slip_money($r['net'],$cur)); ?></strong></div>
      </div>
      <div class="wsa-slip-info">
        <div><b>Employee</b><br><?php echo esc_html($r['staff']->name); ?></div>
        <div><b>Employee ID</b><br><?php echo esc_html($r['staff']->employee_id); ?></div>
        <div><b>Department</b><br><?php echo esc_html($r['staff']->department); ?></div>
        <div><b>Period</b><br><?php echo esc_html($r['date_from'].' to '.$r['date_to']); ?></div>
      </div>
      <div class="wsa-slip-grid">
        <div class="wsa-slip-box">
          <h3>💰 Salary Breakup</h3>
          <table><tr><td>Daily Rate</td><td><?php echo esc_html(wsa_slip_money($r['daily_rate'],$cur)); ?></td></tr>
          <tr><td>Basic Earned (<?php echo (int)$r['present']; ?> days)</td><td><?php echo esc_html(wsa_slip_money($r['earned_basic'],$cur)); ?></td></tr>
          <tr><td>Leave Pay (<?php echo (int)$r['on_leave']; ?> days)</td><td><?php echo esc_html(wsa_slip_money($r['leave_pay'],$cur)); ?></td></tr>
          <tr><td>Overtime Pay (<?php echo esc_html(WSA_Salary::fmt_hours((float)$r['total_ot'])); ?> × <?php echo esc_html(wsa_slip_money($cfg->ot_rate_per_hr,$cur)); ?>)</td><td><?php echo esc_html(wsa_slip_money($r['ot_pay'],$cur)); ?></td></tr>
          <tr><td>Deductions</td><td>- <?php echo esc_html(wsa_slip_money($r['deductions'],$cur)); ?></td></tr>
          <tr class="total"><td>Net Salary</td><td><?php echo esc_html(wsa_slip_money($r['net'],$cur)); ?></td></tr></table>
        </div>
        <div class="wsa-slip-box">
          <h3>📊 Attendance Summary</h3>
          <table><tr><td>Present</td><td><?php echo (int)$r['present']; ?></td></tr>
          <tr><td>Absent</td><td><?php echo (int)$r['absent']; ?></td></tr>
          <tr><td>Leave</td><td><?php echo (int)$r['on_leave']; ?></td></tr>
          <tr><td>Late</td><td><?php echo (int)$r['late_count']; ?></td></tr>
          <tr><td>Working Hours</td><td><?php echo esc_html(WSA_Salary::fmt_hours((float)$r['total_hours'])); ?></td></tr>
          <tr><td>OT Hours</td><td><?php echo esc_html(WSA_Salary::fmt_hours((float)$r['total_ot'])); ?></td></tr></table>
        </div>
      </div>
      <div class="wsa-slip-note">Rule applied: regular checkout at or after 9:00 PM has no 30m break deduction. 9:00 AM–9:00 PM = 8h working + 4h OT. Sunday is OT only.</div>
    </section>
  <?php endforeach; ?>
  </div>
</div>
<style>
.wsa-slip{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:24px;margin:18px 0;box-shadow:0 8px 24px rgba(15,23,42,.08);page-break-after:always}.wsa-slip-head{display:flex;justify-content:space-between;gap:20px;border-bottom:2px solid #e5e7eb;padding-bottom:14px}.wsa-slip-brand{display:flex;align-items:center;gap:14px}.wsa-slip-brand img{width:62px;height:62px;object-fit:contain;border-radius:12px;border:1px solid #e5e7eb;background:#fff}.wsa-slip-head h2{margin:0}.wsa-slip-net{text-align:right}.wsa-slip-net span{display:block;color:#64748b}.wsa-slip-net strong{font-size:26px;color:#16a34a}.wsa-slip-info{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:18px 0}.wsa-slip-info div,.wsa-slip-box{background:#f8fafc;border:1px solid #e5e7eb;border-radius:14px;padding:14px}.wsa-slip-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.wsa-slip-box table{width:100%;border-collapse:collapse}.wsa-slip-box td{padding:9px;border-bottom:1px solid #e5e7eb}.wsa-slip-box td:last-child{text-align:right;font-weight:700}.wsa-slip-box .total td{font-size:18px;color:#16a34a}.wsa-slip-note{margin-top:16px;padding:12px;border-radius:12px;background:#fff7ed;color:#9a3412}@media(max-width:800px){.wsa-slip-info,.wsa-slip-grid{grid-template-columns:1fr}.wsa-slip-head{display:block}.wsa-slip-net{text-align:left;margin-top:12px}}@media print{#adminmenumain,#wpadminbar,.notice,.wsa-wrap>h1,.wsa-wrap>.wsa-sub,.wsa-wrap>form{display:none!important}#wpcontent{margin-left:0!important}.wsa-slip{box-shadow:none;border:1px solid #ddd;margin:0 0 20px}.wsa-slip-list{margin:0}}
</style>
