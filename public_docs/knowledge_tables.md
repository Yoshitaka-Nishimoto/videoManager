# 知識の詳細画面のテーブル設計

design.md の「画面は一点から周囲を見る形にする」を実現するためのデータベース設計。
最初に決めるのは「何を知識として確定し、どう根拠へ戻れるか」で、画面はその規則を表示・操作するだけにする。

完成目標：YouTube 動画を登録 → 分析 → 概念と関係を候補として表示 → 人が採用 → 概念画面から元動画と**実装（コード）**へ戻る。

## 画面の各部分とテーブル

| 画面の部分 | 表示するもの | テーブル・列 | 状態 |
|---|---|---|---|
| 中央 | 選んだノードの説明と状態 | `knowledge_nodes`（title、description、status、proposed_by、confirmed_by） | 済み |
| 左側 | 根拠となった動画・分析・会話、動画の該当時刻 | `knowledge_sources`（video_id、video_analysis_id、agent_conversation_message_id、start_seconds、end_seconds、excerpt） | 済み |
| 右側 | 関連する概念・設計判断・実装 | `knowledge_edges` ＋ `knowledge_relation_types`（label、inverse_label） | 済み |
| 右側 | **実装がコードのどこにあるか** | **`knowledge_code_links`（新規）** | 済み（設計判断から実装を作る → 実装にコードをつなぐ → 概念・設計判断の画面に表示）。graph.json との照合は未 |
| 操作 | 関係の追加・候補の採用・統合 | `knowledge_edges`、`knowledge_nodes.merged_into_id`、`knowledge_revisions` | 済み |
| 操作 | **誤った関係の修正**（正しい関係への置き換え） | **`knowledge_edges.replaced_by_id`（列の追加）** | 列・リレーションのみ済み（操作は未） |
| 操作 | 元データへの移動 | `knowledge_sources` → videos・分析・会話／`knowledge_code_links` → GitHub の該当行 | 済み |

## 「入力画像の品質確認」を開いたときのたどり方

```
動画ノード ←analyzes─ 分析ノード ←extracted_from─ 概念「入力画像の品質確認」   … 左側（根拠）
                                                      ↑ inspired_by
                                              設計判断「生成の前に入力画像を確認する」
                                                      ↑ implements
                                              実装「Runway の失敗防止：入力画像の検査」  … 右側
                                                      │
                                              knowledge_code_links（新規）
                                                ├ app/Services/Runway/….php  InputImageChecker::check  L20-58
                                                └ tests/Feature/….php
```

※ 実装ノード・クラス名は説明用の例。現在、実装ノードは 0 件で、入力画像を検査するクラスもまだない。

- 実装ノードは「機能」の単位にする（例：「Runway の失敗防止：入力画像の検査」）。1 つの実装ノードに、複数のファイル・メソッドをつなげる。
- `implements` は実装 → 概念、実装 → 設計判断のどちらにも引ける（`KnowledgeRelationTypeSeeder` で定義済み）。

## 保存先の役割（design.md の表を具体化）

| 対象 | 正本 | このテーブル設計での扱い |
|---|---|---|
| ソースコード | Git | 保存しない。パス・シンボル名・コミットで指すだけ |
| Graphify の graph.json | ソースから作り直せる解析結果 | ノード ID を「移動用の手がかり」として控えるだけ。再生成で変わってもよい |
| 実装ノードとコードの対応 | **PostgreSQL（`knowledge_code_links`）** | 人が確定した対応。Graphify を再生成しても消えない |

## knowledge_code_links（実装とコードの対応）【新規】

