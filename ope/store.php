<?php
/**
 * 手術予定・実績の MySQL 側（コピー先）処理。テーブルは初回に自動作成する。
 *
 * ope_cases … 手術データのコピー（同期範囲の期間だけ入れ替え、それ以前の月は保持）
 * ope_sync  … 最終同期日時・範囲・件数・直近のエラー（1行のみ）
 */
if (!defined('OPE_APP')) { http_response_code(403); exit('Forbidden'); }

/** 保存する列（query.php の ope_normalize が返すキーと同じ） */
const OPE_COLUMNS = [
    'kancd', 'rei', 'op_date', 'room', 'ngkb', 'dept', 'dept2', 'kekka', 'kinkyu',
    'jutusiki', 'kaku_jutusiki', 'masui', 'surgeon', 'assistant', 'anesth_dr',
    'st_time', 'en_time', 'masui_start', 'masui_end', 'jikan', 'day_surgery', 'confirmed',
    'post_ward', 'pre_ward', 'patient_name', 'byomei', 'irai',
];

function ope_mysql(array $config): PDO
{
    $m = $config['mysql'];
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $m['host'], $m['port'], $m['database']);
    $pdo = new PDO($dsn, $m['user'], $m['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '+09:00'");   // NOW()（同期日時）を日本時間に揃える
    ope_ensure_tables($pdo);
    return $pdo;
}

function ope_ensure_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ope_cases (
           id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
           kancd         VARCHAR(8)   NOT NULL,
           rei           VARCHAR(2)   NOT NULL,
           op_date       DATE         NOT NULL,
           room          VARCHAR(8)   NOT NULL DEFAULT '',
           ngkb          VARCHAR(2)   NOT NULL DEFAULT '',
           dept          VARCHAR(4)   NOT NULL DEFAULT '',
           dept2         VARCHAR(4)   NOT NULL DEFAULT '',
           kekka         TINYINT      NOT NULL DEFAULT 0,
           kinkyu        TINYINT      NOT NULL DEFAULT 0,
           jutusiki      VARCHAR(80)  NOT NULL DEFAULT '',
           kaku_jutusiki VARCHAR(80)  NOT NULL DEFAULT '',
           masui         VARCHAR(40)  NOT NULL DEFAULT '',
           surgeon       VARCHAR(8)   NOT NULL DEFAULT '',
           assistant     VARCHAR(8)   NOT NULL DEFAULT '',
           anesth_dr     VARCHAR(8)   NOT NULL DEFAULT '',
           st_time       VARCHAR(5)   NOT NULL DEFAULT '',
           en_time       VARCHAR(5)   NOT NULL DEFAULT '',
           masui_start   VARCHAR(5)   NOT NULL DEFAULT '',
           masui_end     VARCHAR(5)   NOT NULL DEFAULT '',
           jikan         INT          NULL,
           day_surgery   TINYINT      NOT NULL DEFAULT 0,
           confirmed     TINYINT      NOT NULL DEFAULT 0,
           post_ward     VARCHAR(8)   NOT NULL DEFAULT '',
           pre_ward      VARCHAR(8)   NOT NULL DEFAULT '',
           patient_name  VARCHAR(100) NOT NULL DEFAULT '',
           byomei        VARCHAR(200) NOT NULL DEFAULT '',
           irai          VARCHAR(200) NOT NULL DEFAULT '',
           PRIMARY KEY (id),
           UNIQUE KEY uq_case (kancd, rei),
           KEY idx_date (op_date)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS ope_sync (
           id            TINYINT UNSIGNED NOT NULL,
           synced_at     DATETIME NULL,
           from_date     DATE NULL,
           to_date       DATE NULL,
           row_count     INT NOT NULL DEFAULT 0,
           last_error    TEXT NULL,
           last_error_at DATETIME NULL,
           PRIMARY KEY (id)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec('INSERT IGNORE INTO ope_sync (id) VALUES (1)');
}

function ope_meta(PDO $pdo): array
{
    $row = $pdo->query('SELECT * FROM ope_sync WHERE id = 1')->fetch();
    return $row ?: ['synced_at' => null, 'from_date' => null, 'to_date' => null, 'row_count' => 0,
                    'last_error' => null, 'last_error_at' => null];
}

/** 既定の同期範囲 [from, to]（'Y-m-d'）：sync_months_back ヶ月前の1日 〜 sync_months_ahead ヶ月先の末日 */
function ope_default_range(array $config): array
{
    $first = new DateTimeImmutable('first day of this month');
    $from = $first->modify('-' . max(0, (int)$config['sync_months_back']) . ' month');
    $to   = $first->modify('+' . max(0, (int)$config['sync_months_ahead']) . ' month')->modify('last day of this month');
    return [$from->format('Y-m-d'), $to->format('Y-m-d')];
}

/** 'YYYY-MM' の月の範囲 [from, to]。不正なら null */
function ope_month_range(string $ym): ?array
{
    if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $ym)) { return null; }
    $d = new DateTimeImmutable($ym . '-01');
    return [$d->format('Y-m-d'), $d->modify('last day of this month')->format('Y-m-d')];
}

