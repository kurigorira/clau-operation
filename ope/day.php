<?php
/**
 * 日別の手術明細（要ログイン）。MySQL のコピーのみを参照する。
 */
define('OPE_APP', true);
$config = require __DIR__ . '/auth.php';
ope_require_login();

$today = date('Y-m-d');
$d = (string)($_GET['d'] ?? $today);
$dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
if (!$dt || $dt->format('Y-m-d') !== $d) { $dt = new DateTimeImmutable('today'); $d = $dt->format('Y-m-d'); }
$ym = $dt->format('Y-m');
$weekday = ['日', '月', '火', '水', '木', '金', '土'][(int)$dt->format('w')];

$fatal = null;
$rows = [];
try {
    $rows = ope_rows(ope_mysql($config), $d, $d);
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}
$sum = ope_summarize($rows, $today)['total'];

ope_header($config, $dt->format('Y年n月j日'));
?>
<div class="monthbar">
  <a class="btn-sub" href="day.php?d=<?= h($dt->modify('-1 day')->format('Y-m-d')) ?>">◀ 前日</a>
  <h2><?= h($dt->format('Y年n月j日')) ?>（<?= $weekday ?>）</h2>
  <a class="btn-sub" href="day.php?d=<?= h($dt->modify('+1 day')->format('Y-m-d')) ?>">翌日 ▶</a>
  <a class="btn-sub" href="index.php?ym=<?= h($ym) ?>">月表示へ戻る</a>
  <button type="button" class="btn-sub noprint" onclick="window.print()">印刷</button>
</div>

<?php if ($fatal !== null): ?>
  <div class="error"><strong>表示用データベース(MySQL)に接続できませんでした。</strong><br><?= h($fatal) ?></div>
<?php elseif (!$rows): ?>
  <div class="empty">この日の手術データはありません。</div>
<?php else: ?>
  <div class="summary">
    <div class="card"><div class="label">件数</div><div class="value"><?= $sum['total'] ?><small> 件</small></div></div>
    <div class="card"><div class="label">予定手術</div><div class="value"><?= $sum['plan'] ?><small> 件</small></div></div>
    <div class="card c-done"><div class="label">実施</div><div class="value"><?= $sum['done'] ?><small> 件</small></div></div>
    <div class="card c-undone"><div class="label">未実施</div><div class="value"><?= $sum['undone'] ?><small> 件</small></div></div>
    <div class="card c-rinji"><div class="label">臨時</div><div class="value"><?= $sum['rinji'] ?><small> 件</small></div></div>
  </div>
  <div class="scroll">
  <table class="data detail">
    <thead><tr>
      <th>区分</th><th>予定時刻</th><th>手術室</th><th>患者ID</th><th>氏名</th><th>入外</th><th>診療科</th>
      <th>病名</th><th>術式</th><th>術者</th><th>麻酔</th><th>麻酔時間</th><th>所要</th><th>病棟 術前→術後</th><th>備考</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <?php
        [$label, $cls] = ope_badge($r, $today);
        $done = (int)$r['kekka'] === 1;
        $jutu = $done && $r['kaku_jutusiki'] !== '' ? $r['kaku_jutusiki'] : $r['jutusiki'];
        $time = $r['st_time'] !== '' ? $r['st_time'] . ($r['en_time'] !== '' ? '〜' . $r['en_time'] : '') : '未定';
        $masuiTime = $r['masui_start'] !== '' ? $r['masui_start'] . '〜' . $r['masui_end'] : '';
        $wards = trim($r['pre_ward'] . ' → ' . $r['post_ward'], ' →');
      ?>
      <tr>
        <td class="nowrap"><span class="badge <?= $cls ?>"><?= h($label) ?></span>
          <?php if ((int)$r['kinkyu'] === 1): ?><span class="badge b-kinkyu">緊急</span><?php endif; ?>
          <?php if ((int)$r['day_surgery'] === 1): ?><span class="badge b-ds">日帰り</span><?php endif; ?></td>
        <td class="nowrap"><?= h($time) ?></td>
        <td class="nowrap"><?= h(ope_label($config, 'room_names', $r['room'])) ?></td>
        <td class="nowrap mono"><?= h($r['kancd']) ?></td>
        <td class="nowrap"><?= h($r['patient_name']) ?></td>
        <td class="nowrap"><?= h($config['ngkb_names'][$r['ngkb']] ?? $r['ngkb']) ?></td>
        <td class="nowrap"><?= h(ope_label($config, 'dept_names', $r['dept'])) ?>
          <?php if ($r['dept2'] !== ''): ?><br><small>合同：<?= h(ope_label($config, 'dept_names', $r['dept2'])) ?></small><?php endif; ?></td>
        <td><?= h($r['byomei']) ?></td>
        <td class="mono"><?= h($jutu) ?><?php if ($done && $r['kaku_jutusiki'] !== ''): ?><br><small>確定術式</small><?php endif; ?></td>
        <td class="nowrap"><?= h(ope_label($config, 'doctor_names', $r['surgeon'])) ?></td>
        <td class="mono"><?= h($r['masui']) ?></td>
        <td class="nowrap"><?= h($masuiTime) ?></td>
        <td class="nowrap"><?= $r['jikan'] !== null ? (int)$r['jikan'] . '分' : '' ?></td>
        <td class="nowrap"><?= h($wards) ?></td>
        <td><?= h($r['irai']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="legend">術式・麻酔はコードで表示しています（名称マスタ連携前）。実施済みの手術は確定術式、それ以外は予定術式です。
    予定時刻は入室〜退室の予定、「麻酔時間」は実施時の麻酔開始〜終了です。</p>
<?php endif; ?>
<?php ope_footer();
