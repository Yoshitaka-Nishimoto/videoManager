# Remotion のテーブル設計

Remotion で場面の確認用の静止画と、動画（確認用・完成版）を書き出す記録の設計。
制作案と場面は `production_tables.md`、場面の内容の形式は `scene_content.md` を参照。

## Runway との違い

| 項目 | Runway | Remotion |
|---|---|---|
| 正体 | 外部の API サービス | 手元で動かす React のプログラム |
| 結果 | 生成のたびに変わる | 同じコードと同じ入力なら毎回同じ |
| 費用 | 秒単位の課金 | かからない（自分の PC の処理時間だけ） |
| 記録すべきもの | 依頼、相手のタスクID、失敗コード、出力の保存先 | **どのコードの版に、どの入力を渡したか**、書き出しの設定、出力の保存先 |

Remotion は「同じコード＋同じ入力＝同じ結果」なので、書き出しごとに**入力（input props）の写し**と**Remotion のコードの版**を残せば、後から同じ動画を作り直せる。

## 設計の前提（Remotion の仕様）

| 仕様 | 設計への影響 |
|---|---|
| 動画は「コンポジション」（ID、幅、高さ、fps、長さ（フレーム数））として定義する | 場面の種類ごとにコンポジションを作るのではなく、全体用と場面用の 2 つだけにする（下記） |
| コンポジションには JSON の入力（input props）を渡せる | 制作案と場面の内容を JSON にして渡す。その写しを書き出しの行に残す |
| `calculateMetadata` で、入力から長さ・fps・幅・高さを決められる | 長さは場面の秒数の合計から計算する。DB は秒、Remotion はフレームで扱う |
| `renderMedia()` は動画、`renderStill()` は 1 フレームの静止画を書き出す | 書き出しの種類に still（静止画）を加える |
| `renderMedia()` の `onProgress` で、進捗率・書き出したフレーム数・エンコード済みフレーム数・段階（encoding / muxing）が取れる | 進捗を記録する列を持つ |
| 素材は `public` フォルダのファイルか URL で読み込む | Laravel に保存した素材を、書き出しの前に URL か `public` フォルダのパスに変換して入力に入れる |
| 書き出しの設定：codec（h264 など）、crf（画質）、scale（倍率）、image format（jpeg / png）など | 列として残す |

## コンポジションの構成（Remotion のプロジェクト側）

| コンポジションID | 内容 | 入力 | 使い道 |
|---|---|---|---|
| `Production` | 制作案の全場面を順に並べた動画 | 制作案全体（settings と再生する場面の一覧） | 確認用（preview）と完成版（final）の書き出し |
| `Scene` | 1 つの場面だけ | 場面 1 つと settings | 場面の確認用の静止画（still） |

どちらも、場面の種類キー（`remotion.title` など）から React のコンポーネントを選ぶ同じ対応表を使う。場面の種類を追加しても、コンポジションを増やす必要はない。

**コンポジションはテーブルにしない。** コンポジションはコードそのものなので、DB に一覧を持つと二重管理になる。Laravel 側は `config/remotion.php` に、プロジェクトの場所、コンポジションID、fps などを書くだけにする。

## テーブル：production_renders を Remotion の書き出し記録にする

新しいテーブルは作らず、既存の `production_renders` に列を追加する（`production_renders` はもともと Remotion の書き出しのためのテーブル）。
すでに GitHub に push 済みなので、元のマイグレーションは書き換えず、列を追加するマイグレーションを新しく作る。

### 追加する列

| 列 | 型 | 内容 |
|---|---|---|
| production_scene_id | FK production_scenes, null | 静止画を書き出した場面（kind = still のとき） |
| composition_id | string(64) | コンポジションID（Production / Scene） |
| input_props | json | Remotion に渡した入力の写し（素材は URL に変換した後のもの） |
| code_version | string(64), null | Remotion のコードの版（Git のコミット ID） |
| remotion_version | string(32), null | Remotion のバージョン |
| codec | string(16), null | 動画の形式（h264 / h265 / vp9 など）。静止画では null |
| image_format | string(8), null | フレームの画像形式（jpeg / png / webp） |
| width / height | unsigned smallint, null | 縦横の画素数 |
| fps | unsigned tinyint, null | 1 秒あたりのフレーム数 |
| duration_in_frames | unsigned int, null | 長さ（フレーム数） |
| frame | unsigned int, null | 静止画にしたフレーム（kind = still のとき） |
| scale | decimal(4,2), null | 書き出しの倍率（確認用は 0.5 など） |
| crf | unsigned tinyint, null | 画質（小さいほど高画質） |
| stage | string(16), null | 進行中の段階（bundling / rendering / encoding / muxing） |
| rendered_frames | unsigned int, null | 画像にしたフレーム数 |
| encoded_frames | unsigned int, null | エンコードしたフレーム数 |
| mime_type | string(64), null | 出力の MIME タイプ |
| size_bytes | unsigned bigint, null | 出力のファイルサイズ |
| render_ms | unsigned int, null | 書き出しにかかった時間（ミリ秒） |
| log | text, null | 失敗したときの Remotion の出力の末尾 |

