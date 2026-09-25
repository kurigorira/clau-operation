<?php
/**
 * 手術予定・実績アプリの接続診断。ブラウザで ope/check.php を開くと、
 * [1] 表示用MySQL [2] 医事システムSQL Server [3] 手術データの値分布
 * [4] 患者マスタ(kanmf)の列 [5] 名称マスタ候補 [6] 取得SQLの試験実行 [7] 同期状態
 * [8] ログイン用パスワードハッシュ作成 を順に表示します。
 * ログインユーザー未設定のうちは誰でも開けます（ユーザー設定後はログインが必要）。
 */
define('OPE_APP', true);
$config = require __DIR__ . '/auth.php';
if (!empty($config['users'])) { ope_require_login(); }
ini_set('display_errors', '1');
error_reporting(E_ALL);

$ok = fn(string $s = 'OK') => "<span class='ok'>" . h($s) . '</span>';
$ng = fn(string $s = 'NG') => "<span class='ng'>" . h($s) . '</span>';

/** 行配列を小さな表で出す */
$table = function (array $rows): void {
    if (!$rows) { echo "<p class='legend'>0行</p>"; return; }
    $cols = array_keys($rows[0]);
    echo "<div class='scroll'><table class='data'><thead><tr>";
    foreach ($cols as $c) { echo '<th>' . h($c) . '</th>'; }
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($cols as $c) {
            $v = ope_utf8($r[$c] ?? '');
            echo '<td>' . h($v === '' ? '(空)' : $v) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
};

/** 氏名などを伏せ字にする（先頭1文字のみ表示） */
$mask = fn(string $s) => $s === '' ? '' : mb_substr($s, 0, 1) . str_repeat('＊', max(1, mb_strlen($s) - 1));

// ---- パスワードハッシュ作成（POST）----
$hashOut = null;
$hashErr = null;
$newId = trim((string)($_POST['newid'] ?? ''));
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['newpass'])) {
    if (!ope_csrf_ok($_POST['csrf'] ?? null)) {
        $hashErr = '画面の有効期限が切れました。もう一度入力してください。';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $newId)) {
        $hashErr = 'ID は半角英数字（と _ . -）で1〜32文字にしてください。';
    } elseif ((string)$_POST['newpass'] === '') {
        $hashErr = 'パスワードを入力してください。';
    } else {
        $hashOut = password_hash((string)$_POST['newpass'], PASSWORD_DEFAULT);
    }
}

[$mFrom, $mTo] = ope_month_range(date('Y-m'));
$ymdFrom = str_replace('-', '', $mFrom);
$ymdTo   = str_replace('-', '', $mTo);

ope_header($config, '接続診断');
echo "<style>.ok{color:#1b6b35;font-weight:bold}.ng{color:#b53434;font-weight:bold}
  .check li{margin:6px 0} h3{margin:16px 0 4px;font-size:15px}</style>";
echo "<h2 class='section-title'>接続診断</h2><p>PHPバージョン：<code>" . h(phpversion()) . '</code></p>';

// ---- [1] MySQL ----
echo "<h2 class='section-title'>1. 表示用MySQL（コピー先）</h2><ul class='check'>";
$pdo = null;
try {
    $pdo = ope_mysql($config);
    echo '<li>接続・テーブル作成：' . $ok() . '（DB=<code>' . h($config['mysql']['database']) . '</code>）</li>';
} catch (Throwable $e) {
    echo '<li>' . $ng('NG：MySQLに接続できません。') . '<br><code>' . h($e->getMessage()) . '</code><br>'
       . '→ config.local.php の <code>mysql</code> 設定を確認してください。</li>';
}
echo '</ul>';

