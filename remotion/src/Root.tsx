import type React from 'react';
import { Composition, type CalculateMetadataFunction } from 'remotion';
import sample from '../fixtures/sample.json';
import { Production } from './Production';
import { SceneComposition } from './SceneComposition';
import { DEFAULT_SETTINGS, framesFor, type ProductionProps, type SceneProps } from './types';

// 長さ・fps・幅・高さは入力から決める（DB は秒、Remotion はフレームで扱う）。
const productionMetadata: CalculateMetadataFunction<ProductionProps> = ({ props }) => {
  const { width, height, fps } = props.settings;
  const durationInFrames = props.scenes.reduce((total, scene) => total + framesFor(scene.duration_seconds, fps), 0);

  return { width, height, fps, durationInFrames: Math.max(1, durationInFrames) };
};

const sceneMetadata: CalculateMetadataFunction<SceneProps> = ({ props }) => {
  const { width, height, fps } = props.settings;

  return { width, height, fps, durationInFrames: framesFor(props.scene.duration_seconds, fps) };
};

const sampleProduction = sample as ProductionProps;

// コンポジションは全体用（Production）と場面用（Scene）の 2 つだけ。ID は config/remotion.php と合わせる。
// 既定の入力（fixtures/sample.json）は Remotion Studio での確認用。書き出しでは Laravel が入力を渡す。
export const RemotionRoot: React.FC = () => {
  return (
    <>
      <Composition
        id="Production"
        component={Production}
        defaultProps={sampleProduction}
        calculateMetadata={productionMetadata}
        width={DEFAULT_SETTINGS.width}
        height={DEFAULT_SETTINGS.height}
        fps={DEFAULT_SETTINGS.fps}
        durationInFrames={1}
      />
      <Composition
        id="Scene"
        component={SceneComposition}
        defaultProps={{ settings: sampleProduction.settings, scene: sampleProduction.scenes[0] } satisfies SceneProps}
        calculateMetadata={sceneMetadata}
        width={DEFAULT_SETTINGS.width}
        height={DEFAULT_SETTINGS.height}
        fps={DEFAULT_SETTINGS.fps}
        durationInFrames={1}
      />
    </>
  );
};
