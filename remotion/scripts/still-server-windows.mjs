// VideoManager（WSL の Sail のコンテナ）から静止画を頼まれて、Windows で作って返す受け付けのサーバー。
//
// 描くのは remotion/src の Scene（動画と同じ部品）。依存とバンドルは scripts/windows-remotion.mjs を参照。
// Laravel 側は .env に REMOTION_STILL_DRIVER=windows を設定する（config/remotion.php の still_driver）。
//
// 起動（Windows の PowerShell。remotion/ から）：node scripts\still-server-windows.mjs
//
//   POST /still   入力（JSON）を受け取り、画像を返す。
//                 本文：{ "inputProps": { "settings": {...}, "scene": {...} }, "imageFormat": "png", "scale": 1, "frame": 45 }
//                 frame が null なら場面の中ほど。
//                 応答：画像そのもの。ヘッダーに X-Render-Ms / X-Width / X-Height / X-Frame / X-Remotion-Version / X-Code-Version。
//                 失敗：500 と { "error": "..." }
//   GET  /health  動いているかの確認。
//
// 設定（環境変数）：
//   STILL_SERVER_HOST   待ち受けるアドレス（既定 127.0.0.1。コンテナから届かないときは 0.0.0.0）
//   STILL_SERVER_PORT   ポート（既定 3399）
//   STILL_SERVER_TOKEN  合言葉。設定すると、Authorization: Bearer <合言葉> がない依頼を断る
//   REMOTION_WIN_DIR    Windows 用の依存の置き場所（setup.ps1 と同じ値）

import { mkdtemp, readFile, rm } from 'node:fs/promises';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { cachedBundle, remotionVersion, renderer } from './windows-remotion.mjs';

const { ensureBrowser, openBrowser, renderStill, selectComposition } = renderer;

const host = process.env.STILL_SERVER_HOST ?? '127.0.0.1';
const port = Number(process.env.STILL_SERVER_PORT ?? 3399);
const token = process.env.STILL_SERVER_TOKEN ?? '';
const compositionId = 'Scene';
const maxBodyBytes = 1024 * 1024;

const log = (...args) => console.log(new Date().toISOString(), ...args);

// 最後に使ったバンドルのハッシュ（/health と X-Code-Version 用）。
let bundleHash = null;
// Chrome は起動中ずっと使い回す。
let browser = null;

// バンドルはコード（src/、fixtures/）が変わったときだけ作り直す。依頼ごとにハッシュを確かめる。
async function serveUrl() {
  const { hash, serveUrl: url } = await cachedBundle({ onBundling: (next) => log(`バンドルを作ります（${next}）`) });

  if (bundleHash !== hash) {
    bundleHash = hash;
    log(`バンドルを使います（${hash}）`);
  }

  return url;
}

async function chrome() {
  if (browser === null) {
    await ensureBrowser();
    browser = await openBrowser('chrome');
  }

  return browser;
}

async function renderStillImage({ inputProps, imageFormat = 'png', scale = 1, frame = null }) {
  const startedAt = Date.now();
  const url = await serveUrl();
  const puppeteerInstance = await chrome();
  const composition = await selectComposition({ serveUrl: url, id: compositionId, inputProps, puppeteerInstance });
  // 指定がなければ場面の中ほどのフレーム（render.mjs と同じ）。範囲外は最後のフレームに収める。
  const target = Number.isInteger(frame) ? Math.min(Math.max(frame, 0), composition.durationInFrames - 1) : Math.floor(composition.durationInFrames / 2);
  const directory = await mkdtemp(path.join(os.tmpdir(), 'remotion-still-'));
  const output = path.join(directory, `still.${imageFormat === 'jpeg' ? 'jpg' : imageFormat}`);

  try {
    await renderStill({ serveUrl: url, composition, inputProps, output, frame: target, imageFormat, scale, puppeteerInstance });

    return {
      image: await readFile(output),
      renderMs: Date.now() - startedAt,
      width: Math.round(composition.width * scale),
      height: Math.round(composition.height * scale),
      frame: target,
    };
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
}

// 静止画は 1 枚ずつ順に作る（CPU に一度に負荷をかけない）。
let queue = Promise.resolve();
const enqueue = (task) => {
  const result = queue.then(task, task);
  queue = result.catch(() => {});

  return result;
};

function readJson(request) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];

    request.on('data', (chunk) => {
      size += chunk.length;
      if (size > maxBodyBytes) {
        reject(new Error('依頼の本文が大きすぎます。'));
        request.destroy();

        return;
      }
      chunks.push(chunk);
    });
    request.on('end', () => {
      try {
        resolve(JSON.parse(Buffer.concat(chunks).toString('utf8')));
      } catch {
        reject(new Error('依頼の本文が JSON ではありません。'));
      }
    });
    request.on('error', reject);
  });
}

function sendJson(response, status, body) {
  response.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
  response.end(JSON.stringify(body));
}

const server = http.createServer(async (request, response) => {
  if (token !== '' && request.headers.authorization !== `Bearer ${token}`) {
    return sendJson(response, 401, { error: '合言葉が違います。' });
  }

  if (request.method === 'GET' && request.url === '/health') {
    return sendJson(response, 200, { ok: true, remotionVersion, bundle: bundleHash });
  }

  if (request.method !== 'POST' || request.url !== '/still') {
    return sendJson(response, 404, { error: '見つかりません。' });
  }

  try {
    const body = await readJson(request);

    if (!body?.inputProps?.settings || !body?.inputProps?.scene) {
      return sendJson(response, 422, { error: 'inputProps に settings と scene が必要です。' });
    }

    const result = await enqueue(() => renderStillImage(body));
    log(`静止画を作りました（${body.inputProps.scene.key}、${body.inputProps.scene.scene_type}、フレーム ${result.frame}、${result.renderMs}ms）`);

    response.writeHead(200, {
      'Content-Type': body.imageFormat === 'jpeg' ? 'image/jpeg' : 'image/png',
      'X-Render-Ms': String(result.renderMs),
      'X-Width': String(result.width),
      'X-Height': String(result.height),
      'X-Frame': String(result.frame),
      'X-Remotion-Version': remotionVersion,
      // 書き出したコードの版（remotion/src と fixtures/ のハッシュ）。VideoManager の code_version に残す。
      'X-Code-Version': `remotion-src:${bundleHash}`,
    });
    response.end(result.image);
  } catch (error) {
    log('失敗しました：', error?.stack ?? error);
    sendJson(response, 500, { error: String(error?.message ?? error).split('\n')[0] });
  }
});

server.listen(port, host, async () => {
  log(`受け付けを始めました：http://${host}:${port}（Remotion ${remotionVersion}${token ? '、合言葉あり' : ''}）`);
  // 最初の依頼を待たせないよう、先にバンドルと Chrome を用意する。
  try {
    await serveUrl();
    await chrome();
    log('準備ができました');
  } catch (error) {
    log('準備に失敗しました：', error?.stack ?? error);
  }
});

const shutdown = async () => {
  await browser?.close({ silent: true }).catch(() => {});
  process.exit(0);
};
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
