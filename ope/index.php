<?php
/**
 * 手術予定・実績 月表示（要ログイン）。
 * 表示は MySQL のコピー（ope_cases）のみを参照する。コピーが refresh_sec より古ければ
 * 表示前に SQL Server から自動同期する（auto_sync=true のとき）。失敗しても前回分を表示する。
 */
define('OPE_APP', true);
$config = require __DIR__ . '/auth.php';
ope_require_login();

$today = date('Y-m-d');
$ym = (string)($_GET['ym'] ?? date('Y-m'));
$range = ope_month_range($ym);
if ($range === null) { $ym = date('Y-m'); $range = ope_month_range($ym); }
[$from, $to] = $range;
$first = new DateTimeImmutable($from);
$prevYm = $first->modify('-1 month')->format('Y-m');
$nextYm = $first->modify('+1 month')->format('Y-m');

$fatal = null;
$syncNote = null;
$rows = [];
$meta = ['synced_at' => null];
try {
    $pdo  = ope_mysql($config);
    $meta = ope_meta($pdo);
    $staleSec = max(60, (int)$config['refresh_sec']);
    $isStale  = empty($meta['synced_at']) || (time() - strtotime($meta['synced_at'])) >= $staleSec;
    if (!empty($config['auto_sync']) && $isStale) {
        $lock = $pdo->query("SELECT GET_LOCK('ope_sync', 0) AS l")->fetch();
        if (!empty($lock['l'])) {
            try {
                [$sf, $st] = ope_default_range($config);
                ope_sync_range($config, $pdo, $sf, $st);
                $meta = ope_meta($pdo);
            } catch (Throwable $e) {
                $syncNote = $e->getMessage();
            } finally {
                $pdo->query("SELECT RELEASE_LOCK('ope_sync')");
            }
        }
    }
    $rows = ope_rows($pdo, $from, $to);
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}

$sum = ope_summarize($rows, $today);
$t = $sum['total'];
$rate = ope_rate($t);
[$syncFrom, $syncTo] = ope_default_range($config);
$outOfRange = $to < $syncFrom || $from > $syncTo;   // 自動同期の範囲外の月
$syncedLabel = !empty($meta['synced_at']) ? date('Y年n月j日 H:i', strtotime($meta['synced_at'])) : null;

// カレンダーの週（日曜始まり）
$weeks = [];
$cur = $first->modify('-' . (int)$first->format('w') . ' day');
$last = new DateTimeImmutable($to);
while ($cur <= $last) {
    $week = [];
    for ($i = 0; $i < 7; $i++) { $week[] = $cur; $cur = $cur->modify('+1 day'); }
    $weeks[] = $week;
}

$axes = [
    'dept'    => ['診療科別', '診療科', 'dept_names'],
    'surgeon' => ['術者別',   '術者',   'doctor_names'],
    'room'    => ['手術室別', '手術室', 'room_names'],
];
$fmtRate = fn(?float $r) => $r === null ? '－' : number_format($r, 1) . '%';

ope_header($config, $first->format('Y年n月'));
?>
<div class="monthbar">
  <a class="btn-sub" href="index.php?ym=<?= h($prevYm) ?>">◀ 前月</a>
  <h2><?= h($first->format('Y年n月')) ?></h2>
  <a class="btn-sub" href="index.php?ym=<?= h($nextYm) ?>">翌月 ▶</a>
  <a class="btn-sub" href="index.php">今月</a>
  <form class="jump" method="get" action="index.php">
    <input type="month" name="ym" value="<?= h($ym) ?>"><button type="submit" class="btn-sub">表示</button>
  </form>
  <span class="stamp">
    <?= $syncedLabel ? '最終更新：' . h($syncedLabel) : 'データ未取得' ?>
    ／ <a href="sync.php?back=<?= h($ym) ?>">今すぐ更新</a>
    <?php if ($outOfRange): ?>／ <a href="sync.php?ym=<?= h($ym) ?>&amp;back=<?= h($ym) ?>">この月を取り込み直す</a><?php endif; ?>
  </span>
</div>

<?php if ($fatal !== null): ?>
  <div class="error"><strong>表示用データベース(MySQL)に接続できませんでした。</strong><br><?= h($fatal) ?><br>
    <small>管理者の方は check.php で接続診断ができます。</small></div>
<?php else: ?>

<?php if ($syncNote !== null): ?>
  <div class="warn">最新データの取得（医事システムとの同期）に失敗したため、
    <?= $syncedLabel ? '<strong>' . h($syncedLabel) . '</strong> 時点の' : '取得済みの' ?>データを表示しています。<br>
    <small><?= h($syncNote) ?></small></div>
<?php endif; ?>
<?php if ($outOfRange): ?>
  <div class="warn">この月は自動更新の範囲外です（自動更新は <?= h($syncFrom) ?> 〜 <?= h($syncTo) ?>）。
    過去にコピーしたデータを表示しています。最新にするには「この月を取り込み直す」を押してください。</div>
<?php endif; ?>

