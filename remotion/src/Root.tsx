import type React from 'react';
import { Composition, type CalculateMetadataFunction } from 'remotion';
import sample from '../fixtures/sample.json';
import { Production } from './Production';
import { SceneComposition } from './SceneComposition';
import { studioInput, studioSceneKey } from './studio-input';
import { DEFAULT_SETTINGS, framesFor, type ProductionProps, type SceneProps } from './types';

// 長さ・fps・幅・高さは入力から決める（DB は秒、Remotion はフレームで扱う）。
// 読み取り専用の Studio では、Laravel がページに埋め込んだ制作案の入力に置き換える（studio-input.ts）。
const productionMetadata: CalculateMetadataFunction<ProductionProps> = ({ props }) => {
  const input = studioInput() ?? props;
  const { width, height, fps } = input.settings;
  const durationInFrames = input.scenes.reduce((total, scene) => total + framesFor(scene.duration_seconds, fps), 0);

  return { width, height, fps, durationInFrames: Math.max(1, durationInFrames), props: input };
};

const sceneMetadata: CalculateMetadataFunction<SceneProps> = ({ props }) => {
  const embedded = studioInput();
  const input: SceneProps = embedded
    ? { settings: embedded.settings, scene: embedded.scenes.find((scene) => scene.key === studioSceneKey()) ?? embedded.scenes[0] ?? props.scene }
    : props;
  const { width, height, fps } = input.settings;

  return { width, height, fps, durationInFrames: framesFor(input.scene.duration_seconds, fps), props: input };
};

// 既定の入力（fixtures/sample.json）は Studio での確認用。書き出しでは Laravel が入力を渡す。
// defaultProps は <Composition> に直接書かない（直接書くと、Studio の入力の編集がコードに書き戻されるため）。
const sampleProduction = sample as ProductionProps;
const sampleScene: SceneProps = { settings: sampleProduction.settings, scene: sampleProduction.scenes[0] };

// コンポジションは全体用（Production）と場面用（Scene）の 2 つだけ。ID は config/remotion.php と合わせる。
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
        defaultProps={sampleScene}
        calculateMetadata={sceneMetadata}
        width={DEFAULT_SETTINGS.width}
        height={DEFAULT_SETTINGS.height}
        fps={DEFAULT_SETTINGS.fps}
        durationInFrames={1}
      />
    </>
  );
};