### 変更する列

| 列 | 変更 |
|---|---|
| kind | 値に `still` を追加（still / preview / final） |
| production_plan_id | 変更なし。still でも、どの版の場面かを残すため入れる |

### 書き出しの種類

| kind | コンポジション | 出力 | 主な設定 | 結果の使い道 |
|---|---|---|---|---|
| still | Scene | 静止画（png） | frame は場面の中ほど | 場面の `preview_path` に保存先を入れる。制作案の画面でモックとして表示 |
| preview | Production | 動画（h264） | scale 0.5、crf 高め（低画質・速い） | 制作案の画面で通しの確認 |
| final | Production | 動画（h264） | scale 1、crf 標準 | videos に `source_type = remotion` で登録し、知識に「実現する」でつなぐ |

### 状態の移り変わり

```
queued → running（stage：bundling → rendering → encoding → muxing）→ completed
                                                                  → failed（log に出力の末尾）
```

- 書き出しをやり直すときは、新しい行を作る（同じ入力なら同じ結果になるので、やり直すのは素材やコードを直したとき）。
- Runway の生成物を使う場面がある場合、その場面が `ready`（生成物を選択済み）になるまで preview / final は書き出せない。

## 素材の受け渡し

| 素材 | Remotion への渡し方 |
|---|---|
| 人が用意した素材（production_assets） | 書き出しの前に、Remotion の `public` フォルダの作業用ディレクトリへコピーし、そのパスを入力に入れる |
| Runway の生成物（場面の selected_output） | 同上 |
| 書体（Google Fonts） | Remotion 側の `@remotion/google-fonts` で読み込む（入力には書体名だけ） |

`input_props` に残すのは、変換した後のパス。`asset_id` や `scene_key` は場面の `content` 側に残っているので、両方からたどれる。

## 実行のしかた

| 方法 | 内容 | 判断 |
|---|---|---|
| CLI（`npx remotion render`） | Laravel のジョブからコマンドを実行する | 進捗が機械で読める形で出ないため、進捗の記録には向かない |
| **Node のスクリプト（推奨）** | `renderMedia()` / `renderStill()` を呼ぶ小さなスクリプトを Remotion のプロジェクトに置き、進捗を 1 行ずつ JSON で出力する。Laravel のジョブがそれを読んで `production_renders` を更新する | 進捗・失敗の内容を正確に記録できる |

## 後で決めること

| 項目 | 選択肢 |
|---|---|
| Remotion のプロジェクトの置き場所 | このリポジトリの `remotion/` フォルダ（推奨：Laravel と同じコミットで版を管理でき、`code_version` がそのまま使える）／別のリポジトリ |
| 書き出しを動かす場所 | Sail のコンテナ内（Chrome の動作に必要なライブラリの追加が必要）／WSL 上で直接 |
| fps | 30 で固定（推奨）か、制作ごとに選ぶか |
| ライセンス | 個人と従業員 3 人以下の会社は無料、それ以上は有料のライセンスが必要（公式のライセンスのページで最新の条件を確認する） |

## 出典

- [Remotion ドキュメント](https://www.remotion.dev/docs/)
- [Remotion 用語](https://www.remotion.dev/docs/terminology)
- [renderMedia()](https://www.remotion.dev/docs/renderer/render-media)
- [renderStill()](https://www.remotion.dev/docs/renderer/render-still)
- [npx remotion render](https://www.remotion.dev/docs/cli/render)
- [calculateMetadata](https://www.remotion.dev/docs/calculate-metadata)
- [Remotion ライセンス](https://www.remotion.dev/docs/license)
