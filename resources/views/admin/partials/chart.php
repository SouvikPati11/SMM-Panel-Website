<?php
/** Grouped bar chart (server-rendered SVG). $chart = [date => ['orders' => dec, 'deposits' => dec]] */
use App\Core\Money;
$max = '0';
foreach ($chart as $v) { $max = Money::max($max, Money::max($v['orders'], $v['deposits'])); }
$maxF = max(1.0, (float) $max); // display geometry only — never used for money math
$W = 700; $H = 220; $padL = 44; $padB = 24; $padT = 8;
$plotW = $W - $padL - 8; $plotH = $H - $padB - $padT;
$n = count($chart); $slot = $plotW / max(1, $n); $bw = max(4, ($slot - 10) / 2);
$ticks = 4;
?>
<div class="legend mb-1"><span><i style="background:var(--series-1)"></i>Order volume</span><span><i style="background:var(--series-2)"></i>Deposits</span></div>
<svg class="chart" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Order volume and deposits over the last 14 days">
  <?php for ($t = 0; $t <= $ticks; $t++): $y = $padT + $plotH - $plotH * $t / $ticks; ?>
    <line class="grid" x1="<?= $padL ?>" x2="<?= $W - 8 ?>" y1="<?= round($y, 1) ?>" y2="<?= round($y, 1) ?>"/>
    <text class="axis" x="<?= $padL - 6 ?>" y="<?= round($y + 4, 1) ?>" text-anchor="end"><?= e(Money::format((string) round($maxF * $t / $ticks, 2), 0)) ?></text>
  <?php endfor ?>
  <?php $i = 0; foreach ($chart as $day => $v):
      $x0 = $padL + $i * $slot + 5;
      foreach ([['orders', 's1', 'Order volume'], ['deposits', 's2', 'Deposits']] as $k => [$key, $cls, $label]):
          $h = $plotH * (float) $v[$key] / $maxF; $h = $h > 0 ? max(2, $h) : 0;
          $x = $x0 + $k * ($bw + 2); $y = $padT + $plotH - $h; $r = min(4, $bw / 2, $h);
          if ($h > 0): ?>
    <path class="bar <?= $cls ?>" d="M<?= round($x, 1) ?>,<?= round($padT + $plotH, 1) ?> V<?= round($y + $r, 1) ?> Q<?= round($x, 1) ?>,<?= round($y, 1) ?> <?= round($x + $r, 1) ?>,<?= round($y, 1) ?> H<?= round($x + $bw - $r, 1) ?> Q<?= round($x + $bw, 1) ?>,<?= round($y, 1) ?> <?= round($x + $bw, 1) ?>,<?= round($y + $r, 1) ?> V<?= round($padT + $plotH, 1) ?> Z"><title><?= e(date('M j', strtotime($day)) . ' · ' . $label . ': ' . money($v[$key])) ?></title></path>
      <?php endif; endforeach;
      if ($i % 2 === 0): ?><text class="axis" x="<?= round($x0 + $bw, 1) ?>" y="<?= $H - 6 ?>" text-anchor="middle"><?= e(date('j M', strtotime($day))) ?></text><?php endif;
      $i++; endforeach ?>
</svg>
<details class="mt-1"><summary class="text-sm text-muted" style="cursor:pointer">View as table</summary>
  <div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th class="num">Order volume</th><th class="num">Deposits</th></tr></thead><tbody>
  <?php foreach ($chart as $day => $v): ?><tr><td><?= e($day) ?></td><td class="num"><?= e(money($v['orders'])) ?></td><td class="num"><?= e(money($v['deposits'])) ?></td></tr><?php endforeach ?>
  </tbody></table></div>
</details>
