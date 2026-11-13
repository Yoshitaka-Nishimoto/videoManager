# 情報源の表紙（静止画）

情報源の詳細（`/videos/{id}`）の「表紙（静止画）」の欄から、その情報源の表紙の画像（1920×1080 の PNG）を作る。
描くのは remotion/src の部品 `remotion.cover`（動画の場面と同じ Remotion の部品・書体・配色）。描く場所は **Windows ネイティブの静止画のサーバー**（`remotion/scripts/still-server-windows.mjs`）。この PC では WSL 内のバンドルで CPU のハードウェアエラーが起きるため（`config/remotion.php` の still_driver を参照）。

## 流れ

1. 画面で見出し・副題を確かめる（初めは題名とサイト名・チャンネル名。作ったあとは前の表紙の見出し・副題）。
2. 「表紙（静止画）を作成」を押すと、`CreateVideoCover` が入力を作り、`WindowsStillClient` で `POST /still` に送る（終わるまで画面で待つ。数秒）。
3. 返ってきた画像を `config('remotion.disk')` の `videos/covers/{id}.png` に保存し、`videos.cover_*` を更新する。作り直すと上書きする（履歴は残さない）。
4. 画像は `GET /videos/{id}/cover`（`VideoCoverController`、ログインが必要）で表示する。`?download=1` で保存用。本人だけの情報源は、他の人には 404。

情報源一覧のカードの画像は、表紙があれば表紙、なければ元のサムネイル（`thumbnail_url`）を出す。表紙の URL には作った日時（`?v=`）を付ける（`Video::coverUrl()`）。作り直すと URL が変わるので、`?v=` 付きの応答はブラウザに長くキャッシュさせる。

サーバーが動いていないときは、画面に「Windows の PowerShell で remotion フォルダから node scripts\still-server-windows.mjs を起動してください」と出る。準備は `remotion/windows/README.md`。

## 表紙の内容（remotion.cover の content）

```json
{ "cover": { "heading": "投資信託のチャートを見る", "subheading": "chatgpt.com", "label": "Web ・ 投資", "topics": ["銘柄検索", "チャート"], "footer": "2026-11-13" } }
```

| 項目 | 内容 |
|---|---|
| `heading` | 見出し（必須、100 文字まで）。画面で直せる。長さで文字の大きさを変え、4 行を超える分は省く |
| `subheading` | 副題（80 文字まで、空なら出さない）。画面で直せる |
| `label` | 左上のラベル。種類（動画 / Web / ファイル）とジャンル |
| `topics` | 最新の完了した分析の `topics`（最大 5 個） |
| `footer` | 右下。公開日（ファイルは登録日） |

- 動きはない（どのフレームでも同じ絵）。字幕・フェードアウトは出さない。
- 制作案の場面の種類としては使わない（Gemini に選ばせない）。`scene_content.md` の一覧にも載せない。

## 列（videos）

| 列 | 型 | 内容 |
|---|---|---|
| cover_path | string, null | 表紙の画像の保存先（`config('remotion.disk')` の中のパス） |
| cover_content | json, null | 表紙を描いた内容（上の content の中身） |
| cover_created_at | timestamp, null | 表紙を作った日時 |
