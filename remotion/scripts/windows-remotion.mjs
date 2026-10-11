// Windows ネイティブで remotion/src を描くための共通部分（still-windows.mjs と still-server-windows.mjs が使う）。
//
// 依存（@remotion/* や react）は Windows ローカルのフォルダに入れる。
// npm は \\wsl.localhost\ の UNC パスへインストールできない（arborist の realpath が無限再帰する）ため、
// WSL 上の remotion/ には置かず、既定で %LOCALAPPDATA%\video-manager-remotion-win に入れる。
// 準備は remotion/windows/setup.ps1 を Windows の PowerShell で実行する。
//   環境変数 REMOTION_WIN_DIR で依存先を上書きできる。

import { createHash } from 'node:crypto';
import { existsSync } from 'node:fs';
import { readdir, readFile, rm, stat } from 'node:fs/promises';
import { createRequire } from 'node:module';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..'); // remotion/

// Windows 用の依存の置き場所（Windows ローカル。WSL の UNC パスには npm で入れられない）。
export const winDir = process.env.REMOTION_WIN_DIR
  ? path.resolve(process.env.REMOTION_WIN_DIR)
  : path.join(process.env.LOCALAPPDATA ?? os.homedir(), 'video-manager-remotion-win');
const winNodeModules = path.join(winDir, 'node_modules');

if (!existsSync(winNodeModules)) {
  console.error(`Windows 用の依存が見つかりません（探した場所：${winDir}）。`);
  console.error('先に Windows の PowerShell で次を実行してください：');
  console.error('  remotion\\windows\\setup.ps1');
  process.exit(1);
}

// @remotion/* と react は OS 固有のネイティブバイナリ（Compositor、swc など）を含むため、
// Linux 用の remotion/node_modules ではなく、Windows でインストールした依存から読む。
const requireWin = createRequire(path.join(winDir, 'package.json'));
const { bundle } = requireWin('@remotion/bundler');
export const renderer = requireWin('@remotion/renderer');
export const remotionVersion = requireWin('remotion/package.json').version;

// まとめたコード（バンドル）の置き場所。src/ と fixtures/ が同じ間は使い回す。
// Windows ローカルに置く（WSL の UNC へ書くのは遅いため、依存と同じ場所にまとめる）。
const bundleCacheDirectory = path.join(winDir, '.bundle-cache');
const bundlesToKeep = 3;

async function listFiles(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const nested = await Promise.all(entries.map((entry) => {
    const full = path.join(directory, entry.name);

    return entry.isDirectory() ? listFiles(full) : [full];
  }));

  return nested.flat();
}

// src/ と fixtures/（Root.tsx が fixtures/sample.json を読み込むため）のハッシュ。
export async function codeHash() {
  const files = [
    ...(await listFiles(path.join(root, 'src'))),
    ...(await listFiles(path.join(root, 'fixtures'))),
  ].sort();
  const hash = createHash('sha256');

  for (const file of files) {
    hash.update(path.relative(root, file));
    hash.update(await readFile(file));
  }

  return hash.digest('hex').slice(0, 16);
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

/**
 * remotion/src をまとめる（バンドル）。コードが前回と同じなら、前回のバンドルを使い回す。
 *
 * @returns {Promise<{ hash: string, serveUrl: string }>}
 */
export async function cachedBundle({ onBundling } = {}) {
  const hash = await codeHash();
  const outDir = path.join(bundleCacheDirectory, hash);

  if (existsSync(path.join(outDir, 'index.html'))) {
    return { hash, serveUrl: outDir };
  }

  onBundling?.(hash);
  const serveUrl = await bundle({
    entryPoint: path.join(root, 'src/index.ts'),
    outDir,
    // src が import する remotion / react / @remotion/google-fonts も Windows 用から解決する。
    webpackOverride: (config) => ({
      ...config,
      resolve: {
        ...config.resolve,
        modules: [winNodeModules, 'node_modules'],
      },
    }),
  });

  await removeOldBundles(hash);

  return { hash, serveUrl };
}
