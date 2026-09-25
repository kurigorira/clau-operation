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
  <?php if ($error !== null): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
  <?php if (empty($config['users'])): ?>
    <div class="warn">ログインユーザーが未設定です。管理者は <code>config.local.php</code> の
      <code>users</code> を設定してください（パスワードハッシュは check.php で作成できます）。</div>
  <?php endif; ?>
  <form method="post" action="login.php">
    <input type="hidden" name="csrf" value="<?= h(ope_csrf_token()) ?>">
    <input type="hidden" name="back" value="<?= h($back) ?>">
    <label>ID<input type="text" name="id" autocomplete="username" required autofocus></label>
    <label>パスワード<input type="password" name="pass" autocomplete="current-password" required></label>
    <button type="submit" class="btn">ログイン</button>
  </form>
</div>
<?php ope_footer();
