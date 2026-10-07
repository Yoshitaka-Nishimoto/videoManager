import type React from 'react';
import type { SceneComponentProps } from './content';
import { PlaceholderScene } from './PlaceholderScene';
import { TextScene } from './TextScene';
import { TitleScene } from './TitleScene';

// 場面の種類キーから部品を選ぶ対応表。Production と Scene の両方がこれを使う。
// 種類を追加しても、コンポジションを増やす必要はない。
const SCENES: Record<string, React.FC<SceneComponentProps>> = {
  'remotion.title': TitleScene,
  'remotion.text': TextScene,
};

export function componentFor(sceneType: string): React.FC<SceneComponentProps> {
  return SCENES[sceneType] ?? PlaceholderScene;
}
