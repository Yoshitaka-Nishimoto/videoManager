import type React from 'react';
import { componentFor } from './scenes/registry';
import type { SceneProps } from './types';

// 1 つの場面だけを表示する。確認用の静止画（still）の書き出しに使う。
export const SceneComposition: React.FC<SceneProps> = ({ settings, scene }) => {
  const Component = componentFor(scene.scene_type);

  return <Component scene={scene} settings={settings} />;
};
