<?php
/**
 * 共通読み込み・ログイン・画面部品。
 * 各画面の先頭で define('OPE_APP', true); $config = require __DIR__ . '/auth.php'; とする。
 * ログインユーザーは config の 'users'（ID => password_hash）で管理する。
 */
if (!defined('OPE_APP')) { http_response_code(403); exit('Forbidden'); }

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/query.php';
require_once __DIR__ . '/store.php';

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_name('OPESESSID');   // 他の院内アプリとセッションを分ける
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => ope_base_path() . '/']);
    session_start();
}

return $config;

/** このアプリのURLパス（例 /ope） */
function ope_base_path(): string
{
    return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function ope_user(): ?string
{
    return $_SESSION['ope_user'] ?? null;
}

/** 未ログインならログイン画面へ */
function ope_require_login(): void
{
    if (ope_user() !== null) { return; }
    $back = $_SERVER['REQUEST_URI'] ?? '';
    header('Location: login.php' . ($back !== '' ? '?back=' . rawurlencode($back) : ''));
    exit;
}

function ope_attempt_login(array $config, string $id, string $pass): bool
{
    $hash = $config['users'][$id] ?? null;
    if (!is_string($hash) || $hash === '' || !password_verify($pass, $hash)) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['ope_user'] = $id;
    return true;
}

function ope_csrf_token(): string
{
    if (empty($_SESSION['ope_csrf'])) {
        $_SESSION['ope_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['ope_csrf'];
}

function ope_csrf_ok(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['ope_csrf']) && hash_equals($_SESSION['ope_csrf'], $token);
}

/** コード → 名称（config の辞書にあれば「名称」、無ければコードのまま） */
function ope_label(array $config, string $dict, $code): string
{
    $code = (string)$code;
    if ($code === '') { return '（未設定）'; }
    $name = $config[$dict][$code] ?? null;
    return $name !== null && $name !== '' ? (string)$name : $code;
}

/** 状態バッジ（区分）の [ラベル, CSSクラス] */
function ope_badge(array $r, string $today): array
{
    if ((int)$r['kinkyu'] === 2) {
        return (int)$r['kekka'] === 1 ? ['臨時・実施', 'b-rinji'] : ['臨時', 'b-rinji'];
    }
    switch (ope_status($r, $today)) {
        case 'done':   return ['実施', 'b-done'];
        case 'undone': return ['未実施', 'b-undone'];
        default:       return ['予定', 'b-plan'];
    }
}

/** 画面共通ヘッダ */
function ope_header(array $config, string $subtitle = ''): void
{
    $title = $config['title'] . ($subtitle !== '' ? '｜' . $subtitle : '');
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="topbar">
  <h1><a href="index.php"><?= h($config['title']) ?></a></h1>
  <?php if (ope_user() !== null): ?>
    <span class="user"><?= h(ope_user()) ?> さん ／ <a href="logout.php">ログアウト</a></span>
  <?php endif; ?>
</header>
<main class="container">
<?php
}

function ope_footer(): void
{
    ?>
</main>
<footer class="foot">長崎北徳洲会病院 手術予定・実績（院内イントラネット）</footer>
</body>
</html>
<?php
}