/** 期間 [from, to] のコピーを入れ替え保存し、同期日時を更新する */
function ope_save_range(PDO $pdo, string $from, string $to, array $rows): void
{
    $cols = OPE_COLUMNS;
    $sql = 'INSERT INTO ope_cases (' . implode(',', $cols) . ') VALUES ('
         . implode(',', array_fill(0, count($cols), '?')) . ')'
         . ' ON DUPLICATE KEY UPDATE ' . implode(',', array_map(fn($c) => "$c=VALUES($c)", $cols));
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM ope_cases WHERE op_date BETWEEN ? AND ?')->execute([$from, $to]);
        $ins = $pdo->prepare($sql);
        foreach ($rows as $r) {
            $ins->execute(array_map(fn($c) => $r[$c] ?? null, $cols));
        }
        $pdo->prepare(
            'UPDATE ope_sync SET synced_at = NOW(), from_date = ?, to_date = ?, row_count = ?,
                    last_error = NULL, last_error_at = NULL WHERE id = 1'
        )->execute([$from, $to, count($rows)]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function ope_record_error(PDO $pdo, string $msg): void
{
    $pdo->prepare('UPDATE ope_sync SET last_error = ?, last_error_at = NOW() WHERE id = 1')->execute([$msg]);
}

/**
 * SQL Server から期間分を取得して MySQL へコピーする（同期本体）。
 * @return int コピーした件数
 */
function ope_sync_range(array $config, PDO $pdo, string $from, string $to): int
{
    try {
        $rows = ope_fetch($config, $from, $to);
    } catch (Throwable $e) {
        ope_record_error($pdo, $e->getMessage());
        throw new RuntimeException($e->getMessage());
    }
    ope_save_range($pdo, $from, $to, $rows);
    return count($rows);
}

/** 期間内の手術（表示用・日付と入室時刻順） */
function ope_rows(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare(
        "SELECT * FROM ope_cases WHERE op_date BETWEEN ? AND ?
          ORDER BY op_date, (st_time = ''), st_time, room, kancd, rei"
    );
    $st->execute([$from, $to]);
    return $st->fetchAll();
}

/* ------------------------------------------------------------------
 * 区分判定・集計
 * ------------------------------------------------------------------ */

/**
 * 1術例の状態。
 *   done    … 実施（SjtKekkaKbn=1）
 *   undone  … 未実施（予定日を過ぎても実施入力なし＝中止・延期の目安）
 *   planned … 予定（今日以降で未実施）
 */
function ope_status(array $r, string $today): string
{
    if ((int)$r['kekka'] === 1) { return 'done'; }
    return $r['op_date'] < $today ? 'undone' : 'planned';
}

/** 空の集計バケット */
function ope_bucket(): array
{
    return ['total' => 0, 'plan' => 0, 'done' => 0, 'plan_done' => 0, 'plan_due' => 0,
            'undone' => 0, 'rinji' => 0, 'kinkyu' => 0];
}

/** 1術例を集計バケットに加える */
function ope_add(array &$b, array $r, string $today): void
{
    $status = ope_status($r, $today);
    $rinji  = (int)$r['kinkyu'] === 2;       // 臨時（予定外）
    $b['total']++;
    if ($status === 'done')   { $b['done']++; }
    if ($status === 'undone') { $b['undone']++; }
    if ($rinji) { $b['rinji']++; } else {
        $b['plan']++;                         // 事前に予定された手術
        if ($r['op_date'] <= $today) {        // 実施率の分母：今日までに予定日が来たもの
            $b['plan_due']++;
            if ($status === 'done') { $b['plan_done']++; }
        }
    }
    if ((int)$r['kinkyu'] === 1) { $b['kinkyu']++; }
}

/** 実施率（%）。分母0なら null */
function ope_rate(array $b): ?float
{
    return $b['plan_due'] > 0 ? round($b['plan_done'] * 100 / $b['plan_due'], 1) : null;
}

/**
 * 月データを集計する。
 * @return array ['total'=>bucket, 'days'=>[Y-m-d=>bucket], 'dept'=>[code=>bucket], 'surgeon'=>..., 'room'=>...]
 */
function ope_summarize(array $rows, string $today): array
{
    $sum = ['total' => ope_bucket(), 'days' => [], 'dept' => [], 'surgeon' => [], 'room' => []];
    foreach ($rows as $r) {
        ope_add($sum['total'], $r, $today);
        foreach (['days' => $r['op_date'], 'dept' => $r['dept'], 'surgeon' => $r['surgeon'], 'room' => $r['room']] as $axis => $key) {
            $sum[$axis][$key] ??= ope_bucket();
            ope_add($sum[$axis][$key], $r, $today);
        }
    }
    foreach (['dept', 'surgeon', 'room'] as $axis) {
        uksort($sum[$axis], fn($a, $b) => strcmp((string)$a, (string)$b));
    }
    return $sum;
}
