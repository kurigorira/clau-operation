<?php
/**
 * 医事システム NEWTON（SQL Server）から手術データを取得する。
 * 接続部は空床アプリ（beds/query.php）と同じ方式：
 *   pdo_sqlsrv / sqlsrv / pdo_odbc / odbc のうち使えるものを自動選択。
 *
 * 手術データの構造（ベンダー定義書より）
 *   SjtDatf3      … 1行＝1術例（主キー SjtKancd + SjtRei）。予定と実績を同じ行で持つ
 *                    SjtKekkaKbn 0:未実施 1:実施 / SjtKinkyu 0:通常 1:緊急 2:臨時(予定外)
 *                    SjtYuko 9:無効 / SjtRoomNo カテ室は手術として扱わない
 *   SjtDatf3Sub   … 同キーの補助情報（所要時間・日帰り・術前/術後病棟・手術/カテ判断）
 *   SjtByokanSub2 … 術例ごとの病名（SjtJissiKbn 0:予定 1:実施、SjtKnNam 漢字名称）
 *   SjtByokan     … 術例ごとの病名（SjtByomei。SjtByokanSub2 が空のときの予備）
 */
if (!defined('OPE_APP')) { http_response_code(403); exit('Forbidden'); }

/** SQL に埋め込む識別子（列名）を検証する */
function ope_ident(string $name): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
        throw new RuntimeException('列名の設定が不正です: ' . $name);
    }
    return $name;
}

/** カテ室除外条件（cath_rooms は英数字2桁以内のみ許可して埋め込む） */
function ope_cath_condition(array $c): string
{
    $rooms = [];
    foreach ((array)($c['cath_rooms'] ?? []) as $r) {
        $r = trim((string)$r);
        if ($r === '') { continue; }
        if (!preg_match('/^[A-Za-z0-9]{1,2}$/', $r)) {
            throw new RuntimeException('cath_rooms の値が不正です: ' . $r);
        }
        $rooms[] = "'" . $r . "'";
    }
    return $rooms ? ' and d.SjtRoomNo not in (' . implode(',', $rooms) . ')' : '';
}

/**
 * 手術データ取得SQL。プレースホルダは [開始日yyyymmdd, 終了日yyyymmdd]。
 * 列別名は小文字の半角英字（ドライバによる大文字小文字の揺れと文字コード事故を避ける）。
 */
function ope_sql(array $c): string
{
    $nameCol = trim((string)($c['patient_name_col'] ?? ''));
    $nameSel  = $nameCol !== '' ? 'k.' . ope_ident($nameCol) : "''";
    $nameJoin = $nameCol !== '' ? "left outer join kanmf k on k.code = d.SjtKancd" : '';

    $byomeiSel = "''";
    if (!empty($c['with_byomei'])) {
        $byomeiSel = "isnull(
            (select top 1 b2.SjtKnNam from SjtByokanSub2 b2
              where b2.SjtKancd = d.SjtKancd and b2.SjtRei = d.SjtRei and rtrim(b2.SjtKnNam) <> ''
              order by b2.SjtJissiKbn desc, b2.SjtHyojiNo),
            (select top 1 b1.SjtByomei from SjtByokan b1
              where b1.SjtKancd = d.SjtKancd and b1.SjtRei = d.SjtRei and rtrim(b1.SjtByomei) <> ''
              order by b1.SjtJissiKbn desc, b1.SjtHyojiNo))";
    }

    return "
select
    d.SjtKancd as kancd, d.SjtRei as rei, d.SjtYmd as ymd, d.SjtRoomNo as room,
    d.SjtNgkb as ngkb, d.SjtSnk as dept, d.SjtSnk2 as dept2,
    d.SjtKekkaKbn as kekka, d.SjtKinkyu as kinkyu,
    d.SjtJutusiki1 as j1, d.SjtJutusiki2 as j2, d.SjtJutusiki3 as j3,
    d.SjtJutusiki4 as j4, d.SjtJutusiki5 as j5, d.SjtJutusiki6 as j6,
    d.SjtKakuJutusiki1 as k1, d.SjtKakuJutusiki2 as k2, d.SjtKakuJutusiki3 as k3,
    d.SjtKakuJutusiki4 as k4, d.SjtKakuJutusiki5 as k5, d.SjtKakuJutusiki6 as k6,
    d.SjtMasui1 as m1, d.SjtMasui2 as m2, d.SjtMasui3 as m3,
    d.SjtDrcd as surgeon, d.SjtJosyucd1 as assistant, d.SjtMasuiDr1 as anesth_dr,
    d.SjtStTime as st_time, d.SjtEnTime as en_time,
    d.SjtMasuiStart as masui_start, d.SjtMasuiEnd as masui_end,
    d.SjtIrai as irai,
    s.SjtJikan as jikan, s.SjtDSFlg as dsflg,
    s.SjtYobi1 as yobi1, s.SjtYobi2 as yobi2, s.SjtYobi3 as yobi3,
    {$nameSel} as kanname,
    {$byomeiSel} as byomei