| 列 | 型 | 内容 |
|---|---|---|
| id | bigint PK | 対応 ID |
| knowledge_node_id | FK knowledge_nodes | 実装ノード（node_type = implementation のみ。処理側で確認する） |
| path | string | リポジトリからの相対パス（例：`app/Services/Runway/InputImageChecker.php`） |
| symbol | string null | クラス・メソッド名（例：`InputImageChecker::check`）。ファイル全体なら空 |
| start_line | unsigned int null | 開始行（目安。コードが変わるとずれる） |
| end_line | unsigned int null | 終了行 |
| commit_sha | string(40) null | 対応を記録したときのコミット。GitHub の固定リンクを作るのに使う |
| graphify_node_id | string null | graph.json のノード ID（例：`app_services_runway_inputimagechecker_check`）。移動用 |
| note | text null | 補足（このコードのどこが概念に当たるか） |
| status | string(16) | candidate / confirmed / deprecated（ノード・エッジと同じ） |
| proposed_by | string(16) | ai / human |
| confirmed_by | FK users null | 確認者 |
| confirmed_at | timestamp null | 確認日時 |
| missing_since | timestamp null | graph.json でパス・シンボルが見つからなくなった日時。見つかれば空に戻す |
| checked_at | timestamp null | graph.json と最後に照合した日時 |
| created_by | FK users null | 登録者 |
| created_at / updated_at | timestamp | |

- 一意：`(knowledge_node_id, path, symbol)`。symbol が空の行も重複させないよう NULLS NOT DISTINCT にする。
- 索引：`path`。ファイルから「このコードはどの知識を実装しているか」を逆に引くため。
- 変更履歴は既存の `knowledge_revisions`（revisable_type = 対応）に残す。
- **照合**：`graphify update .` の後に graph.json の `source_file`・`label` と照合し、見つからない対応に `missing_since` を入れる。行の削除や自動修正はしない（人が直す）。

## Graphify の画面で知識をたどる（知識入りの graph.html）

DB の知識を Graphify の graph.json に加え、Graphify の画面（graph.html）で 概念 → 設計判断 → 実装 → コード とたどれるようにする。

```bash
bin/knowledge-graph          # DB の知識を加えて graph.html を作る
bin/knowledge-graph update   # 先に graphify update . でコードのグラフも作り直す
```

- 出力：`graphify-out/knowledge/graphify-out/graph.html`。元の `graphify-out/graph.html` は変えない。
- 処理：`php artisan knowledge:graph`（Sail のコンテナ）で graph.json を書き出し、`graphify cluster-only graphify-out/knowledge --no-label`（WSL）で graph.html を作る。AI は使わない。
- 知識のノードは名称の先頭に `[概念]`・`[設計判断]`・`[実装]` などを付ける。Source には知識の画面の URL を入れる（graph.html の Source 欄は文字だけなので、コピーして開く）。
- エッジの関係名：知識のエッジは `knowledge_<関係キー>`（例：`knowledge_implements`）、実装 → コードは `knowledge_implemented_in`。確認済みは実線、候補は点線。
- 廃止済みのノード・エッジ・コードの対応は含めない。
- 記録した Graphify のノード ID が今の graph.json にないときは、同じファイル・同じシンボルのノードにつなぐ。見つからなければ警告を出してつながない。
- graphify は読み込むときにノード ID を Source から付け直す（例：`knowledge_172` → `http_localhost_knowledge_172`）。エッジも一緒に付け直されるので、つながりは変わらない。
- DB の知識を変えたら実行し直す（自動では更新されない）。

逆に、graph.html でコードのノードの Source（ファイルのパス）を知識一覧の検索欄に貼ると、そのファイルにつながる実装ノードが出る。パス（`/` を含む語）や Graphify のノード ID で探すときは、状態で絞らずに表示する。

## 埋め込み（pgvector）

`knowledge_nodes.embedding`（vector(1536)）に、ノードの意味を表すベクトルを入れる。重複の発見と言い換え検索に使う。候補を出すだけで、確定は人が行う。

| 列 | 内容 |
|---|---|
| embedding | 埋め込み（gemini-embedding-2、1536 次元。HNSW 索引は vector 型で 2000 次元までのため） |
| embedding_model | 作ったモデル |
| embedding_hash | 元にした文のハッシュ（モデル・次元数を含む）。変わったら作り直す |
| embedded_at | 作った日時 |

索引：`knowledge_nodes_embedding_hnsw_index`（HNSW、コサイン距離）。

**元にする文**（`KnowledgeEmbedder::text`）