// ---- [2] SQL Server ----
echo "<h2 class='section-title'>2. 医事システムSQL Server（コピー元）</h2><ul class='check'>";
$avail = ope_available_drivers();
$sqlOk = false;
$srvOk = false;
if (!$avail) {
    echo '<li>' . $ng('NG：SQL Serverに接続できるPHP拡張がありません。') . '<br>php.ini で '
       . '<code>pdo_sqlsrv</code> / <code>sqlsrv</code> / <code>pdo_odbc</code> / <code>odbc</code> のいずれかを有効にしてください。</li>';
} else {
    echo '<li>SQL Server用のPHP拡張：' . $ok() . '（使用可能: <code>' . h(implode(', ', $avail)) . '</code>）</li>';
    echo '<li>接続先：<code>' . h($config['odbc_dsn'] !== '' ? 'DSN=' . $config['odbc_dsn'] : $config['server'])
       . '</code> ／ DB：<code>' . h($config['database']) . '</code> ／ ユーザ：<code>' . h($config['user']) . '</code></li>';
    // 接続そのものの確認（ログインユーザー・既定スキーマ）
    try {
        $who = ope_run_readonly($config, 'select db_name() as db, current_user as usr, schema_name() as sch');
        $srvOk = true;
        echo '<li>接続：' . $ok() . '（DB=<code>' . h($who[0]['db'] ?? '') . '</code> ／ DBユーザー=<code>'
           . h($who[0]['usr'] ?? '') . '</code> ／ 既定スキーマ=<code>' . h($who[0]['sch'] ?? '') . '</code>）</li>';
    } catch (Throwable $e) {
        echo '<li>接続：' . $ng() . '<br><code>' . h($e->getMessage()) . '</code><br>'
           . '→ config.local.php に実際のSQL Server接続情報（空床アプリと同じ値）を記入してください。</li>';
    }
    if ($srvOk) {
        $tDat = null;
        try {
            $tDat = ope_t($config, 'SjtDatf3');
            $t0 = microtime(true);
            $r = ope_run_readonly($config, "select count(*) as cnt from {$tDat} where SjtYmd between ? and ?", [$ymdFrom, $ymdTo]);
            $ms = (int)round((microtime(true) - $t0) * 1000);
            echo '<li><code>' . h($tDat) . '</code>（手術データ）読み取り：' . $ok() . '（今月 ' . (int)($r[0]['cnt'] ?? 0) . " 件・無効/カテ含む / {$ms}ms）</li>";
            $sqlOk = true;
        } catch (Throwable $e) {
            echo '<li><code>' . h($tDat ?? 'SjtDatf3') . '</code> 読み取り：' . $ng() . '<br><code>' . h($e->getMessage()) . '</code><br>'
               . '→ 接続はできていますが、手術テーブルが見つかりません（または読む権限がありません）。下の「2-2. 手術テーブルの所在調査」を確認してください。</li>';
        }
    }
}
echo '</ul>';

