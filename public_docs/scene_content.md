# 場面の content の形式

制作案の場面（production_scenes）に保存する内容の形式。
Gemini に出力させる形式と同じにし、受け取った JSON をそのまま検証して保存する。

## 方針

| 方針 | 理由 |
|---|---|
| 場面の種類ごとに、決まった項目だけを持つ | Remotion のコンポーネントの props にそのまま渡せる |
| Gemini には座標や色の数値を決めさせない | 図の配置や色は Remotion 側で自動で決める。Gemini は「何をどの順で見せるか」だけを書く |
| 場面どうしの参照は `key`（例：`s3`）で書く | 場面の順番を入れ替えても参照が壊れない。版を複製しても `key` は引き継ぐ |
| 費用は Gemini に計算させない | モデルと長さからアプリ側で計算する |
| Gemini の出力は、種類ごとの項目を 1 つのオブジェクトにまとめる | Gemini の構造化出力（VideoAnalyst と同じ `HasStructuredOutput`）で扱えるよう、`anyOf` を使わない。保存時に `scene_type` に合う部分だけを取り出す |

## 場面の 2 つの置き場所（track）

| track | 内容 | 使える種類 |
|---|---|---|
| `video` | 順番に並んで再生される場面 | `remotion.*`、`runway.image_to_video`、`runway.text_to_video`、`runway.video_to_video`、`runway.avatar` |
| `material` | 再生されない素材の生成。他の場面から `key` で参照する | `runway.image`（静止画）、`runway.sound`（BGM・効果音） |

ナレーション（`runway.speech`）は場面にせず、各場面の `narration` から自動で生成する。

## 素材の参照（source）

画像・動画・音声を使う項目は、次のどちらか 1 つで指定する。

```json
{ "asset_id": 12 }          // 人が用意した素材（production_assets の ID）
{ "scene_key": "m1" }       // 別の場面の生成物（runway の場面、または material）
```

## Gemini の出力全体

```json
{
  "summary": "構成の概要（2〜3 文）",
  "style": {
    "tone": "落ち着いた解説",
    "font": "Noto Sans JP",
    "theme": "light"
  },
  "narration": {
    "enabled": true,
    "voice": "落ち着いた女性の声"
  },
  "bgm": { "source": { "scene_key": "m2" }, "volume": 0.2 },
  "scenes": [
    {
      "key": "s1",
      "track": "video",
      "scene_type": "remotion.title",
      "title": "導入",
      "duration_seconds": 4,
      "narration": "生成の前に、入力画像を確認します。",
      "caption": null,
      "transition": "fade",
      "content": { "title": { "heading": "入力画像の品質確認", "subheading": "Runway の失敗を減らす" } }
    }
  ]
}
```

| 項目 | 内容 |
|---|---|
| `summary` | 構成の概要 → production_plans.summary |
| `style` | 全体の見た目 → production_plans.settings.style |
| `narration` | ナレーションを付けるか、声の希望 → production_plans.settings.narration |
| `bgm` | BGM の素材と音量（使わないときは null）→ production_plans.settings.bgm |
| `scenes[]` | 場面 → production_scenes の各行 |

## 場面の共通項目

| 項目 | 型 | 内容 |
|---|---|---|
| `key` | string | 制作案の中で一意。`video` は `s1`, `s2` …、`material` は `m1`, `m2` … |
| `track` | `video` / `material` | 置き場所 |
| `scene_type` | string | 場面の種類キー |
| `title` | string | 場面の名前（画面には出さない。人が確認するため） |
| `duration_seconds` | number | 長さ（秒）。`material` は 0 |
| `narration` | string / null | ナレーションの文言。長さの目安は 1 秒あたり 6〜7 文字 |
| `caption` | string / null | 字幕。null ならナレーションを字幕に使う |
| `transition` | `fade` / `slide` / `wipe` / `none` | 次の場面への切り替え |
| `content` | object | 種類ごとの内容（下記）。`scene_type` に対応する 1 つだけを書く |

## Remotion の場面の content

### remotion.title（タイトル・見出し）

```json
{ "title": { "heading": "入力画像の品質確認", "subheading": "Runway の失敗を減らす", "layout": "center" } }
```

| 項目 | 内容 |
|---|---|
| `heading` | 見出し（30 文字以内） |
| `subheading` | 副題（null 可） |
| `layout` | `center`（中央）/ `lower_third`（画面下部の帯） |

### remotion.text（箇条書き・説明文）

```json
{ "text": { "heading": "確認する 3 つの点", "items": ["解像度", "顔の向き", "明るさ"], "reveal": "one_by_one" } }
```

| 項目 | 内容 |
|---|---|
| `heading` | 見出し（null 可） |
| `items` | 表示する文（1〜6 個、各 40 文字以内） |
| `reveal` | `all`（一度に表示）/ `one_by_one`（1 つずつ表示） |

### remotion.diagram（図解）

```json
{
  "diagram": {
    "layout": "horizontal",
    "nodes": [
      { "id": "a", "label": "画像を受け取る" },
      { "id": "b", "label": "品質を確認", "emphasis": true },
      { "id": "c", "label": "Runway で生成" },
      { "id": "d", "label": "撮り直しを依頼" }
    ],
    "edges": [
      { "from": "a", "to": "b" },
      { "from": "b", "to": "c", "label": "合格" },
      { "from": "b", "to": "d", "label": "不合格" }
    ],
    "reveal_order": ["a", "b", "c", "d"]
  }
}
```