| 種類 | 文 |
|---|---|
| 動画 | 名称 ＋ `videos.summary`（動画ノードの説明も分析のたびに要約で更新する） |
| 分析 | 名称 ＋ 説明（その版の要約） |
| 概念 | 名称 ＋ 説明 |
| 設計判断 | 名称 ＋ 説明 ＋ 着想元の概念の名称（説明が短くても意味が伝わるように） |
| 実装 | 名称 ＋ 説明 ＋ 実装する設計判断・概念の名称 ＋ つないだコードのパス・シンボル |

**作り直すとき**：ノードの作成、名称・説明の変更、関係（設計判断・実装の接続元）やコードの対応の変更で、ジョブ（`EmbedKnowledgeNodes`）が作り直す。元の文が変わっていなければ送らない。まとめて作る・確認するときは `php artisan knowledge:embed`（`--dry-run`：件数と説明が短いノードだけ表示、`--duplicates`：重複の候補を表示、`--force`：すべて作り直す）。テストでは `KNOWLEDGE_EMBEDDINGS=false` で AI を呼ばない。

**重複の候補**：同じ種類の概念・設計判断・実装どうしで、類似度 0.9 以上。動画・分析は事実の記録なので対象にしない（同じ動画の分析の版どうしが似るのは当然のため）。98 件で確かめたところ、関連はあるが別の概念どうしは 0.8〜0.87 だった。

AI 使用量は `ai_usage_logs`（feature = embedding）に記録する。98 件で約 1 万トークン。

## knowledge_edges.replaced_by_id（誤った関係の修正）【列の追加】

| 列 | 型 | 内容 |
|---|---|---|
| replaced_by_id | FK knowledge_edges null | この関係を置き換えた、正しい関係 |

- 関係の種類や向きが誤っていたとき、既存の行を書き換えずに「古い関係を廃止 → 正しい関係を作成 → 古い関係の `replaced_by_id` に新しい関係を入れる」。根拠（knowledge_sources）は新しい関係へ移す。
- 再分析しても古い判断を上書きしない（design.md の「変更履歴」）。ノードの `merged_into_id` と同じ考え方。

## 検索（タイトル・種類・関係・出典）

新しいテーブルは不要。既存の列と索引で足りる。

| 探し方 | 使う列 | 索引 |
|---|---|---|
| タイトル | `knowledge_nodes.title`（部分一致） | なし（下記） |
| 種類 | `knowledge_nodes.node_type` | あり |
| 状態 | `knowledge_nodes.status` | あり |
| ジャンル | `videos.genre`（動画ノードは `video_id`、分析ノードは分析の動画、概念などは自身と廃止していない関係の `knowledge_sources.video_id`）、設計判断は `video_productions.genre`。選択肢は `VideoProduction::GENRES`。動画のジャンルは動画の詳細画面で選ぶ（未設定は空） | あり（`videos.genre`） |
| 関係 | `knowledge_edges.relation_type_id` | あり |
| 出典（動画） | `knowledge_sources.video_id` → `videos.title` | あり |
| コード | `knowledge_code_links.path` | 新規で作る |

- タイトルの部分一致は、ノードが数千件を超えるまでは索引なしで十分（現在 85 件）。遅くなったら `pg_trgm` の GIN 索引を加える。拡張は利用可能で未導入。DB の文字種別は en_US.utf8 なので日本語にも効くが、**2 文字以下の検索語には索引が使われない**。
- 言い換え検索は pgvector で行う（上の「埋め込み（pgvector）」）。埋め込み・HNSW 索引・検索の処理（`KnowledgeEmbedder::search`）は済みで、画面からはまだ使えない。pgvector は類似の候補を出すだけで、同一かどうかや関係が正しいかは人が確定する。

## 作らないもの

| 候補 | 作らない理由 |
|---|---|
| コード用の knowledge_nodes（ファイル・メソッドごとのノード） | コードの正本は Git、構造は Graphify。DB に写すと二重管理になる |
| graph.json の取り込み用テーブル | 作り直せる解析結果なので、保存しない |
| 全体グラフ用の座標・レイアウト | 詳細画面が役立つと確認できてから考える（design.md） |
