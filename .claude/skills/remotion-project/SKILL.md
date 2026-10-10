---
name: remotion-project
description: VideoManager の remotion/ で Remotion のコードを書く・書き出す・確認するときに、remotion-best-practices より先に読む。このプロジェクトでの実行場所（Sail のコンテナ）、書き出し（scripts/render.mjs と production_renders）、場面の部品の追加方法、公式の skill と違う決まりをまとめる。
---

# VideoManager の Remotion

Remotion の一般的な書き方は `remotion-best-practices` に従う。ただし、このプロジェクトでは次の決まりを優先する。

設計の詳細：`public_docs/remotion_tables.md`（書き出しの記録）、`public_docs/scene_content.md`（場面の content の形式）、`public_docs/production_tables.md`（制作案と場面）。

## 公式の skill と違う点

| 公式の skill の指示 | このプロジェクト |
|---|---|
| 作業の前に Studio を起動してブラウザで開く | 用途で分ける（下記「Studio の使い分け」）。Claude が自分で確かめるときは、静止画を書き出して画像を Read で見る |
| 書き出しは `npx remotion render` | Laravel のジョブから `remotion/scripts/render.mjs` で書き出し、`production_renders` に記録する。CLI での書き出しは使わない |
| ナレーションは ElevenLabs を勧め、API キーを聞く | **勧めない。** ナレーションは Runway で各場面の `narration` から作る設計（`public_docs/video_capabilities.md`） |
| `npx create-video` で新しいプロジェクトを作る | 作らない。プロジェクトは `remotion/` の 1 つだけ |
| `npx remotion add ...` などをそのまま実行 | コンテナ内で sail ユーザーとして実行する（下記） |

## 実行のしかた

コマンドはすべて Sail のコンテナ内で、**sail ユーザー**で実行する（root で実行すると node_modules や out/ が root の所有になり、キューのワーカーが扱えなくなる）。

```bash
# WSL のプロジェクトのルートで
docker compose exec -T -u sail laravel.test bash -c 'cd remotion && <コマンド>'
```

| 目的 | コマンド（remotion/ の中で） |
|---|---|
| パッケージの追加 | `npx remotion add @remotion/<名前>`（バージョンは remotion と同じに固定される） |
| 型チェック | `npx tsc --noEmit` |
| 静止画で確認 | `node scripts/render.mjs fixtures/still-job.json` → `out/still-s1.png` |
| 動画で確認 | `node scripts/render.mjs fixtures/preview-job.json` → `out/preview.mp4` |

- `package.json` のバージョンは固定（`^` を付けない）。`@remotion/*` はすべて同じバージョンにする。
- `out/`、`build/`、`node_modules/` は git の管理外。

## Studio の使い分け

**Studio では書き出さない。Studio での操作はすべて破棄する。** 内容は DB（production_scenes）が正で、確認済みの版は直さず、書き出しはすべて Laravel から行い production_renders に記録する。

| 用途 | 使う Studio | 開き方 |
|---|---|---|
| **制作した動画の確認** | 読み取り専用の Studio（`npm run bundle` で作る静的な Studio。Laravel が `/remotion-studio` でログインした人に配信） | 制作の画面の「Studio で確認」。URL は `/remotion-studio/plans/{制作案ID}/{場面キー（省略可）}/?/Production`（`?/Scene` で場面だけ） |
| **部品の開発** | 普通の Studio（`npm run studio -- --port=3000 --no-open`、コンテナのポート 3000 を 127.0.0.1 にだけ公開） | http://localhost:3000 。見本の入力（`fixtures/sample.json`）で表示する |

読み取り専用の Studio：
- 書き出しもコードへの書き戻しもできない（Remotion がサーバーなしの読み取り専用で動かす）。入力を変えてもページを閉じれば消える。
- 「Render in browser」（ブラウザ内での書き出し）は残っているため、Laravel が「Render」を含むボタンとメニューを隠すスクリプトを埋め込む（`RemotionStudioController`）。
- 制作案の入力は Laravel がページに埋め込み、`src/studio-input.ts` → `Root.tsx` の calculateMetadata が使う。
- 部品のコードを変えたら `npm run bundle` で作り直す（作り直すまで古い部品のまま）。

