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
//     "frame": 45                        // 静止画のとき（null なら場面の中ほど）
//   }
//
// 標準出力に 1 行ずつ JSON を出す。Laravel はこれを読んで production_renders を更新する。
//   {"type":"stage","stage":"bundling"}
//   {"type":"progress","stage":"rendering","progress":42,"renderedFrames":120,"encodedFrames":100}
//   {"type":"done","outputPath":"...","sizeBytes":123,"renderMs":4567,"width":960,"height":540,"fps":30,"durationInFrames":270,"frame":null,"remotionVersion":"4.0.534"}
//   {"type":"error","message":"..."}

import { bundle } from '@remotion/bundler';
import { ensureBrowser, renderMedia, renderStill, selectComposition } from '@remotion/renderer';
import { mkdir, readFile, stat } from 'node:fs/promises';
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const require = createRequire(import.meta.url);
const remotionVersion = require('remotion/package.json').version;

const emit = (message) => process.stdout.write(JSON.stringify(message) + '\n');

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
  const serveUrl = await bundle({ entryPoint: path.join(root, 'src/index.ts') });

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
