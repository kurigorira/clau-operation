<?php
define('OPE_APP', true);
$config = require __DIR__ . '/auth.php';

$error = null;
$back = (string)($_GET['back'] ?? $_POST['back'] ?? '');
// 戻り先は同じアプリ内の相対パスのみ許可（外部サイトへの転送を防ぐ）
$base = ope_base_path() . '/';
if ($back === '' || strpos($back, $base) !== 0 || strpos($back, '//') !== false) {
    $back = 'index.php';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!ope_csrf_ok($_POST['csrf'] ?? null)) {
        $error = '画面の有効期限が切れました。もう一度ログインしてください。';
    } elseif (ope_attempt_login($config, trim((string)($_POST['id'] ?? '')), (string)($_POST['pass'] ?? ''))) {
        header('Location: ' . $back);
        exit;
    } else {
        $error = 'ID またはパスワードが違います。';
    }
}

ope_header($config, 'ログイン');
?>
<div class="login-box">
  <h2>ログイン</h2>
  <p class="legend">患者情報を表示するため、ログインが必要です。</p>
  <?php if (empty($config['users'])): ?>
    <div class="warn">
      <strong>ログインユーザーがまだ登録されていません。</strong><br>
      ID とパスワードは、管理者が自分で決めて登録します（電子カルテのIDとは別です）。
      <ol style="margin:6px 0 0;padding-left:20px">
        <li><a href="check.php#hash">check.php の「8. ログイン用パスワードハッシュ作成」</a>で、
          決めた ID とパスワードを入力して「ハッシュ作成」</li>
        <li>表示された1行を <code>ope/config.local.php</code> の <code>return [</code> の中に貼り付けて保存</li>
        <li>この画面に戻り、その ID とパスワードでログイン</li>
      </ol>
    </div>
  <?php else: ?>
  <?php if ($error !== null): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
  <form method="post" action="login.php">
    <input type="hidden" name="csrf" value="<?= h(ope_csrf_token()) ?>">
    <input type="hidden" name="back" value="<?= h($back) ?>">
    <label>ID<input type="text" name="id" autocomplete="username" required autofocus></label>
    <label>パスワード<input type="password" name="pass" autocomplete="current-password" required></label>
    <button type="submit" class="btn">ログイン</button>
  </form>
  <?php endif; ?>
</div>
<?php ope_footer();
