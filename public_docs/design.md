# VideoManager の設計方針

Graphify の graph.json だけを唯一の保存先にする設計では足りない。人が確定した知識は PostgreSQL に置き、言い換え・重複の発見は pgvector で補う。

最初に決めるべきなのはグラフの見た目より、**「何を知識として確定し、どう根拠へ戻れるか」**。Livewire の画面はその規則を表示・操作するだけにする。テーブルの詳細は knowledge_tables.md。

## 知識グラフで最初に決めること

| 優先 | 機能 | 決めたこと | 状況 |
|---|---|---|---|
| 1 | **ノードの種類** | `動画`・`分析`・`概念`・`設計判断`・`実装`を区別する。同じ YouTube 動画を何度分析しても動画ノードは一つにする | 済み |
| 2 | **エッジの意味と向き** | `分析から抽出した`・`根拠となる`・`実装する`・`前提となる`など、使える関係名を定義する（`knowledge_relation_types`） | 済み |
| 3 | **根拠への逆引き** | 各知識から元動画・分析の版・会話メッセージへ戻れるようにし、動画の該当時刻も記録する（`knowledge_sources`） | 済み |
| 4 | **候補と確定の区別** | AI が提案したノード・エッジを即座に確定せず、`候補 → 確認済み → 廃止`で管理する | 済み |
| 5 | **変更履歴** | 要約や関係を修正した人・日時・理由を残す。再分析時に古い判断を上書きしない（`knowledge_revisions`） | 済み。誤った関係の置き換え（`replaced_by_id`）は列のみで操作は未 |
| 6 | **重複の整理** | 「画像品質確認」と「入力画像の品質検査」が同じ概念か、人が確認して統合する（`merged_into_id`）。似たノードの候補は pgvector で出す | 統合の操作は済み。候補は `php artisan knowledge:embed --duplicates` で見られるが、画面には未表示 |
| 7 | **見える範囲** | 知識は全員で共有するのが基本。血液検査の結果など個人のリソースから作った知識は、持ち主だけが見られる（`owner_id`）。共有の概念とは本人の画面でだけつながる（personal_resources.md） | 済み |

## 保存先の役割

Laravel の業務データと Graphify の生成グラフは役割を分ける。Graphify の出力を作り直しても、採用済みのノードとエッジを失わないようにするため。

| 対象 | 正本 | 探索・表示 |
|---|---|---|
| YouTube ID、動画情報、要約、分析結果、会話との対応 | PostgreSQL | VideoManager の Livewire 画面 |
| 個人のファイル（血液検査の結果の CSV など） | `storage/app/private/resources/{利用者 ID}/`（公開しない）。登録・分析・知識は PostgreSQL に `owner_id` 付きで | 持ち主の Livewire 画面だけ |
| 概念・設計判断・実装と、確定したエッジ | PostgreSQL（知識テーブル） | Livewire 画面、知識入りの graph.html（`bin/knowledge-graph`。共有の知識だけ） |
| 「このノードとエッジは確定済み」などの規則 | Laravel 側の知識テーブルと登録処理 | — |
| 実装とコードの対応 | PostgreSQL（`knowledge_code_links`） | 知識の画面、知識入りの graph.html |
| ノードの意味のベクトル | PostgreSQL（`knowledge_nodes.embedding`、pgvector） | 重複の候補・言い換え検索。作り直せる派生データ |
| Laravel・Livewire・Remotion のソース | Git／GitHub | Graphify |
| Graphify の graph.json | ソースから作り直せる解析結果 | 開発エージェントの検索。DB には取り込まない |

pgvector は類似の候補を出す手段で、二つの概念が同一か、エッジが正しいかの確定は人が行う。

## 必要環境

