import { loadFont } from '@remotion/google-fonts/NotoSansJP';
import type { Settings } from './types';

// 日本語の書体。書き出しのたびに Google Fonts から読み込む（コンテナに日本語フォントを入れなくてよい）。
// 日本語は文字の範囲ごとに細かく分かれていて読み込みの回数が多くなるため、その警告は出さない。
const { fontFamily } = loadFont('normal', { weights: ['400', '700'], ignoreTooManyRequestsWarning: true });

export type Palette = {
  background: string;
  text: string;
  muted: string;
  accent: string;
  surface: string;
};

const PALETTES: Record<'light' | 'dark', Palette> = {
  light: { background: '#f7f6f2', text: '#1b1b18', muted: '#6b6a64', accent: '#2563eb', surface: '#ffffff' },
  dark: { background: '#161615', text: '#ededec', muted: '#a1a09a', accent: '#60a5fa', surface: '#262624' },
};

export function paletteFor(settings: Settings): Palette {
  return PALETTES[settings.style?.theme === 'dark' ? 'dark' : 'light'];
}

export { fontFamily };