from SjtDatf3 d
    left outer join SjtDatf3Sub s
        on  s.SjtKancd = d.SjtKancd
        and s.SjtRei   = d.SjtRei
    {$nameJoin}
where
    d.SjtYmd between ? and ?
    and isnull(d.SjtYuko, '0') <> '9'
    and d.SjtKancd between '00000001' and '99989999'
    and isnull(substring(s.SjtYobi4, 10, 1), '0') <> '1'" . ope_cath_condition($c) . "
order by d.SjtYmd, d.SjtStTime, d.SjtRoomNo";
}

/**
 * 期間内の手術データを取得し、MySQL保存用の形に整えて返す。
 * @param string $from 'Y-m-d'
 * @param string $to   'Y-m-d'
 * @throws RuntimeException 接続・実行エラー時
 */
function ope_fetch(array $c, string $from, string $to): array
{
    $rows = ope_run_readonly($c, ope_sql($c), [str_replace('-', '', $from), str_replace('-', '', $to)]);
    $out = [];
    foreach ($rows as $r) {
        $row = ope_normalize($r);
        if ($row !== null) { $out[] = $row; }
    }
    return $out;
}

/** SQL Server の1行を保存用に整形する（日付が不正な行は null） */
function ope_normalize(array $r): ?array
{
    $v = fn($k) => ope_utf8($r[$k] ?? '');
    $ymd = $v('ymd');
    if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $ymd, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return null;
    }
    $join = function (array $keys) use ($v) {
        $codes = [];
        foreach ($keys as $k) { $x = $v($k); if ($x !== '') { $codes[] = $x; } }
        return implode(' / ', $codes);
    };
    $yobi1 = $v('yobi1');
    $yobi2 = $v('yobi2');
    $yobi3 = $v('yobi3');
    return [
        'kancd'         => $v('kancd'),
        'rei'           => $v('rei'),
        'op_date'       => "{$m[1]}-{$m[2]}-{$m[3]}",
        'room'          => $v('room'),
        'ngkb'          => $v('ngkb'),
        'dept'          => $v('dept'),
        'dept2'         => $v('dept2'),
        'kekka'         => $v('kekka') === '1' ? 1 : 0,
        'kinkyu'        => in_array($v('kinkyu'), ['1', '2'], true) ? (int)$v('kinkyu') : 0,
        'jutusiki'      => $join(['j1', 'j2', 'j3', 'j4', 'j5', 'j6']),
        'kaku_jutusiki' => $join(['k1', 'k2', 'k3', 'k4', 'k5', 'k6']),
        'masui'         => $join(['m1', 'm2', 'm3']),
        'surgeon'       => $v('surgeon'),
        'assistant'     => $v('assistant'),
        'anesth_dr'     => $v('anesth_dr'),
        'st_time'       => ope_hhmm($v('st_time')),
        'en_time'       => ope_hhmm($v('en_time')),
        'masui_start'   => ope_hhmm($v('masui_start')),
        'masui_end'     => ope_hhmm($v('masui_end')),
        'jikan'         => ctype_digit($v('jikan')) ? (int)$v('jikan') : null,
        'day_surgery'   => $v('dsflg') === '1' ? 1 : 0,
        'confirmed'     => substr($yobi1, 9, 1) === '1' ? 1 : 0,   // 予備1 10桁目：予定確定区分
        'post_ward'     => trim(substr($yobi2, 6, 2)),              // 予備2 7-8桁目：術後病棟
        'pre_ward'      => trim(substr($yobi3, 6, 2)),              // 予備3 7-8桁目：術前病棟
        'patient_name'  => $v('kanname'),
        'byomei'        => $v('byomei'),
        'irai'          => $v('irai'),
    ];
}

/** 'HHMM' → 'HH:MM'。'9999'（時間未指定）や不正値は '' */
function ope_hhmm(string $t): string
{
    if (!preg_match('/^([01]\d|2[0-3])([0-5]\d)$/', $t, $m)) { return ''; }
    return $m[1] . ':' . $m[2];
}

function ope_utf8($v): string
{
    $v = (string)$v;
    if ($v !== '' && !mb_check_encoding($v, 'UTF-8')) {
        $v = mb_convert_encoding($v, 'UTF-8', 'SJIS-win');
    }
    return trim($v);
}

