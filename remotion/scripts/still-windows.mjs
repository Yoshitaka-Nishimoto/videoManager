// Windows ネイティブで静止画を書き出す独立スクリプト（DB には記録しない。手元確認用）。
//
// 既存の src/ のコンポジション（Scene）をそのまま再利用する。Sail コンテナ内で Laravel から
// 呼ぶ scripts/render.mjs とは別に、Windows の Node で動かす（書き出しの webpack が
// WSL を落とす問題を避けるため。render.mjs の「この PC では WSL が落ちる」コメントを参照）。
// 制作画面の「静止画」ボタンから Windows で作るときは、scripts/still-server-windows.mjs を使う。
//
// 依存の置き場所とバンドルは scripts/windows-remotion.mjs を参照。準備は remotion/windows/setup.ps1。
//
// 使い方（Windows の PowerShell。remotion/ か remotion/windows/ から）：
//   node scripts/still-windows.mjs <入力.json> [--key s1] [--out out] [--scale 1] [--frame N] [--format png]
//
// 入力 JSON は public_docs/scene_content.md の形。次のどれでもよい：
//   { "settings": {...}, "scenes": [ {場面}, ... ] }   … 全場面（または --key で 1 つだけ）
//   { "settings": {...}, "scene": {場面} }               … 1 場面
//   { "key": "...", "scene_type": "...", ... }            … 場面だけ（settings は既定値）
//
// 出力：<out>/still-<key>.png（既定の out は remotion/out）

import { mkdir, readFile, stat } from 'node:fs/promises';
import path from 'node:path';
import { cachedBundle, remotionVersion, renderer, root } from './windows-remotion.mjs';

const { ensureBrowser, renderStill, selectComposition } = renderer;

// settings が無い入力のための既定値（src/types.ts の DEFAULT_SETTINGS に合わせる）。
const DEFAULT_SETTINGS = {
  width: 1920,
  height: 1080,
  fps: 30,
  style: { font: 'Noto Sans JP', theme: 'light' },
};

function parseArgs(argv) {
  const opts = { out: null, key: null, scale: 1, frame: null, format: 'png' };
  const positional = [];

  for (let i = 0; i < argv.length; i += 1) {
    const arg = argv[i];

    switch (arg) {
      case '--key': opts.key = argv[++i]; break;
      case '--out': opts.out = argv[++i]; break;
      case '--scale': opts.scale = Number(argv[++i]); break;
      case '--frame': opts.frame = Number(argv[++i]); break;
      case '--format': opts.format = argv[++i]; break;
      default: positional.push(arg);
    }
  }

  return { opts, positional };
}

function scenesFrom(input) {
  if (Array.isArray(input.scenes)) return input.scenes;
  if (input.scene) return [input.scene];
  if (input.scene_type) return [input];

  throw new Error('入力に scenes / scene / scene_type がありません（public_docs/scene_content.md の形にしてください）。');
}

async function main() {
  const { opts, positional } = parseArgs(process.argv.slice(2));
  const inputPath = positional[0];

  if (!inputPath) {
    console.error('使い方：node scripts/still-windows.mjs <入力.json> [--key s1] [--out out] [--scale 1] [--frame N] [--format png]');
    process.exit(1);
  }

  const input = JSON.parse(await readFile(path.resolve(process.cwd(), inputPath), 'utf8'));
  const settings = input.settings ?? DEFAULT_SETTINGS;
  let scenes = scenesFrom(input);

  if (opts.key) {
    scenes = scenes.filter((scene) => scene.key === opts.key);

    if (scenes.length === 0) {
      throw new Error(`--key ${opts.key} の場面が入力にありません。`);
    }
  }

  const outDir = path.resolve(process.cwd(), opts.out ?? path.join(root, 'out'));
  await mkdir(outDir, { recursive: true });

  await ensureBrowser();
  const { serveUrl } = await cachedBundle({ onBundling: () => console.log('コードをまとめています（初回・コード変更後だけ時間がかかります）…') });

  let count = 0;

  for (const scene of scenes) {
    const inputProps = { settings, scene };
    const composition = await selectComposition({ serveUrl, id: 'Scene', inputProps });
    // 指定がなければ場面の中ほどのフレーム（render.mjs と同じ）。
    const frame = Number.isFinite(opts.frame) && opts.frame !== null ? opts.frame : Math.floor(composition.durationInFrames / 2);
    const key = scene.key ?? `s${count + 1}`;
    const outputPath = path.join(outDir, `still-${key}.png`);
    const startedAt = Date.now();

    await renderStill({
      serveUrl,
      composition,
      inputProps,
      output: outputPath,
      frame,
      imageFormat: opts.format,
      scale: opts.scale,
    });

    const { size } = await stat(outputPath);
    const width = Math.round(composition.width * opts.scale);
    const height = Math.round(composition.height * opts.scale);

    console.log(`✓ ${key}  (${scene.scene_type ?? '?'})  → ${path.relative(process.cwd(), outputPath)}  ${width}x${height}  ${(size / 1024).toFixed(1)} KB  ${Date.now() - startedAt}ms`);
    count += 1;
  }

  console.log(`\n完了：${count} 枚（Remotion ${remotionVersion}）`);
}

main().catch((error) => {
  console.error('\n書き出しに失敗しました：');
  console.error(error?.stack ?? String(error));
  process.exit(1);
});
