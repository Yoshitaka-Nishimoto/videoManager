# Web ページの情報源（公式サイト・GitHub）

YouTube の動画だけでは知識の材料として弱いため、公式サイト・ドキュメント・GitHub などの Web ページも「情報源」として登録し、動画と同じ流れで分析する。
メニューの「動画」は「情報源」に改めた（URL は `/videos` のまま）。

## 方針

- **テーブルは分けない。** `videos.source_type = web` として同じテーブルに登録する。分析の版（`video_analyses`）、分析のジョブ、知識の候補づくり（`KnowledgeCandidateWriter`）、出典（`knowledge_sources.video_id`）、ジャンル、知識一覧の絞り込みをそのまま使う。
- **本文はこちらで取得して AI に渡す。** Gemini の URL 読み取り（`url_context`）には任せない。何を読んで分析したかを分析の版ごとに保存し、知識の根拠に戻れるようにするため。
- **取得できないページは本文を貼り付ける。** ログインが必要なページ（ChatGPT・Claude・Gemini などの AI モデルとの会話）や JavaScript で本文を表示するページは、利用者がページから写した本文を貼り付けて分析する（下の「本文の貼り付け」）。
- **後回し**：PDF。登録・分析のときにエラーで知らせる。

## 登録（`RegisterWebPage`）

情報源一覧の登録欄に URL を貼る。YouTube の動画 URL は動画として、それ以外は Web ページとして登録する（YouTube のチャンネルなど、動画でない YouTube の URL はエラー）。

| 列 | Web ページでの内容 |
|---|---|
| source_type | `web` |
| url | そろえた URL（スキーム・ホストを小文字、`#` 以降と末尾の `/` を除く）。同じ URL は二重に登録しない。削除済みなら復元する |
| title | `og:title`、なければ `<title>`、なければホスト名 |
| channel_title | サイト名（`og:site_name`、なければホスト名） |
| description | `og:description`、なければ `description` |
| thumbnail_url | `og:image`（相対 URL は絶対 URL にする） |
| published_at | `article:published_time` |
| youtube_id・duration_seconds・統計 | 空 |

## 本文の取得（`WebPageClient::content`）

| ページ | 取り方 |
|---|---|
| 一般のページ | HTML から `script`・`nav`・`header`・`footer`・`aside` などを除き、`main`・`article`（なければ `body`）の文章を取る。見出しは `#`、箇条書きは `-` を付けて構造を残す |
| GitHub のリポジトリ（`/owner/repo`、`/tree/<ブランチ>`） | README を GitHub API（`api.github.com/repos/{owner}/{repo}/readme`）で取る。埋め込みの HTML タグ（ロゴ・バッジ）は除く |
| GitHub の Markdown ファイル（`/blob/…/*.md`） | `raw.githubusercontent.com` の生のファイル |

- 文字コードは UTF-8 にそろえる（Shift_JIS・EUC-JP のサイトがあるため）。
- 本文は最大 60,000 文字。超えた分は切り、AI にもその旨を伝える。
- 本文が 50 文字未満なら「読み取れない」としてエラーにする。

## 分析（`GeminiWebPageAnalyzer`・`WebPageAnalyst`）

`SourceTypeAnalyzer` が情報源の種類で振り分ける（YouTube は `GeminiVideoAnalyzer`、Web ページは `GeminiWebPageAnalyzer`）。モデルの切り替え（`GeminiModels`）と AI 使用量の記録は動画と同じ。

返す形は動画と同じ（要約・話題・要点・概念）。違いは要点で、**時刻の代わりに本文からの引用（quote）**を持つ。

| 要点の項目 | 動画 | Web ページ |
|---|---|---|
| start_seconds・end_seconds | 該当時刻 | 空 |
| point | 要点（日本語） | 要点（日本語） |
| quote | — | 本文の一節をそのまま（原文の言語のまま）。**本文に見つからない引用は空にする**（根拠に戻れないため） |

## 保存（`video_analyses` に追加した列）

| 列 | 内容 |
|---|---|
| source_url | 本文を取った URL（GitHub は README の API） |
| source_text | 分析に使った本文 |
| source_fetched_at | 本文の取得日時 |

- `prompt` には本文を含めない（`source_text` と二重になるため）。
- 概念の出典（`knowledge_sources.excerpt`）には「要点＋改行＋「引用」」を入れる。`start_seconds` は空。

## 画面

| 画面 | Web ページでの表示 |
|---|---|
| 情報源一覧 | カードに「Web」とサイト名。再生時間は出さない |
| 情報源の詳細 | 種類・サイト・説明・URL。要点には引用を出し、押すとページのその箇所を開く（テキストフラグメント `#:~:text=`。ブラウザが対応している場合）。「分析に使った本文」で、取得元・日時・本文を確認できる |
| 知識 | 種類の絞り込みの「動画」を「情報源」に改めた。各ノードは「動画」「Web」で表示する |

## 本文の貼り付け（`SavePastedText`）

ログインが必要なページ（例：`https://chatgpt.com/c/…`）は、こちらからはログイン画面しか取れない。ブラウザで本文を表示しても、本人のログイン情報がなければ開けないため、ヘッドレスブラウザでは解決しない。そこで、利用者がページを開いて本文をコピーし、情報源の詳細の「本文を貼り付ける」に貼り付ける。

| 項目 | 内容 |
|---|---|
| 登録 | 401・403 で開けないページも、題名・サイト名をホスト名にして登録する（取得できないので）。404・PDF などは今までどおりエラー |
| 保存先 | `videos.pasted_text`（本文）・`pasted_text_updated_at`（貼り付けた日時）。50〜300,000 文字。AI に渡すのは先頭の 60,000 文字まで |
| 題名 | 取得できないページは「ChatGPT」「claude.ai」などになるので、同じ欄で直せる。直した題名は情報源のノードにもそろえ、変更履歴（`knowledge_revisions`）に残す |
| 分析 | 貼り付けた本文があれば URL から取得せず、それを本文にする（`GeminiWebPageAnalyzer`）。`source_text` に版ごとに残し、`source_url` は空。プロンプトに「利用者が貼り付けた文章」と伝える |
| AI との会話 | `WebPageAnalyst` の指示で、質問と回答を区別し、AI の回答は「AI は〜と説明している」と書き、根拠のない主張の確信度を低めにする。途中で訂正された内容は訂正後を採る |
| 消す | 「本文を消す」で、URL から取得する方法に戻す。これまでの分析の版に残した本文は消えない |
| 画像 | 写した本文には、画像が「xxxxx.png」のようなファイル名（画像の alt）だけで残る。保存するたびに元のページの `<img>` を読み、名前（alt か URL のファイル名。`.png` などの形のものだけ）が本文に出てくる画像について「名前 → 画像の URL」を `videos.pasted_images` に残す。本文の表示（貼り付けた本文・分析に使った本文）では、名前だけの行を画像にする（`<x-source-text>`）。画像はコピーせず元のサイトから表示するので、元のページで画像が消えると表示されない。ページを開けないとき（AI との会話など）は画像なし。AI には画像を渡さない（名前だけ） |

共有の情報源なので、貼り付けた本文は全員が見られる。個人的な会話は、本人だけのものにしたいなら個人のファイル（personal_resources.md）の扱いを広げる必要がある（未対応）。
