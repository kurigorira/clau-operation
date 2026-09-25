<?php
/**
 * 画面確認用のダミー手術データを MySQL(ope_cases) に投入する（CLI専用）。
 * SQL Server が無い環境で見た目を確認するためのもの。本番DBでは実行しないこと。
 *   php tools/demo_data.php          … 前月〜翌月にダミーを投入
 *   php tools/demo_data.php --clear  … ope_cases を空にする
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }
define('OPE_APP', true);
$config = require dirname(__DIR__) . '/auth.php';
$pdo = ope_mysql($config);

if (in_array('--clear', $argv, true)) {
    $pdo->exec('DELETE FROM ope_cases');
    echo "ope_cases を空にしました。\n";
    exit(0);
}

mt_srand(20260925);
$depts    = ['10', '20', '30', '40', '50'];
$surgeons = ['1001', '1002', '1003', '2001', '2002', '3001'];
$rooms    = ['01', '02', '03', '04'];
$names    = ['山田 太郎', '佐藤 花子', '鈴木 一郎', '田中 美咲', '高橋 健', '伊藤 由美', '渡辺 誠', '中村 愛'];
$byomei   = ['胆石症', '鼠径ヘルニア', '大腿骨頸部骨折', '白内障', '胃癌', '虫垂炎', '腰部脊柱管狭窄症'];
$today = date('Y-m-d');

$from = (new DateTimeImmutable('first day of last month'));
$to   = (new DateTimeImmutable('last day of next month'));
$rows = [];
$seq = 0;
for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
    $w = (int)$d->format('w');
    $n = ($w === 0 || $w === 6) ? mt_rand(0, 1) : mt_rand(3, 8);
    $date = $d->format('Y-m-d');
    for ($i = 0; $i < $n; $i++) {
        $seq++;
        $kinkyu = mt_rand(1, 100) <= 8 ? 2 : (mt_rand(1, 100) <= 6 ? 1 : 0);
        $past = $date < $today;
        $kekka = $past ? (mt_rand(1, 100) <= 92 ? 1 : 0) : ($date === $today && mt_rand(0, 1) ? 1 : 0);
        $st = sprintf('%02d%02d', 9 + intdiv($i, 2) * 2, ($i % 2) * 30);
        $jutu = sprintf('%06d', mt_rand(100000, 999999));
        $rows[] = ope_normalize([
            'kancd' => sprintf('%08d', 10000 + $seq), 'rei' => sprintf('%02d', mt_rand(0, 20)),
            'ymd' => $d->format('Ymd'), 'room' => $rooms[$i % count($rooms)],
            'ngkb' => mt_rand(0, 4) ? '3' : '1', 'dept' => $depts[array_rand($depts)], 'dept2' => '',
            'kekka' => (string)$kekka, 'kinkyu' => (string)$kinkyu,
            'j1' => $jutu, 'k1' => $kekka ? $jutu : '',
            'm1' => mt_rand(0, 1) ? '000001' : '000002',
            'surgeon' => $surgeons[array_rand($surgeons)], 'assistant' => '', 'anesth_dr' => '',
            'st_time' => $kinkyu === 2 ? '9999' : $st, 'en_time' => '9999',
            'masui_start' => $kekka ? $st : '', 'masui_end' => $kekka ? sprintf('%02d00', (int)substr($st, 0, 2) + 2) : '',
            'irai' => '', 'jikan' => (string)(60 * mt_rand(1, 3)), 'dsflg' => mt_rand(0, 9) ? '0' : '1',
            'yobi1' => '0000000001', 'yobi2' => '000000' . '3A', 'yobi3' => '000000' . '4B',
            'kanname' => $names[array_rand($names)], 'byomei' => $byomei[array_rand($byomei)],
        ]);
    }
}
ope_save_range($pdo, $from->format('Y-m-d'), $to->format('Y-m-d'), $rows);
echo count($rows) . " 件のダミー手術データを投入しました（{$from->format('Y-m-d')}〜{$to->format('Y-m-d')}）。\n";
echo "表示確認中に自動同期で消えないよう、config.local.php で 'auto_sync' => false にしてください。\n";
