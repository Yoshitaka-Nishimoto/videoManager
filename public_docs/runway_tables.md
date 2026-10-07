# Runway のテーブル設計

Runway API への生成依頼と、その入力素材・出力ファイルを記録する。
制作案と場面のテーブルは `production_tables.md` を参照。生成依頼は場面から作る。

## 設計の前提（Runway API の仕様）

| 仕様 | 設計への影響 |
|---|---|
| 生成は非同期。`POST /v1/image_to_video` などでタスクを作り、`GET /v1/tasks/{id}` で状態を確認する | 依頼ごとに 1 行を作り、Queue のジョブで状態を追う |
| タスクの状態は `PENDING`／`THROTTLED`／`RUNNING`／`SUCCEEDED`／`FAILED`／`CANCELLED` | Runway の状態をそのまま記録し、アプリ側の状態と分ける |
| 出力の URL は 24〜48 時間で無効になる。画面に直接出してはいけない | 完了したらすぐダウンロードして保存し、保存先パスを記録する |
| 入力素材は HTTPS の URL、Data URI、アップロード（`runway://`、24 時間有効）で渡す | ローカル環境には公開 URL がないため、アップロードを使い、URI と有効期限を記録する |
| 失敗コードによって、やり直せるもの（`INTERNAL` など）とやり直せないもの（`SAFETY.*`、`ASSET.INVALID`）がある | 失敗コードを記録し、やり直しは別の行として作る |
| 料金はモデルごとに秒単位・枚数単位で決まる。安全性チェックで拒否された場合も返金されない | 依頼時に費用を見積もって記録し、`ai_usage_logs` にも残す |

## テーブル一覧

| テーブル | 内容 |
|---|---|
| runway_tasks | 生成依頼（1 回の API 呼び出し） |
| runway_task_inputs | 依頼に渡した素材（画像・動画・音声） |
| runway_task_outputs | 生成されたファイル（ダウンロードして保存したもの） |

```
runway_tasks ─┬─< runway_task_inputs >── runway_task_outputs（前の生成物を素材に使う場合）
              └─< runway_task_outputs ──> videos（動画として登録した場合）
runway_tasks ──> runway_tasks（やり直し元）
```

## runway_tasks（生成依頼）

| 列 | 型 | 内容 |
|---|---|---|
| id | bigint | 依頼ID |
| task_type | string(32) | 種類（image_to_video / text_to_video / video_to_video / avatar / image / speech / sound）。`video_capabilities.md` の種類キーから `runway.` を除いたもの |
| model | string(64) | モデル（gen4.5 / gen4_turbo / aleph2 / seedance2_5 / gwm1_avatars / gen4_image_turbo / eleven_v4 / seed_audio など） |
| prompt_text | text, null | 指示文・台本 |
| ratio | string(16), null | 比率（1280:720 など） |
| duration_seconds | unsigned smallint, null | 長さ（秒） |
| seed | unsigned bigint, null | 乱数の種。同じ結果を再現したいときに使う |
| options | json, null | 種類ごとの追加の指定（声、比率以外の画質など） |
| request_payload | json, null | 実際に送った内容。再現と調査のために残す |
| status | string(16) | アプリ側の状態（queued / running / completed / failed / cancelled） |
| runway_task_id | string(64), null, 一意 | Runway が返したタスクID |
| runway_status | string(16), null | Runway の状態（PENDING / THROTTLED / RUNNING / SUCCEEDED / FAILED / CANCELLED） |
| progress | unsigned tinyint | 進捗率（0〜100） |
| failure_code | string(64), null | 失敗コード（SAFETY.INPUT.TEXT など） |
| failure_message | text, null | 失敗の内容 |
| estimated_cost | decimal(10,4), null | 見積もり費用（米ドル） |
| production_scene_id | FK production_scenes, null | どの場面の生成か |
| retry_of_id | FK runway_tasks, null | やり直し元の依頼 |
| requested_by | FK users, null | 依頼者 |
| submitted_at | timestamp, null | Runway に送った日時 |
| completed_at | timestamp, null | 出力の保存まで終わった日時 |
| created_at / updated_at | timestamp | 作成・更新日時 |

状態の移り変わり：

