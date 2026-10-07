import type React from 'react';
import { AbsoluteFill, spring, useCurrentFrame, useVideoConfig } from 'remotion';
import { paletteFor } from '../theme';
import { contentOf, type SceneComponentProps } from './content';
import { SceneFrame } from './SceneFrame';

type TitleContent = {
  heading: string;
  subheading: string | null;
  layout: 'center' | 'lower_third';
};

// remotion.title：見出しと副題。
export const TitleScene: React.FC<SceneComponentProps> = ({ scene, settings }) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const palette = paletteFor(settings);
  const { heading = '', subheading = null, layout = 'center' } = contentOf<TitleContent>(scene);
  const rise = spring({ frame, fps, config: { damping: 200 } });

  const lowerThird = layout === 'lower_third';

  return (
    <SceneFrame scene={scene} settings={settings}>
      <AbsoluteFill
        style={{
          justifyContent: lowerThird ? 'flex-end' : 'center',
          alignItems: lowerThird ? 'flex-start' : 'center',
          padding: lowerThird ? '0 8% 18%' : '0 10%',
        }}
      >
        <div style={{ transform: `translateY(${(1 - rise) * 40}px)`, opacity: rise, textAlign: lowerThird ? 'left' : 'center' }}>
          <div style={{ fontSize: lowerThird ? 72 : 110, fontWeight: 700, lineHeight: 1.25 }}>{heading}</div>
          {subheading ? <div style={{ marginTop: 28, fontSize: lowerThird ? 40 : 52, color: palette.muted }}>{subheading}</div> : null}
          <div style={{ marginTop: 36, height: 8, width: 160 * rise, backgroundColor: palette.accent, borderRadius: 4, marginInline: lowerThird ? 0 : 'auto' }} />
        </div>
      </AbsoluteFill>
    </SceneFrame>
  );
};