| 部分 | 使うもの | 役割 |
|---|---|---|
| 開発端末 | Windows 11、VS Code、WSL2 Ubuntu、Docker Desktop | 編集とローカル実行 |
| Web アプリ | Laravel 13、PHP 8.3 以上（Sail は 8.5）、Livewire 4 | 動画登録、分析結果、会話、知識、制作の管理画面 |
| AI | Laravel AI SDK（`laravel/ai`）、Gemini | 動画分析、台本の候補、知識の埋め込み（gemini-embedding-2） |
| 主データベース | PostgreSQL 18＋pgvector | 業務データ、ノード・エッジ、埋め込みと類似検索 |
| 非同期処理 | Laravel Queue（database）のワーカー | 分析、埋め込み、動画生成などの時間のかかるジョブ |
| グラフ探索 | Graphify（WSL2 側で実行） | コードと DB の知識を横断して調べる |
| 動画制作 | Remotion（Node.js／TypeScript） | テンプレートに沿った動画の組み立て |
| AI 動画生成 | Runway API | 必要な場面だけ生成を依頼 |

## 動画の保存

動画ファイルそのものは videos テーブルに入れない。YouTube ID や、制作した動画の保存先を記録する。

- `videos.source_type` で `youtube`・`web`・`file`・`runway`・`remotion` を区別する。`web` は公式サイト・GitHub など（web_sources.md）、`file` は利用者がアップロードした個人のファイル（personal_resources.md）。
- 制作の作業（台本・場面・Runway の依頼・書き出し）は制作用の別テーブル（`video_productions` など。production_tables.md）に置く。

## 画面

### 画面は「一点から周囲を見る」形にする

最初から全ノードを一画面に描くと、増えたときに読みにくくなる。動画や概念の詳細画面に以下を表示する。

- 中央：選んだノードの説明と状態
- 左側：その知識の根拠となった動画・分析・会話
- 右側：関連概念、設計判断、実装（とコードの場所）
- 操作：関係の追加、候補の採用、誤った関係の修正、元データへの移動

例えば「入力画像の品質確認」を開くと、どの動画・分析から着想し、Runway の失敗防止機能のどこに実装したかが見える。全体グラフは、詳細画面が役立つと確認できてから加える（現状は Graphify の graph.html で代用）。

### 画面一覧

| 画面 | 内容 | 状況 |
|---|---|---|
| 情報源一覧・登録 | YouTube の動画・Web ページの URL と、個人のファイル（CSV）を登録し、重複を防ぐ。ジャンルで絞り込む | 済み |
| 情報源の詳細・分析 | 要約、分析履歴、関連ノード、分析に使った本文・データ。分析をジョブに投入し、進捗と結果を表示する | 済み |
| 知識一覧・詳細 | 提案されたノード・エッジを確認し、採用・修正・統合する | 済み |
| AI 使用量 | VideoManager が呼んだ AI のモデル別・機能別のトークン、推定費用、失敗件数 | 済み |
| 制作 | 台本、場面、素材、Runway・Remotion による動画の制作 | 済み |

### 検索

最初はタイトル・種類・関係・出典・コードのパスで探す（済み）。言い換え検索は pgvector で行う。埋め込みと検索の処理（`KnowledgeEmbedder::search`）はあるが、画面からはまだ使えない。

## AI 使用量のダッシュボードは二つ

| 見たいもの | 表示場所 | 指標 |
|---|---|---|
| **開発中の Codex・Claude・Gemini の使用量** | 各社の公式 usage dashboard、CLI の `/status` | 利用枠と残量 |
| **VideoManager が呼んだ AI の使用量** | VideoManager の AI 使用量画面（`ai_usage_logs`） | モデル別・機能別（分析、台本、embedding など）のトークン、推定費用、失敗件数 |

## 構築順

① Laravel＋Livewire＋PostgreSQL → ② 動画・分析テーブル → ③ AI 会話 → ④ 知識ノードとエッジ → ⑤ Graphify 連携 → ⑥ pgvector 検索

⑥ は埋め込みの作成と重複の候補（コマンド）まで済み。残りは画面での言い換え検索と、統合の画面での重複候補の表示。

## 最初の完成目標

「YouTube 動画を登録 → 分析 → 概念と関係を候補として表示 → 人が採用 → 概念画面から元動画と実装（コード）へ戻る」。この一連の操作で、Obsidian に求めていた知識の蓄積方法を Laravel 上で検証する。
