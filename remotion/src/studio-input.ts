import type { ProductionProps } from './types';

// 読み取り専用の Studio（npx remotion bundle で作り、Laravel の /remotion-studio で配信する）で、
// 制作案を確認するための入力。Laravel がページを返すときに次の 2 つを埋め込む。
//   window.videoManagerStudioInput    … 制作案の入力（Production の形）
//   window.videoManagerStudioSceneKey … Scene コンポジションで見せる場面（s1 など）
//
// 読み取り専用の Studio は書き出しもコードへの書き戻しもできず、ここでの操作はページを閉じれば消える。
// Laravel からの書き出し（scripts/render.mjs）には埋め込みがないため、渡された入力をそのまま使う。

declare global {
  // remotion_isReadOnlyStudio は Remotion 側で宣言されている。
  interface Window {
    videoManagerStudioInput?: ProductionProps;
    videoManagerStudioSceneKey?: string | null;
  }
}

export function studioInput(): ProductionProps | null {
  if (typeof window === 'undefined' || window.remotion_isReadOnlyStudio !== true) {
    return null;
  }

  return window.videoManagerStudioInput ?? null;
}

export function studioSceneKey(): string | null {
  return typeof window === 'undefined' ? null : (window.videoManagerStudioSceneKey ?? null);
}
