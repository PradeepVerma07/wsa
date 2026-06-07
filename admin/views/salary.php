<?php defined('ABSPATH') || exit;

$year    = (int) ($_GET['yr']       ?? date('Y'));
$month   = (int) ($_GET['mn']       ?? date('n'));
$sid     = absint($_GET['staff_id'] ?? 0);
$view    = sanitize_text_field($_GET['view'] ?? 'summary'); // summary | detail
$saved   = isset($_GET['saved'])  ? '<div class="wsa-alert wsa-alert--ok">✅ Saved.</div>' : '';
$err     = isset($_GET['error'])  ? '<div class="wsa-alert wsa-alert--err">⚠️ '.esc_html(urldecode($_GET['error'])).'</div>' : '';

$month_label = date('F Y', mktime(0,0,0,$month,1,$year));
$all_staff   = WSA_DB::get_all_staff(['status'=>'active','limit'=>999]);

// Detail: one staff
$report = ($view === 'detail' && $sid) ? WSA_Salary::monthly_report($sid, $year, $month) : null;

// Summary: all staff
$summary = ($view === 'summary') ? WSA_Salary::all_staff_summary($year, $month) : [];
?>
<div class="wsa-wrap">
  <?php echo $saved . $err; ?>

  <div class="wsa-page-header">
    <div>
      <h1 class="wsa-title">💰 Salary Report</h1>
      <p class="wsa-sub"><?php echo esc_html($month_label); ?></p>
    </div>
    <div style="display:flex;gap:10px;align-items:center">
      <!-- Month navigator -->
      <?php
        $prev_m = $month === 1 ? 12 : $month - 1;
        $prev_y = $month === 1 ? $year - 1 : $year;
        $next_m = $month === 12 ? 1 : $month + 1;
        $next_y = $month === 12 ? $year + 1 : $year;
      ?>
      <a href="<?php echo admin_url("admin.php?page=wsa-salary&yr={$prev_y}&mn={$prev_m}"); ?>" class="wsa-btn">← Prev</a>
      <strong><?php echo esc_html($month_label); ?></strong>
      <?php if ($next_y < date('Y') || ($next_y == date('Y') && $next_m <= date('n'))): ?>
      <a href="<?php echo admin_url("admin.php?page=wsa-salary&yr={$next_y}&mn={$next_m}"); ?>" class="wsa-btn">Next →</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Salary Config Form (collapsed per staff) -->
  <div class="wsa-card" style="margin-bottom:20px">
    <div class="wsa-card-head">
      <h3>⚙️ Salary Configuration</h3>
      <button class="wsa-btn wsa-btn--sm" id="wsa-toggle-sal-cfg">Configure Staff Salary</button>
    </div>
    <div id="wsa-sal-cfg-form" style="display:none;margin-top:16px">
      <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" class="wsa-form" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
        <?php wp_nonce_field('wsa_salary_config_action'); ?>
        <input type="hidden" name="action" value="wsa_save_salary_config">
        <input type="hidden" name="yr" value="<?php echo $year; ?>">
        <input type="hidden" name="mn" value="<?php echo $month; ?>">
        <div class="wsa-field">
          <label>Staff Member</label>
          <select name="cfg_staff_id" id="sal-cfg-staff" required>
            <option value="">— Select Staff —</option>
            <?php foreach ($all_staff as $s): ?>
              <option value="<?php echo $s->id; ?>"><?php echo esc_html($s->name.' ('.$s->employee_id.')'); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="wsa-field">
          <label>Monthly Gross Salary</label>
          <input type="number" name="monthly_salary" id="sal-monthly" min="0" step="any" inputmode="decimal" placeholder="e.g. 25000">
          <small class="wsa-hint">Daily rate auto-calculated if left blank</small>
        </div>
        <div class="wsa-field">
          <label>OR Daily Rate (override)</label>
          <input type="number" name="daily_rate" id="sal-daily" min="0" step="any" inputmode="decimal" placeholder="e.g. 962">
        </div>
        <div class="wsa-field">
          <label>OT Rate per Hour</label>
          <input type="number" name="ot_rate_per_hr" min="0" step="any" inputmode="decimal" placeholder="e.g. 150">
        </div>
        <div class="wsa-field">
          <label>Absent Day Deduction</label>
          <input type="number" name="absent_deduction" min="0" step="any" inputmode="decimal" placeholder="e.g. 962">
          <small class="wsa-hint">Deducted per absent day</small>
        </div>
        <div class="wsa-field">
          <label>Working Days / Month</label>
          <input type="number" name="working_days" min="1" max="31" value="26">
        </div>
        <div class="wsa-field">
          <label>Currency</label>
          <select name="currency">
            <option value="INR">₹ INR</option>
            <option value="USD">$ USD</option>
            <option value="EUR">€ EUR</option>
            <option value="GBP">£ GBP</option>
            <option value="AED">د.إ AED</option>
          </select>
        </div>
        <div class="wsa-field" style="align-self:flex-end">
          <label>&nbsp;</label>
          <button type="submit" class="wsa-btn wsa-btn--accent">💾 Save Config</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($view === 'detail' && $report): ?>
  <!-- ═══════════════════════════════════════════
       DETAIL VIEW — one staff full breakdown
  ═══════════════════════════════════════════ -->
  <?php $r = $report; $cfg = $r['config']; $cur = $r['currency']; ?>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <a href="<?php echo admin_url("admin.php?page=wsa-salary&yr={$year}&mn={$month}"); ?>" class="wsa-btn">← All Staff</a>
    <h2 style="margin:0"><?php echo esc_html($r['staff']->name); ?> — <?php echo esc_html($r['month_label']); ?></h2>
    <?php
      $detail_export_url = wp_nonce_url(
        admin_url("admin-post.php?action=wsa_export_salary_detail&yr={$year}&mn={$month}&staff_id={$sid}"),
        'wsa_export_salary_detail_' . $sid
      );
    ?>
    <a href="<?php echo esc_url($detail_export_url); ?>" class="wsa-btn">⬇ Export CSV</a>
  </div>

  <!-- Summary cards -->
  <div class="wsa-stats" style="margin-bottom:20px">
    <?php $cards = [
      ['Present',   $r['present'],    '✅','green'],
      ['Absent',    $r['absent'],     '❌','red'],
      ['On Leave',  $r['on_leave'],   '📋','blue'],
      ['Late',      $r['late_count'], '⏰','yellow'],
      ['Work Hours',WSA_Salary::fmt_hours($r['total_hours']),'⏱','teal'],
      ['Overtime',  WSA_Salary::fmt_hours($r['total_ot']),   '⚡','orange'],
      ['Late Days', $r['late_count'],                       '⏰','yellow'],
    ]; foreach ($cards as [$l,$v,$ic,$cl]): ?>
    <div class="wsa-stat wsa-stat--<?php echo $cl; ?>">
      <div class="wsa-stat__icon"><?php echo $ic; ?></div>
      <div class="wsa-stat__val"><?php echo $v; ?></div>
      <div class="wsa-stat__label"><?php echo $l; ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Salary breakup -->
  <div class="wsa-grid-2" style="margin-bottom:20px">
    <div class="wsa-card">
      <h3 class="wsa-card-title">💰 Salary Breakup</h3>
      <table class="wsa-table">
        <tr><td>Daily Rate</td><td><strong><?php echo WSA_Salary::fmt_money($r['daily_rate'],$cur); ?></strong></td></tr>
        <tr><td>Basic Earned (<?php echo $r['present']; ?> days)</td><td><?php echo WSA_Salary::fmt_money($r['earned_basic'],$cur); ?></td></tr>
        <tr><td>Leave Pay (<?php echo $r['on_leave']; ?> days)</td><td><?php echo WSA_Salary::fmt_money($r['leave_pay'],$cur); ?></td></tr>
        <tr><td>Overtime Pay (<?php echo WSA_Salary::fmt_hours($r['total_ot']); ?> × <?php echo WSA_Salary::fmt_money($cfg->ot_rate_per_hr,$cur); ?>)</td>
            <td><?php echo WSA_Salary::fmt_money($r['ot_pay'],$cur); ?></td></tr>
        <tr class="wsa-tr-total"><td>Gross</td><td><strong><?php echo WSA_Salary::fmt_money($r['gross'],$cur); ?></strong></td></tr>
        <tr style="color:#ef4444"><td>Absent Deductions (<?php echo $r['absent']; ?> × <?php echo WSA_Salary::fmt_money($cfg->absent_deduction,$cur); ?>)</td>
            <td>− <?php echo WSA_Salary::fmt_money($r['deductions'],$cur); ?></td></tr>
        <tr class="wsa-tr-net"><td><strong>💵 Net Salary</strong></td><td><strong class="wsa-net-val"><?php echo WSA_Salary::fmt_money($r['net'],$cur); ?></strong></td></tr>
      </table>
    </div>

    <!-- Day-by-day calendar -->
    <div class="wsa-card">
      <h3 class="wsa-card-title">📅 Day-by-Day</h3>
      <div class="wsa-cal-grid">
        <?php foreach ($r['days'] as $day):
          $st  = $day['status'];
          $cls = ['present'=>'wsa-cal--present','OUT'=>'wsa-cal--present','IN'=>'wsa-cal--workin',
                  'sunday_ot'=>'wsa-cal--present',
                  'absent'=>'wsa-cal--absent','leave'=>'wsa-cal--leave','future'=>'wsa-cal--future'][$st] ?? 'wsa-cal--future';
          $ico = ['present'=>'✅','OUT'=>'✅','IN'=>'🟡','sunday_ot'=>'⚡','absent'=>'❌','leave'=>'📋','future'=>'—'][$st] ?? '—';
          $tip = $st === 'leave' ? ($day['leave_type'] ?? 'Leave') : ucfirst($st);
        ?>
        <div class="wsa-cal-day <?php echo $cls; ?>" title="<?php echo esc_attr($tip); ?>">
          <span class="wsa-cal-d"><?php echo (int)substr($day['date'],8,2); ?></span>
          <span class="wsa-cal-ico"><?php echo $ico; ?></span>
          <?php if (!empty($day['hours'])): ?><span class="wsa-cal-h"><?php echo WSA_Salary::fmt_hours($day['hours']); ?></span><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Detail attendance table -->
  <div class="wsa-card">
    <h3 class="wsa-card-title">📋 Daily Attendance Log</h3>
    <div class="wsa-table-wrap">
      <table class="wsa-table wsa-table--full">
        <thead><tr><th>Date</th><th>Status</th><th>Check-IN</th><th>Check-OUT</th><th>Hours</th><th>Break</th><th>OT</th><th>Late</th></tr></thead>
        <tbody>
        <?php foreach ($r['days'] as $day):
          if ($day['status'] === 'future') continue;
          $st = $day['status'];
        ?>
        <tr class="<?php echo $st === 'absent' ? 'wsa-row-absent' : ($st === 'leave' ? 'wsa-row-leave' : ''); ?>">
          <td><?php echo date('D, d M', strtotime($day['date'])); ?></td>
          <td><?php
            if ($st === 'absent')     echo '<span class="wsa-badge wsa-badge--absent">Absent</span>';
            elseif ($st === 'sunday_ot') echo '<span class="wsa-badge wsa-badge--out">Sunday OT</span><br><small>'.WSA_Salary::fmt_hours((float)($day['ot'] ?? 0)).' OT</small>';
            elseif ($st === 'leave')  echo '<span class="wsa-badge wsa-badge--leave">'.esc_html($day['leave_type'] ?? 'Leave').'</span>';
            elseif ($st === 'IN')     echo '<span class="wsa-badge wsa-badge--in">Working</span>';
            else                      echo '<span class="wsa-badge wsa-badge--out">Present</span>';
          ?></td>
          <td><?php echo !empty($day['login'])  ? date('h:i A',strtotime($day['login']))  : '—'; ?></td>
          <td><?php echo !empty($day['logout']) ? date('h:i A',strtotime($day['logout'])) : '—'; ?></td>
          <td><?php echo !empty($day['hours'])  ? WSA_Salary::fmt_hours($day['hours'])     : '—'; ?></td>
          <td><?php
            $bdm = isset($day['salary_break_mins']) ? (float)$day['salary_break_mins'] : (float)($day['break_duration_mins'] ?? 0);
            if ($bdm > 0) { $bh = floor($bdm/60); $bm = round(fmod($bdm,60)); echo '<span class="wsa-ot">'.($bh>0?$bh.'h ':'').$bm.'m</span>'; } else { echo '—'; }
          ?></td>
          <td><?php echo !empty($day['ot']) && $day['ot'] > 0 ? '<span class="wsa-ot">+'.WSA_Salary::fmt_hours($day['ot']).'</span>' : '—'; ?></td>
          <td><?php echo !empty($day['type']) && $day['type'] === 'SCAN' && !empty($day['is_late']) && $day['is_late'] ? '<span class="wsa-flag wsa-flag--late">Late</span>' : '—'; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php else: ?>
  <!-- ═══════════════════════════════════════════
       SUMMARY VIEW — all staff table
  ═══════════════════════════════════════════ -->
  <?php if (empty($summary)): ?>
    <div class="wsa-card"><div class="wsa-empty">No active staff found.</div></div>
  <?php else: ?>
  <div class="wsa-card">
    <div class="wsa-card-head">
      <h3>📊 <?php echo esc_html($month_label); ?> — All Staff</h3>
      <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline">
        <?php wp_nonce_field('wsa_export_action'); ?>
        <input type="hidden" name="action"     value="wsa_export_salary">
        <input type="hidden" name="yr"         value="<?php echo $year; ?>">
        <input type="hidden" name="mn"         value="<?php echo $month; ?>">
        <button type="submit" class="wsa-btn wsa-btn--sm">⬇ Export All CSV</button>
      </form>
    </div>
    <div class="wsa-table-wrap">
      <table class="wsa-table wsa-table--full">
        <thead>
          <tr>
            <th>Employee</th><th>Dept</th>
            <th>Present</th><th>Absent</th><th>Leave</th><th>Late</th>
            <th>Work Hours</th><th>OT Hours</th>
            <th>Gross</th><th>Deductions</th><th>Net Salary</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($summary as $r):
          $cur = $r['currency']; ?>
        <tr>
          <td>
            <strong><?php echo esc_html($r['staff']->name); ?></strong>
            <span class="wsa-muted"><?php echo esc_html($r['staff']->employee_id); ?></span>
          </td>
          <td><?php echo esc_html($r['staff']->department ?: '—'); ?></td>
          <td><span class="wsa-badge wsa-badge--out"><?php echo $r['present']; ?></span></td>
          <td><?php echo $r['absent'] > 0 ? '<span class="wsa-badge wsa-badge--absent">'.$r['absent'].'</span>' : '<span class="wsa-muted">0</span>'; ?></td>
          <td><?php echo $r['on_leave'] > 0 ? '<span class="wsa-badge wsa-badge--leave">'.$r['on_leave'].'</span>' : '—'; ?></td>
          <td><?php echo $r['late_count'] > 0 ? '<span class="wsa-flag wsa-flag--late">'.$r['late_count'].'</span>' : '—'; ?></td>
          <td><?php echo WSA_Salary::fmt_hours($r['total_hours']); ?></td>
          <td><?php echo $r['total_ot'] > 0 ? '<span class="wsa-ot">'.WSA_Salary::fmt_hours($r['total_ot']).'</span>' : '—'; ?></td>
          <td><?php echo WSA_Salary::fmt_money($r['gross'], $cur); ?></td>
          <td><?php echo $r['deductions'] > 0 ? '<span style="color:#ef4444">−'.WSA_Salary::fmt_money($r['deductions'],$cur).'</span>' : '—'; ?></td>
          <td><strong class="wsa-net-val"><?php echo WSA_Salary::fmt_money($r['net'], $cur); ?></strong></td>
          <td>
            <a href="<?php echo admin_url("admin.php?page=wsa-salary&yr={$year}&mn={$month}&staff_id={$r['staff']->id}&view=detail"); ?>"
               class="wsa-btn wsa-btn--xs">📋 Detail</a>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="font-weight:700;background:rgba(34,214,138,.05)">
            <td colspan="2">TOTAL</td>
            <td><?php echo array_sum(array_column($summary,'present')); ?></td>
            <td><?php echo array_sum(array_column($summary,'absent')); ?></td>
            <td><?php echo array_sum(array_column($summary,'on_leave')); ?></td>
            <td><?php echo array_sum(array_column($summary,'late_count')); ?></td>
            <td><?php echo WSA_Salary::fmt_hours(array_sum(array_column($summary,'total_hours'))); ?></td>
            <td><?php echo WSA_Salary::fmt_hours(array_sum(array_column($summary,'total_ot'))); ?></td>
            <td><?php echo '₹'.number_format(array_sum(array_column($summary,'gross')),2); ?></td>
            <td><?php echo '₹'.number_format(array_sum(array_column($summary,'deductions')),2); ?></td>
            <td><?php echo '₹'.number_format(array_sum(array_column($summary,'net')),2); ?></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<script>
