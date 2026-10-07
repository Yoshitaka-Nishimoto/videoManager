import type React from 'react';
import { AbsoluteFill, interpolate, useCurrentFrame, useVideoConfig } from 'remotion';
import { fontFamily, paletteFor } from '../theme';
import type { SceneData, Settings } from '../types';

// すべての場面に共通の枠：背景、書体、字幕、前後のフェード。
export const SceneFrame: React.FC<{ scene: SceneData; settings: Settings; children: React.ReactNode }> = ({ scene, settings, children }) => {
  const frame = useCurrentFrame();
  const { durationInFrames, fps } = useVideoConfig();
  const palette = paletteFor(settings);
  const fadeFrames = Math.min(Math.round(fps * 0.4), Math.floor(durationInFrames / 4));

  // 切り替えが fade のときだけ、場面の終わりをフェードアウトする。始まりは常にフェードイン。
  const opacity = interpolate(
    frame,
    [0, fadeFrames, durationInFrames - fadeFrames, durationInFrames],
    [0, 1, 1, scene.transition === 'fade' ? 0 : 1],
    { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' },
  );

  const caption = scene.caption ?? scene.narration ?? null;

  return (
    <AbsoluteFill style={{ backgroundColor: palette.background, color: palette.text, fontFamily }}>
      <AbsoluteFill style={{ opacity }}>{children}</AbsoluteFill>
      {caption ? (
        <AbsoluteFill style={{ justifyContent: 'flex-end', alignItems: 'center', paddingBottom: '5%' }}>
          <div
            style={{
              maxWidth: '80%',
              padding: '0.4em 1em',
              borderRadius: 12,
              backgroundColor: 'rgba(0, 0, 0, 0.6)',
              color: '#ffffff',
              fontSize: 40,
              lineHeight: 1.5,
              textAlign: 'center',
            }}
          >
            {caption}
          </div>
        </AbsoluteFill>
      ) : null}
    </AbsoluteFill>
  );
};
