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
| 右側 | **実装がコードのどこにあるか** | **`knowledge_code_links`（新規）** | テーブル・モデルのみ済み（画面は未） |
| 操作 | 関係の追加・候補の採用・統合 | `knowledge_edges`、`knowledge_nodes.merged_into_id`、`knowledge_revisions` | 済み |
| 操作 | **誤った関係の修正**（正しい関係への置き換え） | **`knowledge_edges.replaced_by_id`（列の追加）** | 列・リレーションのみ済み（操作は未） |
| 操作 | 元データへの移動 | `knowledge_sources` → videos・分析・会話／`knowledge_code_links` → ファイル | コードへの移動が未 |

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
| 関係 | `knowledge_edges.relation_type_id` | あり |
| 出典（動画） | `knowledge_sources.video_id` → `videos.title` | あり |
| コード | `knowledge_code_links.path` | 新規で作る |

- タイトルの部分一致は、ノードが数千件を超えるまでは索引なしで十分（現在 85 件）。遅くなったら `pg_trgm` の GIN 索引を加える。拡張は利用可能で未導入。DB の文字種別は en_US.utf8 なので日本語にも効くが、**2 文字以下の検索語には索引が使われない**。
- 言い換え検索は、必要になってから pgvector で行う。`knowledge_nodes.embedding`（1536 次元）は作成済み。そのときに `embedding_model`・`embedded_at` の列と HNSW 索引を加える。pgvector は類似の候補を出すだけで、同一かどうかや関係が正しいかは人が確定する。

## 作らないもの

| 候補 | 作らない理由 |
|---|---|
| コード用の knowledge_nodes（ファイル・メソッドごとのノード） | コードの正本は Git、構造は Graphify。DB に写すと二重管理になる |
| graph.json の取り込み用テーブル | 作り直せる解析結果なので、保存しない |
| 全体グラフ用の座標・レイアウト | 詳細画面が役立つと確認できてから考える（design.md） |
