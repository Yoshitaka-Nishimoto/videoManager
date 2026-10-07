# 制作案のテーブル設計

設計判断ノードから動画を作るまでを記録する。
Gemini が制作案を提案し、人が採用した案だけを Runway で生成し、Remotion で組み立てる。

## 流れ

```
設計判断ノード（knowledge_nodes）
  ↓ 制作を始める（任意でモック図を添付）
制作（video_productions）
  ↓ Gemini が提案                       ← video_capabilities.md の場面の種類だけを使う
制作案 第1版（production_plans：候補）
  └ 場面 1, 2, 3 …（production_scenes）
  ↓ 人が確認：場面を修正 → 採用          ← ここまでは Runway の費用がかからない
制作案 第1版（確認済み）
  ↓ Runway の場面だけ生成を依頼
runway_tasks（場面ごとに 1 回以上）→ runway_task_outputs
  ↓ 人が生成物を選ぶ（やり直した場合は複数から選ぶ）
場面がすべて「準備完了」
  ↓ Remotion で書き出し
書き出し（production_renders）→ videos（source_type = remotion）
  ↓
知識：動画ノード →「実現する」→ 設計判断ノード
```

## テーブル一覧

| テーブル | 内容 |
|---|---|
| video_productions | 制作（1 つの設計判断から 1 本の動画を作る単位） |
| production_assets | 人が用意した素材（モック図、写真、画面録画、音源） |
| production_plans | 制作案の版（Gemini の提案、または人が修正した版） |
| production_scenes | 制作案の場面 |
| production_renders | Remotion での書き出し（確認用・完成版） |

```
knowledge_nodes（設計判断）─< video_productions ─┬─< production_assets
                                                 ├─< production_plans ─< production_scenes
                                                 └─< production_renders >── videos

production_scenes ─< runway_tasks              （場面の生成依頼の履歴：やり直しを含めて複数）
production_scenes >── runway_task_outputs      （場面に採用した生成物：1 つ）
runway_task_inputs >── production_assets       （人が用意した素材を Runway に渡した場合）
```

## 場面と Runway のつなぎ方

| 向き | 列 | 理由 |
|---|---|---|
| 依頼 → 場面 | `runway_tasks.production_scene_id` | 1 つの場面で依頼が複数になる。やり直し、静止画を作ってから動かす 2 段階の生成など |
| 場面 → 生成物 | `production_scenes.selected_output_id` | 複数の生成物のうち、どれを動画に使うかを人が選ぶ。選び直しても依頼の履歴は残る |

- Runway への依頼は、**確認済みの制作案の場面からだけ**作れる（処理側で確認する）。
- 生成物を選び直すときは `selected_output_id` を変えるだけで、生成のやり直しは不要。

## video_productions（制作）

| 列 | 型 | 内容 |
|---|---|---|
| id | bigint | 制作ID |
| decision_node_id | FK knowledge_nodes | 元の設計判断ノード（node_type = decision） |
| title | string | 動画の仮タイトル |
| brief | text, null | 人が書く制作の意図（誰に何を伝えるか） |
| ratio | string(16) | 画面の比率（1280:720 / 720:1280 / 960:960） |
| target_duration_seconds | unsigned smallint, null | 目標の長さ（秒） |
| status | string(16) | 状態（active / completed / abandoned） |
| created_by | FK users, null | 作成者 |
| created_at / updated_at | timestamp | 作成・更新日時 |

- 1 つの設計判断から複数の制作を作れる（横長版と縦長版など）。

## production_assets（人が用意した素材）

| 列 | 型 | 内容 |
|---|---|---|
| id | bigint | 素材ID |
| video_production_id | FK video_productions | 制作 |
| kind | string(16) | 種類（mock / image / video / audio） |
| title | string, null | 名前・説明 |
| storage_path | string(1024) | 保存先パス |
| original_name | string, null | アップロード時のファイル名 |
| mime_type | string(64), null | MIME タイプ |
| uploaded_by | FK users, null | アップロードした人 |
| created_at / updated_at | timestamp | 作成・更新日時 |

- 案 1（あなたが描いたモック図）は `kind = mock` の素材として登録し、制作案を作るときに Gemini に渡す。

## production_plans（制作案の版）

| 列 | 型 | 内容 |
|---|---|---|
| id | bigint | 制作案ID |
| video_production_id | FK video_productions | 制作 |
| version | unsigned int | 版。制作ごとに 1 から |
| status | string(16) | 状態（generating / failed / candidate / confirmed / deprecated） |
| proposed_by | string(16) | 提案元（ai / human） |
| based_on_id | FK production_plans, null | 修正元の版 |
| summary | text, null | 構成の概要 |
| settings | json, null | 全体の設定（style：見た目、narration：ナレーションの有無と声、bgm：BGM の素材と音量）。形式は scene_content.md |
| mock_asset_ids | json, null | Gemini に渡したモック図（production_assets の ID） |
| model | string, null | 使用モデル（Gemini の場合） |
| prompt | text, null | 使用プロンプト |
| ai_response | json, null | Gemini の応答そのまま（調査用） |
| estimated_cost | decimal(10,4), null | Runway の場面の見積もり費用の合計（米ドル） |
| error_message | text, null | 提案に失敗したときの内容 |
| confirmed_by | FK users, null | 採用した人 |
| confirmed_at | timestamp, null | 採用日時 |
| created_at / updated_at | timestamp | 作成・更新日時 |