<div class="summary">
  <div class="card"><div class="label">予定手術</div><div class="value"><?= $t['plan'] ?><small> 件</small></div></div>
  <div class="card c-done"><div class="label">実施</div><div class="value"><?= $t['done'] ?><small> 件</small></div></div>
  <div class="card c-undone"><div class="label">未実施（中止・延期）</div><div class="value"><?= $t['undone'] ?><small> 件</small></div></div>
  <div class="card c-rinji"><div class="label">臨時（予定外）</div><div class="value"><?= $t['rinji'] ?><small> 件</small></div></div>
  <div class="card c-kinkyu"><div class="label">緊急</div><div class="value"><?= $t['kinkyu'] ?><small> 件</small></div></div>
  <div class="card"><div class="label">実施率</div><div class="value"><?= h($fmtRate($rate)) ?></div></div>
</div>

<table class="calendar">
  <thead><tr>
    <?php foreach (['日', '月', '火', '水', '木', '金', '土'] as $i => $w): ?>
      <th class="<?= $i === 0 ? 'sun' : ($i === 6 ? 'sat' : '') ?>"><?= $w ?></th>
    <?php endforeach; ?>
  </tr></thead>
  <tbody>
  <?php foreach ($weeks as $week): ?>
    <tr>
    <?php foreach ($week as $i => $d): ?>
      <?php
        $key = $d->format('Y-m-d');
        $inMonth = $d->format('Y-m') === $ym;
        $b = $sum['days'][$key] ?? null;
        $cls = [];
        if (!$inMonth) { $cls[] = 'out'; }
        if ($i === 0) { $cls[] = 'sun'; } elseif ($i === 6) { $cls[] = 'sat'; }
        if ($key === $today) { $cls[] = 'today'; }
        if ($b) { $cls[] = 'has'; }
      ?>
      <td class="<?= implode(' ', $cls) ?>">
        <?php if ($inMonth): ?>
          <a class="day" href="day.php?d=<?= h($key) ?>">
            <span class="dnum"><?= (int)$d->format('j') ?></span>
            <?php if ($b): ?>
              <span class="cnt c-plan">予 <?= $b['plan'] ?></span>
              <span class="cnt c-done">実 <?= $b['done'] ?></span>
              <?php if ($b['undone']): ?><span class="mini b-undone">未 <?= $b['undone'] ?></span><?php endif; ?>
              <?php if ($b['rinji']):  ?><span class="mini b-rinji">臨 <?= $b['rinji'] ?></span><?php endif; ?>
              <?php if ($b['kinkyu']): ?><span class="mini b-kinkyu">緊 <?= $b['kinkyu'] ?></span><?php endif; ?>
            <?php endif; ?>
          </a>
        <?php endif; ?>
      </td>
    <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="legend">「予」＝事前に予定された手術、「実」＝実施入力済み（臨時を含む）、「未」＝予定日を過ぎても実施入力がない手術（中止・延期の目安）、
  「臨」＝臨時（予定外）、「緊」＝緊急。日付を押すとその日の明細を表示します。カテ室・カテーテル検査と無効データは含みません。</p>

<h2 class="section-title">集計</h2>
<div class="tabs" role="tablist">
  <?php $firstTab = true; foreach ($axes as $axis => [$label]): ?>
    <button type="button" class="tab<?= $firstTab ? ' active' : '' ?>" data-tab="<?= $axis ?>"><?= h($label) ?></button>
  <?php $firstTab = false; endforeach; ?>
</div>
<?php $firstTab = true; foreach ($axes as $axis => [$label, $colName, $dict]): ?>
  <table class="data agg" data-panel="<?= $axis ?>"<?= $firstTab ? '' : ' hidden' ?>>
    <thead><tr>
      <th><?= h($colName) ?></th><th class="num">予定</th><th class="num">実施</th>
      <th class="num">未実施</th><th class="num">臨時</th><th class="num">緊急</th><th class="num">実施率</th>
    </tr></thead>
    <tbody>
    <?php if (!$sum[$axis]): ?>
      <tr><td colspan="7" class="emptycell">この月の手術データはありません。</td></tr>
    <?php endif; ?>
    <?php foreach ($sum[$axis] as $code => $b): ?>
      <tr>
        <td><?= h(ope_label($config, $dict, $code)) ?></td>
        <td class="num"><?= $b['plan'] ?></td>
        <td class="num"><?= $b['done'] ?></td>
        <td class="num"><?= $b['undone'] ?></td>
        <td class="num"><?= $b['rinji'] ?></td>
        <td class="num"><?= $b['kinkyu'] ?></td>
        <td class="num"><?= h($fmtRate(ope_rate($b))) ?></td>
      </tr>
    <?php endforeach; ?>
      <tr class="total-row">
        <td>合計</td><td class="num"><?= $t['plan'] ?></td><td class="num"><?= $t['done'] ?></td>
        <td class="num"><?= $t['undone'] ?></td><td class="num"><?= $t['rinji'] ?></td>
        <td class="num"><?= $t['kinkyu'] ?></td><td class="num"><?= h($fmtRate($rate)) ?></td>
      </tr>
    </tbody>
  </table>
<?php $firstTab = false; endforeach; ?>
<p class="legend">実施率＝予定手術のうち今日までに予定日が来たものに対する実施の割合（臨時は分母・分子とも含みません）。</p>

<?php endif; ?>
<script>
document.querySelectorAll('.tab').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.querySelectorAll('.tab').forEach(function (b) { b.classList.toggle('active', b === btn); });
    document.querySelectorAll('[data-panel]').forEach(function (p) { p.hidden = p.dataset.panel !== btn.dataset.tab; });
  });
});
</script>
<?php ope_footer();
