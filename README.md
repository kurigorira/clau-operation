# 手術予定・実績 月表示アプリ（長崎北徳洲会病院）

医事システム NEWTON（SQL Server `nagasakidb`）の手術データを、1ヶ月単位で
**予定・実績**をカレンダーと集計表で見られる院内イントラネット用アプリです。
空きベッド情報アプリ（`beds/`）と同じく、**SQL Server のデータを MySQL にコピーしてから表示**します。
画面を開いたときに医事システムへ直接アクセスすることはありません。

| フォルダ | サーバー配置先 | URL例 |
|---|---|---|
| `ope/` | `C:\Apache24\htdocs\ope` | `http://10.20.103.125/ope/` |

## 画面
- **月表示 `index.php`**：月カレンダー（日ごとの予定件数・実施件数、未実施・臨時・緊急）、
  サマリー（予定・実施・未実施・臨時・緊急・実施率）、集計表（診療科別／術者別／手術室別）
- **日別明細 `day.php`**：区分・予定時刻・手術室・患者ID・氏名・入外・診療科・病名・術式・術者・麻酔・
  麻酔時間・所要時間・術前/術後病棟・備考（印刷対応）
- **ログイン必須**（患者氏名を表示するため）

## 使っているテーブル（ベンダー定義書より）
| テーブル | 使う列 |
|---|---|
| `SjtDatf3`（1行＝1術例） | SjtKancd, SjtRei, SjtYmd, SjtRoomNo, SjtNgkb, SjtSnk, SjtSnk2, SjtKekkaKbn, SjtKinkyu, SjtYuko, SjtJutusiki1〜6, SjtKakuJutusiki1〜6, SjtMasui1〜3, SjtDrcd, SjtJosyucd1, SjtMasuiDr1, SjtStTime, SjtEnTime, SjtMasuiStart, SjtMasuiEnd, SjtIrai |
| `SjtDatf3Sub` | SjtJikan（所要時間）, SjtDSFlg（日帰り）, SjtYobi1〜4（確定区分・病棟・手術/カテ判断） |
| `SjtByokanSub2` / `SjtByokan` | SjtKnNam / SjtByomei（病名） |
| `kanmf` | 氏名（列名は `patient_name_col` で設定） |

区分の判定
- **実施**：`SjtKekkaKbn = '1'`
- **未実施（中止・延期の目安）**：実施入力が無いまま予定日を過ぎたもの
- **臨時（予定外）**：`SjtKinkyu = '2'`／**緊急**：`SjtKinkyu = '1'`
- 除外：無効（`SjtYuko = '9'`）、カテ（`SjtDatf3Sub.SjtYobi4` の10桁目が `1`、または `cath_rooms` の手術室）
- **実施率**：臨時を除いた予定手術のうち、今日までに予定日が来たものに対する実施の割合

## 設置手順
1. `ope` フォルダを `C:\Apache24\htdocs\ope` にコピー
2. `ope/config.local.php.example` を `ope/config.local.php` にコピーし、次を記入
   - `mysql` … 空床・レセプトアプリと同じ値でよい
   - `server` / `database` / `user` / `pass`（または `odbc_dsn`）… 空床アプリの `beds/config.local.php` と同じ値
3. ブラウザで `http://<サーバ>/ope/check.php` を開く（ユーザー未設定のうちはログイン不要）
   - **8. パスワードハッシュ作成** でハッシュを作り、`config.local.php` の `users` に `'ID' => 'ハッシュ',` で登録
   - **3. 値分布** の `SjtRoomNo` でカテ室の番号を確認 → `cath_rooms` に設定（Newton.ini `[OPK] Catheroom=XX` の値）
   - **4. kanmf の列一覧** で氏名の列を確認 → `patient_name_col` に設定
   - **6. 取得SQLの試験実行** が OK になることを確認
4. `index.php` を開いてログイン（初回表示時に自動で同期されます）

MySQL のテーブル（`ope_cases` / `ope_sync`）は初回アクセス時に自動作成されます。

## 同期（SQL Server → MySQL）
- 対象期間：**前月1日〜2ヶ月先の末日**（`sync_months_back` / `sync_months_ahead`）。それより前の月はコピーを保持
- 表示時に自動（`refresh_sec` より古いとき）／画面の「今すぐ更新」／タスクスケジューラ
  ```
  schtasks /create /tn "ope_sync" /sc minute /mo 30 /tr "\"C:\php\php.exe\" \"C:\Apache24\htdocs\ope\sync.php\""
  ```
  （定期実行にする場合は `auto_sync` を `false` にしてよい）
- 過去の月を取り込み直す：画面の「この月を取り込み直す」、または `sync.php?ym=2025-04`／`php sync.php 2025-04`
- 同期に失敗しても前回コピーを表示し、画面上部に警告を出します

## 名称の表示
術式・麻酔法・医師・診療科・手術室は、名称マスタ連携前のため**コードで表示**します。
手術室・診療科・医師は `config.local.php` の `room_names` / `dept_names` / `doctor_names` に書けば名称で表示されます。
マスタのテーブルが分かれば（check.php「5. 名称マスタの候補テーブル」参照）、SQL に結合を追加して自動で名称表示にできます。

## 画面確認用ダミーデータ（SQL Server が無い環境）
```
php ope/tools/demo_data.php          # 前月〜翌月にダミーを投入（config.local.php で auto_sync=false にしておく）
php ope/tools/demo_data.php --clear  # 消去
```
本番の MySQL では実行しないでください。

## ファイル構成
```
ope/
  index.php  day.php  login.php  logout.php  sync.php  check.php
  auth.php   … 共通読み込み・ログイン・画面部品
  query.php  … SQL Server 取得（接続方式は beds/query.php と同じ）
  store.php  … MySQL 保存・集計
  config.php / config.local.php.example
  assets/app.css   tools/demo_data.php   .htaccess
```