一意：`(video_production_id, version)`

規則：

- 候補の版は、場面をそのまま修正できる。
- **確認済みの版は直接修正しない。** 直すときは `based_on_id` を付けて新しい版を作る（場面も複製する）。design.md の「古い判断を上書きしない」に合わせる。
- 確認済みにできるのは、制作ごとに 1 つの版だけ。新しい版を採用すると、前の確認済みの版は廃止になる。
- Gemini の使用量は `ai_usage_logs`（`feature = production_plan`、`usable` を制作案の行）に記録する。

## production_scenes（場面）

| 列 | 型 | 内容 |
|---|---|---|
| id | bigint | 場面ID |
| production_plan_id | FK production_plans | 制作案 |
| scene_key | string(16) | 制作案の中の参照名（s1, s2 … / m1, m2 …）。版を複製しても引き継ぐ |
| track | string(16) | 置き場所（video：順番に再生する場面 / material：再生しない素材の生成） |
| position | unsigned smallint | 順番（track ごと） |
| scene_type | string(32) | 場面の種類キー（remotion.diagram / runway.image_to_video など。video_capabilities.md の一覧） |
| title | string | 場面の名前 |
| duration_seconds | decimal(6,2) | 長さ（秒） |
| content | json, null | Remotion の場面の内容（文言、図のノードと矢印、グラフの数値、素材の参照など）。形式は scene_content.md |
| prompt_text | text, null | Runway の場面の指示文・台本 |
| generation_options | json, null | Runway の場面のモデル、比率、長さなどの指定 |
| narration | text, null | ナレーションの文言 |
| caption | text, null | 字幕 |
| transition | string(32), null | 次の場面への切り替え（fade / slide / wipe / none） |
| estimated_cost | decimal(10,4), null | 見積もり費用（米ドル。Remotion の場面は 0） |
| status | string(16) | 状態（pending / generating / ready / failed） |
| selected_output_id | FK runway_task_outputs, null | 採用した生成物（Runway の場面） |
| preview_path | string(1024), null | 確認用の静止画（Remotion で書き出したモック） |
| created_at / updated_at | timestamp | 作成・更新日時 |

一意：`(production_plan_id, scene_key)`、`(production_plan_id, track, position)`

状態：

| 状態 | Remotion の場面 | Runway の場面 |
|---|---|---|
| pending | 内容が未完成 | 生成前 |
| generating | － | Runway で生成中 |
| ready | 内容がそろった | 生成物を選んだ |
| failed | － | 生成に失敗した（やり直し可能な場合は再依頼できる） |

## production_renders（書き出し）

| 列 | 型 | 内容 |
|---|---|---|
| id | bigint | 書き出しID |
| video_production_id | FK video_productions | 制作 |
| production_plan_id | FK production_plans | 書き出した制作案の版 |
| kind | string(16) | 種類（preview：確認用の低画質 / final：完成版） |
| status | string(16) | 状態（queued / running / completed / failed） |
| progress | unsigned tinyint | 進捗率 |
| storage_path | string(1024), null | 書き出したファイルの保存先 |
| video_id | FK videos, null | 完成版を動画として登録した videos の行 |
| error_message | text, null | 失敗の内容 |
| requested_by | FK users, null | 依頼者 |
| started_at / completed_at | timestamp, null | 開始・完了日時 |
| created_at / updated_at | timestamp | 作成・更新日時 |

## 知識とのつながり

| つながり | 方法 |
|---|---|
| 設計判断 → 制作 | `video_productions.decision_node_id` |
| 完成した動画 → 設計判断 | 完成版を videos に登録したら動画ノードを作り、関係「動画 → **実現する** → 設計判断」を追加する。関係の種類 `realizes` を新しく定義する |
| 概念 → 設計判断 | 先に検討した「着想元とする」（`inspired_by`） |

これで「元動画 → 分析 → 概念 → 設計判断 → 制作した動画」を知識画面でたどれる。

## Runway の設計への追加（runway_tables.md）

| テーブル | 追加する列 |
|---|---|
| runway_tasks | `production_scene_id`（FK production_scenes, null）：どの場面の生成か |
| runway_task_inputs | `production_asset_id`（FK production_assets, null）：人が用意した素材を渡した場合 |

## 後で決めること

- Remotion の書き出しの記録と実行のしかた → remotion_tables.md（プロジェクトの置き場所は未定）。
- 場面ごとの `content` の形 → scene_content.md で決定済み。