普通の Studio（部品の開発）のルール：
1. **書き出しボタン（Render）を押さない。** 確認用の書き出しが要るときは `node scripts/render.mjs fixtures/...` を使う。
2. **入力の編集（Props editor）を保存しない。** 書き戻されないよう、`<Composition>` の `defaultProps` は直接書かず変数にしておく（Root.tsx）。
3. **画面上の見た目の編集（コードへの書き戻し）を使わない。** 見た目は部品のコードで直す。
4. 終わったら `git status remotion` で、意図しない変更（Studio による書き戻し）がないことを確かめる。あれば `git restore` で戻す。
5. 部品を直したらコミットしてから Laravel で書き出す（未コミットだと code_version が `-dirty` になる）。

## 構成

| 場所 | 役割 |
|---|---|
| `src/Root.tsx` | コンポジションは `Production`（制作案の全場面）と `Scene`（場面 1 つ）の 2 つだけ。ID は `config/remotion.php` と `ProductionRender::COMPOSITION_*` に合わせる |
| `src/scenes/registry.tsx` | 場面の種類キー（`remotion.title` など）→ 部品の対応表。**種類を増やしてもコンポジションは増やさない** |
| `src/scenes/content.ts` | `contentOf()`：content から種類ごとの部分（`{ "title": {...} }` の中身）を取り出す |
| `src/scenes/SceneFrame.tsx` | 全場面に共通の枠（背景、書体、字幕、フェード） |
| `src/theme.ts` | 書体（Noto Sans JP、`@remotion/google-fonts`）と配色（light / dark） |
| `scripts/render.mjs` | Laravel から呼ぶ書き出し。進捗・完了・失敗を 1 行ずつ JSON で出力し、失敗時は終了コード 1 |
| `fixtures/` | Studio 用の見本の制作案（`sample.json`）と、still / preview の見本のジョブ |

## 場面の部品を追加するとき

1. `public_docs/scene_content.md` で、その種類の content の項目を確認する。
2. `src/scenes/<名前>Scene.tsx` を作り、`SceneFrame` で包む。content は `contentOf<型>(scene)` で読み、項目がなくても落ちないよう既定値を置く。
3. `registry.tsx` の対応表に追加する。
4. 座標や色は部品の中で決める（Gemini には決めさせない設計）。
5. `fixtures/sample.json` にその種類の場面を足し、静止画を書き出して画像を確認する。

## Laravel 側

| 場所 | 役割 |
|---|---|
| `app/Actions/Productions/RequestProductionRender.php` | 書き出しの依頼。production_renders の行を作り、ジョブをキューに入れる |
| `app/Jobs/RenderProduction.php` | `render.mjs` を実行して進捗と結果を記録する。キューの接続は `remotion`（専用のワーカー：`queue:work remotion --tries=1 --timeout=1900`） |
| `app/Services/Remotion/RemotionInput.php` | 制作案と場面から入力（input props）を作る |
| `resources/views/pages/productions/` | 制作の一覧と詳細（静止画・動画の書き出しボタン、履歴、再生） |
| `ProductionRenderFileController` | 書き出したファイルを返す（`production-renders/{render}/file`） |

## 書き出しの記録との対応

`render.mjs` の `done` の値は `production_renders` の列に入れる：`sizeBytes` → `size_bytes`、`renderMs` → `render_ms`、`width` / `height`（倍率を掛けた実際の大きさ）、`fps`、`durationInFrames` → `duration_in_frames`、`frame`、`remotionVersion` → `remotion_version`。`progress` の `stage` / `progress` / `renderedFrames` / `encodedFrames` も同名の列に入れる。

## 公式の skill の更新

`.claude/skills/remotion-best-practices/` は `remotion-dev/skills` をそのままコピーしたもの（中身は書き換えない）。Remotion のバージョンを上げたときは、同じバージョンの skills を取得して上書きする。

```bash
git clone --depth 1 https://github.com/remotion-dev/skills /tmp/remotion-skills
rm -rf .claude/skills/remotion-best-practices
cp -r /tmp/remotion-skills/skills/remotion-best-practices .claude/skills/
```

コピーした版：remotion-dev/skills `32b241b`（Remotion 4.0.534）。