/* ------------------------------------------------------------------
 * 接続層（beds/query.php と同じ方式）
 * ------------------------------------------------------------------ */

/** 利用可能な接続方法（設定が auto のときの優先順） */
function ope_available_drivers(): array
{
    $out = [];
    if (extension_loaded('pdo_sqlsrv')) { $out[] = 'pdo_sqlsrv'; }
    if (function_exists('sqlsrv_connect')) { $out[] = 'sqlsrv'; }
    if (extension_loaded('pdo_odbc')) { $out[] = 'pdo_odbc'; }
    if (function_exists('odbc_connect')) { $out[] = 'odbc'; }
    return $out;
}

/**
 * 読み取りSQLを設定のドライバで実行し、連想配列（キーは小文字）の行を返す。
 * @throws RuntimeException 接続・実行エラー時
 */
function ope_run_readonly(array $c, string $sql, array $params = []): array
{
    $drivers = $c['driver'] === 'auto' ? ope_available_drivers() : [$c['driver']];
    if (!$drivers) {
        throw new RuntimeException(
            'SQL Server に接続できるPHP拡張がありません。php.ini で ' .
            'pdo_sqlsrv / sqlsrv / pdo_odbc / odbc のいずれかを有効にしてください。'
        );
    }
    switch ($drivers[0]) {
        case 'pdo_sqlsrv': $rows = ope_run_pdo($c, ope_pdo_sqlsrv_dsn($c), $sql, $params); break;
        case 'pdo_odbc':   $rows = ope_run_pdo($c, ope_odbc_dsn($c, 'odbc:'), $sql, $params); break;
        case 'sqlsrv':     $rows = ope_run_sqlsrv($c, $sql, $params); break;
        case 'odbc':       $rows = ope_run_odbc($c, $sql, $params); break;
        default:           throw new RuntimeException('不明な接続方法: ' . $drivers[0]);
    }
    return array_map(fn($r) => array_change_key_case($r, CASE_LOWER), $rows);
}

function ope_run_pdo(array $c, string $dsn, string $sql, array $params): array
{
    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (PDOException $e) {
        throw new RuntimeException('データベース接続/実行エラー: ' . $e->getMessage());
    }
}

function ope_run_sqlsrv(array $c, string $sql, array $params): array
{
    $conn = sqlsrv_connect($c['server'], [
        'Database' => $c['database'], 'UID' => $c['user'], 'PWD' => $c['pass'],
        'CharacterSet' => 'UTF-8', 'TrustServerCertificate' => true,
    ]);
    if ($conn === false) { throw new RuntimeException('データベース接続エラー: ' . ope_sqlsrv_errors()); }
    $st = sqlsrv_query($conn, $sql, $params);
    if ($st === false) { throw new RuntimeException('クエリ実行エラー: ' . ope_sqlsrv_errors()); }
    $rows = [];
    while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) { $rows[] = $r; }
    sqlsrv_close($conn);
    return $rows;
}

function ope_sqlsrv_errors(): string
{
    $msgs = [];
    foreach ((array)sqlsrv_errors() as $e) { $msgs[] = $e['message'] ?? ''; }
    return implode(' / ', array_filter($msgs)) ?: '不明なエラー';
}

function ope_run_odbc(array $c, string $sql, array $params): array
{
    $conn = @odbc_connect(ope_odbc_dsn($c, ''), $c['user'], $c['pass']);
    if ($conn === false) { throw new RuntimeException('データベース接続エラー: ' . odbc_errormsg()); }
    $st = @odbc_prepare($conn, $sql);
    if ($st === false || !@odbc_execute($st, $params)) {
        throw new RuntimeException('クエリ実行エラー: ' . odbc_errormsg($conn));
    }
    $rows = [];
    while ($r = odbc_fetch_array($st)) { $rows[] = $r; }
    odbc_close($conn);
    return $rows;
}

function ope_odbc_dsn(array $c, string $prefix): string
{
    if (!empty($c['odbc_dsn'])) {
        return $prefix . 'DSN=' . $c['odbc_dsn'] . ';';
    }
    $dsn = $prefix . 'Driver={' . $c['odbc_driver'] . '};Server=' . $c['server']
         . ';Database=' . $c['database'] . ';';
    // ODBC Driver 18 は既定で暗号化必須のため、社内サーバ向けに無効化
    if (strpos($c['odbc_driver'], '18') !== false) {
        $dsn .= 'Encrypt=no;';
    }
    return $dsn;
}

function ope_pdo_sqlsrv_dsn(array $c): string
{
    return 'sqlsrv:Server=' . $c['server'] . ';Database=' . $c['database'] . ';TrustServerCertificate=1';
}