(function(){
  var btn = document.getElementById('wsa-toggle-sal-cfg');
  var frm = document.getElementById('wsa-sal-cfg-form');
  if (btn && frm) btn.addEventListener('click', function(){ frm.style.display = frm.style.display === 'none' ? '' : 'none'; });

  // Load existing config when staff selected
  var staffSel = document.getElementById('sal-cfg-staff');
  if (staffSel) {
    staffSel.addEventListener('change', function() {
      var sid = this.value;
      if (!sid) return;
      fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=wsa_get_salary_config&nonce=<?php echo wp_create_nonce("wsa_admin"); ?>&staff_id=' + sid
      }).then(function(r){ return r.json(); }).then(function(res) {
        if (!res.success || !res.data) return;
        var d = res.data;
        var f = function(n){ return document.querySelector('[name="'+n+'"]'); };
        if (f('monthly_salary'))   f('monthly_salary').value   = d.monthly_salary   || '';
        if (f('daily_rate'))       f('daily_rate').value       = d.daily_rate       || '';
        if (f('ot_rate_per_hr'))   f('ot_rate_per_hr').value   = d.ot_rate_per_hr   || '';
        if (f('absent_deduction')) f('absent_deduction').value = d.absent_deduction || '';
        if (f('working_days'))     f('working_days').value     = d.working_days     || 26;
        if (f('currency'))         f('currency').value         = d.currency         || 'INR';
      });
    });
  }
})();
</script>
