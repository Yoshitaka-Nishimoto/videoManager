# Windows ネイティブでの静止画書き出し

`remotion/scripts/still-windows.mjs` を **Windows の Node** で動かし、場面の静止画（PNG）を書き出す。
DB（`production_renders`）には記録しない、手元確認用の独立したプログラム。

Laravel から呼ぶ本番の書き出し（`scripts/render.mjs`、Sail コンテナ内）とは別物。
コンテナでの書き出しは webpack のバンドルが CPU 全コアに負荷をかけ、この PC では WSL が落ちる原因になったため、
確認用の静止画は Windows ネイティブでこのスクリプトを使う。

## 依存の置き場所（重要）

`@remotion/renderer`・`@remotion/bundler`・`react` は OS 固有のネイティブバイナリ（Compositor、swc、
Chrome Headless Shell）を含む。`remotion/node_modules` は Sail（Linux）で入れたものなので Windows では使えない。

**依存は Windows ローカルのフォルダに入れる。** npm は `\\wsl.localhost\`（WSL のファイルシステム）へは
インストールできない（arborist の realpath 解決が無限再帰して `Maximum call stack size exceeded` で落ちる）。
そのため、この `windows/package.json` を正本とし、`setup.ps1` が Windows ローカル
（既定 `%LOCALAPPDATA%\video-manager-remotion-win`）へコピーして `npm install` する。

ソースコード（`src/`）とスクリプト本体はリポジトリ（WSL 上）のままでよい。Node が UNC 経由で読む。

```
%LOCALAPPDATA%\video-manager-remotion-win\   ← Windows ローカル（依存とバンドルの置き場所）
  ├─ package.json        （windows/package.json のコピー）
  ├─ node_modules\       （Windows 用。npm install で作る）
  └─ .bundle-cache\      （まとめたコードのキャッシュ）
```

## 準備（初回だけ、Windows の PowerShell で）

Windows に Node.js（v20 以上）が必要。WSL の Node とは別に、Windows 側にインストールする。

```powershell
<リポジトリ>\remotion\windows\setup.ps1
```

`setup.ps1` が依存先フォルダを作り、`package.json` をコピーして `npm install` する。
初回の書き出し時に Chrome Headless Shell が自動でダウンロードされる。

インストール先を変えたいとき（例：別ドライブ）：

```powershell
$env:REMOTION_WIN_DIR = 'D:\remotion-win'
<リポジトリ>\remotion\windows\setup.ps1
# 書き出すときも同じ $env:REMOTION_WIN_DIR を設定しておく
```

## 使い方（Windows の PowerShell）

```powershell
cd <リポジトリ>\remotion
node scripts\still-windows.mjs fixtures\sample.json
```

出力は `remotion\out\still-<key>.png`（場面ごと）。

### オプション

| オプション | 既定 | 内容 |
|---|---|---|
| `--key s1` | （全場面） | その `key` の場面だけ書き出す |
| `--out <dir>` | `remotion\out` | 出力先フォルダ |
| `--scale 0.5` | `1` | 書き出しの倍率（確認用に小さくする） |
| `--frame 45` | 場面の中ほど | 静止画にするフレーム |
| `--format png` | `png` | `png` / `jpeg` |

### 入力 JSON の形

`public_docs/scene_content.md` の形。次のどれでもよい。

```jsonc
// 全場面（fixtures/sample.json と同じ形）
{ "settings": { "width": 1920, "height": 1080, "fps": 30, "style": { "font": "Noto Sans JP", "theme": "light" } },
  "scenes": [ { "key": "s1", "scene_type": "remotion.title", "duration_seconds": 3, "content": { ... } } ] }

// 1 場面だけ
{ "settings": { ... }, "scene": { "key": "s1", "scene_type": "remotion.title", ... } }

// 場面だけ（settings は既定値：1920x1080 / 30fps / Noto Sans JP / light）
{ "key": "s1", "scene_type": "remotion.title", "duration_seconds": 3, "content": { ... } }
```

## 制作画面の「静止画」ボタンから Windows で作る（静止画のサーバー）

`scripts/still-server-windows.mjs` は、Laravel のジョブから HTTP で頼まれて静止画を作り、画像を返すサーバー。
描く部品は `still-windows.mjs` と同じ `src/` の `Scene`（動画と同じ見た目）。依存とバンドルも同じものを使う
（共通部分は `scripts/windows-remotion.mjs`）。画像の保存と DB（`production_renders`）の更新は Laravel が行う。

```
画面の「静止画」ボタン → キュー（Sail） → POST http://host.docker.internal:3399/still → このサーバー（Windows の Node）
                                          ← 画像 ←
                     → storage/app/private に保存し、production_renders と場面の preview_path を更新 → 画面に表示
```

1. Laravel の `.env` に `REMOTION_STILL_DRIVER=windows` を加え、`sail artisan config:clear` とキューワーカーの再起動をする。
2. Windows の PowerShell でサーバーを起動し、開いたままにする（`still-windows.mjs` を先に動かす必要はない）。

   ```powershell
   cd <リポジトリ>\remotion
   node scripts\still-server-windows.mjs
   ```

   起動時にバンドルと Chrome を用意し、「準備ができました」と出たら受け付けられる。
   `src/` を直したら、次の依頼のときに自動でまとめ直す（サーバーの再起動はいらない）。
3. 制作画面で場面の「静止画」ボタンを押す。

| 環境変数 | 既定 | 内容 |
|---|---|---|
| `STILL_SERVER_HOST` | `127.0.0.1` | 待ち受けるアドレス。コンテナから届かないときは `0.0.0.0` |
| `STILL_SERVER_PORT` | `3399` | ポート（Laravel の `REMOTION_STILL_SERVER_URL` と合わせる） |
| `STILL_SERVER_TOKEN` | なし | 合言葉。Laravel の `REMOTION_STILL_SERVER_TOKEN` と同じ値にする |

動いているかは `http://127.0.0.1:3399/health` で確かめられる。

## 注意

- `still-windows.mjs` は DB を更新しない。制作画面に表示する静止画は、上のサーバーか Laravel（`scripts/render.mjs`）から作る。
- 依存とキャッシュは Windows ローカルにあるため git には入らない。リポジトリに入るのは `windows/package.json`・`setup.ps1`・この README と `scripts/still-windows.mjs`・`scripts/still-server-windows.mjs`・`scripts/windows-remotion.mjs` だけ。
- 部品（`src/`）を直したら、次の実行時に自動で作り直す（`src/` と `fixtures/` のハッシュでキャッシュを判定）。
- Remotion のバージョンを上げたときは、`remotion/package.json` と同じバージョンに `windows/package.json` を合わせて `setup.ps1` をもう一度実行する。
完了。静止画の書き出し例：
  cd <リポジトリ>\remotion
  node scripts\still-windows.mjs fixtures\sample.json