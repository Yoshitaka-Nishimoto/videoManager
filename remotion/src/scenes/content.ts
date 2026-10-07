import type { SceneData, Settings } from '../types';

export type SceneComponentProps = {
  scene: SceneData;
  settings: Settings;
};

/**
 * content から種類ごとの部分を取り出す。
 * scene_content.md の形（{ "title": { ... } }）を基本にし、中身だけが直接入っている形も受け付ける。
 */
export function contentOf<T>(scene: SceneData): Partial<T> {
  const key = scene.scene_type.split('.')[1] ?? '';
  const content = scene.content ?? {};
  const nested = content[key];

  return (nested && typeof nested === 'object' ? nested : content) as Partial<T>;
}
