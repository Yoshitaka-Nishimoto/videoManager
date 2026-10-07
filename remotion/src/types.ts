// Laravel から渡される入力（input props）の形。場面の content の形式は public_docs/scene_content.md。

export type Theme = 'light' | 'dark';

export type Settings = {
  width: number;
  height: number;
  fps: number;
  style?: {
    font?: string | null;
    theme?: Theme | null;
    tone?: string | null;
  } | null;
};

export type SceneData = {
  key: string;
  scene_type: string;
  title?: string | null;
  duration_seconds: number;
  narration?: string | null;
  caption?: string | null;
  transition?: 'fade' | 'slide' | 'wipe' | 'none' | null;
  content: Record<string, unknown> | null;
};

// Production コンポジション：制作案の再生する場面を順に並べる。
export type ProductionProps = {
  settings: Settings;
  scenes: SceneData[];
};

// Scene コンポジション：1 つの場面だけ（確認用の静止画）。
export type SceneProps = {
  settings: Settings;
  scene: SceneData;
};

export const DEFAULT_SETTINGS: Settings = {
  width: 1920,
  height: 1080,
  fps: 30,
  style: { font: 'Noto Sans JP', theme: 'light' },
};

export function framesFor(seconds: number, fps: number): number {
  return Math.max(1, Math.round(seconds * fps));
}
