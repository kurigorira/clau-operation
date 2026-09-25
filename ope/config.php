<?php
/**
 * 手術予定・実績アプリ 接続設定（このアプリ単体で完結）。
 *  - コピー元: 医事システム NEWTON の SQL Server（読み取り専用アカウント推奨）
 *  - コピー先: MySQL（レセプト・空床アプリと同じサーバ・DBを使ってよい）
 *
 * 実際の接続情報・パスワードは config.local.php に書く（Git管理外・上書き配置でも消えない）。
 */
if (!defined('OPE_APP')) { http_response_code(403); exit('Forbidden'); }

date_default_timezone_set('Asia/Tokyo');

$config = [
    // ---- コピー先 MySQL（表示用） ----
    'mysql' => [
        'host'     => '127.0.0.1',
        'port'     => '3306',
        'database' => 'receipt_db',
        'user'     => 'receipt_user',
        'pass'     => 'change_me',
    ],

    // ---- SQL Server 接続（コピー元・医事システム NEWTON） ----
    'server'      => '127.0.0.1',        // 例: '192.168.1.10' / 'SERVER\SQLEXPRESS'
    'database'    => 'nagasakidb',
    'user'        => 'readonly_user',
    'pass'        => 'change_me',
    'driver'      => 'auto',             // auto / pdo_sqlsrv / sqlsrv / pdo_odbc / odbc
    'odbc_driver' => 'SQL Server',
    'odbc_dsn'    => '',                 // 名前付きDSNがあればそれを優先

    // ---- 手術データの抽出条件 ----
    // 手術テーブル(SjtDatf3 等)の置き場所。接続先DBの既定スキーマにあれば空のまま。
    // 別スキーマ・別DBにある場合は check.php の「所在調査」の結果に従って設定する
    // 例: 'dbo.' / 'newton.' / 'opedb.dbo.'
    'sjt_prefix' => '',
    // カテ室の手術室番号（Newton.ini の [OPK] Catheroom=XX の値）。カテは手術として扱わない
    'cath_rooms' => [],
    // 患者マスタ(kanmf)の氏名列。check.php の「kanmf の列一覧」で確認して設定する
    // 空なら氏名は表示しない（患者コードのみ）
    'patient_name_col' => '',

    // ---- コード → 名称の辞書（マスタ未連携の間の手入力用。空ならコードをそのまま表示）----
    'room_names'    => [],   // 例: ['01' => '第1手術室', '02' => '第2手術室']
    'dept_names'    => [],   // 例: ['01' => '内科', '10' => '外科']
    'doctor_names'  => [],   // 例: ['1234' => '山田']
    // 入外区分（SjtNgkb）。実際の値は check.php の値分布で確認して必要なら修正
    'ngkb_names'    => ['1' => '外来', '3' => '入院'],

    // 病名（SjtByokanSub2 / SjtByokan）も取り込むか。権限エラー等で同期が失敗する場合は false
    'with_byomei' => true,

    // ---- 同期範囲（今日を基準に、何ヶ月前〜何ヶ月先までをコピーするか）----
    'sync_months_back'  => 1,
    'sync_months_ahead' => 2,

    // ---- ログイン（ID => password_hash() の値）----
    // ハッシュ値は check.php の「パスワードハッシュ作成」で作れる。
    // 未設定のままだとログインできない（config.local.php で必ず設定する）
    'users' => [],

    // ---- 画面設定 ----
    'title'       => '手術予定・実績',
    'refresh_sec' => 600,    // MySQLコピーがこの秒数より古ければ表示時に自動同期
    'auto_sync'   => true,   // タスクスケジューラで sync.php を定期実行するなら false でよい
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $override = require $local;
    if (is_array($override)) {
        $config = array_replace_recursive($config, $override);
    }
}
return $config;
