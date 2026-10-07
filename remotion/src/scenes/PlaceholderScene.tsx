import type React from 'react';
import { AbsoluteFill } from 'remotion';
import { paletteFor } from '../theme';
import type { SceneComponentProps } from './content';
import { SceneFrame } from './SceneFrame';

// まだ部品のない種類（diagram / chart / code / image / clip / split / runway.*）の仮の表示。
// 書き出しを止めずに、どの場面が未実装かが分かるようにする。
export const PlaceholderScene: React.FC<SceneComponentProps> = ({ scene, settings }) => {
  const palette = paletteFor(settings);

  return (
    <SceneFrame scene={{ ...scene, caption: null, narration: null }} settings={settings}>
      <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center', gap: 24 }}>
        <div style={{ padding: '12px 28px', border: `4px dashed ${palette.muted}`, borderRadius: 16, fontSize: 44, color: palette.muted }}>
          {scene.scene_type}（未実装）
        </div>
        <div style={{ fontSize: 64, fontWeight: 700 }}>{scene.title ?? scene.key}</div>
      </AbsoluteFill>
    </SceneFrame>
  );
};
