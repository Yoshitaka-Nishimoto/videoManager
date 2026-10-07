import type React from 'react';
import { Series } from 'remotion';
import { componentFor } from './scenes/registry';
import { framesFor, type ProductionProps } from './types';

// 制作案の再生する場面を、順番どおりに並べる。各場面の長さは duration_seconds から決める。
export const Production: React.FC<ProductionProps> = ({ settings, scenes }) => {
  return (
    <Series>
      {scenes.map((scene) => {
        const Component = componentFor(scene.scene_type);

        return (
          <Series.Sequence key={scene.key} durationInFrames={framesFor(scene.duration_seconds, settings.fps)} name={`${scene.key} ${scene.scene_type}`}>
            <Component scene={scene} settings={settings} />
          </Series.Sequence>
        );
      })}
    </Series>
  );
};
