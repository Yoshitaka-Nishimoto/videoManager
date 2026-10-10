// Laravel のジョブから呼ぶ書き出しスクリプト（public_docs/remotion_tables.md の「実行のしかた」）。
//
// 使い方：node scripts/render.mjs <job.json>
//
// job.json の形：
//   {
//     "kind": "still" | "preview" | "final",
//     "compositionId": "Scene" | "Production",
//     "inputProps": { ... },             // Remotion に渡す入力（production_renders.input_props）
//     "outputPath": "/abs/path/out.mp4", // 出力先（絶対パス）
//     "codec": "h264",                   // 動画のとき
//     "imageFormat": "jpeg" | "png",
//     "scale": 0.5,
//     "crf": 28,                         // 動画のとき（null なら Remotion の既定）
//     "frame": 45,                       // 静止画のとき（null なら場面の中ほど）
//     "concurrency": 1                   // 動画のとき、同時に描くフレーム数（既定 1）
//   }
//
// 標準出力に 1 行ずつ JSON を出す。Laravel はこれを読んで production_renders を更新する。
//   {"type":"stage","stage":"bundling"}
//   {"type":"progress","stage":"rendering","progress":42,"renderedFrames":120,"encodedFrames":100}
//   {"type":"done","outputPath":"...","sizeBytes":123,"renderMs":4567,"width":960,"height":540,"fps":30,"durationInFrames":270,"frame":null,"remotionVersion":"4.0.534"}
//   {"type":"error","message":"..."}

import { bundle } from '@remotion/bundler';
import { ensureBrowser, renderMedia, renderStill, selectComposition } from '@remotion/renderer';
import { createHash } from 'node:crypto';
import { existsSync } from 'node:fs';
import { mkdir, readdir, readFile, rm, stat } from 'node:fs/promises';
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const require = createRequire(import.meta.url);
const remotionVersion = require('remotion/package.json').version;

// まとめたコード（バンドル）の置き場所。コードが同じ間は使い回す。
const bundleCacheDirectory = path.join(root, '.bundle-cache');

// 古いバンドルは、新しい順にこの数だけ残す。
const bundlesToKeep = 3;

const emit = (message) => process.stdout.write(JSON.stringify(message) + '\n');

/**
 * Remotion のコードをまとめる（バンドル）。コードが前回と同じなら、前回のバンドルを使い回す。
 *
 * バンドルは webpack が CPU の全コアに負荷をかける処理で、この PC では WSL が落ちる原因になったため、
 * 1. コード（src/、fixtures/、設定、package-lock.json）のハッシュが同じなら作り直さない。
 * 2. 作るときも webpack の並列数を 1 にして、負荷を一度にかけない。
 */
async function cachedBundle() {
  const hash = await codeHash();
  const outDir = path.join(bundleCacheDirectory, hash);

  if (existsSync(path.join(outDir, 'index.html'))) {
    emit({ type: 'stage', stage: 'bundling', cached: true });

    return outDir;
  }

  const serveUrl = await bundle({
    entryPoint: path.join(root, 'src/index.ts'),
    outDir,
    webpackOverride: (config) => ({ ...config, parallelism: 1 }),
  });

  await removeOldBundles(hash);

  return serveUrl;
}

async function codeHash() {
  const files = [
    ...(await listFiles(path.join(root, 'src'))),
    ...(await listFiles(path.join(root, 'fixtures'))),
    path.join(root, 'remotion.config.ts'),
    path.join(root, 'tsconfig.json'),
    path.join(root, 'package-lock.json'),
  ].sort();
  const hash = createHash('sha256');

  for (const file of files) {
    hash.update(path.relative(root, file));
    hash.update(await readFile(file));
  }

  return hash.digest('hex').slice(0, 16);
}

async function listFiles(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const nested = await Promise.all(entries.map((entry) => {
    const full = path.join(directory, entry.name);

    return entry.isDirectory() ? listFiles(full) : [full];
  }));

  return nested.flat();
}

async function removeOldBundles(current) {
  const entries = await readdir(bundleCacheDirectory, { withFileTypes: true });
  const bundles = await Promise.all(entries
    .filter((entry) => entry.isDirectory() && entry.name !== current)
    .map(async (entry) => ({ name: entry.name, mtime: (await stat(path.join(bundleCacheDirectory, entry.name))).mtimeMs })));

  for (const old of bundles.sort((a, b) => b.mtime - a.mtime).slice(bundlesToKeep - 1)) {
    await rm(path.join(bundleCacheDirectory, old.name), { recursive: true, force: true });
  }
}

async function main() {
  const jobPath = process.argv[2];

  if (!jobPath) {
    throw new Error('使い方：node scripts/render.mjs <job.json>');
  }

  const job = JSON.parse(await readFile(jobPath, 'utf8'));
  const startedAt = Date.now();

  await mkdir(path.dirname(job.outputPath), { recursive: true });

  emit({ type: 'stage', stage: 'bundling' });
  await ensureBrowser();
  const serveUrl = await cachedBundle();

  const composition = await selectComposition({ serveUrl, id: job.compositionId, inputProps: job.inputProps });

  if (job.kind === 'still') {
    // 指定がなければ場面の中ほどのフレームにする。
    const frame = job.frame ?? Math.floor(composition.durationInFrames / 2);

    emit({ type: 'progress', stage: 'rendering', progress: 0, renderedFrames: 0, encodedFrames: 0 });
    await renderStill({
      serveUrl,
      composition,
      inputProps: job.inputProps,
      output: job.outputPath,
      frame,
      imageFormat: job.imageFormat ?? 'png',
      scale: job.scale ?? 1,
    });

    return finish(job, composition, startedAt, frame);
  }

  let lastStage = null;
  let lastProgress = -1;

  await renderMedia({
    serveUrl,
    composition,
    inputProps: job.inputProps,
    outputLocation: job.outputPath,
    codec: job.codec ?? 'h264',
    imageFormat: job.imageFormat ?? 'jpeg',
    scale: job.scale ?? 1,
    ...(job.crf != null ? { crf: job.crf } : {}),
    // 同時に描くフレーム数。CPU の全コアに負荷をかけないよう、既定は 1（config/remotion.php の concurrency）。
    concurrency: job.concurrency ?? 1,
    onProgress: ({ progress, renderedFrames, encodedFrames, stitchStage }) => {
      const stage = stitchStage === 'muxing' ? 'muxing' : renderedFrames < composition.durationInFrames ? 'rendering' : 'encoding';
      const percent = Math.floor(progress * 100);

      // 進捗率か段階が変わったときだけ出力する。
      if (percent !== lastProgress || stage !== lastStage) {
        lastProgress = percent;
        lastStage = stage;
        emit({ type: 'progress', stage, progress: percent, renderedFrames, encodedFrames });
      }
    },
  });

  return finish(job, composition, startedAt, null);
}

async function finish(job, composition, startedAt, frame) {
  const { size } = await stat(job.outputPath);
  const scale = job.scale ?? 1;

  emit({
    type: 'done',
    outputPath: job.outputPath,
    sizeBytes: size,
    renderMs: Date.now() - startedAt,
    // 書き出したファイルの実際の大きさ（倍率を掛けた後）。
    width: Math.round(composition.width * scale),
    height: Math.round(composition.height * scale),
    fps: composition.fps,
    durationInFrames: composition.durationInFrames,
    frame,
    remotionVersion,
  });
}

main().catch((error) => {
  emit({ type: 'error', message: error?.stack ?? String(error) });
  process.exit(1);
});