| 項目 | 内容 |
|---|---|
| `layout` | `horizontal`（左→右）/ `vertical`（上→下）/ `cycle`（循環）/ `tree`（階層） |
| `nodes` | 箱（2〜8 個）。`label` は 15 文字以内。`emphasis` で強調 |
| `edges` | 矢印。`from`・`to` は `nodes` の `id`。`label` は null 可 |
| `reveal_order` | 表示する順番（ノードの `id`）。null なら一度に表示 |

座標は Remotion 側で自動配置する。

### remotion.chart（グラフ）

```json
{
  "chart": {
    "chart_type": "bar",
    "title": "生成の失敗率",
    "unit": "%",
    "labels": ["確認なし", "確認あり"],
    "series": [{ "name": "失敗率", "values": [32, 8] }]
  }
}
```

| 項目 | 内容 |
|---|---|
| `chart_type` | `bar` / `line` / `pie` |
| `labels` | 横軸の項目（`pie` では区分） |
| `series` | 系列。`values` の数は `labels` と同じ。`pie` は系列 1 つ |
| `unit` | 単位（null 可） |

数値は設計判断や根拠に書かれているものだけを使う。Gemini が数値を作らないよう、指示に書く。

### remotion.code（コード）

```json
{ "code": { "language": "php", "code": "if ($image->width < 640) { ... }", "highlight_lines": [1], "caption": "幅が足りない画像は止める" } }
```

| 項目 | 内容 |
|---|---|
| `language` | 言語（php / typescript / python など） |
| `code` | 表示するコード（20 行以内） |
| `highlight_lines` | 強調する行（1 始まり） |
| `caption` | 補足（null 可） |

### remotion.image（静止画）

```json
{ "image": { "source": { "asset_id": 12 }, "motion": "zoom_in" } }
```

| 項目 | 内容 |
|---|---|
| `source` | 画像（素材の参照） |
| `motion` | `none` / `zoom_in` / `zoom_out` / `pan_left` / `pan_right` |

### remotion.clip（動画の切り出し）

```json
{ "clip": { "source": { "asset_id": 15 }, "start_seconds": 3, "end_seconds": 9, "muted": true } }
```

| 項目 | 内容 |
|---|---|
| `source` | 動画（素材の参照）。Runway で作った場面も指定できる |
| `start_seconds` / `end_seconds` | 使う範囲（秒）。長さは場面の `duration_seconds` に合わせる |
| `muted` | 元の音を消すか |

### remotion.split（画面分割）

```json
{ "split": { "layout": "left_right", "sources": [{ "asset_id": 15 }, { "scene_key": "s4" }], "labels": ["修正前", "修正後"] } }
```

| 項目 | 内容 |
|---|---|
| `layout` | `left_right` / `top_bottom` / `pip`（小窓） |
| `sources` | 2 つの素材 |
| `labels` | 各画面の見出し（null 可） |

## Runway の場面の content

Runway の場面では、`content` は生成の指定になる。保存するときに、`prompt` は production_scenes.prompt_text に、それ以外は generation_options に入れる。

| 種類 | content | 主な制約 |
|---|---|---|
| `runway.image_to_video` | `{ "prompt": "動きの指示（英語）", "image": 素材の参照, "model": "gen4_turbo", "duration": 5 }` | `model` は gen4_turbo / gen4.5。`duration` は 5 / 10 |
| `runway.text_to_video` | `{ "prompt": "映像の説明（英語）", "model": "gen4.5", "duration": 5 }` | `model` は gen4.5 / seedance2_5 |
| `runway.video_to_video` | `{ "prompt": "変更の指示（英語）", "video": 素材の参照, "model": "aleph2" }` | 元の動画は 2〜30 秒 |
| `runway.avatar` | `{ "script": "台詞", "image": 素材の参照, "voice": "声の希望" }` | `image` は人物の正面の画像 |
| `runway.image`（material） | `{ "prompt": "画像の説明（英語）", "reference": 素材の参照 または null }` | 文字を含む画像は作らない |
| `runway.sound`（material） | `{ "prompt": "音の説明（英語）", "duration": 30 }` | BGM・効果音 |

- Runway への指示文（`prompt`）は英語で書かせる。画面に出す文言とナレーションは日本語で書かせる。
- 比率は制作（video_productions.ratio）に合わせ、場面ごとには指定しない。
- 場面の `duration_seconds` は、生成する長さ以下にする（例：5 秒生成して 4 秒使う）。

## 保存前の検証（アプリ側）

Gemini の出力は、保存する前に次を確認する。違反があれば、その制作案を `failed` にして理由を記録する。

| 検証 | 内容 |
|---|---|
| 種類 | `scene_type` が一覧にあり、`track` と合っている |
| 内容 | `content` に `scene_type` に対応する項目があり、必須の項目がそろっている |
| 参照 | `scene_key` が同じ制作案にあり、参照先が正しい種類（画像を求める項目に画像）を作る。`asset_id` がこの制作の素材にある |
| 図 | `edges` の `from`・`to` と `reveal_order` が `nodes` の `id` にある |
| 長さ | Runway の場面は生成する長さ以下。合計が目標の長さの ±20% 以内（外れたら警告だけ） |
| 文字数 | 見出し・箇条書き・ラベルの上限を超えたら切り詰める |
| 費用 | モデルと長さから見積もりを計算し、場面と制作案に記録する |

## 決まったことによる設計書の変更

| 設計書 | 変更 |
|---|---|
| production_tables.md | production_scenes に `scene_key`（string(16)、制作案の中で一意）と `track`（video / material）を追加。production_plans に `settings`（json：style、narration、bgm）を追加 |
| video_capabilities.md | `runway.image` と `runway.sound` は material、`runway.speech` は各場面の narration から自動で作ることを追記 |
