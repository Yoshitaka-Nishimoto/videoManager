import type React from 'react';
import { AbsoluteFill } from 'remotion';
import { paletteFor } from '../theme';
import { contentOf, type SceneComponentProps } from './content';
import { SceneFrame } from './SceneFrame';

type CoverContent = {
  heading: string;
  subheading: string | null;
  label: string | null;
  topics: string[];
  footer: string | null;
};

// 見出しの長さで文字の大きさを変える（長い題名でも 1 枚に収める）。
function headingSize(heading: string): number {
  const length = [...heading].length;

  if (length <= 12) return 128;
  if (length <= 24) return 104;
  if (length <= 40) return 84;
  if (length <= 64) return 68;

  return 56;
}

// remotion.cover：情報源の表紙（静止画）。動きはない（どのフレームでも同じ絵）。
// 内容は Laravel の CreateVideoCover が情報源と最新の分析から作る（public_docs/video_covers.md）。
export const CoverScene: React.FC<SceneComponentProps> = ({ scene, settings }) => {
  const palette = paletteFor(settings);
  const { heading = '', subheading = null, label = null, topics = [], footer = null } = contentOf<CoverContent>(scene);
  const shownTopics = (Array.isArray(topics) ? topics : []).filter((topic) => typeof topic === 'string' && topic !== '').slice(0, 5);

  return (
    <SceneFrame scene={{ ...scene, caption: null, narration: null, transition: 'none' }} settings={settings}>
      <AbsoluteFill style={{ padding: '96px 128px', flexDirection: 'column', justifyContent: 'space-between' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 24, fontSize: 36, color: palette.muted }}>
          <div style={{ width: 16, height: 48, borderRadius: 4, backgroundColor: palette.accent }} />
          {label ? <div style={{ fontWeight: 700, letterSpacing: '0.05em' }}>{label}</div> : null}
        </div>

        <div>
          <div
            style={{
              fontSize: headingSize(heading),
              fontWeight: 700,
              lineHeight: 1.3,
              display: '-webkit-box',
              WebkitLineClamp: 4,
              WebkitBoxOrient: 'vertical',
              overflow: 'hidden',
              wordBreak: 'break-word',
            }}
          >
            {heading}
          </div>
          {subheading ? (
            <div style={{ marginTop: 32, fontSize: 48, lineHeight: 1.4, color: palette.muted, overflow: 'hidden', whiteSpace: 'nowrap', textOverflow: 'ellipsis' }}>
              {subheading}
            </div>
          ) : null}
          <div style={{ marginTop: 40, height: 10, width: 200, backgroundColor: palette.accent, borderRadius: 5 }} />
        </div>

        <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: 48 }}>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 16, maxHeight: 160, overflow: 'hidden' }}>
            {shownTopics.map((topic) => (
              <div
                key={topic}
                style={{
                  padding: '10px 28px',
                  borderRadius: 999,
                  border: `2px solid ${palette.muted}`,
                  backgroundColor: palette.surface,
                  fontSize: 32,
                  whiteSpace: 'nowrap',
                }}
              >
                {topic}
              </div>
            ))}
          </div>
          {footer ? <div style={{ fontSize: 32, color: palette.muted, whiteSpace: 'nowrap' }}>{footer}</div> : null}
        </div>
      </AbsoluteFill>
    </SceneFrame>
  );
};