```
queued（作成、未送信）
  → running（Runway に送信済み。runway_status は PENDING / THROTTLED / RUNNING）
  → completed（SUCCEEDED になり、出力を保存し終えた）
  → failed（FAILED、または送信・ダウンロードの失敗）
  → cancelled（取り消し）
```

- やり直すときは、元の行を書き換えず、`retry_of_id` を付けて新しい行を作る（design.md の「古い判断を上書きしない」に合わせる）。
- `SAFETY.*` と `ASSET.INVALID` で失敗した依頼は、画面でやり直しボタンを出さない。

## runway_task_inputs（入力素材）

| 列 | 型 | 内容 |
|---|---|---|
| id | bigint | 入力ID |
| runway_task_id | FK runway_tasks | 依頼 |
| role | string(32) | 役割（prompt_image / first_frame / last_frame / reference_image / video / audio） |
| position | unsigned tinyint | 同じ役割の中の順番 |
| media_type | string(16) | 種類（image / video / audio） |
| storage_path | string(1024), null | 手元の保存先パス |
| source_output_id | FK runway_task_outputs, null | 前の生成物を素材に使った場合の元ファイル |
| production_asset_id | FK production_assets, null | 人が用意した素材を使った場合の元ファイル |
| runway_uri | string(2048), null | Runway に渡した URI（runway://…） |
| runway_uri_expires_at | timestamp, null | URI の有効期限（アップロードから 24 時間） |
| created_at / updated_at | timestamp | 作成・更新日時 |

- 例：`runway.image` で作った静止画を `runway.image_to_video` に渡すとき、`source_output_id` で元の生成物をたどれる。
- 有効期限が切れた URI は使わず、送信の直前にアップロードし直す。

## runway_task_outputs（生成されたファイル）

| 列 | 型 | 内容 |
|---|---|---|
| id | bigint | 出力ID |
| runway_task_id | FK runway_tasks | 依頼 |
| position | unsigned tinyint | 出力の順番（1 回の依頼で複数出ることがある） |
| media_type | string(16) | 種類（video / image / audio） |
| storage_path | string(1024) | 保存先パス（例：`runway/{依頼ID}/0.mp4`） |
| mime_type | string(64), null | MIME タイプ |
| size_bytes | unsigned bigint, null | ファイルサイズ |
| width / height | unsigned smallint, null | 縦横の画素数（映像・画像） |
| duration_seconds | decimal(8,3), null | 長さ（映像・音声） |
| video_id | FK videos, null | 動画として登録した場合の videos の行（source_type = runway） |
| created_at / updated_at | timestamp | 作成・更新日時 |

- Runway の出力 URL は期限があるため保存しない。保存するのは手元のファイルだけ。
- 出力をすべて videos に登録するわけではない。素材の静止画や音声は、ここに残すだけにする。

## 既存テーブルとの関係

| 既存テーブル | 使い方 |
|---|---|
| videos | 完成した映像を `source_type = runway` で登録し、`storage_path` に保存先を入れる。`runway_task_outputs.video_id` でつなぐ |
| ai_usage_logs | `provider = runway`、`feature = generation`、`usable` を runway_tasks の行にして、見積もり費用を記録する。トークン数は 0 にする。既存の使用量の画面でまとめて見られる |
| users | 依頼者 |
| production_scenes | 依頼側の `production_scene_id` で場面につなぐ（やり直しを含めて複数）。場面側の `selected_output_id` で、使う生成物を 1 つ選ぶ |
| production_assets | 入力側の `production_asset_id` で、人が用意した素材につなぐ |

## 後で決めること

- ファイルの保存先（当面は `local` ディスク、容量が増えたら S3 などに移す）。
- 同時に送れる依頼数の上限（Runway の利用枠に合わせて Queue のワーカー数で調整する）。

## 出典

- [Runway API リファレンス](https://docs.dev.runwayml.com/api/)
- [Runway 出力ファイル](https://docs.dev.runwayml.com/assets/outputs/)
- [Runway 入力の制限](https://docs.dev.runwayml.com/assets/inputs/)
- [Runway タスクの失敗](https://docs.dev.runwayml.com/errors/task-failures/)
- [Runway 料金](https://docs.dev.runwayml.com/guides/pricing/)
