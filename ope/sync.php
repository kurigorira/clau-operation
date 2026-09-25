<?php
/**
 * 手術データ同期（SQL Server → MySQL コピー）。
 *
 * 実行方法：
 *  1) 表示時の自動同期 … index.php がデータの古さを見て自動実行（既定でON）
 *  2) ブラウザで手動   … 画面の「今すぐ更新」、または sync.php（要ログイン）
 *     sync.php?ym=2025-04 で指定月だけ取り込み直し（同期範囲より前の月の再取込用）
 *  3) タスクスケジューラ … php.exe でこのファイルを定期実行
 *     例: schtasks /create /tn "ope_sync" /sc minute /mo 30
 *           /tr "\"C:\php\php.exe\" \"C:\Apache24\htdocs\ope\sync.php\""
 *     CLI で月指定: php sync.php 2025-04
 */
define('OPE_APP', true);
$config = require __DIR__ . '/auth.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) { ope_require_login(); }

$ym = $isCli ? (string)($argv[1] ?? '') : (string)($_GET['ym'] ?? '');
$range = null;
if ($ym !== '') {
    $range = ope_month_range($ym);
}
$ok = false;
if ($ym !== '' && $range === null) {
    $msg = '月の指定が不正です（例: 2026-09）';
} else {
    [$from, $to] = $range ?? ope_default_range($config);
    try {
        $pdo = ope_mysql($config);
        $lock = $pdo->query("SELECT GET_LOCK('ope_sync', 30) AS l")->fetch();
        if (empty($lock['l'])) {
            throw new RuntimeException('別の同期処理が実行中です。しばらくしてから再度お試しください。');
        }
        try {
            $n = ope_sync_range($config, $pdo, $from, $to);
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('ope_sync')");
        }
        $ok = true;
        $msg = "同期完了：{$from} 〜 {$to} の手術 {$n} 件をコピーしました。";
    } catch (Throwable $e) {
        $msg = '同期失敗：' . $e->getMessage();
    }
}

if ($isCli) {
    echo '[' . date('Y-m-d H:i:s') . "] {$msg}\n";
    exit($ok ? 0 : 1);
}

$backYm = (string)($_GET['back'] ?? '');
if ($ok && $backYm !== '') {
    header('Location: index.php' . (ope_month_range($backYm) ? '?ym=' . rawurlencode($backYm) : ''));
    exit;
}
ope_header($config, '同期');
?>
<h2 class="section-title">手術データ同期</h2>
<div class="<?= $ok ? 'okbox' : 'error' ?>"><?= h($msg) ?></div>
<?php if (!$ok): ?>
  <p>原因の切り分けは <a href="check.php">check.php（接続診断）</a> をご利用ください。</p>
<?php endif; ?>
<p><a class="btn" href="index.php">月表示へ</a></p>
<?php ope_footer();
