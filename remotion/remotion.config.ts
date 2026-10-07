// Remotion Studio と CLI の設定。Laravel からの書き出しは scripts/render.mjs が行い、設定はそちらで渡す。
import { Config } from '@remotion/cli/config';

Config.setVideoImageFormat('jpeg');
Config.setOverwriteOutput(true);
