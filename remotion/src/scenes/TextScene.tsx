import type React from 'react';
import { AbsoluteFill, interpolate, useCurrentFrame, useVideoConfig } from 'remotion';
import { paletteFor } from '../theme';
import { contentOf, type SceneComponentProps } from './content';
import { SceneFrame } from './SceneFrame';

type TextContent = {
  heading: string | null;
  items: string[];
  reveal: 'all' | 'one_by_one';
};

// remotion.text：見出しと箇条書き。one_by_one なら場面の前半で 1 つずつ表示する。
export const TextScene: React.FC<SceneComponentProps> = ({ scene, settings }) => {
  const frame = useCurrentFrame();
  const { durationInFrames, fps } = useVideoConfig();
  const palette = paletteFor(settings);
  const { heading = null, items = [], reveal = 'all' } = contentOf<TextContent>(scene);

  const step = items.length > 0 ? Math.max(Math.round(fps * 0.3), Math.floor((durationInFrames * 0.6) / items.length)) : 0;

  return (
    <SceneFrame scene={scene} settings={settings}>
      <AbsoluteFill style={{ justifyContent: 'center', padding: '0 12%' }}>
        {heading ? <div style={{ fontSize: 80, fontWeight: 700, marginBottom: 56 }}>{heading}</div> : null}
        {items.map((item, index) => {
          const start = reveal === 'one_by_one' ? index * step : 0;
          const shown = interpolate(frame, [start, start + Math.round(fps * 0.3)], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

          return (
            <div key={index} style={{ display: 'flex', alignItems: 'baseline', gap: 28, fontSize: 56, lineHeight: 1.6, opacity: shown, transform: `translateX(${(1 - shown) * 30}px)` }}>
              <span style={{ color: palette.accent, fontWeight: 700 }}>{index + 1}</span>
              <span>{item}</span>
            </div>
          );
        })}
      </AbsoluteFill>
    </SceneFrame>
  );
};