// ---- [2-2] 手術テーブルの所在調査（SjtDatf3 が読めないとき）----
if ($srvOk && !$sqlOk) {
    echo "<h2 class='section-title'>2-2. 手術テーブルの所在調査</h2>";
    echo "<p class='legend'>SjtDatf3 がどのスキーマ・どのデータベースにあるかを探します。見つかった場所を "
       . "config.local.php の <code>sjt_prefix</code> に設定してください（現在：<code>"
       . h($config['sjt_prefix'] !== '' ? $config['sjt_prefix'] : '未設定') . '</code>）。</p>';

    echo '<h3>(1) 接続中のDB内で名前が sjt で始まるテーブル・ビュー</h3>';
    try {
        $found = ope_run_readonly($config,
            "select table_schema, table_name, table_type from information_schema.tables
              where table_name like 'sjt%' order by table_schema, table_name");
        $table($found);
        foreach ($found as $f) {
            if (strcasecmp((string)$f['table_name'], 'SjtDatf3') === 0) {
                echo '<p>' . $ok('見つかりました') . "：<code>'sjt_prefix' => '" . h($f['table_schema']) . ".',</code> を設定してください。</p>";
                break;
            }
        }
    } catch (Throwable $e) {
        echo '<p>' . $ng('取得失敗') . '：<code>' . h($e->getMessage()) . '</code></p>';
    }

    echo '<h3>(2) シノニム（別名）</h3>';
    try {
        $table(ope_run_readonly($config,
            "select schema_name(schema_id) as sch, name, base_object_name from sys.synonyms where name like 'sjt%' order by name"));
    } catch (Throwable $e) {
        echo '<p>' . $ng('取得失敗') . '：<code>' . h($e->getMessage()) . '</code></p>';
    }

    echo '<h3>(3) 同じSQL Server上の他のデータベース</h3>';
    try {
        $dbs = ope_run_readonly($config,
            "select name from sys.databases where state_desc = 'ONLINE'
               and name not in ('master','tempdb','model','msdb') order by name");
        $hits = [];
        $checked = [];
        foreach ($dbs as $db) {
            $name = (string)$db['name'];
            if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) { continue; }
            try {
                $rows = ope_run_readonly($config,
                    "select table_schema from [{$name}].information_schema.tables where table_name = 'SjtDatf3'");
                $checked[] = $name . '（' . ($rows ? 'あり' : 'なし') . '）';
                foreach ($rows as $row) { $hits[] = [$name, (string)$row['table_schema']]; }
            } catch (Throwable $e) {
                $checked[] = $name . '（権限なし）';
            }
        }
        echo '<p>調べたDB：<code>' . h(implode(' / ', $checked) ?: 'なし') . '</code></p>';
        foreach ($hits as [$dbName, $sch]) {
            echo '<p>' . $ok('見つかりました') . "：データベース <code>" . h($dbName) . '</code> のスキーマ <code>' . h($sch)
               . "</code> → <code>'sjt_prefix' => '" . h($dbName . '.' . $sch) . ".',</code> を設定してください。</p>";
        }
        if (!$hits) {
            echo '<p>' . $ng('見つかりませんでした') . '。ユーザー <code>' . h($config['user']) . '</code> から見えていない可能性があります。'
               . 'ベンダーまたはDB管理者に「SjtDatf3 の置き場所（DB名・スキーマ）と、このユーザーへの SELECT 権限」を確認してください。</p>';
        }
    } catch (Throwable $e) {
        echo '<p>' . $ng('取得失敗') . '：<code>' . h($e->getMessage()) . '</code></p>';
    }
}

if ($sqlOk) {
    // ---- [3] 値分布 ----
    echo "<h2 class='section-title'>3. 今月の手術データの値分布</h2>";
    echo "<p class='legend'>区分の意味が定義書どおりか、カテ室の番号はどれかを確認します。"
       . 'カテ室の番号は config.local.php の <code>cath_rooms</code> に設定してください（現在：<code>'
       . h(implode(', ', (array)$config['cath_rooms']) ?: '未設定') . '</code>）。</p>';
    $dists = [
        'SjtKekkaKbn（結果入力 0:未実施 1:実施）' => 'd.SjtKekkaKbn',
        'SjtKinkyu（0:通常 1:緊急 2:臨時）'       => 'd.SjtKinkyu',
        'SjtYuko（0/1:有効 9:無効）'              => 'd.SjtYuko',
        'SjtRoomNo（手術室）'                     => 'd.SjtRoomNo',
        'SjtNgkb（入外区分）'                     => 'd.SjtNgkb',
        'SjtSnk（診療科）'                        => 'd.SjtSnk',
        'SjtDatf3Sub.SjtYobi4 10桁目（0:手術 1:カテ）' => 'substring(s.SjtYobi4, 10, 1)',
    ];
    foreach ($dists as $title => $expr) {
        echo '<h3>' . h($title) . '</h3>';
        try {
            $table(ope_run_readonly($config,
                "select {$expr} as value, count(*) as cnt
                   from " . ope_t($config, 'SjtDatf3') . " d left outer join " . ope_t($config, 'SjtDatf3Sub') . " s on s.SjtKancd = d.SjtKancd and s.SjtRei = d.SjtRei
                  where d.SjtYmd between ? and ? group by {$expr} order by {$expr}", [$ymdFrom, $ymdTo]));
        } catch (Throwable $e) {
            echo '<p>' . $ng('取得失敗') . '：<code>' . h($e->getMessage()) . '</code></p>';
        }
    }
}

if ($srvOk) {
    // ---- [4] kanmf の列 ----
    echo "<h2 class='section-title'>4. 患者マスタ(kanmf)の列一覧（氏名列の確認）</h2>";
    echo "<p class='legend'>氏名（漢字）が入っている列名を config.local.php の <code>patient_name_col</code> に設定してください"
       . '（現在：<code>' . h($config['patient_name_col'] !== '' ? $config['patient_name_col'] : '未設定') . '</code>）。'
       . '文字列型の列はサンプル値を伏せ字で表示します。</p>';
    try {
        $cols = ope_run_readonly($config,
            "select column_name, data_type, character_maximum_length as len
               from information_schema.columns where table_name = 'kanmf' order by ordinal_position");
        $sample = ope_run_readonly($config, "select top 1 * from kanmf where code between '00000001' and '99989999'");
        $sample = $sample[0] ?? [];
        foreach ($cols as &$c) {
            $name = strtolower((string)$c['column_name']);
            $v = in_array(strtolower((string)$c['data_type']), ['char', 'varchar', 'nchar', 'nvarchar'], true)
                ? ope_utf8($sample[$name] ?? '') : '';
            $c['sample(伏せ字)'] = $mask($v);
        }
        unset($c);
        $table($cols);
    } catch (Throwable $e) {
        echo '<p>' . $ng('取得失敗') . '：<code>' . h($e->getMessage()) . '</code></p>';
    }

    // ---- [5] 名称マスタ候補 ----
    echo "<h2 class='section-title'>5. 名称マスタの候補テーブル</h2>";
    echo "<p class='legend'>術式・麻酔法・医師・診療科・手術室のコードを名称に変換するためのマスタ候補です"
       . '（定義書の Name3 / Name5 / Name6 など）。どれが該当するか分かれば、名称表示に対応できます。</p>';
    try {
        $table(ope_run_readonly($config,
            "select t.table_name, count(c.column_name) as columns
               from information_schema.tables t
               join information_schema.columns c on c.table_name = t.table_name
              where t.table_type = 'BASE TABLE'
                and (t.table_name like '%name%' or t.table_name like 'sjt%' or t.table_name like '%jutu%'
                  or t.table_name like '%masui%' or t.table_name like '%dr%' or t.table_name like '%doc%'
                  or t.table_name like '%snk%' or t.table_name like '%ka%mf%')
              group by t.table_name order by t.table_name"));
    } catch (Throwable $e) {
        echo '<p>' . $ng('取得失敗') . '：<code>' . h($e->getMessage()) . '</code></p>';
    }
}

if ($sqlOk) {
    // ---- [6] 取得SQLの試験実行 ----
    echo "<h2 class='section-title'>6. 取得SQLの試験実行（今月分）</h2><ul class='check'>";
    try {
        $t0 = microtime(true);
        $rows = ope_fetch($config, $mFrom, $mTo);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        echo '<li>実行：' . $ok() . '（' . count($rows) . " 件 / {$ms}ms・無効とカテを除外後）</li>";
        if ($rows) {
            $r0 = $rows[0];
            echo '<li>先頭データ例：<code>' . h($r0['op_date'] . ' / 室' . $r0['room'] . ' / 科' . $r0['dept']
               . ' / 術者' . $r0['surgeon'] . ' / 氏名' . ($r0['patient_name'] !== '' ? $mask($r0['patient_name']) : '(未設定)')
               . ' / 病名' . ($r0['byomei'] !== '' ? $r0['byomei'] : '(なし)')) . '</code></li>';
        }
    } catch (Throwable $e) {
        echo '<li>実行：' . $ng() . '<br><code>' . h($e->getMessage()) . '</code>';
        if (!empty($config['with_byomei'])) {
            echo '<br>→ 病名テーブル(SjtByokanSub2/SjtByokan)の読み取りで失敗する場合は config.local.php で '
               . "<code>'with_byomei' => false</code> にしてください。";
        }
        echo '</li>';
    }
    echo '</ul>';
}

// ---- [7] 同期状態 ----
echo "<h2 class='section-title'>7. 同期状態（MySQLコピー）</h2><ul class='check'>";
if ($pdo) {
    $meta = ope_meta($pdo);
    if (!empty($meta['synced_at'])) {
        echo '<li>最終同期：' . $ok((string)$meta['synced_at']) . '（' . h($meta['from_date'] . '〜' . $meta['to_date'])
           . ' / ' . (int)$meta['row_count'] . ' 件）</li>';
    } else {
        echo '<li>最終同期：' . $ng('未実行') . " → <a href='sync.php'>初回の同期を実行</a></li>";
    }
    if (!empty($meta['last_error'])) {
        echo '<li>直近の同期エラー（' . h($meta['last_error_at']) . '）：<code>' . h($meta['last_error']) . '</code></li>';
    }
} else {
    echo '<li>MySQL未接続のため確認できません。</li>';
}
echo '</ul>';

// ---- [8] パスワードハッシュ作成 ----
echo "<h2 class='section-title' id='hash'>8. ログイン用パスワードハッシュ作成</h2>";
echo "<p class='legend'>このアプリにログインする ID とパスワードを<strong>自分で決めて</strong>入力してください"
   . "（電子カルテのIDとは別です。例：ID <code>ope</code>）。パスワード自体はどこにも保存されません。</p>";
echo "<form method='post' action='check.php#hash'><input type='hidden' name='csrf' value='" . h(ope_csrf_token()) . "'>"
   . "ID <input type='text' name='newid' value='" . h($newId !== '' ? $newId : 'ope') . "' required style='padding:6px;border:1px solid #c9d3de;border-radius:6px;width:10em'> "
   . "パスワード <input type='password' name='newpass' required style='padding:6px;border:1px solid #c9d3de;border-radius:6px'> "
   . "<button type='submit' class='btn-sub'>ハッシュ作成</button></form>";
if ($hashErr !== null) {
    echo "<p class='ng'>" . h($hashErr) . '</p>';
}
if ($hashOut !== null) {
    $line = "'users' => ['" . $newId . "' => '" . $hashOut . "'],";
    echo "<div class='okbox' style='margin-top:10px'>次の1行を <code>ope/config.local.php</code> の <code>return [</code> と <code>];</code> の間に貼り付けて保存してください"
       . "（既に <code>'users' =&gt;</code> の行があれば置き換える）。<br>"
       . "<textarea readonly rows='2' style='width:100%;margin-top:6px;font-family:Consolas,monospace' onclick='this.select()'>"
       . h($line) . "</textarea>"
       . "保存後、<a href='login.php'>ログイン画面</a>で ID <code>" . h($newId) . "</code> と今のパスワードでログインできます。</div>";
}
echo "<p style='margin-top:24px'><a href='sync.php'>今すぐ同期する</a> ／ <a href='index.php'>月表示を開く</a></p>";
ope_footer();
